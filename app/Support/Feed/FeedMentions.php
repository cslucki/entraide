<?php

namespace App\Support\Feed;

use App\Models\FeedPost;
use App\Models\LoopMember;
use App\Models\Organization;
use App\Models\Scopes\BelongsToOrganizationScope;
use App\Models\User;
use App\Support\Loops\LoopRoleRegistry;
use Illuminate\Support\Collection;

/**
 * TASK-1880 (demande Cyril, 22/11) — les mentions dans une annonce du Flux.
 *
 * Un message porte UN SEUL mode (arbitrages de Cyril) :
 *
 * - nominatif : 1 a 5 personnes, qui doivent pouvoir LIRE l'annonce ;
 * - `@tous` : les membres des Boucles CHOISIES dont l'auteur est proprietaire
 *   ou animateur (meme regle que le ChatLoop, `LoopChat::calculerPeutMentionnerTous`) ;
 * - `@membres` : tous les membres de l'Organization, reserve a son
 *   administrateur (et au superadmin) — un NOUVEAU mode.
 *
 * Nominatif + `@tous`, nominatif + `@membres`, `@tous` + `@membres` : refuses.
 *
 * Tout se deduit du TEXTE de l'annonce et de ses Boucles : la meme analyse sert
 * au formulaire (refus avant enregistrement) et au job d'envoi (destinataires),
 * qui tourne dans un worker SANS Organization courante — d'ou les lectures
 * `withoutGlobalScope` bornees a la main par `organization_id`.
 */
final class FeedMentions
{
    public const MAX_PERSONNES = 5;

    public const JETON_TOUS = '@tous';

    public const JETON_MEMBRES = '@membres';

    public const MODE_AUCUNE = 'aucune';

    public const MODE_PERSONNES = 'personnes';

    public const MODE_TOUS = 'tous';

    public const MODE_MEMBRES = 'membres';

