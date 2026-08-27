<?php

namespace Tests\Feature;

use App\Livewire\LoopChat;
use App\Models\Loop;
use App\Models\LoopMessage;
use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Models\User;
use App\Services\LoopService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * TASK-1312 — polish de lisibilite du ChatLoop.
 *
 * Deux gestes, et une sentinelle.
 *
 * 1. **Badge de mode** sur les reponses IA seulement. Sa valeur vient de
 *    `metadata['ai_mode']` — la provenance canonique du message — et jamais
 *    d'une couleur, d'une classe ou du texte de la bulle. Aucun badge
 *    « Humain » : marquer ce qui est ordinaire n'informe personne.
 *
 * 2. **Sources repliees par defaut.** Une reponse documentaire cite jusqu'a dix
 *    sources ; deployees, elles reléguaient la REPONSE hors de l'ecran. Le
 *    contenu se retrouvait enseveli sous sa propre provenance.
 *
 * 3. **Sentinelle des marqueurs Livewire.** L'anomalie `[if BLOCK]` de T1310
 *    n'est PAS reproductible sur la base actuelle — elle a ete corrigee dans
 *    cette TASK-la. Mais T1312 ajoute deux blocs dans la zone exacte du piege
 *    (le slot de `message-bubble` traverse `markdown()`), et c'est precisement
 *    pour cela que la garde est etendue ici.
 */
