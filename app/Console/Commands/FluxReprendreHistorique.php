<?php

namespace App\Console\Commands;

use App\Models\FeedPost;
use App\Models\Loop;
use App\Models\LoopEvent;
use App\Models\LoopMember;
use App\Models\Organization;
use App\Models\Scopes\BelongsToOrganizationScope;
use App\Models\Service;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Support\Feed\FeedEventCatalogue;
use App\Support\Feed\FeedEventEmitter;
use Illuminate\Console\Command;

/**
 * TASK-1752 (§8.4) — remplir le Flux avec ce qui s'est deja passe.
 *
 * > Decision Cyril, 04/11 : « on diffuse "Jean a fini sa presentation" et on
 * > anti-date, ce n'est pas grave. C'est pour montrer que l'organisation vit.
 * > Et justement la fonctionnalite de reprise ou de recreer est une bonne
 * > chose. Cela plaira aux membres. »
 *
 * ## ADDITIVE, exclusivement — et c'est ce qui la rend rejouable sans danger
 *
 * Cyril parle de « reprise **ou de recreer** ». Les deux ne coutent pas la
 * meme chose :
 *
 * - **reprise** : ajoute ce qui manque, ne touche a rien. La contrainte
 *   d'unicite rend chaque relance inoffensive — on peut la lancer dix fois ;
 * - **« recreer »** (effacer puis reconstruire) : **detruirait le travail des
 *   membres**. `feed_post_comments.feed_post_id` est une FK `cascadeOnDelete`,
 *   donc un `forceDelete()` efface les commentaires. Et `reactions` est un
 *   morph **sans FK** : elles ne seraient meme pas nettoyees, elles
 *   deviendraient orphelines.
 *
 * Cette commande n'efface donc JAMAIS. Retirer une ligne precise est deja
 * couvert, ligne par ligne, par la page SuperAdmin.
 *
 * `--annuler` existe quand meme, et il est exact : chaque ligne reprise est
 * identifiable par `object_type`/`object_id` + son `type` au registre. Il ne
 * peut pas deborder sur une annonce humaine. Il emporte les commentaires
 * attaches, et le dit.
 *
 * ## Les dates reelles sont en base pour quatre evenements sur cinq
 *
 * `loops.created_at`, `loop_members.joined_at`, `services.created_at`,
 * `service_requests.created_at`, `loop_events.created_at`.
 *
 * La **presentation** n'en a aucune : ce n'est pas un objet cree a une date,
 * c'est un ETAT (`bio` non vide). Le depot n'a aucune trace datee — pas de
 * colonne, rien dans les journaux, et `users.updated_at` ne vaut rien (c'est
 * la date du dernier enregistrement de profil, peut-etre un changement de
 * telephone de la veille).
 *
 * Retenu : **`users.created_at`**. L'approximation se trompe dans le bon sens
 * — jamais dans le futur, jamais en pretendant que c'est recent. Le drapeau
 * `--presentations` rend ce choix VISIBLE et reversible plutot qu'enfoui.
 *
 * ## Aucune notification, jamais
 *
 * La reprise ecrit des lignes de Flux et rien d'autre. Si des notifications
 * sont un jour branchees sur ces evenements, ce chemin doit rester separe —
 * sinon une reprise enverrait des dizaines de notifications pour des faits de
 * mai. L'emetteur de Flux n'en emet aucune : c'est deja vrai par construction,
 * et c'est note ici pour que cela le reste.
 */
