<?php

namespace Tests\Feature;

use App\Livewire\LoopChat;
use App\Models\Loop;
use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Models\User;
use App\Services\LoopService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * TASK-1475 — l'IA de la Boucle redevient visible sur mobile.
 *
 * ## D'ou vient ce manque
 *
 * TASK-1466 a retire le Shell global des Boucles, et c'etait juste : la Boucle
 * porte deja son IA, le Shell y ouvrait une seconde porte vers les memes
 * actions.
 *
 * Mais la mesure a 390 px, faite dans la foulee, montrait ensuite **zero
 * affordance IA visible** : la seule porte etait un bouton « Plus d'actions »
 * qui ne nomme pas l'IA, et qu'il fallait ouvrir pour la trouver. Sur desktop
 * l'action est dans la barre ; sur mobile elle avait quitte le champ de vision.
 *
 * ## Ce que ce bouton n'est pas
 *
 * Il n'ajoute AUCUNE capacite. Il actionne exactement le meme interrupteur que
 * la feuille (`toggleComposerEngine('ia')`), sur le MEME composant Livewire,
 * avec les memes gardes. Il ne monte pas le Shell global — l'invariant de
 * TASK-1466 tient, et ce fichier le mesure explicitement.
 *
 * ## Le piege evite, mesure avant livraison
 *
 * La couleur active etait d'abord ecrite `bg-[var(--bp-primary,#4f46e5)]` —
 * une valeur arbitraire Tailwind, absente du build. `getComputedStyle` rendait
 * `rgba(0,0,0,0)` : le bouton actif etait TRANSPARENT alors que la classe
 * figurait bien dans le HTML. La regle vit desormais dans une feuille locale.
 *
 * C'est la quatrieme fois de la nuit que ce motif apparait ; cette fois il a
 * ete vu avant le commit, en mesurant le style CALCULE et non la chaine.
 */
class TASK1475LoopMobileAiVisibleTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $member;

    private Loop $loop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create([
            'is_active' => true,
            'slug' => 'org-ux-mobile',
            'name' => 'Org UX Mobile',
        ]);

        OrganizationAiSetting::factory()->create([
            'organization_id' => $this->organization->id,
            'provider' => 'openai',
            'model' => 'gpt-4o-mini',
            'api_key' => 'sk-task1475',
        ]);

        $this->member = User::factory()->complete()->create([
            'organization_id' => $this->organization->id,
            'preferred_locale' => 'fr',
        ]);

        app()->instance('current_organization', $this->organization);

        $this->loop = (new LoopService)->createLoop($this->member, 'Boucle Mobile');

        config([
            'ai.chatloop.enabled' => true,
            'ai.fab.enabled' => true,
            'ai.shell.enabled' => true,
            'ai.providers.openai.driver' => 'openai',
            'ai.providers.openai.key' => 'platform-key',
        ]);

        Http::preventStrayRequests();
        Http::fake();
    }

    // =====================================================================
    // A. L'action est la, elle se nomme, et elle se VOIT
    // =====================================================================

    /**
     * TASK-1621 a change le MOYEN, pas la promesse.
     *
     * TASK-1475 avait ajoute un raccourci `data-engine-quick="ia"` dans le
     * champ de saisie parce que, sous 768 px, la rangee des modes etait
     * `hidden md:flex` : aucune affordance IA visible, la seule porte etant le
     * menu `+`, qui ne nomme pas l'IA.
     *
     * La rangee est devenue visible sur tous les formats, et le raccourci muet
     * a ete retire : deux surfaces pour un meme interrupteur, a vingt pixels
     * l'une de l'autre, dont l'une etait un glyphe muet — un membre a demande
     * en recette a quoi elle servait.
     *
     * TASK-1769 deplace le MOYEN une seconde fois, et la promesse ne bouge
     * toujours pas.
     *
     * Sous 768 px la rangee ne se rend plus : elle coutait 34 px au-dessus
     * d'un composeur qui en coutait deja 65, sur un ecran dont 27 % partait
     * en chrome. La porte nommee vers l'IA entre donc DANS la pilule du
     * composeur, a droite du « + ».
     *
     * Ce test ne verifie donc plus « la rangee n'est pas masquee » — ce serait
     * verifier un moyen. Il verifie ce qui a toujours compte : sur telephone,
     * une action IA NOMMEE est atteignable SANS ouvrir de feuille.
     */
    public function test_the_named_ai_action_is_visible_without_opening_a_sheet(): void
    {
        $html = $this->actingAs($this->member)->get($this->loopUrl())->assertOk()->getContent();

        // La porte de la pilule existe, et elle n'est pas masquee sous `md`.
        $this->assertSame(1, preg_match(
            '/<button[^>]*data-composer-ai-door[^>]*class="([^"]*)"/s', $html, $porte,
        ), 'la porte IA du composeur est rendue');
        $this->assertStringNotContainsString('hidden', $porte[1],
            'elle doit etre visible sur telephone : c\'est la seule porte nommee qui y reste');

        // Elle est NOMMEE : un mot visible, et son intitule complet en
        // `aria-label`. C'est tout l'ecart avec le glyphe muet de T1475.
        $this->assertSame(1, preg_match(
            '/<button[^>]*data-composer-ai-door.*?<\/button>/s', $html, $bloc,
        ));
        $this->assertStringContainsString(e(__('loops.ia_mode_label')), $bloc[0],
            'le mot « IA » doit etre VISIBLE dans le bouton, pas seulement en attribut');
        $this->assertStringContainsString(e(__('loops.ask_ai_button')), $bloc[0],
            'et l\'intitule complet doit y etre, pour le lecteur d\'ecran');
        $this->assertStringContainsString('data-engine-toggle="ia"', $bloc[0]);
        $this->assertStringContainsString('aria-pressed="false"', $bloc[0]);

        // Et elle n'est PAS derriere une feuille : la promesse est « sans
        // ouvrir quoi que ce soit ». Tout ce qui s'ouvre sur cette page vit
        // dans un `<template>` (feuille du « + », modales teleportees). On
        // retire donc TOUS les `<template>` et on verifie que la porte
        // survit — mesurer la position du premier d'entre eux ne dirait rien,
        // la page en porte plusieurs, dont certains AVANT le composeur.
        $horsFeuilles = preg_replace('/<template\b.*?<\/template>/s', '', $html);
        $this->assertStringContainsString('data-composer-ai-door', $horsFeuilles,
            'la porte doit vivre dans le composeur, pas dans la feuille du « + »');

        // La rangee, elle, est desormais une surface de BUREAU.
        $this->assertSame(1, preg_match(
            '/<div class="(hidden[^"]*overflow-x-auto[^"]*)"[^>]*x-data="\{ askOpen/', $html, $rangee,
        ), 'la rangee des modes est rendue, masquee sous md');
        $this->assertStringContainsString('md:flex', $rangee[1],
            'elle revient a partir de 768 px : le bureau ne perd rien');
    }

    /** Le raccourci muet a disparu, et rien ne le remplace en douce. */
    public function test_the_silent_shortcut_is_gone(): void
    {
        $html = $this->actingAs($this->member)->get($this->loopUrl())->assertOk()->getContent();

        $this->assertStringNotContainsString('data-engine-quick', $html);
        $this->assertStringNotContainsString('bp-loop-ai-quick', $html);
    }

    /**
     * Le bouton `+` reste la SEULE porte vers l'ajout d'image et « Qui peut
     * m'aider » sur mobile : il doit se VOIR. Il etait `text-gray-400` sur fond
     * clair et `dark:text-gray-500` sur fond sombre — invisible dans les deux
     * themes, au point qu'un membre a cru les modes supprimes.
     */
    public function test_the_sheet_button_has_a_real_contrast(): void
    {
        $html = $this->actingAs($this->member)->get($this->loopUrl())->assertOk()->getContent();

        $this->assertSame(1, preg_match(
            '/<button[^>]*aria-label="'.preg_quote(e(__('loops.composer_more_actions')), '/').'"[^>]*>/', $html, $bouton,
        ), 'le bouton du menu est rendu');

        $this->assertStringContainsString('bg-gray-100', $bouton[0], 'un fond, pas un glyphe gris sur gris');
        $this->assertStringNotContainsString('text-gray-400', $bouton[0]);
    }

    // =====================================================================
    // B. Aucune seconde autorite : le meme interrupteur
    // =====================================================================

    /**
     * Les deux surfaces (rangee et feuille) commandent le MEME etat. Si elles
     * divergeaient, l'utilisateur verrait deux boutons IA se contredire.
     */
    public function test_both_surfaces_drive_the_same_engine_state(): void
    {
        // L'etat vit dans `composerMode` : `engineActive` est une variable de
        // VUE derivee du mode, pas une propriete Livewire. Asserter
        // `engineActive.ia` rendait `null` — vrai pour une mauvaise raison si
        // l'assertion avait ete plus permissive.
        Livewire::actingAs($this->member)
            ->test(LoopChat::class, ['loop' => $this->loop])
            ->assertSet('composerMode', 'normal')
            ->call('toggleComposerEngine', 'ia')
            ->assertSet('composerMode', 'ia')
            ->call('toggleComposerEngine', 'ia')
            ->assertSet('composerMode', 'normal');
    }

    /**
     * Un interrupteur par SURFACE, et pas un de plus.
     *
     * TASK-1769 : l'IA en a trois, et chacune a sa raison d'etre — la rangee
     * (bureau), la feuille du « + » (telephone, liste complete), la porte de
     * la pilule (telephone, acces direct). Dossiers en garde deux : il n'a pas
     * de porte dediee dans la pilule, il s'arme depuis la feuille.
     *
     * Le compte est verifie parce qu'un quatrieme interrupteur serait la
     * rechute de T1621 : deux commandes pour un meme etat, a vingt pixels
     * l'une de l'autre.
     */
    public function test_each_engine_has_its_surfaces_and_no_more(): void
    {
        $html = $this->actingAs($this->member)->get($this->loopUrl())->assertOk()->getContent();

        $this->assertSame(3, substr_count($html, 'data-engine-toggle="ia"'),
            'rangee bureau + feuille mobile + porte de la pilule');
        $this->assertSame(2, substr_count($html, 'data-engine-toggle="dossiers"'),
            'rangee bureau + feuille mobile');
        $this->assertSame(1, substr_count($html, 'data-composer-ai-door'),
            'une seule porte dans la pilule');
    }

    // =====================================================================
    // C. L'invariant de TASK-1466 tient
    // =====================================================================

    public function test_no_global_shell_comes_back_with_it(): void
    {
        $this->actingAs($this->member)->get($this->loopUrl())
            ->assertOk()
            ->assertDontSee('data-ai-shell-panel', false)
            ->assertDontSee('data-ai-fab', false)
            ->assertDontSee('bp-open-ai-shell', false);
    }

    /** Et l'IA native de la page n'a pas bouge. */
    public function test_the_native_loop_ai_is_untouched(): void
    {
        $html = $this->actingAs($this->member)->get($this->loopUrl())->assertOk()->getContent();

        $this->assertStringContainsString('@bp-open-ask-ai.window', $html);
        $this->assertStringContainsString(e(__('loops.ask_ai_button')), $html);
    }

    private function loopUrl(): string
    {
        return route('organization.loops.show', ['organization' => $this->organization->slug, 'loop' => $this->loop->id]);
    }
}
