<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\SystemEmailTemplate;
use App\Models\User;
use App\Notifications\NewMessageReceived;
use App\Support\Email\SystemEmailTemplateResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1694, bug M — une seule autorité choisit un gabarit d'email système.
 *
 * ## Le défaut, mesuré
 *
 * Neuf endroits du code résolvaient un gabarit. **Sept ne regardaient que les
 * lignes du tenant** : une ligne « Global » leur était invisible.
 * L'administration en affichait donc que le produit ne lisait jamais.
 *
 * C'est le bug G constaté en grand — il y avait été corrigé dans *un* fichier.
 *
 * ## Pourquoi ce bug est un préalable, et pas un confort
 *
 * Cyril veut n'avoir que des gabarits « Global ». Sans ce correctif, supprimer
 * les lignes par Organization ferait taire **toute** la base : tout
 * retomberait en silence sur les replis Blade, dont cinq sont en français pur.
 */
class TASK1694SystemEmailTemplateResolverTest extends TestCase
{
    use RefreshDatabase;

    private const SLUG = 'new_message';

    private Organization $organization;

    private User $membre;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create([
            'slug' => 'org-1694m',
            'locale' => 'fr',
            'is_active' => true,
        ]);

        $this->membre = User::factory()->create([
            'organization_id' => $this->organization->id,
        ]);

