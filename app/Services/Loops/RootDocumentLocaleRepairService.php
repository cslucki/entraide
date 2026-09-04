<?php

namespace App\Services\Loops;

use App\Models\BlogPost;
use App\Models\Loop;
use App\Models\Organization;
use App\Support\Loops\LoopTypeRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Traits\Localizable;

/**
 * Remettre dans la langue de son Organization le texte SYSTEME d'un document
 * racine deja ecrit (TASK-1389).
 *
 * TASK-1388 a corrige le MECANISME : un pack ou un backfill pose desormais la
 * locale de l'Organization avant d'ecrire. Il n'a rien repare de ce qui etait
 * deja en base — `ensureRootDocument()` est idempotent et rend le document
 * existant sans le regarder. Ce service est cette reparation, et elle seule.
 *
 * ## Ce qui est SYSTEME, et ce qui ne l'est pas
 *
 * Un document racine melange, dans les memes colonnes, du texte fabrique par
 * l'application et du texte ecrit par une personne. La reparation ne touche
 * QUE le premier :
 *
 * | fragment | origine | traite ici |
 * |---|---|---|
 * | prefixe du titre (`Manifeste — `) | `rootDocumentLabel()`, un `__()` | OUI |
 * | suite du titre (le nom de la Boucle) | humain | non |
 * | intro, si elle est le placeholder | `root_document_intro_placeholder` | OUI |
 * | intro, si elle vient de la description | humain | non |
 * | en-tetes `<h2>` des sections du type | `root_document_sections.*` | OUI |
 * | texte SOUS une section | humain | non |
 * | slug | derive du libelle, mais c'est une URL | **jamais** |
 *
 * ## Pourquoi remplacer des FRAGMENTS plutot que regenerer
 *
 * La premiere conception comparait le document entier au scaffold recalcule,
 * et ne reparait qu'en cas d'egalite. Elle a deux defauts mesures sur le parc
 * reel :
 *
 * - `initialContent()` recopie `loops.description` **une seule fois**, a la
 *   creation. Une description modifiee depuis rend le recalcul different, et
 *   le document serait saute a tort ;
 * - le parc contient des documents adoptes via `designate()`, qui n'ont jamais
 *   ete des scaffolds et n'ont aucune section.
 *
 * En ne remplacant que le fragment `<h2>libelle</h2>`, le texte qui le suit est
 * **structurellement** hors de portee : il n'y a aucun moyen pour ce service
 * d'effacer une phrase ecrite par quelqu'un. La garde n'est pas une precaution
 * qu'on ajoute, c'est la forme meme de l'operation.
 *
 * ## Le slug ne bouge pas
 *
 * `uniqueSlug()` derive du libelle traduit, donc les slugs du parc anglais
 * portent `-cadre-du-dialogue`. C'est une decision produit assumee : un slug
 * est un identifiant d'URL durable. La dette est signalee, pas corrigee.
 */
class RootDocumentLocaleRepairService
{
    use Localizable;

    public function __construct(private readonly LoopTypeRegistry $types) {}

    /**
     * Ce que la reparation ferait, sans rien ecrire.
     *
     * @return array<int, array{loop: string, champs: array<int, string>, avant: string, apres: string}>
     */
    public function preview(Organization $organization): array
    {
        return $this->parcourir($organization, ecrire: false);
    }

    /**
     * Applique la reparation.
     *
     * @return array<int, array{loop: string, champs: array<int, string>, avant: string, apres: string}>
     */
    public function repair(Organization $organization): array
    {
        return $this->parcourir($organization, ecrire: true);
    }

    /**
     * Le meme parcours repond a l'apercu et a l'application.
     *
     * `LoopsSyncPresets` etablit la regle et la raison : un `--dry-run` qui
     * emprunterait un autre chemin que l'ecriture pourrait raconter une autre
     * histoire. Ici les deux ne different que par un `save()`.
     *
     * @return array<int, array{loop: string, champs: array<int, string>, avant: string, apres: string}>
     */
    private function parcourir(Organization $organization, bool $ecrire): array
    {
        $cible = $organization->locale;
        $sources = array_values(array_diff($this->localesSupportees(), [$cible]));

        $rapport = [];

        $boucles = Loop::query()
            ->where('organization_id', $organization->id)
            ->whereNotNull('manifesto_blog_post_id')
            ->orderBy('created_at')
            ->get();

        foreach ($boucles as $boucle) {
            $document = BlogPost::query()->find($boucle->manifesto_blog_post_id);

            if (! $document || $document->organization_id !== $organization->id) {
                // Un document racine hors de l'Organization serait une anomalie
                // de rattachement, pas un probleme de langue. On ne la repare
                // pas ici, et surtout on n'ecrit pas dedans.
                continue;
            }

            $titre = $this->titreRepare($boucle, $document->title, $cible, $sources);
            $contenu = $this->contenuRepare($boucle, (string) $document->content, $cible, $sources);

            $champs = [];

            if ($titre !== null) {
                $champs[] = 'title';
            }

            if ($contenu !== null) {
                $champs[] = 'content';
            }

            if ($champs === []) {
                continue;
            }

            $rapport[] = [
                'loop' => $boucle->name,
                'champs' => $champs,
                'avant' => $document->title,
                'apres' => $titre ?? $document->title,
            ];

            if (! $ecrire) {
                continue;
            }

            DB::transaction(function () use ($document, $titre, $contenu) {
                if ($titre !== null) {
                    $document->title = $titre;
                }

                if ($contenu !== null) {
                    $document->content = $contenu;
                }

                // `slug` n'est jamais assigne : voir le docblock de classe.
                $document->save();
            });
        }

        return $rapport;
    }

