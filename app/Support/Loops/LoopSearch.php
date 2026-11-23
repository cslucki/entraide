<?php

namespace App\Support\Loops;

use App\Models\BlogPost;
use App\Models\Dossier;
use App\Models\DossierFile;
use App\Models\LoopEvent;
use App\Models\LoopMessage;
use App\Support\Search\TexteContient;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

/**
 * TASK-1885 C9 (demande Cyril, 22/11) — rechercher dans les Boucles dont on
 * est MEMBRE : messages, articles, dossiers (et leurs fichiers), evenements.
 *
 * Recherche texte simple (ILIKE sous PostgreSQL, LIKE ailleurs), sans IA.
 *
 * ## Perimetre
 *
 * L'appelant fournit les Boucles ou la personne est membre ACTIVE : c'est la
 * meme regle que `LoopPolicy::viewWorkspace`, qui ouvre le fil, les Cards et
 * le Dossier racine d'une Boucle. Rien ici n'elargit ce perimetre :
 *
 * - messages : humains et non supprimes (`humanVisible`), de ces Boucles ;
 * - articles : PUBLIES et rattaches a l'une de ces Boucles ;
 * - dossiers : le Dossier racine de ces Boucles et ses sous-dossiers, plus
 *   leurs fichiers non supprimes ;
 * - evenements : ceux de ces Boucles, hors annules.
 *
 * Tenant explicite sur chaque requete.
 */
class LoopSearch
{
    public const MIN = 2;

    public const MAX = 100;

    /** Plafond par famille : la page reste lisible, la requete bornee. */
    private const LIMITE = 40;