        SystemEmailTemplate::query()->where('slug', self::SLUG)->delete();
    }

    private function resolver(): SystemEmailTemplateResolver
    {
        return app(SystemEmailTemplateResolver::class);
    }

    private function gabarit(array $attributs): SystemEmailTemplate
    {
        return SystemEmailTemplate::query()->create($attributs + [
            'slug' => self::SLUG,
            'name' => 'Gabarit 1694M',
            'content_html' => '<p>corps</p>',
            'variables' => ['name'],
            'enabled' => true,
        ]);
    }

    /**
     * LE défaut corrigé : une ligne Global répond à un membre qui a une
     * Organization.
     */
    public function test_a_global_row_answers_a_member_of_an_organization(): void
    {
        $this->gabarit(['organization_id' => null, 'locale' => 'fr', 'subject' => 'GLOBAL_FR']);

        $resolu = $this->resolver()->resolve(self::SLUG, $this->organization->id, 'fr');

        $this->assertSame('GLOBAL_FR', $resolu?->subject);
    }

    /** Et la ligne du tenant garde la priorité : le Global ne la recouvre jamais. */
    public function test_a_tenant_row_still_wins(): void
    {
        $this->gabarit(['organization_id' => null, 'locale' => 'fr', 'subject' => 'GLOBAL_FR']);
        $this->gabarit(['organization_id' => $this->organization->id, 'locale' => 'fr', 'subject' => 'TENANT_FR']);

        $this->assertSame(
            'TENANT_FR',
            $this->resolver()->resolve(self::SLUG, $this->organization->id, 'fr')?->subject,
        );
    }

    /**
     * La langue du tenant passe avant la langue demandée en Global.
     *
     * Une Organization qui a écrit son email en français le garde pour un
     * membre qui lit l'anglais — son texte vaut mieux qu'un texte générique.
     */
    public function test_the_tenant_default_locale_comes_before_the_global_one(): void
    {
        $this->gabarit(['organization_id' => $this->organization->id, 'locale' => 'fr', 'subject' => 'TENANT_FR']);
        $this->gabarit(['organization_id' => null, 'locale' => 'en', 'subject' => 'GLOBAL_EN']);

        $this->assertSame(
            'TENANT_FR',
            $this->resolver()->resolve(self::SLUG, $this->organization->id, 'en')?->subject,
        );
    }

    /** La ligne historique SANS langue reste atteignable, en dernier. */
    public function test_the_legacy_row_without_locale_is_the_last_resort(): void
    {
        $this->gabarit(['organization_id' => null, 'locale' => null, 'subject' => 'GLOBAL_SANS_LANGUE']);

        $this->assertSame(
            'GLOBAL_SANS_LANGUE',
            $this->resolver()->resolve(self::SLUG, $this->organization->id, 'en')?->subject,
        );
    }

    /**
     * Désactivé veut dire ABSENT, pas « masque le cran suivant ».
     *
     * C'est ce qui rend sûre la création « désactivée par défaut » de l'écran
     * d'administration (TASK-1681) : une surcharge décochée ne doit jamais
     * priver personne de l'email.
     */
    public function test_a_disabled_row_does_not_hide_the_next_rung(): void
    {
        $this->gabarit([
            'organization_id' => $this->organization->id,
            'locale' => 'fr',
            'subject' => 'TENANT_DESACTIVE',
            'enabled' => false,
        ]);
        $this->gabarit(['organization_id' => null, 'locale' => 'fr', 'subject' => 'GLOBAL_FR']);

        $this->assertSame(
            'GLOBAL_FR',
            $this->resolver()->resolve(self::SLUG, $this->organization->id, 'fr')?->subject,
        );
    }

    /**
     * Défaut trouvé en lisant les sept copies : un gabarit « Global » n'est
     * jamais celui d'une AUTRE Organization.
     *
     * Pour une personne sans Organization, elles faisaient
     * `where('slug')->enabled()->first()` — **sans filtre de tenant**. La
     * première ligne venue gagnait, c'est-à-dire le gabarit d'une Organization
     * quelconque, celle que l'ordre des lignes désignait.
     */
    public function test_a_member_without_organization_never_reads_another_tenants_row(): void
    {
        $autre = Organization::factory()->create(['slug' => 'org-1694m-autre', 'is_active' => true]);
        $this->gabarit(['organization_id' => $autre->id, 'locale' => 'fr', 'subject' => 'FUITE']);

        $this->assertNull($this->resolver()->resolve(self::SLUG, null, 'fr'));
    }

    /** Et rien du tout veut dire `null` : l'appelant retombe sur son Blade. */
    public function test_nothing_at_all_means_null(): void
    {
        $this->assertNull($this->resolver()->resolve(self::SLUG, $this->organization->id, 'fr'));
    }

    /**
     * Le diagnostic distingue deux causes opposées.
     *
     * `template_missing` dit « personne n'a jamais écrit ce gabarit » ;
     * `template_disabled` dit « il existe, quelqu'un l'a décoché ». Deux
     * gestes de réparation différents, donc deux mots différents dans le
     * journal.
     */
    public function test_the_diagnosis_tells_disabled_from_missing(): void
    {
        $this->assertSame(
            'template_missing',
            $this->resolver()->diagnoseAbsence(self::SLUG, $this->organization->id, 'fr'),
        );

        $this->gabarit(['organization_id' => null, 'locale' => 'fr', 'subject' => 'X', 'enabled' => false]);

        $this->assertSame(
            'template_disabled',
            $this->resolver()->diagnoseAbsence(self::SLUG, $this->organization->id, 'fr'),
        );
    }

    /**
     * Et le gain se voit DANS un vrai envoi, pas seulement dans le résolveur.
     *
     * `NewMessageReceived` était l'une des sept copies. Le test mesure que son
     * email porte désormais le sujet de la ligne Global — c'est-à-dire que le
     * câblage a bien été fait, et pas seulement la classe écrite.
     */
    public function test_a_real_notification_now_reads_the_global_row(): void
    {
        $this->gabarit(['organization_id' => null, 'locale' => 'fr', 'subject' => 'GLOBAL_VU_PAR_LA_NOTIF']);

        $this->membre->forceFill(['preferred_locale' => 'fr'])->save();

        $transaction = \App\Models\Transaction::factory()->create([
            'organization_id' => $this->organization->id,
            'buyer_id' => $this->membre->id,
        ]);

        $message = \App\Models\Message::factory()->create([
            'transaction_id' => $transaction->id,
            'sender_id' => $transaction->seller_id,
        ]);

        $mail = (new NewMessageReceived($transaction, $message))->toMail($this->membre);

        $this->assertStringContainsString('GLOBAL_VU_PAR_LA_NOTIF', (string) $mail->subject);
    }
}
