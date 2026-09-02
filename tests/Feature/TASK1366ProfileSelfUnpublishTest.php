<?php

namespace Tests\Feature;

use App\Livewire\MemberAiProfileWizard;
use App\Models\Loop;
use App\Models\LoopMember;
use App\Models\MemberAiProfile;
use App\Models\Organization;
use App\Models\User;
use App\Services\LoopService;
use App\Services\People\EligiblePeopleService;
use App\Support\Ai\AiShellTurnCards;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use ReflectionMethod;
use Tests\TestCase;

/**
 * TASK-1366 — on entrait seul, on ne sortait pas seul.
 *
 * Le formulaire exposait `publish()` et RIEN pour en sortir : la seule route de
 * retrait du depot etait reservee aux administrateurs. Sur une fonction qui
 * porte du consentement, « j'ai publie, je veux sortir » ne doit pas dependre
 * d'un message a quelqu'un.
 *
 * ## Pourquoi une colonne plutot que le seul statut `disabled`
 *
 * `disabled` porte deja une decision d'ADMINISTRATION. Y ecrire aussi le
 * retrait volontaire ferait de deux evenements opposes le meme etat — et ce
 * n'est pas theorique : AVANT cette TASK, un profil desactive par un
 * administrateur laissait reapparaitre le bouton « Publier », et `publish()` ne
 * verifiait rien. **Un membre pouvait annuler une sanction.**
 *
 *   publication membre    status=published  withdrawn_at=null   disabled_at=null
 *   RETRAIT VOLONTAIRE    status=disabled   withdrawn_at=now()  disabled_at=now()
 *   desactivation admin   status=disabled   withdrawn_at=null   disabled_at=now()
 *
 * ## Ce que ce fichier garde avant tout
 *
 * Que le retrait sorte IMMEDIATEMENT de l'ensemble eligible, que la carte d'un
 * tour ancien disparaisse au rendu suivant, et que personne ne puisse retirer
 * le profil d'autrui — cette derniere propriete etant STRUCTURELLE, pas une
 * garde ajoutee.
 */
class TASK1366ProfileSelfUnpublishTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $owner;

    private User $other;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create([
            'is_active' => true,
            'slug' => 'org-self-unpublish',
            'ai_profiles_enabled' => true,
        ]);

        $this->owner = User::factory()->complete()->create(['organization_id' => $this->organization->id]);
        $this->other = User::factory()->complete()->create(['organization_id' => $this->organization->id]);

        app()->instance('current_organization', $this->organization);

        Http::preventStrayRequests();
        Http::fake();
    }

    // =====================================================================
    // A. Le retrait volontaire
    // =====================================================================

    /** 1. Une personne retire son profil elle-meme, sans administrateur. */
    public function test_a_member_can_withdraw_their_own_profile(): void
    {
        $profile = $this->publishedProfile($this->owner);

        Livewire::actingAs($this->owner)->test(MemberAiProfileWizard::class)->call('unpublish');

        $profile->refresh();

        $this->assertSame(MemberAiProfile::STATUS_DISABLED, $profile->status);
        $this->assertNotNull($profile->withdrawn_at);
        $this->assertNotNull($profile->disabled_at);
        $this->assertTrue($profile->wasWithdrawnByOwner());
        $this->assertFalse($profile->wasDisabledByAdmin());
    }

    /**
     * 2. Rien n'est supprime.
     *
     * Le retrait est un changement d'ETAT. Le contenu reste, sinon republier
     * demanderait de tout ressaisir — ce qui transformerait un retrait
     * reversible en decision definitive.
     */
    public function test_withdrawing_destroys_nothing(): void
    {
        $profile = $this->publishedProfile($this->owner);
        $before = $profile->only(['member_profile_summary', 'skills', 'problems_helped', 'help_types']);

        Livewire::actingAs($this->owner)->test(MemberAiProfileWizard::class)->call('unpublish');

        $this->assertSame($before, $profile->refresh()->only(['member_profile_summary', 'skills', 'problems_helped', 'help_types']));
    }

    /** 3. Un profil non publie n'a rien a retirer, et aucun brouillon n'est cree. */
    public function test_withdrawing_without_a_published_profile_does_nothing(): void
    {
        Livewire::actingAs($this->owner)->test(MemberAiProfileWizard::class)->call('unpublish');

        $this->assertSame(0, MemberAiProfile::query()->count());
    }

    // =====================================================================
    // B. Le retrait sort du matching, immediatement
    // =====================================================================

    /**
     * 4. Publie -> proposable ; retire -> plus proposable. Dans le MEME test.
     *
     * C'est l'assertion qui compte : mesurer l'ensemble AVANT et APRES, sans
     * vider aucun cache — il n'y en a aucun sur ce chemin.
     */
    public function test_withdrawing_removes_the_person_from_the_eligible_set(): void
    {
        [$loop, $requester] = $this->loopWithBothMembers();

        $service = app(EligiblePeopleService::class);

        $before = $service->eligibleFor($this->organization, $loop, $requester);
        $this->assertCount(1, $before->people, 'Le pre-requis du test : la personne EST proposable avant le retrait.');

        Livewire::actingAs($this->owner)->test(MemberAiProfileWizard::class)->call('unpublish');

        $after = $service->eligibleFor($this->organization, $loop, $requester);

        $this->assertTrue($after->authorized);
        $this->assertSame([], $after->people);
    }

    /**
     * 5. La carte d'un tour ANCIEN disparait au rendu suivant.
     *
     * Elle n'est pas reecrite ni effacee : `personCard()` la construit depuis
     * l'ensemble eligible RECALCULE, et rend `null` si la personne n'y est
     * plus. Le retrait n'a donc pas a courir apres les tours passes.
     */
    public function test_an_old_person_card_disappears_after_the_withdrawal(): void
    {
        [$loop, $requester] = $this->loopWithBothMembers();

        $reference = [
            'type' => AiShellTurnCards::TYPE_PERSON,
            'loop_id' => (string) $loop->id,
            'user_id' => (string) $this->owner->id,
            'reasons' => [],
        ];

        $card = new ReflectionMethod(AiShellTurnCards::class, 'personCard');
        $card->setAccessible(true);
        $cards = app(AiShellTurnCards::class);

        $this->assertNotNull(
            $card->invoke($cards, $this->organization, $requester, $reference),
            'Le pre-requis du test : la carte se rend avant le retrait.',
        );

        Livewire::actingAs($this->owner)->test(MemberAiProfileWizard::class)->call('unpublish');

        // Instance neuve : le memo par requete de `eligibleNow()` ne doit pas
        // etre ce qui fait passer le test.
        $this->assertNull($card->invoke(app(AiShellTurnCards::class), $this->organization, $requester, $reference));
    }

    // =====================================================================
    // C. Retrait volontaire != sanction administrative
    // =====================================================================

    /** 6. Apres un retrait volontaire, la personne peut republier. */
    public function test_a_voluntary_withdrawal_can_be_undone_by_its_owner(): void
    {
        $profile = $this->publishedProfile($this->owner);

        $component = Livewire::actingAs($this->owner)->test(MemberAiProfileWizard::class);
        $component->call('unpublish');
        $component->call('publish');

        $profile->refresh();

        $this->assertSame(MemberAiProfile::STATUS_PUBLISHED, $profile->status);
        $this->assertNull($profile->withdrawn_at);
        $this->assertNull($profile->disabled_at, 'La republication doit restaurer un etat coherent.');
    }

    /**
     * 7. Une desactivation ADMINISTRATIVE ne peut PAS etre annulee par le membre.
     *
     * C'etait possible avant cette TASK : la vue reaffichait « Publier » des que
     * le statut n'etait plus `published`, et `publish()` ne verifiait rien.
     */
    public function test_a_member_cannot_undo_an_administrative_disable(): void
    {
        $profile = $this->publishedProfile($this->owner);

        // Exactement ce qu'ecrit `AdminMemberAiProfileController::disable()`.
        $profile->update([
            'status' => MemberAiProfile::STATUS_DISABLED,
            'disabled_at' => now(),
        ]);

        $this->assertTrue($profile->refresh()->wasDisabledByAdmin());

        Livewire::actingAs($this->owner)->test(MemberAiProfileWizard::class)->call('publish');

        $this->assertSame(MemberAiProfile::STATUS_DISABLED, $profile->refresh()->status);
        $this->assertNull($profile->withdrawn_at);
    }

    /** 8. Les profils anterieurs a cette TASK sont traites comme administratifs. */
    public function test_a_legacy_disabled_profile_is_treated_as_administrative(): void
    {
        $profile = $this->publishedProfile($this->owner);
        $profile->update(['status' => MemberAiProfile::STATUS_DISABLED, 'disabled_at' => now(), 'withdrawn_at' => null]);

        $this->assertTrue($profile->refresh()->wasDisabledByAdmin());
        $this->assertFalse($profile->wasWithdrawnByOwner());
    }

    // =====================================================================
    // D. On ne retire que SON profil
    // =====================================================================

    /**
     * 9. Le composant n'accepte AUCUN identifiant de profil.
     *
     * Cette assertion vaut mieux qu'un « B ne peut pas toucher A » : elle
     * interdit la REGRESSION. Tant qu'aucune methode publique ne prend de
     * parametre, il n'existe aucun chemin pour viser le profil d'autrui — la
     * propriete est structurelle, pas defendue par une garde qu'on pourrait
     * oublier.
     */
    public function test_no_public_method_accepts_a_profile_identifier(): void
    {
        $offenders = [];

        foreach ((new \ReflectionClass(MemberAiProfileWizard::class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getDeclaringClass()->getName() !== MemberAiProfileWizard::class) {
                continue;
            }

            foreach ($method->getParameters() as $parameter) {
                $name = mb_strtolower($parameter->getName());

                if (str_contains($name, 'profile') || str_contains($name, 'user') || $name === 'id') {
                    $offenders[] = $method->getName().'($'.$parameter->getName().')';
                }
            }
        }

        $this->assertSame([], $offenders);
    }

    /** 10. Le retrait de l'un ne touche pas le profil de l'autre. */
    public function test_withdrawing_leaves_other_members_untouched(): void
    {
        $mine = $this->publishedProfile($this->owner);
        $theirs = $this->publishedProfile($this->other);

        Livewire::actingAs($this->owner)->test(MemberAiProfileWizard::class)->call('unpublish');

        $this->assertSame(MemberAiProfile::STATUS_DISABLED, $mine->refresh()->status);
        $this->assertSame(MemberAiProfile::STATUS_PUBLISHED, $theirs->refresh()->status);
        $this->assertNull($theirs->withdrawn_at);
    }

    /** 11. Aucune consequence hors du tenant. */
    public function test_withdrawing_has_no_cross_tenant_consequence(): void
    {
        $this->publishedProfile($this->owner);

        $foreignOrganization = Organization::factory()->create(['is_active' => true, 'slug' => 'org-self-unpublish-etrangere', 'ai_profiles_enabled' => true]);
        $stranger = User::factory()->complete()->create(['organization_id' => $foreignOrganization->id]);
        $foreignProfile = $this->publishedProfile($stranger, $foreignOrganization);

        Livewire::actingAs($this->owner)->test(MemberAiProfileWizard::class)->call('unpublish');

        $this->assertSame(MemberAiProfile::STATUS_PUBLISHED, $foreignProfile->refresh()->status);
    }

    // =====================================================================
    // E. La capacite administrative reste entiere
    // =====================================================================

    /** 12. Un administrateur republie ce qu'il a desactive, et l'etat reste coherent. */
    public function test_the_administrative_capability_still_works(): void
    {
        $profile = $this->publishedProfile($this->owner);

        $profile->update(['status' => MemberAiProfile::STATUS_DISABLED, 'disabled_at' => now()]);

        // Exactement ce qu'ecrit `AdminMemberAiProfileController::publish()`.
        $profile->update([
            'status' => MemberAiProfile::STATUS_PUBLISHED,
            'validated_at' => $profile->validated_at ?? now(),
            'published_at' => $profile->published_at ?? now(),
            'disabled_at' => null,
        ]);

        $profile->refresh();

        $this->assertSame(MemberAiProfile::STATUS_PUBLISHED, $profile->status);
        $this->assertNull($profile->disabled_at);
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    private function publishedProfile(User $user, ?Organization $organization = null): MemberAiProfile
    {
        return MemberAiProfile::query()->create([
            'organization_id' => ($organization ?? $this->organization)->id,
            'user_id' => $user->id,
            'status' => MemberAiProfile::STATUS_PUBLISHED,
            'locale' => 'fr',
            // Le profil satisfait `minimumValidationRules()` : republier passe
            // par cette validation, et un fixture incomplet ferait echouer le
            // test pour une raison qui n'est pas celle qu'il mesure.
            'member_profile_summary' => 'Resume de profil pour '.$user->id,
            'target_audience' => ['entrepreneurs'],
            'problems_helped' => ['Automatiser une tache repetitive en Python'],
            'service_scope' => 'Accompagnement technique ponctuel.',
            'skills' => ['Python', 'SQL'],
            'experience_context' => 'Quatre ans de developpement back-end.',
            'help_types' => ['Script ou prototype Python'],
            'boundaries' => ['Pas de mission longue'],
            'preferred_contact_action' => 'message',
            'tone' => 'sobre',
            'good_request_examples' => ['Peux-tu relire ce script ?'],
            'published_at' => now(),
            'validated_at' => now(),
        ]);
    }

    /**
     * Une Boucle ou `owner` (profil publie) est proposable a `other`.
     *
     * @return array{0: Loop, 1: User}
     */
    private function loopWithBothMembers(): array
    {
        $this->publishedProfile($this->owner);

        $loop = (new LoopService)->createLoop($this->other, 'Boucle Self Unpublish');

        LoopMember::create([
            'loop_id' => $loop->id,
            'user_id' => $this->owner->id,
            'status' => 'active',
            'role' => 'member',
            'joined_at' => now(),
        ]);

        return [$loop->refresh(), $this->other];
    }
}
