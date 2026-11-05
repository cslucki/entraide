<?php

namespace Tests\Feature;

use App\Models\Loop;
use App\Models\MemberNotification;
use App\Models\Organization;
use App\Models\User;
use App\Support\Notifications\NotificationCatalogue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1761 point 6 — « bien verifier que quand le SuperAdmin ajoute un membre
 * depuis son interface, l'email est envoye a la personne ajoutee ».
 *
 * ## C'est une MESURE, pas un developpement
 *
 * La chaine semblait complete a la lecture :
 * `AdminLoopController::addMember()` -> `LoopService::addMemberByUserId()` ->
 * `notifierAjoutDirect()`, la cle `loop.member_added` declare `EMAIL` actif par
 * defaut, et le gabarit `loop_member_added` existe en base, actif, dans les
 * deux locales.
 *
 * Mais **des pieces qui existent ne font pas une chaine qui fonctionne** — on
 * l'a paye assez souvent. Ce test exerce le GESTE REEL depuis `/admin` et
 * mesure ce qui en sort.
 */
class TASK1761AjoutMembreEmailTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $superAdmin;

    private Loop $boucle;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create([
            'slug' => 'org-1761', 'is_active' => true, 'locale' => 'fr',
        ]);
        app()->instance('current_organization', $this->organization);

        $this->superAdmin = User::factory()->create(['is_admin' => true]);

        $proprietaire = User::factory()->create(['organization_id' => $this->organization->id]);

        $this->boucle = Loop::factory()->create([
            'organization_id' => $this->organization->id,
            'created_by' => $proprietaire->id,
        ]);
    }

    /**
     * 🔴 LE GESTE REEL : ajouter depuis `/admin` notifie, et l'EMAIL est
     * declare.
     */
    public function test_ajouter_un_membre_depuis_admin_notifie_la_personne_ajoutee(): void
    {
        $ajoute = User::factory()->create(['organization_id' => $this->organization->id]);

        $this->actingAs($this->superAdmin)
            ->post(route('admin.loops.members.add', $this->boucle), ['user_id' => $ajoute->id])
            ->assertSessionHasNoErrors();

        $notification = MemberNotification::query()->withoutGlobalScopes()
            ->where('recipient_id', $ajoute->id)
            ->where('notification_key', NotificationCatalogue::LOOP_MEMBER_ADDED)
            ->first();

        $this->assertNotNull($notification,
            'Aucune notification n\'a ete emise pour la personne ajoutee.');

        // L'EMAIL doit etre declare au catalogue, actif par defaut.
        //
        // `channelsFor()` rend une liste de NOMS (`array_keys`), pas la carte
        // des definitions : le defaut se lit par `channelDefault()`, « l'unique
        // source de verite » selon le catalogue lui-meme.
        $this->assertContains(
            NotificationCatalogue::CHANNEL_EMAIL,
            NotificationCatalogue::channelsFor(NotificationCatalogue::LOOP_MEMBER_ADDED),
            'Le canal EMAIL n\'est pas declare pour `loop.member_added`.'
        );

        $this->assertTrue(
            NotificationCatalogue::channelDefault(
                NotificationCatalogue::LOOP_MEMBER_ADDED,
                NotificationCatalogue::CHANNEL_EMAIL
            ),
            'Le canal EMAIL n\'est pas actif par defaut : la personne ajoutee ne recevrait rien.'
        );
    }

    /**
     * Et le gabarit d'email EXISTE, dans les DEUX locales, actif.
     *
     * Sans lui, la livraison retomberait sur un repli — ou sur rien. C'est la
     * piece que la lecture du code ne montre pas, parce qu'elle vit en base.
     */
    public function test_le_gabarit_d_email_existe_et_est_actif_dans_les_deux_langues(): void
    {
        $gabarits = \Illuminate\Support\Facades\DB::table('system_email_templates')
            ->where('slug', 'loop_member_added')
            ->get(['locale', 'enabled']);

        $this->assertGreaterThanOrEqual(2, $gabarits->count(),
            'Le gabarit `loop_member_added` doit exister dans les deux locales.');

        foreach (['fr', 'en'] as $locale) {
            $g = $gabarits->firstWhere('locale', $locale);
            $this->assertNotNull($g, "Gabarit manquant en « {$locale} ».");
            $this->assertTrue((bool) $g->enabled, "Gabarit desactive en « {$locale} ».");
        }
    }

    /**
     * 🔴 LA RECIPROQUE — l'acteur n'est pas notifie de son propre geste.
     *
     * Sans elle, un test qui compte « au moins une notification » resterait
     * vert si le produit notifiait tout le monde, SuperAdmin compris.
     */
    public function test_le_superadmin_n_est_pas_notifie_de_son_propre_geste(): void
    {
        $ajoute = User::factory()->create(['organization_id' => $this->organization->id]);

        $this->actingAs($this->superAdmin)
            ->post(route('admin.loops.members.add', $this->boucle), ['user_id' => $ajoute->id]);

        $this->assertSame(0, MemberNotification::query()->withoutGlobalScopes()
            ->where('recipient_id', $this->superAdmin->id)->count(),
            'Le SuperAdmin a ete notifie de son propre ajout.');
    }
}
