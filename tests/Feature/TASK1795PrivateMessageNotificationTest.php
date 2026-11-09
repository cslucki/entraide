<?php

namespace Tests\Feature;

use App\Models\Message;
use App\Models\Organization;
use App\Models\Service;
use App\Models\SystemEmailTemplate;
use App\Models\Transaction;
use App\Models\User;
use App\Notifications\NewMessageReceived;
use Database\Seeders\SystemEmailTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * TASK-1795 correctifs 1 et 2 — la notification d'un message PRIVE.
 *
 * ## Les deux defauts, qui n'en font qu'un
 *
 * Une discussion privee est portee par une ligne `transactions` sans service,
 * sans demande et a zero point : le modele est partage avec l'entraide, et
 * `Transaction::isDirectConversation()` les distingue DEJA. Faute de consulter
 * cette regle, la notification faisait deux fois la meme erreur :
 *
 * 1. elle envoyait `new_message`, dont le texte parle d'un « echange » et
 *    interpole `{{ transaction_title }}` — une variable qui vaut « Echange »
 *    sur une discussion privee, un mot invente par un repli ;
 * 2. elle construisait `route('messages.show', ...)`, la surface COURTE, ou
 *    `ResolveUrlOrganization` lie l'Organization PAR DEFAUT. Le scope global
 *    `BelongsToOrganizationScope` filtre alors `transactions`, le liage de
 *    modele ne trouve rien, et la route rend 404 — avant la policy.
 *
 * C'est le second qui se voyait : « le lien ammene vers une erreur 404 »
 * (Cyril, 09/11). Les deux ont la meme cause — le contexte d'envoi avait ete
 * pris pour le contexte de la conversation.
 */
