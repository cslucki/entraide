<?php

namespace Tests\Feature;

use App\Models\UsageReference;
use App\Support\Ai\AiShellUsageReference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * TASK-1485 — le seeder canonique des UsageReference.
 *
 * ## La regle qui gouverne tout le fichier
 *
 * **Une reference existante n'est JAMAIS ecrasee.** Ni publiee, ni brouillon,
 * ni retiree. Un second passage ne modifie aucune ligne. C'est la seule chose
 * qui rend un seeder de contenu editorial sur : le texte que quelqu'un a ecrit
 * ou publie ne doit pas pouvoir disparaitre parce qu'une commande a ete relancee.
 *
 * ## Et rien n'est publie
 *
 * Tout ce qui est cree nait en `draft`. Un brouillon n'est servi ni au Shell
 * membre, ni au Shell visiteur, ni a aucun modele : le fail-closed de
 * `UsageReferenceResolver` s'en charge. La publication reste un geste humain.
 *
 * ## Ce que la mesure a appris, et qui n'etait pas dans le brief
 *
 * Le brief parlait de « 10 brouillons de banc a preserver ». Releve reel du
 * 2026-09-09 : 14 references, **toutes publiees, aucun brouillon**. Le nombre 10
 * etait celui des paires MANQUANTES. La regle ne change pas — elle protege
 * simplement 14 publications au lieu de 10 brouillons.
 *
 * Plus genant : sur les cinq surfaces PUBLIQUES declarees, une seule est
 * reellement demandee par du code. La section E le fige.
 */
class TASK1485CanonicalUsageReferenceSeederTest extends TestCase
{
    use RefreshDatabase;

    private const COMMAND = 'ai:seed-usage-references';

    // =====================================================================
    // A. Le contrat de couverture
    // =====================================================================

    /**
     * Les 12 surfaces x 2 langues = 24 paires.
     *
     * La base de test n'est pas vierge : la migration de TASK-1439 seme deja
     * `shell_welcome` FR et EN. C'est justement ce que le seeder doit savoir
     * traverser sans rien casser.
     */
    public function test_a_first_run_covers_every_declared_surface_in_both_locales(): void
    {
        $seededByMigration = UsageReference::query()->count();

        $this->assertSame(2, $seededByMigration, 'premisse : la migration T1439 a seme shell_welcome FR + EN');

        $this->artisan(self::COMMAND)->assertSuccessful();

        $this->assertSame(24, UsageReference::query()->count());
        $this->assertCount(12, UsageReference::SURFACES);

        foreach (UsageReference::SURFACES as $surface) {
            foreach (['fr', 'en'] as $locale) {
                $this->assertTrue(
                    UsageReference::query()->forSurface($surface)->forLocale($locale)->exists(),
                    "[{$surface}/{$locale}] manquante"
                );
            }
        }
    }

    /**
     * Tout ce que le seeder cree est un BROUILLON. Rien n'est publie, jamais.
     *
     * Les deux references publiees par la migration T1439 restent publiees :
     * le seeder ne les a ni touchees, ni retrogradees.
     */
    public function test_everything_created_is_a_draft_and_nothing_is_published(): void
    {
        $this->artisan(self::COMMAND)->assertSuccessful();

        $this->assertSame(22, UsageReference::query()->where('state', UsageReference::STATE_DRAFT)->count());
        $this->assertSame(2, UsageReference::query()->where('state', UsageReference::STATE_PUBLISHED)->count());
        $this->assertSame(2, UsageReference::query()->whereNotNull('published_at')->count());
        $this->assertSame(0, UsageReference::query()->whereNotNull('published_by')->count());

        // Les deux publiees sont bien celles de la migration, pas des nouvelles.
        $this->assertSame(
            [UsageReference::SURFACE_SHELL_WELCOME, UsageReference::SURFACE_SHELL_WELCOME],
            UsageReference::query()->where('state', UsageReference::STATE_PUBLISHED)->pluck('surface_key')->all(),
        );
    }

    // =====================================================================
    // B. L'idempotence, mesuree — pas affirmee
    // =====================================================================

    /**
     * Second passage = ZERO modification. On ne compare pas seulement le
     * NOMBRE de lignes : une commande qui supprimerait puis recreerait tout
     * garderait le meme compte. On compare l'etat complet, identifiants et
     * horodatages compris.
     */
    public function test_a_second_run_changes_absolutely_nothing(): void
    {
        $this->artisan(self::COMMAND)->assertSuccessful();

        $before = $this->snapshot();

        $this->artisan(self::COMMAND)->assertSuccessful();

        $this->assertSame($before, $this->snapshot(), 'un second passage a modifie la base');
    }

    /** Et un troisieme non plus. */
    public function test_a_third_run_changes_nothing_either(): void
    {
        $this->artisan(self::COMMAND)->assertSuccessful();
        $this->artisan(self::COMMAND)->assertSuccessful();

        $before = $this->snapshot();

        $this->artisan(self::COMMAND)->assertSuccessful();

        $this->assertSame($before, $this->snapshot());
    }

