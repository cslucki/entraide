<?php

namespace Tests\Feature;

use App\Models\Loop;
use App\Models\LoopEvent;
use App\Models\LoopMember;
use App\Models\Organization;
use App\Models\User;
use App\Services\LoopPermissionSettingsService;
use App\Services\Loops\LoopCardCompositionService;
use App\Services\Loops\LoopEventService;
use App\Support\Loops\LoopTypeRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * TASK-1706 — organiser une rencontre DEPUIS l'agenda.
 *
 * Cyril, §4 : « sur l'agenda on a les evenements qui ont ete crees via les
 * boucles. Mais ceci n'est pas pratique, car il faut donc passer par une
 * boucle. Pas de bouton pour creer un evenement. »
 *
 * ## Une decision produit ECRITE, revisee et non contredite
 *
 * `LoopEventAgendaController` portait : « La page ne CREE rien — organiser se
 * fait dans la Boucle. » Cette TASK la renverse, explicitement, dans le
 * docblock lui-meme.
 *
 * ## Ce que la mesure a corrige dans la demande
 *
 * La demande disait « on selectionne la boucle dont animateur ou
 * proprietaire ». Or `config/loop_permissions.php` accorde `events.create` a
 * **tout membre actif** : un membre simple cree deja des rencontres DEPUIS la
 * Boucle. Restreindre l'agenda aurait donne deux reponses au meme geste selon
 * l'ecran. **Cyril a tranche : refleter la regle de la Boucle.**
 *
 * Il a aussi tranche un cas qu'il n'avait pas nomme — etre proprietaire d'une
 * Boucle dont la card « Evenements » est desactivee — en lui donnant son
 * propre message : se replier sur celui du role afficherait un texte FAUX pour
 * cette personne, qui a bien le role.
 *
 * ## Ce qui n'a PAS change, et ne pouvait pas changer
 *
 * `loop_events.loop_id` est **NOT NULL**. « Creer pour toute l'organisation »
 * ne designe donc pas un Evenement sans Boucle — cela n'existe pas — mais sa
 * VISIBILITE, que `canPublishToOrganization()` accorde deja.
 */
