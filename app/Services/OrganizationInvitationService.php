<?php

namespace App\Services;

use App\Models\Loop;
use App\Models\LoopMember;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\PointLedger;
use App\Models\User;
use App\Support\Tenancy\UserWriteLock;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

/**
 * Direct-to-Organization invitations (TASK-1659): a SuperAdmin names a
 * person, an e-mail goes out, and clicking it creates the account — no
 * registration form, no password ever transmitted.
 *
 * Every state transition lives here rather than in a controller, exactly as
 * LoopInvitationService does for Loops, so the admin surface (create/resend)
 * and the public accept path cannot drift apart on the same rules.
 */
class OrganizationInvitationService
{
    /** Outcome of invite(): which case applied. */
    public const CASE_CREATED = 'created';

    public const CASE_RESENT = 'resent';

    public const CASE_ALREADY_MEMBER = 'already_member';

    /**
     * TASK-1732 (A6) — REMPLACE `CASE_EMAIL_USED_ELSEWHERE`.
     *
     * L'ancien nom disait « cette adresse est employee ailleurs », et c'etait
     * exact tant qu'un homonyme dans une AUTRE Organization etait un refus.
     * Depuis A6 cet homonyme est le cas NOMINAL : l'invitation part, et le clic
     * cree un compte FRERE. Le refus qui reste ne couvre plus qu'une seule
     * paire — celle qu'aucun index unique ne peut exprimer : un compte SANS
     * Organization portant la meme adresse (asymetrie documentee dans
     * `UniqueEmailInOrganization`). Creer le frere rendrait cet orphelin MUET.
     *
     * Le renommage est deliberement cassant : il force chaque site d'appel a
     * etre relu. Garder un nom qui affirme « employee ailleurs » pour designer
     * « collision avec un orphelin » aurait fait raisonner juste sur une
     * premisse fausse — exactement le defaut que le commentaire du Cas C
     * denoncait.
     */
    public const CASE_EMAIL_NOT_INVITABLE = 'email_not_invitable';

    public const CASE_SANDBOX_FORBIDDEN = 'sandbox_forbidden';

    /** Outcome of accept(). */
    public const RESULT_ACCEPTED = 'accepted';

    public const RESULT_ALREADY_ACCEPTED = 'already_accepted';

    public const RESULT_EXPIRED = 'expired';

    public const RESULT_REVOKED = 'revoked';

    public const RESULT_SANDBOX_FORBIDDEN = 'sandbox_forbidden';

    /** TASK-1732 (A6) — meme renommage, meme raison : voir `CASE_EMAIL_NOT_INVITABLE`. */
    public const RESULT_EMAIL_NOT_INVITABLE = 'email_not_invitable';

    public const RESULT_NOT_FOUND = 'not_found';

    /** Un compte existe deja pour cette adresse : on consomme, on n'authentifie pas. */
    public const RESULT_ACCOUNT_ALREADY_EXISTS = 'account_already_exists';