    // =====================================================================
    // C. Ce qui existe est INTOUCHABLE — dans les trois etats
    // =====================================================================

    /**
     * Le coeur de la TASK. Un texte publie par un humain ne doit pas pouvoir
     * etre remplace par le texte canonique parce que quelqu'un a relance une
     * commande.
     */
    public function test_a_published_reference_is_never_overwritten(): void
    {
        $mine = UsageReference::query()->create([
            'surface_key' => UsageReference::SURFACE_DASHBOARD,
            'locale' => 'fr',
            'title' => 'MON TITRE A MOI',
            'content' => 'MON TEXTE PUBLIE, ecrit par un humain.',
            'version' => 7,
            'state' => UsageReference::STATE_PUBLISHED,
            'published_at' => now(),
        ]);

        $this->artisan(self::COMMAND)->assertSuccessful();

        $reloaded = $mine->fresh();

        $this->assertSame('MON TITRE A MOI', $reloaded->title);
        $this->assertSame('MON TEXTE PUBLIE, ecrit par un humain.', $reloaded->content);
        $this->assertSame(UsageReference::STATE_PUBLISHED, $reloaded->state);
        $this->assertSame(7, $reloaded->version);

        // Et aucune version concurrente n'a ete glissee a cote.
        $this->assertSame(1, UsageReference::query()->forSurface(UsageReference::SURFACE_DASHBOARD)->forLocale('fr')->count());
    }

    /** Un BROUILLON en cours de redaction est protege exactement pareil. */
    public function test_an_existing_draft_is_never_overwritten(): void
    {
        $draft = UsageReference::query()->create([
            'surface_key' => UsageReference::SURFACE_PROFILE,
            'locale' => 'en',
            'title' => 'WORK IN PROGRESS',
            'content' => 'Half-written, do not touch.',
            'version' => 3,
            'state' => UsageReference::STATE_DRAFT,
        ]);

        $this->artisan(self::COMMAND)->assertSuccessful();

        $this->assertSame('Half-written, do not touch.', $draft->fresh()->content);
        $this->assertSame(1, UsageReference::query()->forSurface(UsageReference::SURFACE_PROFILE)->forLocale('en')->count());
    }

    /**
     * Une reference RETIREE l'a ete par decision. La re-semer par-dessus
     * annulerait cette decision en silence.
     */
    public function test_a_retired_reference_is_not_resurrected(): void
    {
        UsageReference::query()->create([
            'surface_key' => UsageReference::SURFACE_SIGNUP,
            'locale' => 'fr',
            'title' => 'Retiree',
            'content' => 'Ce texte a ete retire volontairement.',
            'version' => 2,
            'state' => UsageReference::STATE_RETIRED,
        ]);

        $this->artisan(self::COMMAND)->assertSuccessful();

        $rows = UsageReference::query()->forSurface(UsageReference::SURFACE_SIGNUP)->forLocale('fr')->get();

        $this->assertCount(1, $rows);
        $this->assertSame(UsageReference::STATE_RETIRED, $rows->first()->state);
    }

    /** Le seeder ne supprime rien, jamais — y compris hors de son perimetre. */
    public function test_the_seeder_deletes_nothing(): void
    {
        $this->artisan(self::COMMAND)->assertSuccessful();

        $ids = UsageReference::query()->orderBy('id')->pluck('id')->all();

        $this->artisan(self::COMMAND)->assertSuccessful();

        $this->assertSame($ids, UsageReference::query()->orderBy('id')->pluck('id')->all());
    }

    // =====================================================================
    // D. `--dry-run` : il MONTRE, il n'ecrit pas
    // =====================================================================

    /**
     * L'option existe pour une raison precise : elle permet de mesurer ce que
     * la commande ferait sur une base REELLE sans y toucher. Une action durable
     * sur des donnees qu'on n'a pas le mandat de modifier ne se tente pas « pour
     * voir ».
     */
    public function test_dry_run_writes_nothing(): void
    {
        $before = $this->snapshot();

        $this->artisan(self::COMMAND, ['--dry-run' => true])->assertSuccessful();

        $this->assertSame($before, $this->snapshot());
        $this->assertSame(2, UsageReference::query()->count(), 'seules les 2 de la migration');
    }

    /** Et sur une base deja pourvue, il n'ecrit pas davantage. */
    public function test_dry_run_writes_nothing_on_a_populated_database(): void
    {
        $this->artisan(self::COMMAND)->assertSuccessful();

        $before = $this->snapshot();

        $this->artisan(self::COMMAND, ['--dry-run' => true])->assertSuccessful();

        $this->assertSame($before, $this->snapshot());
    }

    // =====================================================================
    // E. Les textes sont ANCRES dans le depot, pas imagines
    // =====================================================================