    /**
     * @param  Collection<int, \App\Models\Loop>  $boucles  Boucles ou la personne est membre active.
     * @return Collection<int, array{loop: \App\Models\Loop, messages: Collection, articles: Collection, dossiers: Collection, fichiers: Collection, evenements: Collection, total: int}>
     */
    public function rechercher(string $terme, Collection $boucles, string $organizationId): Collection
    {
        $terme = trim(Str::limit($terme, self::MAX, ''));

        if (mb_strlen($terme) < self::MIN || $boucles->isEmpty()) {
            return collect();
        }

        $ids = $boucles->pluck('id')->map(fn ($id) => (string) $id)->all();
        $motif = TexteContient::motif($terme);

        $messages = LoopMessage::query()
            ->whereIn('loop_id', $ids)
            ->where('organization_id', $organizationId)
            ->humanVisible()
            ->where(fn (Builder $q) => TexteContient::where($q, 'body', $motif))
            ->with('sender')
            ->latest('created_at')->orderByDesc('id')
            ->limit(self::LIMITE)
            ->get();

        $articles = BlogPost::query()
            ->where('organization_id', $organizationId)
            ->published()
            ->whereHas('loops', fn (Builder $q) => $q->whereIn('loops.id', $ids))
            ->where(function (Builder $q) use ($motif): void {
                TexteContient::where($q, 'title', $motif);
                TexteContient::orWhere($q, 'content', $motif);
            })
            ->with(['loops' => fn ($q) => $q->whereIn('loops.id', $ids)])
            ->latest('published_at')
            ->limit(self::LIMITE)
            ->get();

        // Dossier racine de chaque Boucle, puis ses descendants : un enfant
        // ne porte pas de `loop_id`, il herite de sa racine (TASK-1130).
        $boucleDuDossier = Dossier::query()
            ->where('organization_id', $organizationId)
            ->whereIn('loop_id', $ids)
            ->pluck('loop_id', 'id')
            ->map(fn ($id) => (string) $id)
            ->all();
        $niveau = array_keys($boucleDuDossier);
        for ($profondeur = 0; $profondeur < 6 && $niveau !== []; $profondeur++) {
            $enfants = Dossier::query()
                ->where('organization_id', $organizationId)
                ->whereIn('parent_id', $niveau)
                ->pluck('parent_id', 'id');
            $niveau = [];
            foreach ($enfants as $enfant => $parent) {
                if (! isset($boucleDuDossier[$enfant])) {
                    $boucleDuDossier[$enfant] = $boucleDuDossier[(string) $parent];
                    $niveau[] = $enfant;
                }
            }
        }

        $dossiers = $boucleDuDossier === [] ? collect() : Dossier::query()
            ->where('organization_id', $organizationId)
            ->whereIn('id', array_keys($boucleDuDossier))
            ->where(fn (Builder $q) => TexteContient::where($q, 'name', $motif))
            ->limit(self::LIMITE)
            ->get();

        $fichiers = $boucleDuDossier === [] ? collect() : DossierFile::query()
            ->where('organization_id', $organizationId)
            ->whereIn('dossier_id', array_keys($boucleDuDossier))
            ->where(function (Builder $q) use ($motif): void {
                TexteContient::where($q, 'display_name', $motif);
                TexteContient::orWhere($q, 'original_name', $motif);
            })
            ->latest('created_at')
            ->limit(self::LIMITE)
            ->get();

        $evenements = LoopEvent::query()
            ->where('organization_id', $organizationId)
            ->whereIn('loop_id', $ids)
            ->where('status', '!=', LoopEvent::STATUS_CANCELLED)
            ->whereNull('cancelled_at')
            ->where(function (Builder $q) use ($motif): void {
                TexteContient::where($q, 'title', $motif);
                TexteContient::orWhere($q, 'description', $motif);
                TexteContient::orWhere($q, 'location', $motif);
            })
            ->orderByDesc('starts_at')
            ->limit(self::LIMITE)
            ->get();

        return $boucles
            ->map(function ($boucle) use ($messages, $articles, $dossiers, $fichiers, $evenements, $boucleDuDossier) {
                $id = (string) $boucle->id;
                $groupe = [
                    'loop' => $boucle,
                    'messages' => $messages->where('loop_id', $id)->values(),
                    'articles' => $articles->filter(fn ($a) => $a->loops->contains('id', $id))->values(),
                    'dossiers' => $dossiers->filter(fn ($d) => ($boucleDuDossier[(string) $d->id] ?? null) === $id)->values(),
                    'fichiers' => $fichiers->filter(fn ($f) => ($boucleDuDossier[(string) $f->dossier_id] ?? null) === $id)->values(),
                    'evenements' => $evenements->where('loop_id', $id)->values(),
                ];
                $groupe['total'] = $groupe['messages']->count() + $groupe['articles']->count()
                    + $groupe['dossiers']->count() + $groupe['fichiers']->count() + $groupe['evenements']->count();

                return $groupe;
            })
            ->filter(fn ($g) => $g['total'] > 0)
            ->sortByDesc('total')
            ->values();
    }

    /**
     * Extrait d'un texte (HTML retire) centre sur la premiere occurrence, le
     * terme surligne. Le texte est ECHAPPE avant le surlignage : seul `<mark>`
     * est du HTML.
     */
    public static function extrait(?string $texte, string $terme, int $largeur = 160): HtmlString
    {
        $plat = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string) $texte), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
        $terme = trim($terme);

        if ($plat === '') {
            return new HtmlString('');
        }

        $pos = $terme === '' ? false : mb_stripos($plat, $terme);
        $debut = $pos === false ? 0 : max(0, $pos - (int) ($largeur / 3));
        $morceau = mb_substr($plat, $debut, $largeur);
        $morceau = ($debut > 0 ? '…' : '').$morceau.(mb_strlen($plat) > $debut + $largeur ? '…' : '');

        $html = e($morceau);

        if ($terme !== '') {
            $html = preg_replace(
                '/'.preg_quote(e($terme), '/').'/iu',
                // Style en ligne : aucune dependance a un rebuild Tailwind.
                '<mark data-loop-search-hit style="background: rgba(251, 191, 36, 0.45); color: inherit; border-radius: 3px; padding: 0 2px;">$0</mark>',
                $html,
            ) ?? $html;
        }

        return new HtmlString($html);
    }
}
