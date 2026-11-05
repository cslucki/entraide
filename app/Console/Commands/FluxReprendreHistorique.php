<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Support\Feed\FeedHistoryReplay;
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

        $reprise = app(FeedHistoryReplay::class);

        if ($this->option('annuler')) {
            $n = $reprise->dejaPosees($organization);

            if ($n === 0) {
                $this->info('Aucune ligne d\'evenement a retirer.');

                return self::SUCCESS;
            }

            if (! $this->confirm("Retirer {$n} ligne(s) d'evenement de « {$organization->name} » ? Leurs commentaires seront effaces avec elles.")) {
                $this->line('Annule.');

                return self::SUCCESS;
            }

            $this->info($reprise->retirer($organization).' ligne(s) retiree(s).');

            return self::SUCCESS;
        }

        $presentations = (string) $this->option('presentations');

        if (! in_array($presentations, [FeedHistoryReplay::PRESENTATIONS_DATE_DU_COMPTE, FeedHistoryReplay::PRESENTATIONS_IGNORER], true)) {
            $this->error('--presentations attend `date-du-compte` ou `ignorer`.');

            return self::FAILURE;
        }

        $sec = (bool) $this->option('dry-run');

        $this->info(($sec ? '[A BLANC] ' : '').'Organization : '.$organization->name.' ('.$organization->slug.')');
        $this->newLine();

        $comptes = $sec
            ? $reprise->compter($organization, $presentations)
            : $reprise->reprendre($organization, $presentations);

        foreach ($comptes as $clef => $n) {
            $this->line(sprintf('  %-28s %d', __('admin.feed_replay_source_'.$clef), $n));
        }

        $total = array_sum($comptes);
        $this->newLine();

        $this->info($sec
            ? "A blanc : {$total} ligne(s) seraient ajoutee(s). Relancer sans --dry-run pour ecrire."
            : "{$total} ligne(s) ajoutee(s).");

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