    /**
     * Create — or refresh — the single pending invitation for this
     * recipient, in this Organization.
     *
     * The three business cases (§16-18 of the MASTER brief) are all decided
     * HERE, under one transaction with lockForUpdate, so a SuperAdmin
     * submitting several people in one operation and a resend from the
     * tracking table share exactly the same rules and cannot race each
     * other into two live tokens for the same address.
     *
     * @return array{case: string, invitation: ?OrganizationInvitation, user: ?User}
     */
    public function invite(Organization $organization, User $admin, string $email, ?string $firstName, ?string $lastName, ?string $locale = null, ?string $loopId = null, ?string $hostOverride = null): array
    {
        $email = OrganizationInvitation::normalizeEmail($email);
        $locale = in_array($locale, OrganizationInvitation::LOCALES, true)
            ? $locale
            : OrganizationInvitation::DEFAULT_LOCALE;

        // Meme discipline que pour `loopId` juste en dessous : la relance
        // rentre par cette methode sans repasser par la validation de la
        // requete, donc l'environnement et la forme se reverifient ICI. Un
        // override hors local/testing est simplement abandonne.
        $hostOverride = OrganizationInvitation::hostOverrideAllowed()
            ? OrganizationInvitation::normalizeHostOverride($hostOverride)
            : null;

        // Une Boucle d'un AUTRE tenant n'est pas une cible : la frontiere se
        // verifie ici, et pas seulement dans la validation du formulaire, car
        // la relance rentre par cette meme methode sans repasser par elle.
        if ($loopId !== null && ! Loop::where('id', $loopId)->where('organization_id', $organization->id)->exists()) {
            $loopId = null;
        }

        return DB::transaction(function () use ($organization, $admin, $email, $firstName, $lastName, $locale, $loopId, $hostOverride) {
            // TASK-1650, third expression of the same guard: no real account
            // is ever provisioned into a Scenario Manager sandbox. Checked
            // here, not only at the form's server-side validation, because
            // resend() re-enters this same method without re-validating the
            // request.
            if ($organization->scenario_sandbox_created_at !== null) {
                return ['case' => self::CASE_SANDBOX_FORBIDDEN, 'invitation' => null, 'user' => null];
            }

            // Recherche INSENSIBLE A LA CASSE, et BORNEE A CETTE Organization.
            //
            // La casse : `email` est sensible a la casse sur PostgreSQL, et
            // `/admin/users` enregistre une adresse SANS regle `lowercase` —
            // une comparaison exacte laissait passer `Alice@x.com` quand on
            // invitait `alice@x.com`, soit deux comptes pour une seule boite
            // DANS LA MEME Organization. `LOWER()` des deux cotes, exactement
            // la forme que portent les index uniques de T1729. Meme clause que
            // LoopInvitationService, dont ce service reprend le patron.
            //
            // La portee : une seule recherche GLOBALE suivie d'un `->first()`
            // suffisait tant que l'adresse etait unique sur la plateforme.
            // Depuis A4 elle vit dans plusieurs Organizations, et `->first()`
            // pouvait rendre le compte d'un AUTRE tenant — donc repondre
            // « deja membre » ou « employee ailleurs » a propos de la mauvaise
            // personne. On ne demande donc qu'a CETTE Organization : c'est la
            // seule dont la reponse nous concerne.
            $membreIci = User::query()
                ->where('organization_id', $organization->id)
                ->whereRaw('LOWER(email) = ?', [$email])
                ->lockForUpdate()
                ->first();

            if ($membreIci) {
                return ['case' => self::CASE_ALREADY_MEMBER, 'invitation' => null, 'user' => $membreIci];
            }

            // TASK-1732 (A6) — LE CAS C EST LEVE.
            //
            // Ici se trouvait une recherche GLOBALE : tout homonyme, dans
            // n'importe quelle Organization, refusait l'invitation. Le
            // commentaire qui l'accompagnait le disait deja : « le refus [...]
            // repose sur une decision : creer ce compte frere est l'etape A6 ».
            // Nous y sommes (arbitrage MASTER du 30/10, points A a F).
            //
            // Un homonyme membre d'une AUTRE Organization n'est donc plus
            // consulte du tout : l'invitation part, et le clic creera un compte
            // FRERE. Deux consequences voulues :
            //
            //   - le compte existant n'est jamais lu, jamais touche, jamais
            //     deplace (T1726) — on ne le cherche meme plus ;
            //   - l'administrateur de CETTE Organization n'apprend rien sur les
            //     comptes des autres. L'ancien refus etait une fuite
            //     d'information inter-Organizations (exigence MASTER 3,
            //     non-enumeration) : il disait « cette adresse possede deja un
            //     compte associe a une autre organisation ».
            //
            // Reste UN refus, et un seul : un compte SANS Organization portant
            // cette adresse. Ce n'est pas une symetrie oubliee, c'est
            // l'asymetrie que `UniqueEmailInOrganization` documente — un
            // orphelin n'a qu'une porte, le login GLOBAL, et T1728 lui refuse
            // une adresse ambigue. Creer le frere le rendrait MUET. Aucun index
            // unique ne peut exprimer cette paire (une ligne a
            // `organization_id` nul, l'autre non) : c'est a l'application de la
            // tenir, et l'invitation devient le SIXIEME site a le faire.
            //
            // Ce controle-ci est celui de l'ERGONOMIE : il evite d'envoyer un
            // courriel qui echouerait au clic. Le controle d'INTEGRITE est dans
            // `accept()`, sous `UserWriteLock` (doctrine T1729).
            $orphelinHomonyme = User::query()
                ->whereNull('organization_id')
                ->whereRaw('LOWER(email) = ?', [$email])
                ->lockForUpdate()
                ->first();

            if ($orphelinHomonyme) {
                // `user` reste NUL, deliberement : l'appelant est une surface
                // d'administration d'une autre Organization que celle de ce
                // compte — lui rendre l'objet serait lui livrer l'information
                // qu'on vient de refuser de divulguer.
                return ['case' => self::CASE_EMAIL_NOT_INVITABLE, 'invitation' => null, 'user' => null];
            }

            $existing = OrganizationInvitation::where('organization_id', $organization->id)
                ->where('recipient_email', $email)
                ->lockForUpdate()
                ->get();

            $pending = $existing->first(fn (OrganizationInvitation $i) => $i->isPending());

            if ($pending) {
                // Refresh the human-facing fields and PUSH the expiry back
                // out to a fresh 48h window (Cyril, 30/09) — "Relancer"
                // must extend the deadline, not just resend the same link
                // with its original clock still running. The token itself
                // is kept: the link already in the recipient's mailbox
                // stays valid, it just lives longer.
                $pending->update(array_merge(
                    array_filter([
                        'recipient_first_name' => $firstName,
                        'recipient_name' => $lastName,
                    ], fn ($v) => $v !== null),
                    array_filter(['loop_id' => $loopId], fn ($v) => $v !== null),
                    // `host_override` est ecrit MEME a null — mais ce n'est
                    // jamais un retour SILENCIEUX au lien canonique :
                    // le bouton « Relancer » repasse la valeur DEJA stockee
                    // (cf. AdminUserBulkCreationController::resend), et le
                    // formulaire repasse ce que l'admin vient de saisir.
                    // Dans les deux cas la valeur ecrite est un choix
                    // explicite, pas un oubli.
                    ['locale' => $locale, 'host_override' => $hostOverride, 'expires_at' => now()->addHours(48)],
                ));

                return ['case' => self::CASE_RESENT, 'invitation' => $pending->fresh(), 'user' => null];
            }

            // A stale row flagged pending but past its window would
            // otherwise block a fresh invitation for ever.
            $existing->filter(fn (OrganizationInvitation $i) => $i->status === OrganizationInvitation::STATUS_PENDING && $i->isExpired())
                ->each(fn (OrganizationInvitation $i) => $i->update(['status' => OrganizationInvitation::STATUS_EXPIRED]));

            $invitation = OrganizationInvitation::create([
                'organization_id' => $organization->id,
                'loop_id' => $loopId,
                'created_by_user_id' => $admin->id,
                'recipient_first_name' => $firstName,
                'recipient_name' => $lastName,
                'recipient_email' => $email,
                'locale' => $locale,
                'host_override' => $hostOverride,
                'status' => OrganizationInvitation::STATUS_PENDING,
            ]);

            return ['case' => self::CASE_CREATED, 'invitation' => $invitation, 'user' => null];
        });
    }

