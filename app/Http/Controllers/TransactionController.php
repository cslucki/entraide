<?php

namespace App\Http\Controllers;

use App\Models\Message;
use App\Models\PointLedger;
use App\Models\Scopes\BelongsToOrganizationScope;
use App\Models\Service;
use App\Models\ServiceRequest;
use App\Models\Transaction;
use App\Notifications\TransactionStatusChanged;
use App\Services\Entraide\EvaluationInviteMailer;
use App\Services\ServiceRequestLifecycle;
use App\Support\Entraide\Atelier;
use App\Support\Entraide\EvaluationEntraide;
use App\Support\Entraide\MessageSysteme;
use App\Support\Entraide\TempsPartage;
use App\Support\Feed\FeedEventCatalogue;
use App\Support\Feed\FeedEventEmitter;
use App\Support\Notifications\NotificationCatalogue;
use App\Support\Notifications\NotificationEmitter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TransactionController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $organization = currentOrganization();
        $actor = auth()->user();

        // TASK-1289 : la garde existante verifiait l'Organization resolue puis,
        // plus bas, que la CIBLE (service ou demande) lui appartient (T075.15).
        // Elle ne verifiait jamais que l'ACTEUR lui appartient : un membre
        // d'une autre Organization devenait buyer (service) ou seller (demande)
        // d'une Transaction creee dans l'Organization par defaut. Completee
        // ici, meme forme que ProfileController / ReportController.
        if (! $organization || $actor->organization_id !== $organization->id) {
            abort(404);
        }

        $data = $request->validate([
            'service_id' => 'nullable|uuid|exists:services,id',
            'request_id' => 'nullable|uuid|exists:service_requests,id',
            // TASK-1754 §9.3 — `min:0`, et c'est LA difference philosophique avec
            // une place de marche : le don y est impossible, ici il doit etre un
            // bouton. Arbitrage Q7 de Cyril : partout, pas seulement en reponse a
            // une demande — une proposition d'aide peut aussi s'offrir.
            'points_proposed' => 'required|integer|min:0',
        ]);

        $buyer = $actor;

        // Determine seller and organization_id
        $organizationId = null;
        if (! empty($data['service_id'])) {
            $service = Service::withoutGlobalScope(BelongsToOrganizationScope::class)->findOrFail($data['service_id']);

            if ($service->organization_id === null || $service->organization_id !== $organization->id) {
                abort(404);
            }

            $seller = $service->user;
            $sellerId = $seller->id;
            $organizationId = $service->organization_id;

            // TASK-1751 — le destinataire est pose ICI, dans la branche.
            //
            // Il doit etre celui qui n'a PAS clique : l'auteur de l'annonce.
            // Le deduire apres coup de `$buyer` ou `$seller` serait fragile —
            // la branche voisine REASSIGNE `$buyer` quelques lignes plus bas,
            // et une relecture distraite notifierait l'acteur lui-meme.
            $destinataire = $service->user;
        } else {
            $serviceReq = ServiceRequest::withoutGlobalScope(BelongsToOrganizationScope::class)->findOrFail($data['request_id']);

            if ($serviceReq->organization_id === null || $serviceReq->organization_id !== $organization->id) {
                abort(404);
            }

            $seller = $buyer;
            $sellerId = $buyer->id;
            $buyer = $serviceReq->user;
            $organizationId = $serviceReq->organization_id;

            // TASK-1751 — ici aussi l'auteur de l'annonce, et c'est la ligne
            // qui rend le piege visible : `$buyer` VIENT d'etre reassigne.
            $destinataire = $serviceReq->user;
        }

        // Prevent self-transaction
        if ($buyer->id === $sellerId) {
            return back()->with('error', __('entraide.flash.soi_meme'));
        }

        // Check buyer balance
        if ($buyer->points_balance < $data['points_proposed']) {
            return back()->with('error', __('entraide.flash.solde_insuffisant'));
        }

        // TASK-1693 bug S — « VOUS avez deja une transaction en cours » doit
        // parler de CELUI QUI PROPOSE, pas de l'acheteur.
        //
        // Sur une demande d'aide, `$buyer` a ete echange juste au-dessus : il
        // designe l'AUTEUR DE LA DEMANDE, pas la personne qui clique. La garde
        // disait donc « cette demande a deja une proposition » tout en
        // l'annoncant au second aidant comme SA transaction — un message faux
        // pour lui, qui n'avait rien. C'est le troisieme symptome de la meme
        // racine que le bouton « Accepter » : le proposant n'etait pas celui
        // qu'on croyait.
        //
        // Le proposant est TOUJOURS l'acteur authentifie. On vise donc la
        // colonne qui le porte, cote service comme cote demande.
        // TASK-1754 — la MEME methode que les deux pages d'annonce interrogent.
        // Recopiee ici, elle aurait diverge, et la divergence serait muette :
        // une vue qui croit qu'il n'y a pas d'echange affiche le formulaire, le
        // POST part, et la garde le refuse — le cul-de-sac revient.

        // TASK-1754 (§4.2b) — un doublon n'est plus une ERREUR, c'est une
        // REDIRECTION vers la conversation qui existe deja.
        //
        // ## Le defaut repare
        //
        // La garde rendait `back()->with('error', …)` : un toast rouge sur la
        // page de l'annonce, et AUCUN chemin vers la conversation. Un
        // cul-de-sac, reproduit en recette le 04/11. L'utilisateur voulait
        // parler a quelqu'un ; on lui disait qu'il ne pouvait pas, sans lui dire
        // ou aller.
        //
        // ## Pourquoi la garde RESTE malgre le correctif de la vue
        //
        // Le lot 3 fait disparaitre le formulaire quand un echange est actif.
        // Ca ne suffit pas : un second onglet, un bouton de la PWA garde en
        // cache, un double-submit — le POST en double reste possible. La vue
        // supprime l'occasion, cette garde supprime la consequence.
        //
        // `info` et non `error` : il ne s'est rien passe de mal.
        // TASK-1756 §6.3 — un atelier COMPLET ne recoit plus de proposition.
        //
        // Cette garde-ci est un CONFORT : elle evite a quelqu'un d'ecrire pour
        // rien. Elle ne suffit pas, et ce n'est pas un defaut — voir
        // `approve()`, ou la borne tient reellement.
        if (! empty($data['service_id'])) {
            $annonceVisee = Service::withoutGlobalScope(BelongsToOrganizationScope::class)->find($data['service_id']);

            if ($annonceVisee !== null && Atelier::estComplet($annonceVisee)) {
                return back()->with('error', __('entraide.atelier.complet'));
            }

            // TASK-1756, defaut de recette du 05/11 — sur un atelier, le temps
            // propose n'est pas celui qu'on a tape : c'est la PART.
            //
            // La part se calcule, elle ne se negocie pas (voir
            // `Atelier::tempsNegociable()`). On la pose ICI, avant la garde de
            // solde, et c'est tout l'enjeu : la vue proposait `points_cost`
            // entier, si bien qu'un membre a 40 min de solde se voyait refuser
            // une place a 15 min. Corriger la seule vue aurait laisse la porte
            // ouverte a un POST forge.
            //
            // On pose le MAXIMUM, pas la part du moment : entre la proposition
            // et l'acceptation l'atelier ne peut que se remplir, donc la part
            // ne peut que BAISSER. Le participant s'engage sur un plafond qu'il
            // a vu a l'ecran, et `approve()` arrete le chiffre definitif.
            if ($annonceVisee !== null && ! Atelier::tempsNegociable($annonceVisee)) {
                $data['points_proposed'] = Atelier::partMaximale($annonceVisee);
            }
        }

        $existant = Transaction::echangeActif(
            $actor->id,
            $data['service_id'] ?? null,
            $data['request_id'] ?? null,
        );

        if ($existant !== null) {
            return $this->redirectToMessage($existant)
                ->with('info', __('entraide.deja_en_discussion'));
        }

        $transaction = Transaction::create([
            'service_id' => $data['service_id'] ?? null,
            'request_id' => $data['request_id'] ?? null,
            'buyer_id' => $buyer->id,
            'seller_id' => $sellerId,
            'organization_id' => $organizationId,
            'points_proposed' => $data['points_proposed'],
            'status' => 'pending',
        ]);

        MessageSysteme::ecrire($transaction, 'proposition_envoyee', ['points' => (int) $data['points_proposed']]);

        // TASK-1751 — prevenir l'auteur de l'annonce.
        //
        // `store()` n'emettait RIEN jusqu'ici : ni in-app, ni email, ni push.
        // Quelqu'un pouvait repondre a votre annonce sans que vous l'appreniez
        // autrement qu'en revenant voir par vous-meme.
        //
        // `rescue()` comme `approve()` et `refuse()` le font deja : l'echange
        // EST cree a ce stade, et la transaction metier ne doit jamais echouer
        // parce qu'une notification a leve. Le destinataire verrait une erreur
        // pour un echange pourtant enregistre.
        //
        // `eventId` = l'id de la Transaction. Ici l'EVENEMENT (« cet echange
        // vient d'etre cree ») et l'OBJET coincident, parce qu'une Transaction
        // n'est creee qu'une fois — contrairement a `loop.help_request.created`
        // qui devait eviter l'id de la Boucle, reutilisable par plusieurs
        // demandes. L'idempotence sur `(event_id, recipient_id)` protege donc
        // d'une double soumission.
        rescue(fn () => app(NotificationEmitter::class)->emit(
            notificationKey: NotificationCatalogue::TRANSACTION_PROPOSAL_CREATED,
            organization: (string) $organizationId,
            recipient: $destinataire,
            eventId: (string) $transaction->id,
            objectType: NotificationCatalogue::OBJECT_TRANSACTION,
            objectId: (string) $transaction->id,
            actor: $actor,
        ), report: true);

        // TASK-1693 bug W — la demande ne quitte PLUS l'explorateur ici.
        //
        // Elle y restait jusqu'a la premiere proposition seulement, et ne
        // pouvait donc recevoir qu'UNE offre, de qui que ce soit. Elle le
        // quitte desormais a l'ACCEPTATION (`approve()`), ce qui laisse son
        // auteur comparer plusieurs propositions.
        //
        // Rien d'autre ne bouge : la demande reste `open`, donc l'explorateur
        // (`scopeOpen`) et le formulaire « Proposer mon aide »
        // (`requests/show.blade.php:111`) la montrent sans qu'on ait a les
        // toucher — les deux verrous tombent d'eux-memes.

        return $this->redirectToMessage($transaction)->with('success', __('entraide.flash.proposition_envoyee'));
    }

    public function orgStore(Request $request, string $org): RedirectResponse
    {
        return $this->store($request);
    }

    public function approve(Transaction $transaction): RedirectResponse
    {
        $this->authorize('approve', $transaction);

        // TASK-1693 bug W — le solde est verifie AU MOMENT DE L'ENGAGEMENT.
        //
        // Il ne l'etait qu'a la proposition, et rien n'est reserve entre les
        // deux (les points ne bougent qu'a la fin de la prestation). Tant
        // qu'une seule proposition pouvait exister, cela ne pouvait pas nuire.
        // Avec plusieurs, trois offres de 20 points a quelqu'un qui en a 25
        // passeraient toutes, et accepter la deuxieme mettrait son compte a
        // decouvert. L'acceptation est le moment ou l'engagement devient reel.
        if ($transaction->buyer->points_balance < $transaction->points_proposed) {
            return back()->with('error', __('requests.lifecycle.insufficient_balance'));
        }

        // TASK-1756 §6.3 — l'atelier est-il COMPLET ?
        //
        // ## C'est ICI que la borne tient, pas a `store()`
        //
        // Une place est prise par un echange ACCEPTE, pas par une proposition
        // en attente — sinon trois personnes bloqueraient un atelier de trois
        // sans que l'aidant ait dit oui a quiconque.
        //
        // Consequence : deux propositions en attente pour UNE place restante
        // sont toutes deux legitimes, et la garde de `store()` les laisse
        // passer A RAISON. C'est la seconde acceptation qui doit etre refusee.
        // Une garde posee uniquement a la proposition laisserait donc
        // sur-reserver, en silence.
        $annonce = $transaction->service;

        if ($annonce !== null && Atelier::estComplet($annonce)) {
            return back()->with('error', __('entraide.atelier.complet'));
        }

        // TASK-1756 §6.3 — sur un ATELIER, la part est calculee et FIGEE ici.
        //
        // ## Ce que « figee » achete, et ce qu'elle coute
        //
        // Le prix affiche a l'inscription est le prix paye : c'est la promesse
        // de l'option C, et la tenir vaut qu'on ne rebouge rien ensuite. Le CDC
        // le recommande aussi (§6.3, points durs) — faire varier la part des
        // deja-inscrits a chaque nouvelle arrivee rendrait un engagement
        // instable.
        //
        // Consequence assumee : sur 45 min avec un plancher de 3, les trois
        // premiers paient 15, et un quatrieme ne paie que 12. L'aidant recoit
        // donc 57 et non 45. L'ecart va TOUJOURS dans ce sens — jamais l'aidant
        // ne touche moins que son annonce — et il est le prix d'un engagement
        // qui ne bouge pas sous les pieds de celui qui l'a pris.
        //
        // `placesPrises()` compte les deja ACCEPTES ; ce participant-ci n'en
        // fait pas encore partie, d'ou le `+ 1`.
        $partConvenue = $annonce !== null && Atelier::estGroupe($annonce)
            ? Atelier::part($annonce, Atelier::placesPrises($annonce) + 1)
            : $transaction->points_proposed;

        $transaction->update([
            'status' => 'accepted',
            'points_agreed' => $partConvenue,
        ]);

        MessageSysteme::ecrire($transaction, 'echange_accepte');

        // La demande est pourvue, et les propositions concurrentes closes.
        app(ServiceRequestLifecycle::class)->markHandled($transaction);

        rescue(
            fn () => $transaction->buyer->notify(new TransactionStatusChanged($transaction->fresh())),
            fn (\Throwable $e) => Log::error('Email notification failed after successful business action', [
                'event' => 'transaction_approved',
                'transaction_id' => $transaction->id,
                'organization_id' => $transaction->organization_id,
                'notifiable_id' => $transaction->buyer->id,
                'exception_class' => $e::class,
            ]),
            false,
        );

        return $this->redirectToMessage($transaction)->with('success', __('entraide.flash.echange_accepte'));
    }

    public function orgApprove(string $org, Transaction $transaction): RedirectResponse
    {
        return $this->approve($transaction);
    }

    public function refuse(Transaction $transaction): RedirectResponse
    {
        $this->authorize('refuse', $transaction);

        $transaction->update(['status' => 'refused']);

        MessageSysteme::ecrire($transaction, 'echange_decline');

        rescue(
            fn () => $transaction->buyer->notify(new TransactionStatusChanged($transaction->fresh())),
            fn (\Throwable $e) => Log::error('Email notification failed after successful business action', [
                'event' => 'transaction_refused',
                'transaction_id' => $transaction->id,
                'organization_id' => $transaction->organization_id,
                'notifiable_id' => $transaction->buyer->id,
                'exception_class' => $e::class,
            ]),
            false,
        );

        // TASK-1693 bug W — ne rouvrir que si AUCUNE autre proposition ne
        // tient la demande. Sans cette condition, relacher une concurrente
        // rouvrirait une demande deja pourvue — sans danger tant qu'une seule
        // proposition pouvait exister, plus du tout aujourd'hui.
        app(ServiceRequestLifecycle::class)->releaseIfNothingHolds($transaction);

        return $this->redirectToMessage($transaction)->with('success', __('entraide.flash.echange_decline'));
    }

    public function orgRefuse(string $org, Transaction $transaction): RedirectResponse
    {
        return $this->refuse($transaction);
    }

    public function adjust(Request $request, Transaction $transaction): RedirectResponse
    {
        $this->authorize('adjust', $transaction);

        $data = $request->validate([
            // TASK-1754 §9.3 — on doit pouvoir OFFRIR en cours de route, pas
            // seulement a l'ouverture.
            'points_proposed' => 'required|integer|min:0',
        ]);

        if ($transaction->status !== 'pending') {
            return back()->with('error', __('entraide.flash.ajustement_impossible'));
        }

        // TASK-1756 — on n'ajuste pas sa part d'atelier.
        //
        // `approve()` recalcule de toute facon `points_agreed`, donc un
        // ajustement n'aurait rien preleve de different : il aurait seulement
        // ecrit au fil « le temps passe a 5 minutes » avant d'en prelever 15.
        // Un message systeme qui annonce un chiffre que le produit ne tiendra
        // pas est un defaut a lui seul.
        $annonce = $transaction->service;

        if ($annonce !== null && ! Atelier::tempsNegociable($annonce)) {
            return back()->with('error', __('entraide.atelier.temps_non_negociable'));
        }

        $transaction->update(['points_proposed' => $data['points_proposed']]);

        MessageSysteme::ecrire($transaction, 'temps_ajuste', ['points' => (int) $data['points_proposed']]);

        return $this->redirectToMessage($transaction)->with('success', __('entraide.flash.temps_ajuste'));
    }

    public function orgAdjust(Request $request, string $org, Transaction $transaction): RedirectResponse
    {
        return $this->adjust($request, $transaction);
    }

    public function cancel(Transaction $transaction): RedirectResponse
    {
        $this->authorize('cancel', $transaction);

        $transaction->update(['status' => 'cancelled']);

        MessageSysteme::ecrire($transaction, 'echange_annule');

        // TASK-1693 bug W — ne rouvrir que si AUCUNE autre proposition ne
        // tient la demande. Sans cette condition, relacher une concurrente
        // rouvrirait une demande deja pourvue — sans danger tant qu'une seule
        // proposition pouvait exister, plus du tout aujourd'hui.
        app(ServiceRequestLifecycle::class)->releaseIfNothingHolds($transaction);

        return $this->redirectToMessage($transaction)->with('success', __('entraide.flash.echange_annule'));
    }

    public function orgCancel(string $org, Transaction $transaction): RedirectResponse
    {
        return $this->cancel($transaction);
    }

    /**
     * TASK-1806 (arbitrage du 11/11) — LA 3e ET DERNIERE ETAPE : l'aide
     * confirme, et tout se termine dans ce seul geste.
     *
     * ## Ce que cette methode a absorbe
     *
     * Le parcours comptait deux confirmations : l'aide declarait l'aide recue
     * (`buyer_done`), puis l'AIDANT confirmait a son tour pour declencher le
     * transfert de points. Cette seconde confirmation disparait du parcours
     * visible : l'aide confirme, les points partent, l'evaluation se prend au
     * meme endroit. Trois etapes, pas cinq, et aucune quatrieme cachee.
     *
     * `confirm()` plus bas reste en place pour denouer une eventuelle ligne
     * heritee en `buyer_done` — statut que plus aucun code ne produit (zero
     * ligne mesuree sur le jumeau le 11/11).
     *
     * ## L'atomicite porte sur la CLOTURE ET LES POINTS, pas sur le reste
     *
     * Le verrou `lockForUpdate()` + la garde de statut tiennent ensemble, dans
     * UNE transaction : le passage a `completed`, les deux lignes de ledger,
     * les deux soldes et la cloture de la demande. Un second envoi ne peut
     * donc pas transferer deux fois — il ressort sur `deja_traite`.
     *
     * Ce qui vient APRES (message systeme, evaluation, notifications, Flux)
     * vit hors de la transaction, sous `rescue()` : l'entraide EST close et
     * les points SONT transferes a ce stade. Faire echouer la requete — ou
     * pire, annuler le transfert — parce qu'un courriel ou un avis a bronche
     * serait le mauvais arbitrage. Meme doctrine que les six autres points de
     * bascule de ce controleur.
     */
    public function complete(Request $request, Transaction $transaction): RedirectResponse
    {
        $this->authorize('complete', $transaction);

        $donnees = $request->validate([
            // L'evaluation est FACULTATIVE : « ou ignorer l'evaluation ».
            // Absente, aucune ligne `reviews` n'est ecrite et le renoncement
            // est trace (`reviews_skipped_at`) — jamais une note inventee.
            'rating' => 'nullable|integer|min:1|max:5',
            'comment' => 'nullable|string|max:1000',
        ]);

        DB::transaction(function () use ($transaction) {
            $tx = Transaction::where('id', $transaction->id)->lockForUpdate()->first();

            if (! in_array($tx->status, ['accepted', 'buyer_done'], true)) {
                return;
            }

            $points = (int) $tx->points_agreed;

            // TASK-1754 §9.3 — un coup de main OFFERT ne touche NI le ledger NI
            // les soldes : deux lignes a zero seraient du bruit comptable dans
            // la piece justificative des soldes. Le reste de la choregraphie
            // est inchange, un don traverse tout le cycle sans rien transferer.
            if ($points > 0) {
                PointLedger::create([
                    'user_id' => $tx->buyer_id,
                    'transaction_id' => $tx->id,
                    'delta' => -$points,
                    'organization_id' => $tx->organization_id,
                    'reason' => 'exchange_spent',
                ]);

                PointLedger::create([
                    'user_id' => $tx->seller_id,
                    'transaction_id' => $tx->id,
                    'delta' => $points,
                    'organization_id' => $tx->organization_id,
                    'reason' => 'exchange_earned',
                ]);

                $tx->buyer()->update(['points_balance' => DB::raw('points_balance - '.$points)]);
                $tx->seller()->update(['points_balance' => DB::raw('points_balance + '.$points)]);
            }

            // `seller_confirmed_at` n'est PLUS renseigne : la colonne
            // enregistrait un geste de l'aidant qui n'existe plus. Elle reste
            // en base pour l'historique (arbitrage MASTER du 11/11).
            $tx->update([
                'status' => 'completed',
                'buyer_confirmed_at' => $tx->buyer_confirmed_at ?? now(),
                'completed_at' => now(),
            ]);

            app(ServiceRequestLifecycle::class)->close($tx);
        });

        $fresh = $transaction->fresh();

        if ($fresh->status !== 'completed') {
            return $this->redirectToMessage($transaction)
                ->with('error', __('entraide.flash.deja_traite'));
        }

        MessageSysteme::ecrire($transaction, 'echange_termine', ['points' => (int) ($transaction->points_agreed ?? $transaction->points_proposed)]);

        // L'avis se prend dans le MEME ecran que la confirmation. Hors
        // transaction et sous `rescue()` : perdre un mot de remerciement est
        // regrettable, annuler un transfert de points deja valide le serait
        // bien davantage.
        //
        // MAIS on retient si l'ecriture a abouti (verification demandee par
        // MASTER le 11/11) : annoncer « merci ! » alors que l'avis n'est pas
        // parti serait une FAUSSE confirmation. Trois proprietes tenues :
        //
        // 1. l'echec est IDENTIFIABLE — journal structure, meme forme que les
        //    six autres points de bascule de ce controleur ;
        // 2. le message dit la verite — l'entraide est close, l'avis non ;
        // 3. l'avis reste RECUPERABLE sans second transfert : rien n'a ete
        //    ecrit, donc `evaluationEnAttente()` reste vrai, la carte
        //    d'evaluation revient, et `ReviewController` ne touche jamais aux
        //    points.
        $avisDemande = ($donnees['rating'] ?? null) !== null;

        // TASK-1808 (clarification MASTER) — cloturer SANS note n'est PLUS un
        // renoncement. T1806 appelait `enregistrer(null)` ici, ce qui ecrivait
        // `reviews_skipped_at` : l'ecran des etoiles, qui vient APRES la
        // cloture dans le nouveau parcours, ne serait alors jamais apparu.
        // L'evaluation n'a simplement pas encore ete proposee — on n'ecrit
        // RIEN. Le renoncement explicite reste possible, par `reviews.skip`.
        // Le test de recette `TASK1808ParcoursFinal...` epingle precisement
        // cette ligne : une cloture nue laisse `reviews_skipped_at` a NULL.
        $avisEnregistre = ! $avisDemande || rescue(
            function () use ($fresh, $donnees) {
                // Enferme dans sa PROPRE transaction, et ce n'est pas une
                // precaution de confort : sous PostgreSQL, une requete en
                // echec AVORTE la transaction en cours et la connexion refuse
                // tout ordre suivant (`SQLSTATE[25P02]`) — SQLite, lui, le
                // tolere. Si cette action tourne un jour dans une transaction
                // englobante, un avis perdu emporterait avec lui les
                // notifications et le partage dans le Flux qui le suivent.
                // Imbriquee, `DB::transaction()` pose un POINT DE SAUVEGARDE :
                // le retour arriere rend la connexion utilisable et seule
                // l'ecriture de l'avis est perdue. Mesure a l'appui : sans
                // cela, la CI PostgreSQL rougit la ou SQLite passait.
                DB::transaction(function () use ($fresh, $donnees) {
                    EvaluationEntraide::enregistrer(
                        $fresh,
                        $donnees['rating'] ?? null,
                        $donnees['comment'] ?? null,
                    );
                });

                return true;
            },
            function (\Throwable $e) use ($fresh) {
                Log::error('Evaluation could not be recorded after a successful closure', [
                    'event' => 'entraide_evaluation_not_recorded',
                    'transaction_id' => $fresh->id,
                    'organization_id' => $fresh->organization_id,
                    'reviewer_id' => $fresh->buyer_id,
                    'exception_class' => $e::class,
                ]);

                return false;
            },
            false,
        );

        rescue(
            fn () => $fresh->buyer->notify(new TransactionStatusChanged($fresh)),
            fn (\Throwable $e) => Log::error('Email notification failed after successful business action', [
                'event' => 'entraide_confirmed_buyer_notification',
                'transaction_id' => $fresh->id,
                'organization_id' => $fresh->organization_id,
                'notifiable_id' => $fresh->buyer->id,
                'exception_class' => $e::class,
            ]),
            false,
        );
        rescue(
            fn () => $fresh->seller->notify(new TransactionStatusChanged($fresh)),
            fn (\Throwable $e) => Log::error('Email notification failed after successful business action', [
                'event' => 'entraide_confirmed_seller_notification',
                'transaction_id' => $fresh->id,
                'organization_id' => $fresh->organization_id,
                'notifiable_id' => $fresh->seller->id,
                'exception_class' => $e::class,
            ]),
            false,
        );

        // TASK-1809 §3 — l'email d'invitation a evaluer, desormais
        // IMMEDIAT (MASTER a retire la relance planifiee a dix minutes :
        // « BouclePro lutte contre l'infobesite », un seul email, jamais
        // deux). Idempotent par une mise a jour conditionnelle ATOMIQUE
        // (`whereNull`, 0 ou 1 ligne touchee) — meme garde que l'ancienne
        // commande `entraide:invite-to-evaluate`, deplacee ici puisque le
        // declencheur est desormais synchrone a la cloture et non plus un
        // balayage planifie. Une discussion directe n'a rien a evaluer.
        // `rescue()` : un echec d'envoi ne doit jamais faire echouer la
        // cloture, deja validee et irreversible a ce stade.
        if (! $fresh->isDirectConversation()) {
            rescue(
                function () use ($fresh) {
                    $aEnvoyer = Transaction::whereKey($fresh->id)->whereNull('evaluation_invited_at')->exists();

                    if (! $aEnvoyer) {
                        return;
                    }

                    if (app(EvaluationInviteMailer::class)->envoyer($fresh)) {
                        Transaction::whereKey($fresh->id)->whereNull('evaluation_invited_at')->update(['evaluation_invited_at' => now()]);
                    }
                },
                fn (\Throwable $e) => Log::error('Evaluation invitation email failed after successful closure', [
                    'event' => 'entraide_evaluation_invite_not_sent',
                    'transaction_id' => $fresh->id,
                    'organization_id' => $fresh->organization_id,
                    'exception_class' => $e::class,
                ]),
                false,
            );
        }

        $this->partagerDansLeFlux($request, $fresh);

        // `error` et non `warning` : le layout ne rend QUE `success`, `error`
        // et `info` (`layouts/app.blade.php`) — un `warning` serait un message
        // invisible, exactement le no-op silencieux qu'on veut eviter ici. Et
        // `info` se lit comme une remarque : celui-ci appelle un geste.
        if ($avisDemande && ! $avisEnregistre) {
            return $this->redirectToMessage($transaction)
                ->with('error', __('entraide.flash.echange_termine_avis_non_enregistre'));
        }

        return $this->redirectToMessage($transaction)->with('success', __('entraide.flash.echange_termine'));
    }

    public function orgComplete(Request $request, string $org, Transaction $transaction): RedirectResponse
    {
        return $this->complete($request, $transaction);
    }

    /**
     * CHEMIN HERITE (TASK-1806) — la confirmation de l'aidant a quitte le
     * parcours visible : l'aide confirme seul, et `complete()` fait tout.
     *
     * Conservee pour une seule raison : denouer une ligne restee en
     * `buyer_done`, statut que plus aucun code ne produit (zero ligne mesuree
     * sur le jumeau le 11/11). Aucune surface ne propose ce geste —
     * `CarteEntraide::actions()` ne l'emet plus.
     */
    public function confirm(Request $request, Transaction $transaction): RedirectResponse
    {
        $this->authorize('confirm', $transaction);

        DB::transaction(function () use ($transaction) {
            $tx = Transaction::where('id', $transaction->id)->lockForUpdate()->first();

            if ($tx->status !== 'buyer_done') {
                return;
            }

            $points = (int) $tx->points_agreed;

            // TASK-1754 §9.3 — un coup de main OFFERT ne touche NI le ledger NI
            // les soldes.
            //
            // Deux lignes a zero seraient du bruit comptable : elles
            // alourdiraient l'historique de points de chaque membre sans rien
            // dire, et le ledger est la piece justificative des soldes — y
            // ecrire des mouvements nuls le rend plus difficile a auditer, pas
            // plus complet.
            //
            // Le reste de la choregraphie est INCHANGE : l'echange passe bien a
            // `completed`, la demande se clot, le remerciement est propose. Un
            // don traverse donc tout le cycle, il ne transfere simplement rien.
            if ($points > 0) {
                PointLedger::create([
                    'user_id' => $tx->buyer_id,
                    'transaction_id' => $tx->id,
                    'delta' => -$points,
                    'organization_id' => $tx->organization_id,
                    'reason' => 'exchange_spent',
                ]);

                PointLedger::create([
                    'user_id' => $tx->seller_id,
                    'transaction_id' => $tx->id,
                    'delta' => $points,
                    'organization_id' => $tx->organization_id,
                    'reason' => 'exchange_earned',
                ]);

                $tx->buyer()->update(['points_balance' => DB::raw('points_balance - '.$points)]);
                $tx->seller()->update(['points_balance' => DB::raw('points_balance + '.$points)]);
            }

            $tx->update([
                'status' => 'completed',
                'seller_confirmed_at' => now(),
                'completed_at' => now(),
            ]);

            app(ServiceRequestLifecycle::class)->close($tx);
        });

        $fresh = $transaction->fresh();

        if ($fresh->status !== 'completed') {
            return $this->redirectToMessage($transaction)
                ->with('error', __('entraide.flash.deja_traite'));
        }

        MessageSysteme::ecrire($transaction, 'echange_termine', ['points' => (int) ($transaction->points_agreed ?? $transaction->points_proposed)]);

        rescue(
            fn () => $fresh->buyer->notify(new TransactionStatusChanged($fresh)),
            fn (\Throwable $e) => Log::error('Email notification failed after successful business action', [
                'event' => 'transaction_confirmed_buyer_notification',
                'transaction_id' => $fresh->id,
                'organization_id' => $fresh->organization_id,
                'notifiable_id' => $fresh->buyer->id,
                'exception_class' => $e::class,
            ]),
            false,
        );
        rescue(
            fn () => $fresh->seller->notify(new TransactionStatusChanged($fresh)),
            fn (\Throwable $e) => Log::error('Email notification failed after successful business action', [
                'event' => 'transaction_confirmed_seller_notification',
                'transaction_id' => $fresh->id,
                'organization_id' => $fresh->organization_id,
                'notifiable_id' => $fresh->seller->id,
                'exception_class' => $e::class,
            ]),
            false,
        );

        $this->partagerDansLeFlux($request, $fresh);

        return $this->redirectToMessage($transaction)->with('success', __('entraide.flash.echange_termine'));
    }

    /**
     * TASK-1757 (§9.5 du CDC entraide) — l'entraide entre dans le Flux, si on
     * le demande.
     *
     * ## L'opt-in se prend ICI, pas dans un reglage
     *
     * Arbitrage Cyril du 05/11. Le §9.5 dit « opt-in » et aucun stockage
     * n'existait pour ca : T1752 n'offre qu'un interrupteur d'Organization
     * tout-ou-rien. Les trois autres voies demandaient une colonne nouvelle, et
     * un reglage a OFF enterre dans une page de reglages est une
     * fonctionnalite invisible — le Flux serait reste vide.
     *
     * La case vit donc dans le formulaire de confirmation, la ou la personne
     * decide. **Aucune colonne, aucune migration** : si la case n'est pas
     * cochee, aucune ligne n'est ecrite, et il n'y a rien a retenir.
     *
     * ## Seul l'AIDANT est nomme — et depuis T1806, c'est l'AIDE qui coche
     *
     * La carte dit « 🤝 Ottoline a donne 30 min — Bricolage », jamais « a
     * Barnabe » : le beneficiaire n'est jamais expose, et cela n'a pas change.
     *
     * Ce qui a change avec le parcours a trois etapes (TASK-1806) : la case
     * vit dans l'ecran de CONFIRMATION, et la confirmation est desormais le
     * geste de l'AIDE. Celui qui coche n'est donc plus celui qui est nomme —
     * l'invariant pose par T1757 s'inverse.
     *
     * ARBITRAGE MASTER du 11/11, explicite : « L'aide peut decider de partager
     * l'entraide dans le Flux, sans demander une autorisation supplementaire a
     * l'aidant. Il ne faut pas multiplier les validations entre membres. »
     * Motif retenu : BouclePro repose sur des communautes professionnelles de
     * confiance, et l'avis public du profil fonctionne DEJA ainsi — l'aide
     * ecrit, l'aidant est nomme publiquement. La doctrine communautaire sera
     * rendue explicite pour les membres.
     *
     * ## `rescue()`, comme les six autres points de bascule
     *
     * L'echange EST clos a ce stade, les points sont transferes. La personne ne
     * doit pas voir une erreur pour une entraide pourtant terminee parce que le
     * Flux a eu un probleme.
     */
    /**
     * TASK-1809 (correctif Cyril, 12/11) — le partage dans le Flux n'est
     * PLUS une action distincte (`shareInFeed()`/`orgShareInFeed()`,
     * supprimees avec leurs deux routes et `TransactionPolicy::
     * shareInFeed()`) : le lien explicite menait a une erreur 500 et
     * demandait un clic de plus pour un geste qui devrait etre acquis. Le
     * partage est desormais AUTOMATIQUE, declenche par
     * `EvaluationEntraide::enregistrer()` des qu'un avis est reellement
     * depose — meme emetteur (`FeedEventEmitter::emettre()`, idempotent),
     * meme acteur (l'AIDANT), seul le declencheur change.
     */
    private function partagerDansLeFlux(Request $request, Transaction $echange): void
    {
        if (! $request->boolean('partager_dans_le_flux')) {
            return;
        }

        // Une discussion directe n'a rien a raconter : ni annonce, ni temps.
        if ($echange->isDirectConversation()) {
            return;
        }

        rescue(fn () => app(FeedEventEmitter::class)->emettre(
            FeedEventCatalogue::ENTRAIDE_TERMINEE,
            $echange,
            // L'acteur est l'AIDANT — et celui que la carte nomme.
            //
            // TASK-1808 : ce commentaire disait « celui qui vient de
            // confirmer ». C'est FAUX depuis T1806 : celui qui confirme est
            // l'aide. Le choix d'acteur, lui, ne change pas — le Flux celebre
            // celui qui a aide, et le nom de l'aide n'y apparait jamais.
            $echange->seller,
            $echange->organization_id,
        ), report: true);
    }

    public function orgConfirm(Request $request, string $org, Transaction $transaction): RedirectResponse
    {
        return $this->confirm($request, $transaction);
    }

    public function contest(Transaction $transaction): RedirectResponse
    {
        $this->authorize('contest', $transaction);

        DB::transaction(function () use ($transaction) {
            $tx = Transaction::where('id', $transaction->id)->lockForUpdate()->first();

            if ($tx->status !== 'buyer_done') {
                return;
            }

            $tx->update(['status' => 'accepted']);
        });

        $fresh = $transaction->fresh();

        if ($fresh->status !== 'accepted') {
            return $this->redirectToMessage($transaction)
                ->with('error', __('entraide.flash.contestation_impossible'));
        }

        MessageSysteme::ecrire($transaction, 'pas_encore_termine');

        return $this->redirectToMessage($transaction)->with('success', __('entraide.flash.pas_encore_termine'));
    }

    public function orgContest(string $org, Transaction $transaction): RedirectResponse
    {
        return $this->contest($transaction);
    }

    public function exportCsv(): StreamedResponse
    {
        $user = auth()->user();

        $transactions = Transaction::where('buyer_id', $user->id)
            ->orWhere('seller_id', $user->id)
            ->with(['service', 'serviceRequest', 'buyer', 'seller'])
            ->orderByDesc('created_at')
            ->get()
            // TASK-1754 (§5.4 du CDC, critere d'acceptation n°7) — les
            // DISCUSSIONS directes sortent de l'export.
            //
            // « Ecrire a quelqu'un » cree une Transaction a 0 point
            // (`MessageController::showWithUser()`). Ces pseudo-echanges
            // sortaient dans le CSV en « Achat, 0 pts, En attente » —
            // eternellement, puisque rien ne les termine jamais. Un membre
            // lisait donc son historique d'entraide pollue par ses simples
            // conversations.
            //
            // Le filtre est pose ICI et non dans la requete : `isDirectConversation()`
            // est la definition CANONIQUE (ni service, ni demande, ET 0 point),
            // et la re-deriver en SQL garantirait la divergence le jour ou elle
            // change.
            ->reject(fn (Transaction $t) => $t->isDirectConversation());

        $filename = __('entraide.csv.fichier').'-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($transactions, $user) {
            $handle = fopen('php://output', 'w');
            fwrite($handle, "\xEF\xBB\xBF"); // BOM UTF-8 pour Excel
            fputcsv($handle, (array) __('entraide.csv.entetes'), ';');

            foreach ($transactions as $transaction) {
                // `buyer` = celui qui RECOIT l'aide, `seller` = celui qui
                // l'APPORTE. Les colonnes gardent leurs noms de marketplace en
                // base ; l'export, lui, parle d'entraide.
                $recoitLAide = $transaction->buyer_id === $user->id;
                $avec = $recoitLAide ? $transaction->seller->name : $transaction->buyer->name;
                $points = $transaction->points_agreed ?? $transaction->points_proposed;

                fputcsv($handle, [
                    $transaction->created_at->format('d/m/Y'),
                    $recoitLAide ? __('entraide.csv.aide_recue') : __('entraide.csv.aide_apportee'),
                    $transaction->subject,
                    $avec,
                    TempsPartage::duree($points),
                    $points,
                    $transaction->status_label,
                ], ';');
            }

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function redirectToMessage(Transaction $transaction): RedirectResponse
    {
        $organization = request()->route('organization');

        if ($organization) {
            return redirect()->route('organization.messages.show', ['organization' => $organization, 'transaction' => $transaction]);
        }

        return redirect()->route('messages.show', $transaction);
    }
}
