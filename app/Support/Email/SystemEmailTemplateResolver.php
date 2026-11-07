<?php

namespace App\Support\Email;

use App\Models\Organization;
use App\Models\SystemEmailTemplate;
use App\Models\User;
use App\Support\ScopedReadCache;

/**
 * TASK-1694 bug M — l'autorité UNIQUE qui choisit un gabarit d'email système.
 *
 * ## Pourquoi cette classe existe
 *
 * Neuf endroits du code résolvaient un gabarit, chacun à sa façon. Mesure du
 * 23/10 : **sept d'entre eux ne regardaient que les lignes du tenant**. Une
 * ligne « Global » leur était donc **invisible** — l'administration en
 * affichait que le produit ne lisait jamais.
 *
 * C'est le même défaut que le bug G, constaté en grand : il y avait été corrigé
 * dans *un* fichier. Le corriger sept fois de plus aurait créé sept chemins à
 * maintenir ; il n'y en a plus qu'un.
 *
 * ## L'ordre, et ce que chaque cran veut dire
 *
 *   1. le tenant, dans la langue demandée        — « cette Organization a écrit ceci »
 *   2. le tenant, dans SA langue par défaut      — « …mais pas dans cette langue-là »
 *   3. le Global, dans la langue demandée        — « la plateforme a écrit ceci »
 *   4. le Global, dans sa langue par défaut
 *   5. le Global sans langue                     — héritage : des lignes portent `locale = NULL`
 *   6. `null` → l'appelant retombe sur son gabarit Blade
 *
 * Les crans 1-2 passent AVANT les crans 3-5 : une Organization qui a
 * personnalisé son email garde sa version, le Global ne la recouvre jamais.
 * C'est l'invariant que le bug G avait établi, et il ne change pas ici — même
 * quand la permission d'éditer sera retirée aux Organizations (bug P), les
 * lignes déjà écrites continueront d'être servies tant qu'elles existent.
 *
 * ## Un gabarit « Global » n'est jamais le gabarit d'une AUTRE Organization
 *
 * Défaut trouvé en lisant les sept copies : pour une personne **sans**
 * Organization, elles faisaient `where('slug')->enabled()->first()` — sans
 * filtre de tenant. La première ligne venue gagnait, c'est-à-dire le gabarit
 * **d'une Organization quelconque**, celle que l'ordre des lignes désignait.
 * Ici, l'absence de tenant impose `whereNull('organization_id')` : seul le
 * Global répond.
 *
 * ## Désactivé veut dire absent
 *
 * `enabled = true` borne tous les crans. Une surcharge désactivée ne masque
 * donc pas le cran suivant — c'est ce qui rend sûre la création « désactivée
 * par défaut » de l'écran d'administration (TASK-1681).
 */
class SystemEmailTemplateResolver
{
    public const FALLBACK_LOCALE = 'fr';

    /**
     * @param  string|null  $organizationId  le tenant du destinataire, s'il en a un
     * @param  string|null  $locale  la langue voulue ; `null` → la langue courante
     * @param  string|null  $defaultLocale  la langue de repli ; `null` → celle de
     *                                      l'Organization, sinon `fr`
     */
    public function resolve(
        string $slug,
        ?string $organizationId = null,
        ?string $locale = null,
        ?string $defaultLocale = null,
    ): ?SystemEmailTemplate {
        // TASK-1778 — le GABARIT n'est volontairement PAS memorise.
        //
        // Il l'a ete, et la mesure a montre le gain : les cinq crans sont
        // parcourus a chaque appel et le gabarit trouve est souvent le dernier
        // (le Global sans langue), soit 4 requetes par destinataire pour
        // rendre N fois LA MEME ligne.
        //
        // Deux tests l'ont pourtant fait rougir, et ils avaient raison :
        // `TASK1751...::test_le_repli_rend_un_contenu_quand_le_gabarit_est_retire`
        // et `TASK1772...::test_un_gabarit_desactive_retombe_sur_le_repli_de_langue`
        // retirent le gabarit PUIS resolvent, dans la meme requete. Une
        // memoire de lecture leur rendait la ligne d'avant.
        //
        // La vraie raison de renoncer n'est pas qu'ils rougissaient — c'est ce
        // qu'ils ont revele : un gabarit est du CONTENU ADMINISTRABLE, fait
        // pour etre modifie, et l'ecran d'administration le re-rend dans la
        // requete meme ou il l'enregistre. Pire, un `Builder::update()` ou
        // `Builder::delete()` en masse ne declenche AUCUN evenement de
        // modele : aucun observateur ne pourrait vider la memoire de facon
        // fiable. La justesse aurait dependu de la MANIERE dont l'ecriture a
        // ete faite, ce qui est exactement le genre de defaut qui ne se voit
        // qu'en production.
        //
        // La langue de l'Organization, elle, reste memorisee (voir
        // `candidats()`) : ce n'est pas du contenu administrable rendu dans la
        // meme requete, et ce n'est pas la meme prise de risque.
        foreach ($this->candidats($slug, $organizationId, $locale, $defaultLocale, true) as $candidat) {
            $template = $candidat->first();

            if ($template) {
                return $template;
            }
        }

        return null;
    }