    /**
     * Faire entrer la personne dans la Boucle CIBLE, quand il y en a une.
     *
     * C'est ici que l'invitation « vaut autorisation » : un SuperAdmin a
     * designe la Boucle, donc une Boucle privee ou sur invitation s'ouvre
     * sans demande d'adhesion separee.
     *
     * Un echec n'annule PAS l'acceptation : le compte vient d'etre cree, il
     * est valide, et l'ancrer a la reussite d'une adhesion ferait perdre
     * l'identite pour un probleme d'appartenance. L'echec est journalise —
     * jamais avale en silence — et la personne atterrit simplement sur son
     * Organization au lieu de la Boucle.
     *
     * TASK-1732 (A6) : appelee APRES le commit de `accept()`, et plus dedans.
     * Deux raisons, detaillees en tete de `accept()` — ne pas tenir le verrou
     * de table pendant une notification, et rendre la promesse ci-dessus
     * structurelle au lieu de dependre de la liste des exceptions attrapees.
     */
    private function joinTargetLoop(OrganizationInvitation $invitation, User $user): void
    {
        $loop = $invitation->loop;

        if (! $loop || $loop->organization_id !== $user->organization_id) {
            return;
        }

        // Symetrique de la re-verification faite sur l'Organization juste
        // au-dessus (sandbox / desactivation) : la Boucle a pu etre archivee
        // dans les 48 h qui separent l'envoi du clic. Sans cette garde, la
        // personne etait ajoutee comme membre ACTIF d'une Boucle archivee —
        // l'asymetrie entre les deux gardes etait le defaut, pas le cas
        // particulier (revue 2, 01/10). `LoopService::addMemberByUserId()` ne
        // regarde que le statut du MEMBRE, jamais celui de la Boucle.
        if ($loop->status !== 'active') {
            Log::warning('organization_invitation: Boucle cible non active a l\'acceptation', [
                'invitation_id' => $invitation->id,
                'loop_id' => $loop->id,
                'loop_status' => $loop->status,
            ]);

            return;
        }

        $dejaMembre = LoopMember::where('loop_id', $loop->id)
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->exists();

        if ($dejaMembre) {
            return;
        }

        try {
            app(LoopService::class)->addMemberByUserId($loop, $user->id);
        } catch (\RuntimeException|ModelNotFoundException $e) {
            Log::warning('organization_invitation: adhesion a la Boucle cible refusee', [
                'invitation_id' => $invitation->id,
                'loop_id' => $loop->id,
                'user_id' => $user->id,
                'reason' => $e->getMessage(),
            ]);
        }
    }