    /**
     * Le texte brut d'un message HTML (TipTap), paragraphes conserves.
     */
    public static function texte(string $html): string
    {
        $avecSauts = preg_replace('#</(p|h2|h3|li|blockquote)>|<br\s*/?>#i', "\n", $html) ?? $html;
        $texte = html_entity_decode(strip_tags($avecSauts), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $texte = preg_replace("/[ \t]+/u", ' ', $texte) ?? $texte;
        $texte = preg_replace("/\n{3,}/", "\n\n", $texte) ?? $texte;

        return trim($texte);
    }

    /**
     * Les personnes qu'on peut nommer : celles qui LIRONT l'annonce, hors soi.
     *
     * @param  list<string>  $boucleIds
     * @return list<array{id: string, nom: string, boucles: list<string>}>
     */
    public function mentionnables(Organization $org, User $auteur, array $boucleIds, bool $audienceOrganisation): array
    {
        if ($audienceOrganisation) {
            return $this->utilisateursDeLOrganisation($org)
                ->reject(fn (User $u) => $u->id === $auteur->id)
                ->map(fn (User $u) => ['id' => (string) $u->id, 'nom' => trim($u->publicDisplayName()), 'boucles' => []])
                ->reject(fn (array $m) => $m['nom'] === '')
                ->values()
                ->all();
        }

        $adhesions = LoopMember::query()
            ->where('organization_id', $org->id)
            ->whereIn('loop_id', $boucleIds)
            ->where('status', 'active')
            ->get(['loop_id', 'user_id'])
            ->groupBy('user_id');

        if ($adhesions->isEmpty()) {
            return [];
        }

        return $this->utilisateursDeLOrganisation($org, $adhesions->keys()->all())
            ->reject(fn (User $u) => $u->id === $auteur->id)
            ->map(fn (User $u) => [
                'id' => (string) $u->id,
                'nom' => trim($u->publicDisplayName()),
                'boucles' => $adhesions->get($u->id, collect())->pluck('loop_id')->map(fn ($id) => (string) $id)->values()->all(),
            ])
            ->reject(fn (array $m) => $m['nom'] === '')
            ->values()
            ->all();
    }

    /**
     * Les Boucles (parmi celles donnees) ou l'auteur peut employer `@tous`.
     *
     * @param  list<string>  $boucleIds
     * @return list<string>
     */
    public function bouclesAnimees(Organization $org, User $auteur, array $boucleIds): array
    {
        if ($auteur->isDeactivated() || $boucleIds === []) {
            return [];
        }

        $registre = app(LoopRoleRegistry::class);

        return LoopMember::query()
            ->where('organization_id', $org->id)
            ->where('user_id', $auteur->id)
            ->whereIn('loop_id', $boucleIds)
            ->where('status', 'active')
            ->get(['loop_id', 'role'])
            ->filter(fn (LoopMember $m) => in_array($registre->canonical($m->role), [LoopRoleRegistry::OWNER, LoopRoleRegistry::FACILITATOR], true))
            ->pluck('loop_id')
            ->map(fn ($id) => (string) $id)
            ->values()
            ->all();
    }

    /**
     * L'analyse d'un message : mode, personnes, Boucles visees, ou l'erreur.
     *
     * @param  list<string>  $boucleIds  les Boucles CHOISIES pour l'annonce
     * @return array{mode: string, personnes: list<string>, boucles: list<string>, erreur: string|null}
     */
    public function analyser(string $html, Organization $org, User $auteur, array $boucleIds, bool $audienceOrganisation, bool $estAdministrateur): array
    {
        $texte = self::texte($html);
        $resultat = ['mode' => self::MODE_AUCUNE, 'personnes' => [], 'boucles' => [], 'erreur' => null];

        $tous = self::contientJeton($texte, self::JETON_TOUS);
        $membres = self::contientJeton($texte, self::JETON_MEMBRES);

        $personnes = [];

        if (str_contains($texte, '@')) {
            foreach ($this->mentionnables($org, $auteur, $boucleIds, $audienceOrganisation) as $candidat) {
                if (self::contientNom($texte, $candidat['nom'])) {
                    $personnes[] = $candidat['id'];
                }
            }
        }

        $personnes = array_values(array_unique($personnes));
        $modes = ($personnes !== [] ? 1 : 0) + ($tous ? 1 : 0) + ($membres ? 1 : 0);

        if ($modes > 1) {
            return ['erreur' => __('feed-post.mentions_one_mode')] + $resultat;
        }

        if ($membres) {
            if (! $estAdministrateur) {
                return ['erreur' => __('feed-post.mentions_members_forbidden')] + $resultat;
            }

            return ['mode' => self::MODE_MEMBRES] + $resultat;
        }

        if ($tous) {
            $animees = $this->bouclesAnimees($org, $auteur, $boucleIds);

            if ($animees === []) {
                return ['erreur' => __('feed-post.mentions_all_forbidden')] + $resultat;
            }

            return ['mode' => self::MODE_TOUS, 'boucles' => $animees] + $resultat;
        }

        if (count($personnes) > self::MAX_PERSONNES) {
            return ['erreur' => __('feed-post.mentions_too_many', ['max' => self::MAX_PERSONNES])] + $resultat;
        }

        if ($personnes !== []) {
            return ['mode' => self::MODE_PERSONNES, 'personnes' => $personnes] + $resultat;
        }

        return $resultat;
    }

    /** L'analyse d'une annonce ENREGISTREE (job d'envoi, publication differee). */
    public function analyserAnnonce(FeedPost $post, User $auteur): array
    {
        $org = Organization::query()->whereKey($post->organization_id)->first();

        if ($org === null) {
            return ['mode' => self::MODE_AUCUNE, 'personnes' => [], 'boucles' => [], 'erreur' => null];
        }

        $boucleIds = $post->loops()->pluck('loops.id')->map(fn ($id) => (string) $id)->all();
        $estAdministrateur = $org->admin_id === $auteur->id || (bool) $auteur->is_admin;

        return $this->analyser(
            (string) $post->content,
            $org,
            $auteur,
            $boucleIds,
            $post->visibility !== FeedPost::VISIBILITE_BOUCLES,
            $estAdministrateur,
        );
    }

    /**
     * Les destinataires d'une analyse, hors l'auteur, comptes actifs seulement.
     *
     * @return Collection<int, User>
     */
    public function destinataires(array $analyse, Organization $org, User $auteur): Collection
    {
        $ids = match ($analyse['mode']) {
            self::MODE_PERSONNES => $analyse['personnes'],
            self::MODE_TOUS => LoopMember::query()
                ->where('organization_id', $org->id)
                ->whereIn('loop_id', $analyse['boucles'])
                ->where('status', 'active')
                ->distinct()
                ->pluck('user_id')
                ->map(fn ($id) => (string) $id)
                ->all(),
            self::MODE_MEMBRES => null,
            default => [],
        };

        if ($ids === []) {
            return collect();
        }

        return $this->utilisateursDeLOrganisation($org, $ids)
            ->reject(fn (User $u) => $u->id === $auteur->id)
            ->values();
    }

    /**
     * La configuration du menu `@` du composeur (lue par
     * `markdown-wysiwyg-editor.js`). N'autorise rien : le serveur re-analyse
     * le texte a l'enregistrement.
     *
     * @param  list<string>  $boucleIds  les Boucles proposees a l'auteur
     * @param  list<string>|null  $bouclesFixes  Boucles deja enregistrees (modification)
     */
    public function configurationDuMenu(Organization $org, User $auteur, array $boucleIds, bool $audienceOrganisation, bool $estAdministrateur, ?array $bouclesFixes = null): array
    {
        return [
            'audience' => $audienceOrganisation ? 'organisation' : 'boucles',
            'candidats' => $this->mentionnables($org, $auteur, $boucleIds, $audienceOrganisation),
            'animees' => $this->bouclesAnimees($org, $auteur, $boucleIds),
            'admin' => $estAdministrateur,
            'bouclesFixes' => $bouclesFixes,
            'max' => self::MAX_PERSONNES,
            'jetons' => ['tous' => self::JETON_TOUS, 'membres' => self::JETON_MEMBRES],
            'libelles' => [
                'tous' => __('feed-post.mention_all_option'),
                'membres' => __('feed-post.mention_members_option'),
                'liste' => __('feed-post.mentions_list_label'),
            ],
        ];
    }

    /** @param  list<string>|null  $ids  null = toute l'Organization */
    private function utilisateursDeLOrganisation(Organization $org, ?array $ids = null): Collection
    {
        return User::query()
            ->withoutGlobalScope(BelongsToOrganizationScope::class)
            ->where('organization_id', $org->id)
            ->whereNull('banned_at')
            ->when($ids !== null, fn ($q) => $q->whereIn('id', $ids))
            ->orderBy('name')
            ->get()
            ->reject(fn (User $u) => $u->isDeactivated())
            ->values();
    }

    /** `@tous` / `@membres` en mot ENTIER (`@toussaint` ne compte pas). */
    private static function contientJeton(string $texte, string $jeton): bool
    {
        return (bool) preg_match('/(^|\s)'.preg_quote($jeton, '/').'(?![\p{L}\p{N}_-])/u', $texte);
    }

    /** « @Prenom Nom » suivi d'une fin de mot (pas « @Prenom Nomade »). */
    private static function contientNom(string $texte, string $nom): bool
    {
        return (bool) preg_match('/(^|\s)@'.preg_quote($nom, '/').'(?![\p{L}\p{N}_-])/u', $texte);
    }
}