    /**
     * Le titre repare, ou `null` s'il n'y a rien a faire.
     *
     * Compare sur le PREFIXE, pas sur le titre entier : le nom d'une Boucle a
     * pu etre change depuis la creation du document, et le titre ne se
     * resynchronise jamais. Exiger l'egalite complete refuserait de reparer
     * toute Boucle renommee — c'est-a-dire, sur un parc vivant, la plupart.
     */
    private function titreRepare(Loop $boucle, string $titre, string $cible, array $sources): ?string
    {
        $prefixeCible = $this->libelle($boucle->type, $cible).' — ';

        if (str_starts_with($titre, $prefixeCible)) {
            return null;
        }

        foreach ($sources as $source) {
            $prefixeSource = $this->libelle($boucle->type, $source).' — ';

            if (str_starts_with($titre, $prefixeSource)) {
                return $prefixeCible.mb_substr($titre, mb_strlen($prefixeSource));
            }
        }

        // Aucun prefixe systeme reconnu : le titre a ete ecrit ou reecrit a la
        // main. On n'y touche pas.
        return null;
    }

    /**
     * Le contenu repare, ou `null` s'il n'y a rien a faire.
     *
     * Deux fragments seulement, et ils sont delimites par leurs propres
     * balises : le paragraphe d'intro s'il est le placeholder systeme, et les
     * en-tetes `<h2>` des sections du type. Rien d'autre n'est lu, et surtout
     * rien d'autre n'est ecrit.
     */
    private function contenuRepare(Loop $boucle, string $contenu, string $cible, array $sources): ?string
    {
        $repare = $contenu;

        foreach ($sources as $source) {
            $repare = $this->remplacerPlaceholder($boucle, $repare, $cible, $source);
            $repare = $this->remplacerSections($boucle, $repare, $cible, $source);
        }

        return $repare === $contenu ? null : $repare;
    }

    /**
     * Le paragraphe d'introduction, UNIQUEMENT s'il est le placeholder systeme.
     *
     * `initialContent()` n'ecrit ce placeholder que si la Boucle n'avait pas de
     * description. Une intro qui ne lui correspond pas exactement vient donc
     * d'une personne, et elle reste telle quelle.
     */
    private function remplacerPlaceholder(Loop $boucle, string $contenu, string $cible, string $source): string
    {
        $arguments = ['name' => $boucle->name];

        $avant = '<p>'.e($this->traduire('loops.root_document_intro_placeholder', $source, $arguments)).'</p>';
        $apres = '<p>'.e($this->traduire('loops.root_document_intro_placeholder', $cible, $arguments)).'</p>';

        return str_replace($avant, $apres, $contenu);
    }

    /**
     * Les en-tetes de section du type de la Boucle.
     *
     * `rootDocumentSections()` rend des CLES, pas des libelles : la liste ne
     * depend pas de la locale, seule sa traduction en depend. Le remplacement
     * est borne a `<h2>…</h2>` — ce qui suit la balise fermante n'est jamais
     * lu ni reecrit.
     *
     * `e()` est applique des deux cotes parce que `initialContent()` l'applique
     * a l'ecriture. Comparer la chaine brute a une chaine echappee ne
     * correspondrait jamais, et la reparation serait un no-op silencieux.
     */
    private function remplacerSections(Loop $boucle, string $contenu, string $cible, string $source): string
    {
        foreach ($this->types->rootDocumentSections($boucle->type) as $cle) {
            $avant = '<h2>'.e($this->traduire($cle, $source)).'</h2>';
            $apres = '<h2>'.e($this->traduire($cle, $cible)).'</h2>';

            if ($avant === $apres) {
                continue;
            }

            $contenu = str_replace($avant, $apres, $contenu);
        }

        return $contenu;
    }

    /**
     * Le libelle de document racine d'un type, sous une locale donnee.
     *
     * Passe par le registre plutot que par la cle brute : c'est lui qui sait
     * quelle cle un type utilise, et quel repli s'applique a un type qui n'en
     * declare pas.
     */
    private function libelle(?string $type, string $locale): string
    {
        return $this->withLocale($locale, fn () => $this->types->rootDocumentLabel($type));
    }

    /**
     * @param  array<string, string>  $arguments
     */
    private function traduire(string $cle, string $locale, array $arguments = []): string
    {
        return (string) trans($cle, $arguments, $locale);
    }

    /**
     * @return array<int, string>
     */
    private function localesSupportees(): array
    {
        return config('services.supported_locales', ['fr', 'en']);
    }
}
