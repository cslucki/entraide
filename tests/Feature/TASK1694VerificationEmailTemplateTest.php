<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\SystemEmailTemplate;
use App\Models\User;
use App\Notifications\VerifyEmailWithTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * TASK-1694, bug D — l'email de confirmation vient d'un gabarit modifiable.
 *
 * ## Ce qui manquait
 *
 * Il partait du gabarit Laravel EN DUR : impossible d'en changer un mot sans
 * toucher au code, et invisible dans `/admin/system-email-templates`.
 *
 * ## GLOBAL, arbitrage de Cyril
 *
 * « On ne va pas créer un email pour chaque organisation. » Un test mesure
 * donc explicitement qu'une ligne par tenant ne prend PAS le dessus — sinon
 * la promesse « global » serait fausse sans que rien ne rougisse.
 */
class TASK1694VerificationEmailTemplateTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $membre;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create([
            'slug' => 'org-1694d',
            'is_active' => true,
        ]);

        $this->membre = User::factory()->create([
            'organization_id' => $this->organization->id,
            'email_verified_at' => null,
        ]);
    }

    /** LE manque comblé : le gabarit existe, global, dans les deux langues. */
    public function test_the_template_exists_globally_in_both_locales(): void
    {
        foreach (['fr', 'en'] as $locale) {
            $gabarit = SystemEmailTemplate::query()
                ->where('slug', VerifyEmailWithTemplate::SLUG)
                ->whereNull('organization_id')
                ->where('locale', $locale)
                ->first();

            $this->assertNotNull($gabarit, "le gabarit {$locale} doit exister");
            $this->assertTrue((bool) $gabarit->enabled);
            $this->assertNotSame('', trim((string) $gabarit->subject));
            $this->assertStringContainsString('{{ url }}', $gabarit->content_html,
                'sans le lien, l\'email ne sert a rien');
        }
    }

    /** C'est bien CETTE notification que l'inscription déclenche. */
    public function test_the_account_sends_the_template_notification(): void
    {
        Notification::fake();

        $this->membre->sendEmailVerificationNotification();

        Notification::assertSentTo($this->membre, VerifyEmailWithTemplate::class);
    }

    /** Le sujet rendu vient du gabarit, pas du framework. */
    public function test_the_rendered_subject_comes_from_the_template(): void
    {
        app()->setLocale('fr');

        $message = (new VerifyEmailWithTemplate)->toMail($this->membre);

        $this->assertSame('Confirmez votre adresse email', $message->subject);
    }

    /** Et l'anglais rend son propre sujet. */
    public function test_english_renders_its_own_subject(): void
    {
        app()->setLocale('en');

        $message = (new VerifyEmailWithTemplate)->toMail($this->membre);

        $this->assertSame('Confirm your email address', $message->subject);
    }

    /**
     * GLOBAL veut dire global : une ligne par tenant ne prend pas le dessus.
     *
     * Sans cette mesure, ajouter une recherche par Organization passerait
     * inaperçu et la promesse « un seul email pour toutes » serait fausse.
     */
    public function test_a_tenant_row_does_not_win(): void
    {
        SystemEmailTemplate::query()->create([
            'slug' => VerifyEmailWithTemplate::SLUG,
            'locale' => 'fr',
            'organization_id' => $this->organization->id,
            'name' => 'Variante tenant',
            'subject' => 'SUJET_DU_TENANT_1694D',
            'content_html' => '<p>{{ url }}</p>',
            'variables' => ['name', 'url'],
            'enabled' => true,
        ]);

        app()->setLocale('fr');

        $this->assertSame(
            'Confirmez votre adresse email',
            (new VerifyEmailWithTemplate)->toMail($this->membre)->subject,
        );
    }

    /**
     * Si le gabarit disparaît, l'email part quand même.
     *
     * Un email de confirmation qui n'arrive pas rend le compte inutilisable :
     * le repli sur le gabarit du framework est une garantie, pas une politesse.
     */
    public function test_a_missing_template_falls_back_to_the_framework(): void
    {
        SystemEmailTemplate::query()->where('slug', VerifyEmailWithTemplate::SLUG)->delete();

        $message = (new VerifyEmailWithTemplate)->toMail($this->membre);

        $this->assertNotSame('', trim((string) $message->subject));
        $this->assertNotSame('Confirmez votre adresse email', $message->subject);
    }

    /**
     * La durée du lien reste calculée à UN seul endroit.
     *
     * La notification étend celle du framework : elle n'a pas recopié la
     * construction de l'URL, donc `auth.verification.expire` gouverne les deux.
     */
    public function test_the_signed_url_still_honours_the_configured_expiry(): void
    {
        $this->assertTrue(
            is_subclass_of(VerifyEmailWithTemplate::class, \Illuminate\Auth\Notifications\VerifyEmail::class),
            'recopier la construction de l\'url creerait une seconde verite sur la duree',
        );

        $this->assertSame(1440, (int) config('auth.verification.expire'));
    }
}