class FluxReprendreHistorique extends Command
{
    protected $signature = 'flux:reprendre-historique
        {--organization= : le slug ou l\'identifiant de l\'Organization (OBLIGATOIRE)}
        {--dry-run : annonce ce qui serait ecrit, sans rien ecrire}
        {--presentations=date-du-compte : date-du-compte|ignorer}
        {--annuler : retire les lignes reprises (emporte leurs commentaires)}';

    protected $description = 'Remplit le Flux d\'une Organization avec les faits deja survenus, antidates.';

    public function handle(): int
    {
        $organization = $this->resoudreOrganization();

        if ($organization === null) {
            return self::FAILURE;
        }

        if ($this->option('annuler')) {
            return $this->annuler($organization);
        }

        $presentations = (string) $this->option('presentations');

        if (! in_array($presentations, ['date-du-compte', 'ignorer'], true)) {
            $this->error('--presentations attend `date-du-compte` ou `ignorer`.');

            return self::FAILURE;
        }

        $sec = (bool) $this->option('dry-run');

        $this->info(($sec ? '[A BLANC] ' : '').'Organization : '.$organization->name.' ('.$organization->slug.')');
        $this->newLine();

        $comptes = [];

        foreach ($this->sources($organization, $presentations) as $libelle => $source) {
            $comptes[$libelle] = $this->reprendre($organization, $source, $sec);
        }

        $this->newLine();

        foreach ($comptes as $libelle => $n) {
            $this->line(sprintf('  %-28s %d', $libelle, $n));
        }

        $total = array_sum($comptes);
        $this->newLine();

        $this->info($sec
            ? "A blanc : {$total} ligne(s) seraient ajoutee(s). Relancer sans --dry-run pour ecrire."
            : "{$total} ligne(s) ajoutee(s).");

        return self::SUCCESS;
    }

    /**
     * Les cinq sources, chacune avec sa vraie date.
     *
     * Les scopes globaux sont retires nommement : cette commande tourne en
     * console, sans Organization courante, et le fail-closed du scope ne
     * trouverait rien. La portee est reposee a la main par le `where`.
     *
     * @return array<string, iterable<array{type: string, objet: mixed, acteur: User|null, boucle: Loop|null, date: mixed}>>
     */
    private function sources(Organization $organization, string $presentations): array
    {
        $sources = [];

        $sources['Boucles creees'] = Loop::withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->get()
            ->map(fn (Loop $loop) => [
                'type' => FeedEventCatalogue::BOUCLE_CREEE,
                'objet' => $loop,
                'acteur' => $loop->created_by ? User::find($loop->created_by) : null,
                'boucle' => $loop,
                'date' => $loop->created_at,
            ]);

        $sources['Adhesions'] = LoopMember::withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->where('status', 'active')
            // `owner` exclu : sinon chaque creation produirait DEUX lignes.
            ->where('role', '!=', 'owner')
            ->get()
            ->map(function (LoopMember $m) {
                $boucle = Loop::withoutGlobalScopes()->find($m->loop_id);

                return [
                    'type' => FeedEventCatalogue::ADHESION_BOUCLE,
                    'objet' => $boucle,
                    'acteur' => User::find($m->user_id),
                    'boucle' => $boucle,
                    'date' => $m->joined_at ?? $m->created_at,
                ];
            });

        $sources['Propositions d\'aide'] = Service::withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->get()
            ->map(fn (Service $s) => [
                'type' => FeedEventCatalogue::PROPOSITION_PUBLIEE,
                'objet' => $s,
                'acteur' => User::find($s->user_id),
                'boucle' => null,
                'date' => $s->created_at,
            ]);

        $sources['Demandes d\'aide'] = ServiceRequest::withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->get()
            ->map(fn (ServiceRequest $d) => [
                'type' => FeedEventCatalogue::DEMANDE_PUBLIEE,
                'objet' => $d,
                'acteur' => User::find($d->user_id),
                'boucle' => null,
                'date' => $d->created_at,
            ]);

        $sources['Evenements'] = LoopEvent::withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->get()
            ->map(function (LoopEvent $e) {
                $boucle = Loop::withoutGlobalScopes()->find($e->loop_id);

                return [
                    'type' => FeedEventCatalogue::EVENEMENT_CREE,
                    'objet' => $e,
                    'acteur' => $e->created_by ? User::find($e->created_by) : null,
                    'boucle' => $boucle,
                    'date' => $e->created_at,
                ];
            });

        if ($presentations === 'date-du-compte') {
            $sources['Presentations'] = User::where('organization_id', $organization->id)
                ->whereNotNull('bio')
                ->where('bio', '!=', '')
                ->get()
                ->map(fn (User $u) => [
                    'type' => FeedEventCatalogue::PRESENTATION_TERMINEE,
                    'objet' => $u,
                    'acteur' => $u,
                    'boucle' => null,
                    // APPROXIMATION ASSUMEE, voir le docblock de la classe.
                    'date' => $u->created_at,
                ]);
        }

        return $sources;
    }

    private function reprendre(Organization $organization, iterable $lignes, bool $sec): int
    {
        $emetteur = app(FeedEventEmitter::class);
        $ecrites = 0;

        foreach ($lignes as $ligne) {
            if ($ligne['objet'] === null || $ligne['acteur'] === null) {
                continue;
            }

            // Compter a blanc demande la MEME question que l'ecriture : la
            // ligne existe-t-elle deja ? Sinon le compte annonce serait faux
            // des la seconde passe.
            $dejaLa = FeedPost::withTrashed()->where([
                'organization_id' => $organization->id,
                'type' => $ligne['type'],
                'object_type' => FeedEventCatalogue::classeAttendue($ligne['type']),
                'object_id' => (string) $ligne['objet']->getKey(),
            ])->exists();

            if ($dejaLa) {
                continue;
            }

            if ($sec) {
                $ecrites++;

                continue;
            }

            $resultat = $emetteur->emettre(
                $ligne['type'],
                $ligne['objet'],
                $ligne['acteur'],
                $organization,
                $ligne['boucle'],
                $ligne['date'],
            );

            if ($resultat !== null) {
                $ecrites++;
            }
        }

        return $ecrites;
    }

    /**
     * Le retrait EXACT.
     *
     * Vise uniquement les lignes dont le `type` est au registre : une annonce
     * humaine ne peut pas etre emportee, quelle que soit la faute de frappe.
     */
    private function annuler(Organization $organization): int
    {
        $lignes = FeedPost::withTrashed()
            ->where('organization_id', $organization->id)
            ->whereIn('type', FeedEventCatalogue::clefs());

        $n = (clone $lignes)->count();

        if ($n === 0) {
            $this->info('Aucune ligne d\'evenement a retirer.');

            return self::SUCCESS;
        }

        if (! $this->confirm("Retirer {$n} ligne(s) d'evenement de « {$organization->name} » ? Leurs commentaires seront effaces avec elles.")) {
            $this->line('Annule.');

            return self::SUCCESS;
        }

        foreach ($lignes->get() as $ligne) {
            // Les reactions sont un morph SANS FK : sans ce retrait explicite,
            // elles survivraient en orphelines.
            $ligne->reactions()->delete();
            $ligne->forceDelete();
        }

        $this->info("{$n} ligne(s) retiree(s).");

        return self::SUCCESS;
    }

    private function resoudreOrganization(): ?Organization
    {
        $cle = (string) $this->option('organization');

        if ($cle === '') {
            // Jamais « toutes » par defaut : trois tenants, trois decisions.
            $this->error('--organization est obligatoire (slug ou identifiant).');

            return null;
        }

        $organization = Organization::where('slug', $cle)->first()
            ?? Organization::whereKey($cle)->first();

        if ($organization === null) {
            $this->error("Organization introuvable : {$cle}");
        }

        return $organization;
    }
}