class TASK1795PrivateMessageNotificationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $ofsh;

    private Organization $main;

    private User $expediteur;

    private User $destinataire;

    protected function setUp(): void
    {
        parent::setUp();

        // `main` est l'Organization PAR DEFAUT : c'est elle que la surface
        // courte lie, et c'est donc elle qui fabriquait la 404. Elle doit
        // exister pour que le test reproduise la situation reelle.
        $this->main = Organization::factory()->create([
            'slug' => 'main',
            'is_active' => true,
            'is_default' => true,
        ]);

        $this->ofsh = Organization::factory()->create([
            'slug' => 'ofsh',
            'name' => 'OFSH',
            'is_active' => true,
            'is_default' => false,
        ]);

        $this->expediteur = User::factory()->create([
            'organization_id' => $this->ofsh->id,
            'first_name' => 'Cyril',
            'name' => 'SLUCKI',
            'email_verified_at' => now(),
        ]);

        $this->destinataire = User::factory()->create([
            'organization_id' => $this->ofsh->id,
            'first_name' => 'Mélanie',
            'name' => 'Scoubart',
            'email_verified_at' => now(),
        ]);

        app()->instance('current_organization', $this->ofsh);

        Http::preventStrayRequests();
        Http::fake();
    }

    /** Une DISCUSSION PRIVEE : ni service, ni demande, zero point. */
    private function conversationPrivee(): Transaction
    {
        return Transaction::factory()->create([
            'organization_id' => $this->ofsh->id,
            'buyer_id' => $this->expediteur->id,
            'seller_id' => $this->destinataire->id,
            'service_id' => null,
            'request_id' => null,
            'points_proposed' => 0,
            'status' => 'pending',
        ]);
    }

    /** Un ECHANGE d'entraide : un service et des points. */
    private function echangeDEntraide(): Transaction
    {
        $service = Service::factory()->create([
            'organization_id' => $this->ofsh->id,
            'user_id' => $this->destinataire->id,
            'title' => 'Coup de main sur un devis',
        ]);

        return Transaction::factory()->create([
            'organization_id' => $this->ofsh->id,
            'buyer_id' => $this->expediteur->id,
            'seller_id' => $this->destinataire->id,
            'service_id' => $service->id,
            'request_id' => null,
            'points_proposed' => 30,
            'status' => 'pending',
        ]);
    }

    private function message(Transaction $transaction, string $body = 'Bonjour, une question rapide.'): Message
    {
        return Message::query()->create([
            'transaction_id' => $transaction->id,
            'sender_id' => $this->expediteur->id,
            'organization_id' => $this->ofsh->id,
            'body' => $body,
        ]);
    }

    /** Le sujet reellement rendu par la notification, gabarit interpole compris. */
    private function sujetRendu(Transaction $transaction, Message $message): string
    {
        return (new NewMessageReceived($transaction, $message))
            ->toMail($this->destinataire)
            ->subject;
    }

    private function htmlRendu(Transaction $transaction, Message $message): string
    {
        $mail = (new NewMessageReceived($transaction, $message))->toMail($this->destinataire);

        return (string) $mail->render();
    }

    // ---------------------------------------------------------------------
    // 1 et 2 — le GABARIT choisi depend de la nature de la conversation
    // ---------------------------------------------------------------------

    public function test_un_message_prive_choisit_le_gabarit_private_message_received(): void
    {
        $this->seed(SystemEmailTemplateSeeder::class);

        $transaction = $this->conversationPrivee();
        $this->assertTrue($transaction->isDirectConversation(), 'precondition : la conversation est bien directe');

        // Le sujet du gabarit `private_message_received` est la SEULE preuve
        // qui distingue les deux chemins sans re-deriver leur condition.
        $this->assertSame(
            'Message privé de Cyril SLUCKI',
            $this->sujetRendu($transaction, $this->message($transaction)),
        );
    }

    public function test_un_message_d_echange_conserve_le_gabarit_new_message(): void
    {
        $this->seed(SystemEmailTemplateSeeder::class);

        $transaction = $this->echangeDEntraide();
        $this->assertFalse($transaction->isDirectConversation(), 'precondition : la conversation est un echange');

        $this->assertSame(
            'Nouveau message de SLUCKI',
            $this->sujetRendu($transaction, $this->message($transaction)),
        );
    }

    // ---------------------------------------------------------------------
    // 3 — prenom et nom reels, et un sujet lisible quand le prenom manque
    // ---------------------------------------------------------------------

    public function test_le_sujet_porte_le_prenom_et_le_nom_reels_de_l_expediteur(): void
    {
        $this->seed(SystemEmailTemplateSeeder::class);

        $transaction = $this->conversationPrivee();
        $sujet = $this->sujetRendu($transaction, $this->message($transaction));

        $this->assertStringContainsString('Cyril', $sujet);
        $this->assertStringContainsString('SLUCKI', $sujet);
        $this->assertStringNotContainsString('{{', $sujet, 'aucune variable brute dans un sujet d\'email');
    }

    public function test_un_prenom_absent_ne_laisse_ni_variable_brute_ni_double_espace(): void
    {
        $this->seed(SystemEmailTemplateSeeder::class);

        $this->expediteur->forceFill(['first_name' => null])->save();

        $transaction = $this->conversationPrivee();
        $sujet = $this->sujetRendu($transaction, $this->message($transaction)->fresh());

        $this->assertSame('Message privé de SLUCKI', $sujet);
        $this->assertStringNotContainsString('  ', $sujet, 'pas d\'espace disgracieux');
        $this->assertStringNotContainsString('{{', $sujet);
    }

    /**
     * Un gabarit d'entraide qui NOMME le prenom le recoit, lui aussi.
     *
     * Defaut mesure sur les donnees PROD du 09/11/2026 : le gabarit
     * `new_message` FR avait ete reecrit depuis l'administration en « Nouveau
     * message de {{ sender_first_name }} {{ sender_name }} », mais ce chemin ne
     * fournissait pas `sender_first_name`. `interpolateSubject()` laisse alors
     * le jeton BRUT, et le journal d'emails du jumeau porte un sujet reellement
     * envoye « Nouveau message de {{ sender_first_name }} SLUCKI ».
     *
     * Le gabarit est du contenu administrable : c'est la variable qui manquait.
     */
    public function test_un_gabarit_d_entraide_qui_nomme_le_prenom_ne_laisse_pas_de_variable_brute(): void
    {
        $this->seed(SystemEmailTemplateSeeder::class);

        // On reproduit l'edition faite en production, sur la ligne Globale FR.
        SystemEmailTemplate::query()
            ->where('slug', 'new_message')
            ->where('locale', 'fr')
            ->whereNull('organization_id')
            ->update(['subject' => 'Nouveau message de {{ sender_first_name }} {{ sender_name }}']);

        $transaction = $this->echangeDEntraide();
        $sujet = $this->sujetRendu($transaction, $this->message($transaction));

        $this->assertSame('Nouveau message de Cyril SLUCKI', $sujet);
        $this->assertStringNotContainsString('{{', $sujet);
    }

    // ---------------------------------------------------------------------
    // 4 — les gabarits FR/EN sont GLOBAUX et administrables
    // ---------------------------------------------------------------------

    public function test_les_gabarits_fr_et_en_sont_globaux_actives_et_administrables(): void
    {
        $this->seed(SystemEmailTemplateSeeder::class);

        foreach (['fr', 'en'] as $locale) {
            $gabarit = SystemEmailTemplate::query()
                ->where('slug', 'private_message_received')
                ->where('locale', $locale)
                ->whereNull('organization_id')
                ->first();

            $this->assertNotNull($gabarit, "le gabarit $locale doit exister en Global");
            $this->assertTrue((bool) $gabarit->enabled, "le gabarit $locale doit etre actif par defaut");
        }

        $superAdmin = User::factory()->create([
            'organization_id' => $this->main->id,
            'is_admin' => true,
            'email_verified_at' => now(),
        ]);

        // Administrable veut dire JOIGNABLE depuis l'ecran, pas seulement
        // present en base : la liste doit le nommer.
        $this->actingAs($superAdmin)
            ->get(route('admin.system-email-templates'))
            ->assertOk()
            ->assertSee('private_message_received');
    }

    /**
     * Le catalogue du seeder et la migration jumelle disent le MEME texte.
     *
     * Les deux points d'entree sont obligatoires — l'un sert les bases
     * fraiches, l'autre les bases deja installees — et une divergence entre eux
     * ne se verrait que sur la moitie du parc. On la mesure, on ne la relit
     * pas a l'oeil.
     */
    public function test_le_catalogue_du_seeder_et_la_migration_portent_le_meme_texte(): void
    {
        $definition = SystemEmailTemplateSeeder::definitions()['private_message_received'];

        $migration = file_get_contents(
            database_path('migrations/2026_11_09_160000_add_private_message_received_email_template.php')
        );

        foreach ([
            $definition['subject_fr'],
            $definition['subject_en'],
            $definition['content_html_fr'],
            $definition['content_html_en'],
        ] as $texte) {
            $this->assertStringContainsString(
                str_replace("'", "\\'", $texte),
                $migration,
                'la migration doit porter exactement le texte du catalogue',
            );
        }
    }

    // ---------------------------------------------------------------------
    // 5 et 8 — l'URL porte l'Organization de la CONVERSATION, gabarit ET repli
    // ---------------------------------------------------------------------

    public function test_le_lien_de_l_email_porte_l_organization_de_la_conversation(): void
    {
        $this->seed(SystemEmailTemplateSeeder::class);

        $transaction = $this->conversationPrivee();
        $html = $this->htmlRendu($transaction, $this->message($transaction));

        // L'URL attendue est demandee au ROUTEUR, jamais reconstruite a la
        // main : un test qui re-derive l'URL qu'il verifie ne verifie rien.
        $attendue = route('organization.messages.show', [
            'organization' => 'ofsh',
            'transaction' => $transaction->id,
        ]);

        $this->assertStringContainsString($attendue, $html);
        $this->assertStringNotContainsString(
            route('messages.show', ['transaction' => $transaction->id]),
            $html,
            'la surface courte est precisement celle qui rend 404',
        );
    }

    public function test_le_repli_blade_ne_reintroduit_pas_l_url_fautive(): void
    {
        // Aucun gabarit en base : la notification tombe sur son repli Blade,
        // le dernier cran du resolveur. C'est ce chemin qui fabriquait l'URL
        // lui-meme, et qui aurait survecu a un correctif pose uniquement sur
        // le chemin gabarit.
        SystemEmailTemplate::query()->delete();

        foreach ([$this->conversationPrivee(), $this->echangeDEntraide()] as $transaction) {
            $html = $this->htmlRendu($transaction, $this->message($transaction));

            $this->assertStringContainsString(
                route('organization.messages.show', [
                    'organization' => 'ofsh',
                    'transaction' => $transaction->id,
                ]),
                $html,
            );
            $this->assertStringNotContainsString(
                route('messages.show', ['transaction' => $transaction->id]),
                $html,
            );
        }
    }

    // ---------------------------------------------------------------------
    // 6 et 7 — le lien ABOUTIT pour le destinataire, et pour personne d'autre
    // ---------------------------------------------------------------------

    public function test_le_destinataire_ouvre_la_conversation_depuis_le_lien_de_l_email(): void
    {
        $this->seed(SystemEmailTemplateSeeder::class);

        $transaction = $this->conversationPrivee();
        $html = $this->htmlRendu($transaction, $this->message($transaction));

        // L'URL est EXTRAITE de l'email rendu, pas reconstruite : c'est le lien
        // reel qui doit repondre 200, pas un lien equivalent.
        $this->assertSame(1, preg_match(
            '#https?://[^"\s]+/org/ofsh/messages/'.preg_quote($transaction->id, '#').'#',
            $html,
            $trouve,
        ), 'le lien tenant doit figurer dans l\'email');

        $this->actingAs($this->destinataire)
            ->get($trouve[0])
            ->assertOk();
    }

    public function test_un_tiers_n_atteint_pas_la_conversation(): void
    {
        $transaction = $this->conversationPrivee();
        $this->message($transaction);

        $url = route('organization.messages.show', [
            'organization' => 'ofsh',
            'transaction' => $transaction->id,
        ]);

        // Membre de la MEME Organization, mais etranger a la conversation :
        // c'est le cas qu'un scope tenant seul ne refuserait pas.
        $tiers = User::factory()->create([
            'organization_id' => $this->ofsh->id,
            'email_verified_at' => now(),
        ]);

        $reponse = $this->actingAs($tiers)->get($url);
        $this->assertContains($reponse->status(), [403, 404], 'un tiers ne lit pas la conversation');

        // Membre d'une AUTRE Organization : refus aussi, et sans rien divulguer.
        $etranger = User::factory()->create([
            'organization_id' => $this->main->id,
            'email_verified_at' => now(),
        ]);

        $reponse = $this->actingAs($etranger)->get($url);
        $this->assertContains($reponse->status(), [403, 404], 'aucun acces cross-tenant');
    }
}