    /** Chaque texte canonique porte un titre et un corps non vides, dans les deux langues. */
    public function test_every_canonical_text_is_complete(): void
    {
        foreach ($this->texts() as $surface => $locales) {
            $this->assertSame(['fr', 'en'], array_keys($locales), "[{$surface}] les deux langues");

            foreach ($locales as $locale => $text) {
                $this->assertNotSame('', trim($text['title']), "[{$surface}/{$locale}] titre vide");
                $this->assertNotEmpty($text['lines'], "[{$surface}/{$locale}] corps vide");
            }
        }
    }

    /** Aucun texte ne depasse la limite de redaction ni la borne d'ancrage de TASK-1484. */
    public function test_no_canonical_text_exceeds_the_grounding_bound(): void
    {
        foreach ($this->texts() as $surface => $locales) {
            foreach ($locales as $locale => $text) {
                $length = mb_strlen(implode("\n", $text['lines']));

                $this->assertLessThanOrEqual(UsageReference::maxChars(), $length, "[{$surface}/{$locale}] au-dela de la limite de redaction");
                $this->assertLessThanOrEqual(
                    AiShellUsageReference::GROUNDING_MAX_CHARS,
                    $length,
                    "[{$surface}/{$locale}] serait tronque a l'ancrage — le modele lirait un texte coupe"
                );
                $this->assertLessThanOrEqual(UsageReference::MAX_TITLE_CHARS, mb_strlen($text['title']), "[{$surface}/{$locale}] titre trop long");
            }
        }
    }

    /**
     * `workshop_session` n'a AUCUNE route, et le depot le dit depuis TASK-1450 :
     * `GuestPageContextResolver` la laisse a NULL pour ne pas fabriquer un faux
     * contexte.
     *
     * Son texte doit donc dire que les sessions se choisissent DEPUIS la page de
     * l'atelier — pas decrire une page qui n'existe pas. Le jour ou la route
     * apparait, ce test rougit et rappelle de relire le texte.
     */
    public function test_the_session_text_does_not_describe_a_page_that_does_not_exist(): void
    {
        $this->assertFalse(Route::has('organization.workshop.session.show'), 'la route est apparue : relire le texte de workshop_session');

        foreach (['fr', 'en'] as $locale) {
            $body = implode("\n", $this->texts()['workshop_session'][$locale]['lines']);

            $this->assertMatchesRegularExpression(
                '/page[^.]{0,40}(de son atelier|of its workshop)/iu',
                $body,
                "[{$locale}] le texte doit renvoyer a la page de l'atelier"
            );
        }
    }

    /**
     * La distinction que la page d'atelier fait vraiment, et qu'un texte flou
     * effacerait : marquer son INTERET n'est pas CONFIRMER sa participation.
     * Les deux gestes ont deux routes distinctes.
     */
    public function test_the_workshop_text_keeps_interest_and_registration_apart(): void
    {
        $this->assertTrue(Route::has('organization.workshop.session.interest'), 'premisse : le geste d\'interet existe');
        $this->assertTrue(Route::has('organization.workshop.session.register'), 'premisse : le geste d\'inscription existe');

        $fr = implode("\n", $this->texts()['workshop']['fr']['lines']);
        $en = implode("\n", $this->texts()['workshop']['en']['lines']);

        $this->assertMatchesRegularExpression('/inter[eê]t/iu', $fr);
        $this->assertMatchesRegularExpression('/participation/iu', $fr);
        $this->assertMatchesRegularExpression('/interest/i', $en);
        $this->assertMatchesRegularExpression('/attendance|confirm/i', $en);
    }

    /**
     * CANONIQUE veut dire les DOUZE surfaces, pas seulement celles qui manquent
     * sur une machine donnee.
     *
     * Une premiere version de ce fichier n'en portait que cinq — celles absentes
     * du banc. Ce test l'a fait rougir sur une base neuve : douze paires y
     * manquaient. Le fichier etait cale sur l'etat d'une machine, pas canonique.
     */
    public function test_the_data_file_carries_every_declared_surface(): void
    {
        $provided = array_keys($this->texts());

        $declared = UsageReference::SURFACES;
        sort($declared);
        sort($provided);

        $this->assertSame($declared, $provided, 'le fichier canonique doit couvrir les 12 surfaces declarees');
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    /** @return array<string, array<string, array{title: string, lines: list<string>}>> */
    private function texts(): array
    {
        return require database_path('seeders/data/usage_references.php');
    }

    /**
     * L'etat COMPLET, pas un compte. Un seeder qui supprimerait puis recreerait
     * tout garderait le meme nombre de lignes.
     *
     * @return list<array<string, mixed>>
     */
    private function snapshot(): array
    {
        return UsageReference::query()
            ->orderBy('surface_key')
            ->orderBy('locale')
            ->orderBy('version')
            ->get(['id', 'surface_key', 'locale', 'title', 'content', 'version', 'state', 'published_at', 'created_at', 'updated_at'])
            ->map(fn ($r) => $r->only(['id', 'surface_key', 'locale', 'title', 'content', 'version', 'state', 'published_at', 'created_at', 'updated_at']))
            ->map(fn ($row) => array_map(static fn ($v) => is_object($v) ? (string) $v : $v, $row))
            ->all();
    }
}