    /**
     * POURQUOI aucun gabarit n'a répondu — pour le journal, pas pour décider.
     *
     * Les appelants qui retombent sur leur Blade journalisent la raison. La
     * distinction compte : `template_missing` dit « personne n'a jamais écrit
     * ce gabarit », `template_disabled` dit « il existe, quelqu'un l'a
     * décoché ». Deux causes, deux gestes de réparation opposés.
     *
     * Ce diagnostic réemploie **la même liste de candidats** que `resolve()`,
     * sans la borne `enabled` : une question posée sur d'autres crans que ceux
     * réellement consultés produirait un journal faux.
     */
    public function diagnoseAbsence(
        string $slug,
        ?string $organizationId = null,
        ?string $locale = null,
        ?string $defaultLocale = null,
    ): string {
        foreach ($this->candidats($slug, $organizationId, $locale, $defaultLocale, false) as $candidat) {
            if ($candidat->exists()) {
                return 'template_disabled';
            }
        }

        return 'template_missing';
    }

    /**
     * Les crans, dans l'ordre. Une seule liste, deux lecteurs.
     *
     * @return array<int, \Illuminate\Database\Eloquent\Builder>
     */
    private function candidats(
        string $slug,
        ?string $organizationId,
        ?string $locale,
        ?string $defaultLocale,
        bool $enabledOnly,
    ): array {
        $locale ??= app()->getLocale();

        // TASK-1778 — la langue de l'Organization est une donnee de reference :
        // identique pour les 100 destinataires d'un meme evenement, et relue
        // pour chacun avant cette TASK.
        $defaultLocale ??= $organizationId
            ? (app(ScopedReadCache::class)->souviens(
                'organization-locale:'.$organizationId,
                fn () => Organization::query()->whereKey($organizationId)->value('locale'),
            ) ?: self::FALLBACK_LOCALE)
            : self::FALLBACK_LOCALE;

        $base = SystemEmailTemplate::query()->where('slug', $slug);

        if ($enabledOnly) {
            $base->where('enabled', true);
        }

        $candidats = [];

        if ($organizationId) {
            $candidats[] = (clone $base)
                ->where('organization_id', $organizationId)
                ->where('locale', $locale);

            if ($locale !== $defaultLocale) {
                $candidats[] = (clone $base)
                    ->where('organization_id', $organizationId)
                    ->where('locale', $defaultLocale);
            }
        }

        $candidats[] = (clone $base)->whereNull('organization_id')->where('locale', $locale);

        if ($locale !== $defaultLocale) {
            $candidats[] = (clone $base)->whereNull('organization_id')->where('locale', $defaultLocale);
        }

        $candidats[] = (clone $base)->whereNull('organization_id')->whereNull('locale');

        return $candidats;
    }

    /**
     * Le cas courant : résoudre pour un destinataire.
     *
     * Accepte n'importe quel notifiable — y compris un `AnonymousNotifiable`,
     * qui n'a ni `organization_id` ni `preferred_locale`. Lire ces propriétés
     * sans précaution y lève une erreur : mesuré en bug G, sur ce même objet.
     *
     * ## Sans `User`, aucun gabarit administrable — et c'est une REGLE, pas une
     * précaution
     *
     * Un gabarit administrable n'est qu'un texte à trous : il n'a de sens
     * qu'interpolé avec les variables d'une personne, et
     * `EmailerService::availableVariables()` exige un `User`. Rendre un gabarit
     * pour un destinataire qui n'en est pas un revient à promettre une
     * interpolation impossible.
     *
     * Le défaut était **latent** avant le bug M : les sept copies faisaient
     * `where('slug')->enabled()->first()` et auraient trouvé une ligne, elles
     * aussi. Il n'éclatait pas parce qu'aucune ligne n'existait dans ce cas de
     * figure. Les 18 lignes globales du bug Q l'ont rendu atteignable, et la
     * suite l'a attrapé : `TypeError` sur `AnonymousNotifiable`.
     *
     * Fermé ici, dans l'autorité unique, plutôt que cinq fois dans les
     * notifications. Le `null` renvoyé fait retomber chacune sur son repli
     * Blade, qui sait déjà se passer d'un `User` (bug G).
     */
    public function resolveForNotifiable(string $slug, object $notifiable): ?SystemEmailTemplate
    {
        if (! $notifiable instanceof User) {
            return null;
        }

        return $this->resolve(
            $slug,
            $notifiable->organization_id,
            $notifiable->preferred_locale,
        );
    }
}
