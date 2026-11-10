<?php

namespace Tests\Feature;

use App\Models\Loop;
use App\Models\LoopMessage;
use App\Models\Organization;
use App\Models\User;
use App\Services\LoopService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1801 correctif 05 — « Derniers messages », en page.
 *
 * Demande de Cyril, en deux temps :
 *
 * 1. Un bouton a la gauche de « Nouvelle » sur `/loops`, menant aux derniers
 *    messages des Boucles dont la personne est proprietaire/animateur/membre.
 * 2. « Prevois une page, c'est mieux qu'un pop-up » — et sur cette page,
 *    « c'est l'inverse de ce qu'on avait prevu » : la Boucle (visuel + nom)
 *    est mise en avant, pas l'auteur. Chaque Boucle montre SES propres 3
 *    derniers messages (avatar, date, heure), cliquables vers le message.
 *
 * Deux etats degrades explicites : aucune adhesion -> invitation a en
 * rejoindre une (lien vers `/loops`) ; adhesion sans aucun message nulle
 * part -> rien d'affiche.
 */
class TASK1801LoopsLatestMessagesTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();

        Organization::factory()->create(['is_active' => true, 'is_default' => true]);
        $this->org = Organization::factory()->create([
            'is_active' => true,
            'loops_enabled' => true,
            // Un membre simple ne peut PAS creer de Boucle : garde le bouton
            // « Nouvelle » absent pour lui, condition dont le bouton
            // « Derniers messages » doit rester independant.
            'members_can_create_loops' => false,
        ]);

        app()->instance('current_organization', $this->org);
    }

    private function loopsIndex(User $user): string
    {
        return $this->actingAs($user)
            ->get(route('organization.loops.index', ['organization' => $this->org->slug]))
            ->assertOk()
            ->getContent();
    }

    private function latestMessagesPage(User $user): string
    {
        return $this->actingAs($user)
            ->get(route('organization.loops.latest-messages', ['organization' => $this->org->slug]))
            ->assertOk()
            ->getContent();
    }

    /**
     * `created_at` n'est pas dans `$fillable` (LoopMessage) : passer par
     * `create()` le jetterait ou le laisserait a `now()`, et deux messages
     * crees a la meme microseconde rendraient l'ordre non deterministe
     * (lecon deja consignee du depot). On le pose APRES, hors assignation
     * de masse, avant la sauvegarde.
     */
    private function messageAt(Loop $loop, ?User $sender, string $body, string $type, \Illuminate\Support\Carbon $when, ?string $imagePath = null): LoopMessage
    {
        $message = new LoopMessage([
            'loop_id' => $loop->id,
            'organization_id' => $this->org->id,
            'sender_id' => $sender?->id,
            'body' => $body,
            'type' => $type,
            'image_path' => $imagePath,
        ]);
        $message->created_at = $when;
        $message->save();

        return $message;
    }

    public function test_the_button_on_loops_index_links_to_the_latest_messages_page_regardless_of_create_permission(): void
    {
        $owner = User::factory()->create(['organization_id' => $this->org->id]);
        $membre = User::factory()->create(['organization_id' => $this->org->id]);

        $service = new LoopService;
        $loop = $service->createLoop($owner, 'Boucle A');
        $service->addMemberByUserId($loop, $membre->id);

        // Boucles cachees par defaut : il en faut une 2e pour que `/loops`
        // affiche la liste au lieu de rediriger vers la Boucle unique.
        $service->createLoop($owner, 'Boucle B');

        $html = $this->loopsIndex($membre);

        $this->assertStringContainsString('data-loops-latest-messages-trigger', $html);
        $this->assertStringContainsString(
            'href="'.route('organization.loops.latest-messages', ['organization' => $this->org->slug]).'"',
            $html,
        );
        // `members_can_create_loops = false` : ce membre ne voit pas
        // « Nouvelle » — le bouton « Derniers messages » n'en depend pas.
        $this->assertStringNotContainsString('data-loops-new-mobile', $html);
    }

    public function test_the_page_groups_messages_by_loop_with_its_name_and_up_to_three_entries_each(): void
    {
        $moi = User::factory()->create(['organization_id' => $this->org->id]);
        $autreMembre = User::factory()->create(['organization_id' => $this->org->id]);

        $service = new LoopService;
        $loopA = $service->createLoop($moi, 'Boucle des mentions');
        $loopB = $service->createLoop($moi, 'Boucle du jardin');
        $service->addMemberByUserId($loopB, $autreMembre->id, 'facilitator');
        // Une 3e Boucle a laquelle « moi » n'appartient PAS : son message ne
        // doit JAMAIS sortir sur cette page.
        $loopEtrangere = $service->createLoop($autreMembre, 'Boucle etrangere');

        $a1 = $this->messageAt($loopA, $moi, 'Plus ancien message de A', 'user', now()->subMinutes(9));
        $a2 = $this->messageAt($loopA, $moi, 'Second message de A', 'user', now()->subMinutes(8));
        $a3 = $this->messageAt($loopA, $moi, 'Troisieme message de A', 'user', now()->subMinutes(7));
        $a4 = $this->messageAt($loopA, $moi, 'Dernier message de A', 'user', now()->subMinutes(6));
        $dernierB = $this->messageAt($loopB, $autreMembre, 'Dernier mot sur le jardin', 'user', now());

        // Hors perimetre : Boucle etrangere (pas de membre) et message IA
        // (aucun humain derriere) — tous deux plus RECENTS que tout le reste,
        // pour prouver qu'ils sont exclus par la regle et non par la date.
        $this->messageAt($loopEtrangere, $autreMembre, 'Message d une Boucle etrangere', 'user', now()->addMinute());
        $this->messageAt($loopA, null, 'Reponse IA', 'ai', now()->addMinutes(2));

        $html = $this->latestMessagesPage($moi);

        // Boucle A : SES 3 plus recents — a4, a3, a2 — jamais a1 (le 4e).
        $this->assertStringContainsString($a4->body, $html);
        $this->assertStringContainsString($a3->body, $html);
        $this->assertStringContainsString($a2->body, $html);
        $this->assertStringNotContainsString($a1->body, $html);

        // Boucle B : son seul message.
        $this->assertStringContainsString($dernierB->body, $html);

        $this->assertStringNotContainsString('Message d une Boucle etrangere', $html);
        $this->assertStringNotContainsString('Reponse IA', $html);

        // La Boucle est mise en avant : son NOM apparait (section groupee),
        // et son visuel (`<x-loops.cover>`) est present pour chaque section.
        $this->assertStringContainsString($loopA->name, $html);
        $this->assertStringContainsString($loopB->name, $html);
        $this->assertSame(2, substr_count($html, 'data-loops-latest-messages-section'));

        // Chaque ligne de message mene DIRECTEMENT au message dans la Boucle.
        $this->assertStringContainsString(
            route('organization.loops.show', [
                'organization' => $this->org->slug,
                'loop' => $loopA->slug ?: $loopA->id,
                'message' => $a4->id,
            ]),
            $html,
        );
        $this->assertStringContainsString($dernierB->created_at->isoFormat('D MMM, HH:mm'), $html);

        // 4 lignes au total : 3 pour A (a2,a3,a4) + 1 pour B.
        $this->assertSame(4, substr_count($html, 'data-loops-latest-message-row'));
    }

    public function test_a_user_with_no_loop_membership_sees_the_join_prompt(): void
    {
        $owner = User::factory()->create(['organization_id' => $this->org->id]);
        $service = new LoopService;
        $service->createLoop($owner, 'Boucle A');
        $service->createLoop($owner, 'Boucle B');

        $sansBoucle = User::factory()->create(['organization_id' => $this->org->id]);

        $html = $this->latestMessagesPage($sansBoucle);

        // L'apostrophe de `n'êtes`/`d'aucune` ressort HTML-echappee
        // (`&#039;`) dans le rendu Blade : `e()` reproduit la meme
        // transformation cote attendu.
        $this->assertStringContainsString(e(__('loops.latest_messages_empty_before')), $html);
        $this->assertStringContainsString(
            'href="'.route('organization.loops.index', ['organization' => $this->org->slug]).'"',
            $html,
        );
        $this->assertStringNotContainsString('data-loops-latest-message-row', $html);
    }

    public function test_a_member_whose_loops_have_no_message_sees_nothing_extra(): void
    {
        $moi = User::factory()->create(['organization_id' => $this->org->id]);
        $service = new LoopService;
        $service->createLoop($moi, 'Boucle silencieuse A');
        $service->createLoop($moi, 'Boucle silencieuse B');

        $html = $this->latestMessagesPage($moi);

        $this->assertStringNotContainsString(__('loops.latest_messages_empty_before'), $html);
        $this->assertStringNotContainsString('data-loops-latest-message-row', $html);
        $this->assertStringNotContainsString('data-loops-latest-messages-section', $html);
    }

    /**
     * Demande de Cyril : une image SANS commentaire ne doit pas rendre la
     * ligne vide — sa vignette (et un libelle neutre) tiennent lieu de
     * contenu.
     */
    public function test_an_image_only_message_shows_its_thumbnail_instead_of_an_empty_row(): void
    {
        $moi = User::factory()->create(['organization_id' => $this->org->id]);
        $service = new LoopService;
        $loop = $service->createLoop($moi, 'Boucle avec photo');

        $sansCommentaire = $this->messageAt($loop, $moi, '', 'user', now(), 'loop-images/sans-commentaire.jpg');
        $avecCommentaire = $this->messageAt($loop, $moi, 'Regardez ce coucher de soleil', 'user', now()->subMinute(), 'loop-images/avec-commentaire.jpg');

        $html = $this->latestMessagesPage($moi);

        $this->assertStringContainsString($sansCommentaire->imageUrl(), $html);
        $this->assertStringContainsString(e(__('loops.latest_messages_image_only')), $html);

        $this->assertStringContainsString($avecCommentaire->imageUrl(), $html);
        $this->assertStringContainsString('Regardez ce coucher de soleil', $html);
    }

    /**
     * TASK-1801 correctif 07 — demande de Cyril : sur smartphone, la barre
     * mobile doit afficher « Boucles » (pas le repli « BouclePro », cette
     * route etant absente de `$levelOneTitles` et de la chaine
     * `$routeTitle` de `mobile-topbar.blade.php`), et le lien « ← Boucles »
     * propre a cette page doit disparaitre SUR SMARTPHONE SEULEMENT — la
     * barre mobile porte deja sa propre fleche de retour (inchangee).
     */
    public function test_the_mobile_topbar_shows_boucles_and_keeps_its_own_back_arrow(): void
    {
        $owner = User::factory()->create(['organization_id' => $this->org->id]);
        $service = new LoopService;
        $service->createLoop($owner, 'Boucle A');
        $service->createLoop($owner, 'Boucle B');

        $html = $this->latestMessagesPage($owner);

        $this->assertStringContainsString(
            '<h1 class="truncate text-lg font-semibold text-gray-900 dark:text-gray-100 tracking-tight">'.__('navigation.loops').'</h1>',
            $html,
        );
        $this->assertStringContainsString(
            'data-bp-topbar-back="'.route('organization.loops.index', ['organization' => $this->org->slug]).'"',
            $html,
        );
    }

    /**
     * TASK-1801 correctif 08 — demande de Cyril : cette page est une
     * SOUS-PAGE de « Boucles », elle doit donc porter la MEME barre de
     * navigation qu'une sous-page sur BUREAU (`<x-page-topbar>`, TASK-1749)
     * — pas un `<h1>` et un lien de retour faits a la main. Sur smartphone,
     * rien ne change : ce composant est `hidden md:flex` par construction,
     * la barre mobile (correctif 07) reste la seule a s'afficher.
     *
     * Precision de Cyril (suite) — la barre de sous-page porte « Boucles »,
     * la section PARENTE (meme etiquette que le back-label et que la barre
     * mobile), pas « Derniers messages ». Le titre PROPRE a cette page
     * redevient un `<h1>` dans le corps, mais seulement sur bureau.
     */
    public function test_the_desktop_topbar_matches_other_loop_sub_pages(): void
    {
        $owner = User::factory()->create(['organization_id' => $this->org->id]);
        $service = new LoopService;
        $service->createLoop($owner, 'Boucle A');
        $service->createLoop($owner, 'Boucle B');

        $html = $this->latestMessagesPage($owner);

        $this->assertStringContainsString('data-page-topbar', $html);
        $this->assertStringContainsString(
            'href="'.route('organization.loops.index', ['organization' => $this->org->slug]).'" data-page-topbar-back',
            $html,
        );
        $this->assertStringContainsString(
            '<span class="truncate text-sm font-medium text-gray-900 dark:text-gray-100" data-page-topbar-title>'.__('loops.index_title').'</span>',
            $html,
        );

        // L'ancien lien « ← Boucles » fait a la main a disparu : la barre
        // de sous-page le remplace, desktop comme mobile.
        $this->assertStringNotContainsString('&larr;', $html);
    }

    /**
     * TASK-1801 correctif 08 (suite, precision de Cyril) — le titre PROPRE
     * a cette page, « Derniers messages », reste visible dans le corps de
     * la page (un `<h1>`), mais SEULEMENT sur bureau : sur smartphone la
     * barre mobile (correctif 07) l'a deja dit a sa maniere.
     */
    public function test_the_page_keeps_its_own_title_as_a_desktop_only_heading(): void
    {
        $owner = User::factory()->create(['organization_id' => $this->org->id]);
        $service = new LoopService;
        $service->createLoop($owner, 'Boucle A');

        $html = $this->latestMessagesPage($owner);

        $this->assertStringContainsString(
            '<h1 class="hidden md:block mb-6 text-2xl font-bold tracking-tight text-gray-900 dark:text-gray-100">',
            $html,
        );
        $this->assertStringContainsString(__('loops.latest_messages_title'), $html);
    }
}