    public function revoke(OrganizationInvitation $invitation): void
    {
        // Transaction + verrou : sans eux, une revocation qui avait lu la
        // ligne « pending » pouvait ecraser en `revoked` une acceptation
        // commitee entre-temps — compte cree et session ouverte, mais suivi
        // annoncant « revoquee » (revue 1, 01/10).
        DB::transaction(function () use ($invitation) {
            $verrouillee = OrganizationInvitation::whereKey($invitation->getKey())->lockForUpdate()->first();

            if (! $verrouillee || $verrouillee->status !== OrganizationInvitation::STATUS_PENDING) {
                throw new \RuntimeException('Only a pending invitation can be revoked.');
            }

            $verrouillee->update(['status' => OrganizationInvitation::STATUS_REVOKED]);
            $invitation->setAttribute('status', OrganizationInvitation::STATUS_REVOKED);
        });
    }

    /**
     * Accept an invitation by token — public, unauthenticated. Creates the
     * User (minimal fields only, random unusable password) and verifies its
     * e-mail, because clicking a token sent to that exact address IS the
     * proof of possession. Never throws for an expected refusal: callers
     * render a message, they do not catch exceptions.
     *
     * TASK-1732 (A6) — L'ADHESION A LA BOUCLE EST SORTIE DE LA TRANSACTION.
     *
     * La creation du compte prend desormais `UserWriteLock` (voir plus bas), et
     * un verrou de table se tient jusqu'au COMMIT : tout ce qui reste dans la
     * transaction est tenu avec lui. Or `joinTargetLoop()` appelle
     * `LoopService::addMemberByUserId()`, qui notifie — donc, en
     * `QUEUE_CONNECTION=sync` (c'est la configuration de PROD), envoie un
     * courriel. Tenir `SHARE ROW EXCLUSIVE` sur `users` pendant un aller-retour
     * SMTP aurait bloque toutes les creations de comptes de l'application le
     * temps d'une latence exterieure — precisement ce que T1729 interdit : « le
     * verrou doit entourer le controle et l'ecriture, et RIEN d'autre ».
     *
     * Ce deplacement ne change pas le contrat, il le rend structurel : la
     * methode promettait deja « un echec n'annule PAS l'acceptation », et elle
     * le tenait en attrapant deux exceptions nommees. Une troisieme, inattendue,
     * annulait silencieusement la creation du compte. Hors transaction, la
     * promesse ne depend plus de la liste des exceptions prevues.
     *
     * @return array{result: string, invitation: ?OrganizationInvitation, user: ?User}
     */
    public function accept(string $token): array
    {
        $resultat = DB::transaction(function () use ($token) {
            $invitation = OrganizationInvitation::where('token', $token)->lockForUpdate()->first();

            if (! $invitation) {
                return ['result' => self::RESULT_NOT_FOUND, 'invitation' => null, 'user' => null];
            }

            if ($invitation->isRevoked()) {
                return ['result' => self::RESULT_REVOKED, 'invitation' => $invitation, 'user' => null];
            }

            if ($invitation->isAccepted()) {
                // DEFAUT DE SECURITE corrige (Cyril, 30/09) : ce chemin
                // rendait `acceptedBy`, et l'appelant y ouvrait une session.
                // Le jeton — envoye par courriel, donc recopie dans une boite
                // mail, un historique de navigation, une capture d'ecran —
                // devenait alors un mot de passe PERMANENT pour ce compte :
                // n'importe qui le detenant se connectait, indefiniment.
                //
                // Un jeton a usage unique est CONSOMME : il n'authentifie
                // plus personne une fois accepte. La personne se reconnecte
                // par le formulaire, ou passe par « mot de passe oublie »
                // si elle n'en a jamais pose — son adresse est verifiee, ce
                // chemin lui est ouvert.
                return [
                    'result' => self::RESULT_ALREADY_ACCEPTED,
                    'invitation' => $invitation,
                    'user' => null,
                ];
            }

            if ($invitation->isExpired()) {
                $invitation->update(['status' => OrganizationInvitation::STATUS_EXPIRED]);

                return ['result' => self::RESULT_EXPIRED, 'invitation' => $invitation, 'user' => null];
            }

            $organization = $invitation->organization;

            // Defensive re-check: the Organization could have become a
            // sandbox, or been deactivated, after the invitation was sent.
            // `is_active` est verifie ICI aussi : le commentaire promettait
            // « ou desactivee » alors que la condition ne regardait que la
            // sandbox (revue 1, 01/10). Un commentaire qui ment est pire que
            // pas de commentaire.
            if (! $organization || $organization->scenario_sandbox_created_at !== null || ! $organization->is_active) {
                return ['result' => self::RESULT_SANDBOX_FORBIDDEN, 'invitation' => $invitation, 'user' => null];
            }

            $adresse = OrganizationInvitation::normalizeEmail($invitation->recipient_email);

            // TASK-1732 (A6) — le repli GLOBAL est retire, et c'est tout le
            // changement de ce chemin.
            //
            // Il y avait ici deux recherches : CETTE Organization, puis, a
            // defaut, la plateforme entiere. La seconde ne servait qu'a produire
            // le refus « adresse employee ailleurs ». Ce refus n'existe plus :
            // un homonyme dans une autre Organization est desormais le cas
            // nominal, et le compte FRERE se cree plus bas par le chemin deja
            // ecrit — sans jamais lire, toucher ni deplacer le compte de l'autre
            // tenant (T1726).
            //
            // La question posee ici redevient donc la seule qui compte : « cette
            // adresse a-t-elle deja un compte DANS CETTE Organization ? ».
            //
            // `UserWriteLock` AVANT la question, et non apres : la paire
            // « orphelin + membre » n'est exprimable par aucun index unique, et
            // c'est la seule collision que la base ne rattrapera pas. Sans ce
            // verrou, une suppression d'Organization concurrente — qui DETACHE
            // ses membres, donc fabrique des orphelins — pouvait commiter entre
            // notre controle et notre ecriture ; sa propre garde
            // (`OrganizationDetachGuard`) ne voyait pas notre ligne encore non
            // commitee, et les deux cotes se croyaient saufs. Le verrou rend les
            // deux gestes mutuellement exclusifs : l'un des deux voit l'autre et
            // refuse. Huitieme site a prendre ce verrou, meme doctrine que les
            // sept autres (T1729 revue F2).
            //
            // Le verrou est pris ICI et pas au debut de `accept()` : les
            // controles qui precedent (jeton, expiration, revocation, sandbox)
            // ne portent pas sur `users`.
            UserWriteLock::acquire();

            $existingUser = User::query()
                ->where('organization_id', $organization->id)
                ->whereRaw('LOWER(email) = ?', [$adresse])
                ->lockForUpdate()
                ->first();

            if ($existingUser) {
                // DEFAUT DE SECURITE corrige (revue 1, 01/10) : ce chemin
                // rendait l'utilisateur, et l'appelant ouvrait une session.
                // Un jeton envoye par courriel devenait donc un moyen de se
                // connecter a un compte DEJA EXISTANT — y compris un compte
                // dont la personne avait entre-temps pose son propre mot de
                // passe en s'inscrivant normalement.
                //
                // Le jeton ne prouve que la possession de l'ADRESSE, ce qui
                // suffit a CREER un compte, jamais a entrer dans un compte
                // existant. L'invitation est donc consommee — elle a bien
                // atteint sa personne — mais sans session, et sans toucher
                // aux appartenances : une requete non authentifiee ne doit
                // produire aucune mutation.
                //
                // Consequence assumee : l'adhesion a la Boucle cible n'est
                // PAS posee dans ce cas ; elle redevient un geste
                // d'administration.
                $invitation->update([
                    'status' => OrganizationInvitation::STATUS_ACCEPTED,
                    'accepted_at' => now(),
                    'accepted_by_user_id' => $existingUser->id,
                ]);

                return ['result' => self::RESULT_ACCOUNT_ALREADY_EXISTS, 'invitation' => $invitation->fresh(), 'user' => null];
            }

            // TASK-1732 (A6) — LE CONTROLE D'INTEGRITE, sous le verrou pris
            // ci-dessus (exigence MASTER 2).
            //
            // `invite()` pose la meme question, mais c'est un controle
            // d'ERGONOMIE : il tourne 48 h plus tot et sans verrou, et il evite
            // seulement d'envoyer un courriel qui echouerait au clic. Celui-ci
            // est le controle qui COMPTE : c'est le dernier instant avant
            // l'ecriture, et il est solidaire d'elle.
            //
            // Les deux posent la MEME question — « un compte sans Organization
            // porte-t-il cette adresse ? » — et les deux refusent pour la meme
            // raison : creer le frere rendrait cet orphelin MUET (il n'a que le
            // login GLOBAL, que T1728 ferme sur une adresse ambigue).
            //
            // LE JETON N'EST PAS CONSOMME (exigence MASTER 2). On sort avant
            // toute mutation : ni compte, ni ledger, ni changement de statut.
            // L'invitation reste « pending » et redeviendra cliquable le jour ou
            // l'orphelin sera rattache ou supprime — ce qui est exactement le
            // bon comportement : la personne n'a rien fait de mal, et son lien
            // ne doit pas etre brule par un etat de la plateforme qui ne la
            // concerne pas.
            $orphelinHomonyme = User::query()
                ->whereNull('organization_id')
                ->whereRaw('LOWER(email) = ?', [$adresse])
                ->lockForUpdate()
                ->first();

            if ($orphelinHomonyme) {
                Log::warning('organization_invitation: creation refusee, un compte sans Organization porte cette adresse', [
                    'invitation_id' => $invitation->id,
                    'organization_id' => $organization->id,
                ]);

                return ['result' => self::RESULT_EMAIL_NOT_INVITABLE, 'invitation' => $invitation, 'user' => null];
            }

            $user = User::create([
                'name' => $invitation->recipientFullName(),
                'first_name' => $invitation->recipient_first_name,
                'email' => $invitation->recipient_email,
                // Random, hashed, and never transmitted anywhere — the
                // person sets their own password later through the
                // existing "forgot password" primitive if they want one.
                'password' => Hash::make(bin2hex(random_bytes(16))),
                'points_balance' => 100,
                'organization_id' => $organization->id,
            ]);

            // Both flags are deliberately NOT in User::$fillable —
            // mass-assigning them would let any other form flip them by
            // accident. This is the one place allowed to set them.
            //
            // email_verified_at: clicking a token sent to this exact
            // address IS the proof of possession (MASTER,
            // INVITATION_CLICK_VERIFIES_EMAIL = YES).
            //
            // must_set_password: the account was born with a random secret
            // nobody knows, so the person must choose one before going
            // anywhere (Cyril, 30/09) — otherwise they never would.
            // TASK-1714 volet 2 (arbitrage MASTER A2) : `approved_at` rejoint
            // ces deux-la, pour la meme raison et par le meme chemin. Une
            // invitation EST une approbation — quelqu'un de l'interieur a deja
            // vouche, et faire attendre une personne explicitement conviee
            // n'ajouterait aucune decision.
            $user->forceFill([
                'email_verified_at' => now(),
                'must_set_password' => true,
                'approved_at' => now(),
            ])->save();

            PointLedger::create([
                'user_id' => $user->id,
                'transaction_id' => null,
                'delta' => 100,
                'organization_id' => $user->organization_id,
                'reason' => 'welcome_bonus',
            ]);

            $invitation->update([
                'status' => OrganizationInvitation::STATUS_ACCEPTED,
                'accepted_at' => now(),
                'accepted_by_user_id' => $user->id,
            ]);

            return ['result' => self::RESULT_ACCEPTED, 'invitation' => $invitation->fresh(), 'user' => $user];
        });

        // HORS transaction, donc hors verrou : voir l'explication en tete de
        // methode. L'ordre reste le meme — le compte existe et l'invitation est
        // consommee avant qu'on tente l'adhesion —, et l'echec reste sans effet
        // sur l'acceptation.
        if ($resultat['result'] === self::RESULT_ACCEPTED) {
            $this->joinTargetLoop($resultat['invitation'], $resultat['user']);
        }

        return $resultat;
    }
}