#[Group('ai')]
class TASK1312ChatLoopReadabilityTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $owner;

    private Loop $loop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create(['name' => 'LaunchPals', 'slug' => 'launchpals']);
        OrganizationAiSetting::factory()->create([
            'organization_id' => $this->organization->id,
            'provider' => 'openrouter',
            'model' => 'openai/gpt-4o-mini',
            'api_key' => 'sk-or-tenant',
        ]);

        $this->owner = User::factory()->create(['organization_id' => $this->organization->id]);

        app()->instance('current_organization', $this->organization);
        $this->loop = (new LoopService)->createLoop($this->owner, 'Boucle de lecture');

        Http::preventStrayRequests();
    }

    // =====================================================================
    // 1. LE BADGE DE MODE
    // =====================================================================

    /** @return list<array{0: string, 1: string}> */
    public static function aiModes(): array
    {
        return [
            ['llm', 'loops.ia_mode_label'],
            ['rag', 'loops.dossiers_mode_label'],
            ['llm_rag', 'loops.hybrid_mode_label'],
        ];
    }

    /**
     * Les trois moteurs portent leur badge, et sa valeur est la METADONNEE
     * canonique — pas le libelle traduit, pas une couleur.
     */
    #[DataProvider('aiModes')]
    public function test_every_ai_answer_carries_a_badge_from_its_canonical_metadata(string $mode, string $labelKey): void
    {
        $this->aiMessage($mode);

        $this->actingAs($this->owner);
        Livewire::test(LoopChat::class, ['loop' => $this->loop])
            ->assertSee('data-ai-mode="'.$mode.'"', false)
            ->assertSee(__($labelKey));
    }

    /**
     * Un message humain ne porte AUCUN badge. Marquer ce qui est ordinaire
     * n'informe personne, et ferait du badge un bruit plutot qu'un signal.
     */
    public function test_a_human_message_never_carries_a_mode_badge(): void
    {
        LoopMessage::create([
            'loop_id' => $this->loop->id,
            'sender_id' => $this->owner->id,
            'body' => 'Un message humain ordinaire.',
            'type' => 'user',
            'organization_id' => $this->loop->organization_id,
        ]);

        $this->actingAs($this->owner);
        Livewire::test(LoopChat::class, ['loop' => $this->loop])
            ->assertSee('Un message humain ordinaire.')
            ->assertDontSee('data-ai-mode', false);
    }

    /**
     * Le badge vient de la METADONNEE, jamais du texte de la bulle.
     *
     * Une reponse `llm` dont le corps parle abondamment de Dossiers doit
     * afficher « IA ». Sans cette sentinelle, une implementation qui devinerait
     * le mode depuis le contenu passerait tous les autres tests.
     */
    public function test_the_badge_never_follows_the_text_of_the_answer(): void
    {
        $this->aiMessage('llm', 'Je parle de Dossiers, de documents et de sources, mais je n\'en ai consulte aucun.');

        $this->actingAs($this->owner);
        Livewire::test(LoopChat::class, ['loop' => $this->loop])
            ->assertSee('data-ai-mode="llm"', false)
            ->assertDontSee('data-ai-mode="rag"', false)
            ->assertDontSee('data-ai-mode="llm_rag"', false);
    }

    /**
     * Un mode inconnu ne produit AUCUN badge, plutot qu'un badge menteur.
     *
     * Le repli historique de `LoopChat` ramene une bulle sans `ai_mode` connu
     * vers `llm` ou `rag` selon son `action` : c'est ce repli qui est verifie
     * ici, et non l'invention d'une quatrieme valeur.
     */
    public function test_a_legacy_bubble_falls_back_to_a_known_mode_and_never_invents_one(): void
    {
        LoopMessage::create([
            'loop_id' => $this->loop->id,
            'sender_id' => null,
            'body' => 'Une bulle anterieure a ai_mode.',
            'type' => 'ai',
            'metadata' => ['action' => 'knowledge'],
            'organization_id' => $this->loop->organization_id,
        ]);

        $this->actingAs($this->owner);
        Livewire::test(LoopChat::class, ['loop' => $this->loop])
            ->assertSee('data-ai-mode="rag"', false)
            ->assertDontSee('data-ai-mode=""', false);
    }

    /**
     * L'identite tenant survit au decoupage : le NOM de l'Organization est
     * rendu, jamais son slug (invariant T1308), et le separateur reste dans le
     * TEXTE — c'est lui que verifie le parcours E2E canonique par
     * `toContainText`, et le perdre aurait impose de rejouer l'E2E, donc de
     * depenser des appels provider.
     */
    public function test_the_tenant_identity_and_the_separator_survive_the_split(): void
    {
        $this->aiMessage('llm_rag');

        $this->actingAs($this->owner);
        Livewire::test(LoopChat::class, ['loop' => $this->loop])
            ->assertSee('>LaunchPals</span>', false)
            ->assertDontSee('>launchpals</span>', false)
            ->assertSee('>·</span>', false);
    }

    // =====================================================================
    // 2. LES SOURCES REPLIEES
    // =====================================================================

    /**
     * Le bloc est un `<details>` SANS attribut `open` : replie par defaut.
     *
     * `<details>`/`<summary>` est natif — deployable au clavier, annonce comme
     * tel par les lecteurs d'ecran, et sans une ligne de JavaScript.
     */
    public function test_the_sources_block_is_collapsed_by_default(): void
    {
        $this->aiMessage('rag', 'Une reponse [S1].', $this->sources(3));

        $this->actingAs($this->owner);
        $html = Livewire::test(LoopChat::class, ['loop' => $this->loop])->html();

        $this->assertStringContainsString('<details', $html);
        $this->assertStringContainsString('data-message-sources', $html);
        $this->assertStringContainsString('<summary', $html);

        // Aucun `open` sur le bloc de sources : c'est CA, « replie par defaut ».
        //
        // On ISOLE la balise ouvrante, puis on y cherche l'attribut. Une regex
        // du genre `<details[^>]*data-message-sources[^>]*\bopen\b` exigerait
        // que `open` vienne APRES le marqueur : `<details open ... data-...>`
        // lui echapperait. Verifie par mutation — cette version-la passait
        // encore avec le bloc deplie.
        $this->assertSame(
            1,
            preg_match('/<details(?=[^>]*data-message-sources)([^>]*)>/', $html, $balise),
            'la balise ouvrante du bloc de sources est introuvable',
        );
        $this->assertDoesNotMatchRegularExpression('/\bopen\b/', $balise[1]);
    }

    public function test_the_summary_announces_how_many_sources_there_are(): void
    {
        $this->aiMessage('rag', 'Une reponse [S1].', $this->sources(4));

        $this->actingAs($this->owner);
        Livewire::test(LoopChat::class, ['loop' => $this->loop])
            ->assertSee('data-sources-count="4"', false)
            ->assertSee(__('loops.knowledge_sources_title'))
            ->assertSee('· 4');
    }

    /**
     * Replier ne doit RIEN retirer : les references, les titres et les URLs
     * sont exactement ceux d'avant. Un bloc replie qui aurait perdu ses liens
     * serait une regression deguisee en polish.
     */
    public function test_collapsing_removes_no_reference_and_no_url(): void
    {
        $this->aiMessage('rag', 'Une reponse [S1][S2][S3].', $this->sources(3));

        $this->actingAs($this->owner);
        $component = Livewire::test(LoopChat::class, ['loop' => $this->loop]);

        foreach ([1, 2, 3] as $n) {
            $component->assertSee('[S'.$n.']')
                ->assertSee('Document '.$n.'.md')
                ->assertSee('https://test.laravel/source/'.$n);
        }
    }

    /**
     * Le distinguo T1309 « utilisees / consultees » survit au repli : ce sont
     * deux etats mutuellement exclusifs, et un document seulement consulte ne
     * doit jamais etre presente comme un appui.
     */
    public function test_the_used_versus_consulted_distinction_survives_the_collapse(): void
    {
        LoopMessage::create([
            'loop_id' => $this->loop->id,
            'sender_id' => null,
            'body' => 'Une reponse qui ne cite rien.',
            'type' => 'ai',
            'metadata' => ['ai_mode' => 'rag', 'consulted' => $this->sources(2)],
            'organization_id' => $this->loop->organization_id,
        ]);

        $this->actingAs($this->owner);
        Livewire::test(LoopChat::class, ['loop' => $this->loop])
            ->assertSee('data-sources-kind="consulted"', false)
            ->assertDontSee('data-sources-kind="used"', false)
            ->assertSee(__('loops.knowledge_consulted_title'))
            ->assertSee('data-sources-count="2"', false);
    }

    /**
     * Une reponse SANS aucune source n'affiche aucun bloc — pas un bloc replie
     * vide, qui promettrait une provenance inexistante.
     */
    public function test_an_answer_without_any_source_shows_no_block_at_all(): void
    {
        $this->aiMessage('llm', 'Une reponse sans la moindre source.');

        $this->actingAs($this->owner);
        Livewire::test(LoopChat::class, ['loop' => $this->loop])
            ->assertSee('Une reponse sans la moindre source.')
            ->assertDontSee('data-message-sources', false)
            ->assertDontSee('data-sources-count', false);
    }

    /**
     * Le bloc doit survivre au `wire:poll` — sinon il se referme TOUT SEUL.
     *
     * BUG VECU, trouve par un utilisateur en recette : deplier « Sources
     * utilisees · N », ne toucher a rien, et le voir se refermer trois secondes
     * plus tard. Cause : le ChatLoop porte `wire:poll.3s` ; a chaque cycle, le
     * morph de Livewire realigne les attributs sur le HTML du SERVEUR, qui
     * ignore que l'utilisateur vient de deplier. L'attribut `open`, pose cote
     * client, etait donc efface au premier poll. Mesure : `open` vrai apres le
     * clic, FAUX apres 12 s et 4 requetes `/update`.
     *
     * `wire:ignore.self` gele les attributs de CET element, et de lui seul —
     * les enfants continuent d'etre morphes. Ce qui est gele ne bouge d'ailleurs
     * jamais : une bulle IA n'est pas editable
     * (`LoopMessage::isEditableBy()` n'accepte que `user` et `member_agent`),
     * donc `metadata['sources']` est ecrit une fois pour toutes.
     *
     * La preuve REELLE est au navigateur (`recette-1312-poll-repli.mjs`) :
     * seul un vrai cycle de poll peut la donner. Cette garde-ci existe pour
     * qu'on ne retire pas l'attribut par megarde entre deux recettes.
     */
    public function test_the_sources_block_is_protected_from_the_poll_morph(): void
    {
        $this->aiMessage('rag', 'Une reponse [S1].', $this->sources(2));

        $this->actingAs($this->owner);
        $html = Livewire::test(LoopChat::class, ['loop' => $this->loop])->html();

        $this->assertSame(
            1,
            preg_match('/<details(?=[^>]*data-message-sources)([^>]*)>/', $html, $balise),
            'la balise ouvrante du bloc de sources est introuvable',
        );
        $this->assertMatchesRegularExpression('/wire:ignore\.self/', $balise[1]);
    }

    /**
     * Le chevron doit DIRE l'etat du bloc : a droite replie, vers le bas
     * deplie.
     *
     * DEFAUT VECU, signale a l'ecran : le chevron restait identique dans les
     * deux etats. La rotation etait ecrite en variant arbitraire imbrique —
     * `[details[open]_&]:rotate-90` — que Tailwind **ne genere pas**. La classe
     * etait absente du CSS compile (0 occurrence), donc la regle n'existait tout
     * simplement pas. Un style qu'aucune feuille ne porte ne se voit pas : c'est
     * le genre de defaut qu'aucune assertion de DOM ne rattrape, seulement un
     * oeil ou une mesure de `getComputedStyle`.
     *
     * `group-open:` est la forme reellement produite, et exige un `group` sur le
     * `<details>` : les deux sont assertes ensemble, car l'un sans l'autre ne
     * fait rien.
     */
    public function test_the_chevron_tells_which_state_the_block_is_in(): void
    {
        $this->aiMessage('rag', 'Une reponse [S1].', $this->sources(2));

        $this->actingAs($this->owner);
        $html = Livewire::test(LoopChat::class, ['loop' => $this->loop])->html();

        $this->assertSame(
            1,
            preg_match('/<details(?=[^>]*data-message-sources)([^>]*)>/', $html, $balise),
            'la balise ouvrante du bloc de sources est introuvable',
        );
        $this->assertMatchesRegularExpression('/\bgroup\b/', $balise[1]);
        $this->assertStringContainsString('group-open:rotate-90', $html);
    }

    // =====================================================================
    // 3. LA SENTINELLE DES MARQUEURS
    // =====================================================================

    /**
     * Aucun marqueur de bloc Livewire ne doit etre LU par un utilisateur.
     *
     * L'anomalie de T1310 n'est plus reproductible : elle a ete corrigee la-bas
     * (`@if` remis A L'INTERIEUR du slot nomme). La garde est etendue ici parce
     * que T1312 ajoute DEUX blocs conditionnels de plus dans la zone exacte du
     * piege — le slot par defaut de `message-bubble`, qui traverse
     * `markdown()` et transforme donc tout balisage en texte.
     *
     * La sentinelle vise la forme ECHAPPEE : c'est elle, et elle seule, que
     * l'utilisateur verrait. Les commentaires HTML `<!--[if BLOCK]-->` de
     * Livewire, eux, sont normaux et invisibles.
     */
    #[DataProvider('aiModes')]
    public function test_no_livewire_block_marker_is_ever_readable_in_any_mode(string $mode, string $labelKey): void
    {
        $this->aiMessage($mode, 'Une reponse [S1].', $this->sources(2));

        $this->actingAs($this->owner);
        Livewire::test(LoopChat::class, ['loop' => $this->loop])
            ->assertSee(__($labelKey))
            ->assertDontSee('&lt;!--[if BLOCK]&gt;', false)
            ->assertDontSee('&lt;![endif]--&gt;', false)
            ->assertDontSee('&lt;!--[if ENDBLOCK]&gt;', false);
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    /** @param list<array<string, mixed>>|null $sources */
    private function aiMessage(string $mode, string $body = 'Une reponse IA.', ?array $sources = null): LoopMessage
    {
        return LoopMessage::create([
            'loop_id' => $this->loop->id,
            'sender_id' => null,
            'body' => $body,
            'type' => 'ai',
            'metadata' => array_filter([
                'ai_mode' => $mode,
                'sources' => $sources,
            ], static fn ($v): bool => $v !== null),
            'organization_id' => $this->loop->organization_id,
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function sources(int $count): array
    {
        return array_map(static fn (int $n): array => [
            'ref' => 'S'.$n,
            'title' => 'Document '.$n.'.md',
            'dossier_name' => 'Dossier de la Boucle',
            'excerpt' => 'Extrait du document '.$n.'.',
            'url' => 'https://test.laravel/source/'.$n,
        ], range(1, $count));
    }
}