class TASK1706AgendaCreateEventTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $orgAdmin;

    private User $proprietaire;

    private User $membre;

    private Loop $boucle;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orgAdmin = User::factory()->create();
        $this->org = Organization::factory()->create([
            'is_active' => true,
            'slug' => 'org-1706',
            'admin_id' => $this->orgAdmin->id,
            'loops_enabled' => true,
        ]);
        $this->orgAdmin->update(['organization_id' => $this->org->id]);

        $this->proprietaire = $this->membreDeLOrg();
        $this->membre = $this->membreDeLOrg();

        $this->boucle = $this->creerBoucle('Boucle Agenda');
        $this->rejoindre($this->boucle, $this->proprietaire, 'owner');
        $this->rejoindre($this->boucle, $this->membre, 'member');

        app()->instance('current_organization', $this->org);
    }

    private function membreDeLOrg(): User
    {
        return User::factory()->create(['organization_id' => $this->org->id, 'email_verified_at' => now()]);
    }

    private function creerBoucle(string $nom, string $visibilite = 'public'): Loop
    {
        $loop = Loop::create([
            'organization_id' => $this->org->id,
            'name' => $nom,
            'slug' => Str::slug($nom).'-'.uniqid(),
            'type' => 'general',
            'status' => 'active',
            'visibility' => $visibilite,
            'created_by' => $this->orgAdmin->id,
        ]);

        app(LoopTypeRegistry::class)->applyPreset($loop);

        return $loop;
    }

    private function rejoindre(Loop $loop, User $user, string $role): void
    {
        LoopMember::create([
            'organization_id' => $loop->organization_id,
            'loop_id' => $loop->id,
            'user_id' => $user->id,
            'role' => $role,
            'status' => 'active',
        ]);
    }

    private function agenda(User $qui): string
    {
        app()->forgetInstance('current_organization');
        app()->instance('current_organization', $this->org);

        return $this->actingAs($qui)->get('/org/'.$this->org->slug.'/agenda')->assertOk()->getContent();
    }

    private function refus(string $html): ?string
    {
        return preg_match('/data-agenda-create-refused="([a-z_]*)"/', $html, $m) ? $m[1] : null;
    }

    // ── 1. Le bouton, et les trois refus ────────────────────────────────────

    /** Celui qui peut organiser recoit un LIEN, pas un message. */
    public function test_celui_qui_peut_organiser_recoit_un_lien(): void
    {
        $html = $this->agenda($this->proprietaire);

        $this->assertSame(
            1,
            preg_match('/<a[^>]*data-agenda-create[\s>]/', $html),
            'Le bouton de creation n\'est pas un lien pour qui peut organiser.'
        );
        $this->assertNull($this->refus($html), 'Un message de refus est affiche a qui peut organiser.');
    }

    /**
     * Un membre SIMPLE le peut aussi.
     *
     * C'est la decision de Cyril, et elle va contre la lettre de sa demande :
     * `events.create` est accorde a tout membre actif, et un membre simple cree
     * deja des rencontres depuis la Boucle. L'agenda ne doit pas etre plus
     * strict que la Boucle.
     */
    public function test_un_membre_simple_peut_organiser_comme_dans_la_boucle(): void
    {
        $html = $this->agenda($this->membre);

        $this->assertNull($this->refus($html), 'Un membre simple est refuse ici alors que la Boucle l\'autorise.');

        // Et la mesure est faite a la SOURCE : le service dit bien oui.
        $this->assertTrue(app(LoopEventService::class)->canCreate($this->membre, $this->boucle));
    }

    /** Membre d'aucune Boucle : le premier refus, avec le chemin pour en sortir. */
    public function test_membre_d_aucune_boucle(): void
    {
        $solitaire = $this->membreDeLOrg();

        $html = $this->agenda($solitaire);

        $this->assertSame('no_loop', $this->refus($html));
        $this->assertStringContainsString(e(__('events.no_loop_refus')), $html);
        $this->assertSame(
            1,
            preg_match('/data-agenda-create-cta[^>]*>/', $html),
            'Le message n\'offre aucun chemin vers les Boucles.'
        );
    }

    /**
     * Proprietaire, mais la card « Evenements » est desactivee.
     *
     * Le TROISIEME refus, celui que la demande ne nommait pas. Sans lui, cette
     * personne lirait « vous devez avoir le role de proprietaire » alors
     * qu'elle l'a — un message faux, qui ne dit pas quoi faire.
     */
    public function test_proprietaire_mais_la_card_evenements_est_desactivee(): void
    {
        app(LoopCardCompositionService::class)->disable($this->boucle, 'core.events');

        $html = $this->agenda($this->proprietaire);

        $this->assertSame('card', $this->refus($html));
        $this->assertStringContainsString(e(__('events.card_refus')), $html);
    }

    /**
     * Membre d'une Boucle qui a la card, mais la permission est retiree.
     *
     * C'est le refus de ROLE au sens strict : l'outil est la, le droit non.
     */
    public function test_la_permission_de_creer_est_retiree(): void
    {
        foreach (['owner', 'facilitator', 'member'] as $role) {
            app(LoopPermissionSettingsService::class)
                ->setOrganization($this->org, 'general', $role, 'events.create', false);
        }

        $html = $this->agenda($this->membre);

        $this->assertSame('role', $this->refus($html));
        $this->assertStringContainsString(e(__('events.role_refus')), $html);
    }

    // ── 2. Le formulaire ────────────────────────────────────────────────────

    private function formulaire(User $qui): \Illuminate\Testing\TestResponse
    {
        app()->forgetInstance('current_organization');
        app()->instance('current_organization', $this->org);

        return $this->actingAs($qui)->get('/org/'.$this->org->slug.'/agenda/nouveau');
    }

    public function test_le_formulaire_ne_propose_que_les_boucles_permises(): void
    {
        // Une seconde Boucle, dont cette personne n'est PAS membre.
        $ailleurs = $this->creerBoucle('Boucle sans moi');

        $html = $this->formulaire($this->membre)->assertOk()->getContent();

        $this->assertStringContainsString($this->boucle->id, $html);
        $this->assertStringNotContainsString(
            $ailleurs->id,
            $html,
            'Le selecteur propose une Boucle dont la personne n\'est pas membre : le serveur refuserait ensuite.'
        );
    }

    /**
     * Le droit est REEVALUE sur l'URL, pas seulement a l'affichage du bouton.
     *
     * Une adresse tapee a la main doit rencontrer la meme reponse qu'un clic.
     */
    public function test_l_url_tapee_a_la_main_est_refusee_de_la_meme_facon(): void
    {
        $solitaire = $this->membreDeLOrg();

        $this->formulaire($solitaire)
            ->assertRedirect()
            ->assertSessionHas('error', __('events.no_loop_refus'));
    }

    // ── 3. L'enregistrement ─────────────────────────────────────────────────

    private function enregistrer(User $qui, array $donnees = []): \Illuminate\Testing\TestResponse
    {
        app()->forgetInstance('current_organization');
        app()->instance('current_organization', $this->org);

        return $this->actingAs($qui)->post('/org/'.$this->org->slug.'/agenda', array_merge([
            'loop_id' => $this->boucle->id,
            'title' => 'Atelier Qualiopi',
            'format' => 'in_person',
            'location' => 'Salle B',
            'starts_at' => now()->addWeek()->format('Y-m-d\TH:i'),
            'timezone' => 'Europe/Paris',
        ], $donnees));
    }

    public function test_la_rencontre_est_creee_dans_la_boucle_choisie(): void
    {
        $this->enregistrer($this->membre)->assertRedirect();

        $event = LoopEvent::where('loop_id', $this->boucle->id)->first();

        $this->assertNotNull($event, 'La rencontre n\'a pas ete creee.');
        $this->assertSame('Atelier Qualiopi', $event->title);
        $this->assertSame((string) $this->membre->id, (string) $event->created_by);
        $this->assertSame((string) $this->org->id, (string) $event->organization_id);
        $this->assertSame(LoopEvent::VISIBILITY_LOOP, $event->visibility);
    }

    /** Et elle apparait ensuite dans l'agenda — la boucle est bouclee. */
    public function test_la_rencontre_apparait_ensuite_dans_l_agenda(): void
    {
        $this->enregistrer($this->membre)->assertRedirect();

        $this->assertStringContainsString('Atelier Qualiopi', $this->agenda($this->membre));
    }

    /**
     * Une Boucle d'un AUTRE tenant ne passe pas.
     *
     * La policy ne la verrait meme pas ; c'est la resolution a la main, avec sa
     * frontiere de tenant ecrite, qui refuse avant tout appel au service.
     */
    public function test_une_boucle_d_un_autre_tenant_est_refusee(): void
    {
        $autreOrg = Organization::factory()->create(['is_active' => true, 'slug' => 'org-1706-bis']);
        $ailleurs = Loop::create([
            'organization_id' => $autreOrg->id,
            'name' => 'Boucle etrangere',
            'slug' => 'boucle-etrangere-'.uniqid(),
            'type' => 'general', 'status' => 'active', 'visibility' => 'public',
            'created_by' => $this->orgAdmin->id,
        ]);

        $this->enregistrer($this->membre, ['loop_id' => $ailleurs->id])->assertNotFound();

        $this->assertSame(0, LoopEvent::where('loop_id', $ailleurs->id)->count());
    }

    /**
     * Une Boucle du MEME tenant dont on n'est pas membre est refusee.
     *
     * Le 404 ne s'applique pas ici — la Boucle existe dans ce tenant. C'est le
     * SERVICE qui refuse, et c'est bien ainsi : le controleur n'ecrit aucune
     * regle d'autorisation.
     */
    public function test_une_boucle_dont_on_n_est_pas_membre_est_refusee(): void
    {
        $ailleurs = $this->creerBoucle('Boucle sans moi');

        $this->enregistrer($this->membre, ['loop_id' => $ailleurs->id])->assertRedirect();

        $this->assertSame(0, LoopEvent::where('loop_id', $ailleurs->id)->count());
    }

    // ── 4. « Pour toute l'organisation » ────────────────────────────────────

    /**
     * L'admin de l'Organization peut rendre la rencontre visible par tous.
     *
     * Cyril : « concernant l'admin de l'organisation scopee, il peut creer des
     * evenements pour toute l'organisation ». `loop_events.loop_id` etant NOT
     * NULL, cela designe la VISIBILITE — il choisit toujours une Boucle.
     */
    public function test_l_admin_de_l_organisation_publie_a_toute_l_organisation(): void
    {
        $this->rejoindre($this->boucle, $this->orgAdmin, 'member');

        $this->enregistrer($this->orgAdmin, ['visibility' => 'organization'])->assertRedirect();

        $event = LoopEvent::where('loop_id', $this->boucle->id)->firstOrFail();

        $this->assertSame(LoopEvent::VISIBILITY_ORGANIZATION, $event->visibility);
    }

    /** Et le choix lui est propose a l'ecran. */
    public function test_le_choix_de_portee_est_propose_a_qui_y_a_droit(): void
    {
        $this->rejoindre($this->boucle, $this->orgAdmin, 'member');

        $this->assertSame(
            1,
            preg_match('/data-agenda-visibility[\s>]/', $this->formulaire($this->orgAdmin)->assertOk()->getContent()),
            'Le choix de portee n\'est pas propose a l\'admin de l\'Organization.'
        );
    }

    /**
     * Une Boucle PRIVEE ne remonte jamais, quel que soit le rang.
     *
     * C'est la regle que `canPublishToOrganization()` pose en premier, et elle
     * ne se negocie pas. Sans ce volet, le nouveau chemin d'ecriture serait
     * celui par lequel on la contourne.
     */
    public function test_une_boucle_privee_ne_remonte_jamais(): void
    {
        $privee = $this->creerBoucle('Boucle privee', 'private');
        $this->rejoindre($privee, $this->orgAdmin, 'owner');

        $this->enregistrer($this->orgAdmin, [
            'loop_id' => $privee->id,
            'visibility' => 'organization',
        ])->assertRedirect();

        $this->assertSame(
            0,
            LoopEvent::where('loop_id', $privee->id)->where('visibility', LoopEvent::VISIBILITY_ORGANIZATION)->count(),
            'Une Boucle privee a ete publiee a toute l\'Organization.'
        );
    }

    /** Un membre simple ne se voit PAS proposer la portee Organization. */
    public function test_le_membre_simple_ne_choisit_pas_la_portee(): void
    {
        $this->assertSame(
            0,
            preg_match('/data-agenda-visibility[\s>]/', $this->formulaire($this->membre)->assertOk()->getContent()),
            'Le choix de portee est propose a un membre simple.'
        );
    }
}
