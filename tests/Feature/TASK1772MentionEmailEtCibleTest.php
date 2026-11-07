<?php

namespace Tests\Feature;

use App\Livewire\LoopChat;
use App\Models\Loop;
use App\Models\LoopMessage;
use App\Models\MemberNotification;
use App\Models\Organization;
use App\Models\SystemEmailTemplate;
use App\Models\User;
use App\Services\LoopService;
use App\Support\Notifications\NotificationCatalogue;
use App\Support\Notifications\NotificationEmailDeliverer;
use App\Support\Notifications\NotificationTargetResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * TASK-1772 — l'email administrable et la cible du clic.
 *
 * Ces deux chemins portent les pièges les plus silencieux du CDC :
 *
 *  - **P5** — une branche manquante dans `rendreDepuisGabarit()` ne casse
 *    rien : l'email **part quand même**, par le repli de langue, sans nom de
 *    Boucle ni de personne. Le dépôt l'a oublié DEUX fois (T1751, T1761) ;
 *  - **P6** — une branche manquante dans le résolveur de cible fait conclure
 *    `skipped_unreachable` : rien ne part, et la clé est morte en se déclarant
 *    vivante.
 */
class TASK1772MentionEmailEtCibleTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $auteur;

    private User $cible;

    private Loop $loop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create(['is_active' => true, 'slug' => 'org-1772-mail']);
        $this->auteur = User::factory()->complete()->create(['organization_id' => $this->org->id, 'first_name' => 'Alice', 'name' => 'Martin']);
        $this->cible = User::factory()->complete()->create(['organization_id' => $this->org->id, 'first_name' => 'Bob', 'name' => 'Durand']);

        app()->instance('current_organization', $this->org);

        $service = new LoopService;
        $this->loop = $service->createLoop($this->auteur, 'Boucle du courrier');
        $service->addMemberByUserId($this->loop, $this->cible->id);

        $this->mentionner();
    }

    private function mentionner(): void
    {
        $nom = $this->cible->fresh()->publicDisplayName();

        Livewire::actingAs($this->auteur)
            ->test(LoopChat::class, ['loop' => $this->loop])
            ->set('body', 'Coucou @'.$nom.' regarde ça')
            ->set('mentionsSelectionnees', [$this->cible->id])
            ->call('sendMessage');
    }

    /**
     * Rejoue la mention avec un corps choisi, apres avoir efface la trace de
     * celle de `setUp()`.
     *
     * L'`event_id` de la mention est un UUID v5 derive de l'id du MESSAGE
     * (T1772) : un second message produit donc bien une seconde notification,
     * mais `notification()` exige qu'il n'y en ait qu'une.
     */
    private function rementionnerAvec(string $corps): void
    {
        MemberNotification::query()
            ->where('notification_key', NotificationCatalogue::LOOP_MENTION_CREATED)
            ->delete();
        LoopMessage::query()->where('loop_id', $this->loop->id)->where('type', 'user')->delete();

        $nom = $this->cible->fresh()->publicDisplayName();

        Livewire::actingAs($this->auteur)
            ->test(LoopChat::class, ['loop' => $this->loop])
            ->set('body', str_replace('@MENTION', '@'.$nom, $corps))
            ->set('mentionsSelectionnees', [$this->cible->id])
            ->call('sendMessage');
    }

    private function notification(): MemberNotification
    {
        return MemberNotification::query()
            ->where('notification_key', NotificationCatalogue::LOOP_MENTION_CREATED)
            ->where('recipient_id', $this->cible->id)
            ->firstOrFail();
    }

    private function rendre(string $url = 'https://exemple.test/cible'): ?array
    {
        $methode = (new \ReflectionClass(NotificationEmailDeliverer::class))->getMethod('rendreDepuisGabarit');
        $methode->setAccessible(true);

        return $methode->invoke(
            app(NotificationEmailDeliverer::class),
            $this->notification(),
            $url,
            'fr',
            $this->cible->fresh(),
        );
    }

    /** PREUVE ANTI-P5 : l'email passe par le GABARIT, et il nomme. */
    public function test_l_email_est_rendu_depuis_le_gabarit_et_nomme_la_boucle_et_l_auteur(): void
    {
        $contenu = $this->rendre();

        $this->assertNotNull($contenu,
            'Aucune branche ne relie la cle au gabarit : l\'email partirait par le repli de langue, '
            .'sans nom de Boucle ni de personne — et RIEN ne le signalerait.');

        $this->assertStringContainsString($this->loop->name, $contenu['subject'],
            'Le sujet ne nomme pas la Boucle : c\'est la signature du REPLI, pas du gabarit.');
        $this->assertStringContainsString('Alice', $contenu['body'],
            'Le corps ne nomme pas l\'auteur de la mention.');
        $this->assertStringContainsString($this->cible->fresh()->publicDisplayName(), $contenu['body'],
            'Le corps ne salue pas la personne mentionnee.');
    }

    /**
     * La version journalisée porte le marqueur expurgé, jamais l'adresse.
     *
     * `body_for_log` finit dans `email_logs` : une URL réelle y laisserait un
     * jeton de cible en base.
     */
    public function test_la_version_journalisee_ne_porte_pas_l_adresse_reelle(): void
    {
        $contenu = $this->rendre('https://exemple.test/secret-de-cible');

        $this->assertStringContainsString('secret-de-cible', $contenu['body']);
        $this->assertStringNotContainsString('secret-de-cible', $contenu['body_for_log']);
    }

    /** Gabarit désactivé : le repli de langue prend le relais, sans casser. */
    public function test_un_gabarit_desactive_retombe_sur_le_repli_de_langue(): void
    {
        SystemEmailTemplate::query()
            ->where('slug', 'loop_mention_created')
            ->update(['enabled' => false]);

        $contenu = $this->rendre();

        $this->assertNotNull($contenu);
        $this->assertStringNotContainsString($this->loop->name, $contenu['subject'],
            'Le repli est generique : s\'il nommait la Boucle, c\'est que le gabarit a repondu.');
    }

    /** PREUVE ANTI-P6 : le clic mène au MESSAGE, pas seulement à la Boucle. */
    public function test_la_cible_mene_au_message(): void
    {
        $message = LoopMessage::where('loop_id', $this->loop->id)->where('type', 'user')->firstOrFail();

        $url = app(NotificationTargetResolver::class)->resolve($this->notification());

        $this->assertNotNull($url, 'sans branche, la livraison conclurait « cible plus joignable »');
        $this->assertStringContainsString('message='.$message->getKey(), $url,
            'l\'URL doit designer LA PHRASE ou l\'on a ete nomme');
    }

    /**
     * Les quatre `null` ordinaires. On en mesure deux, qui couvrent les deux
     * familles : l'objet disparu et la porte refermée.
     */
    public function test_un_message_supprime_ou_un_membre_sorti_ne_mene_plus_nulle_part(): void
    {
        $notification = $this->notification();
        $message = LoopMessage::where('loop_id', $this->loop->id)->where('type', 'user')->firstOrFail();

        // 1. Le message est supprime. `loop_messages` n'a PAS de trait
        //    `SoftDeletes` : c'est un drapeau manuel, aucun scope ne le filtre.
        $message->forceFill(['deleted_at' => now()])->save();
        $this->assertNull(app(NotificationTargetResolver::class)->resolve($notification));

        // 2. Le message revient, mais la personne n'est plus membre.
        $message->forceFill(['deleted_at' => null])->save();
        \App\Models\LoopMember::where('loop_id', $this->loop->id)
            ->where('user_id', $this->cible->id)
            ->update(['status' => 'removed']);

        $this->assertNull(app(NotificationTargetResolver::class)->resolve($notification->fresh()));
    }

    /**
     * TASK-1772 recette 06/11 — LE MESSAGE EST DANS LE COURRIEL.
     *
     * ## Pourquoi c'est une exigence, et pas une commodite
     *
     * Demande de Cyril, avec sa raison : « cela reduit le FOMO, et on ne
     * stresse pas les membres. Ils peuvent lire le message sur la Boucle, mais
     * ils ont le temps de le faire quand ils veulent car ils ont eu l'info dans
     * l'email. » Un courriel qui dit seulement « on vous a mentionne » OBLIGE a
     * ouvrir l'application pour savoir si c'est urgent. Celui-ci repond a la
     * question sur place.
     *
     * Et le lien de desabonnement, exige par la meme demande, mene aux reglages
     * DE L'ORGANIZATION : les preferences sont par tenant.
     */
    public function test_le_courriel_porte_le_message_et_le_lien_de_desabonnement(): void
    {
        $this->rementionnerAvec('Coucou @MENTION, le local est libre jeudi soir');

        $contenu = $this->rendre();

        $this->assertStringContainsString('le local est libre jeudi soir', $contenu['body'],
            'Le courriel ne reprend pas le message : la personne doit ouvrir l\'application '
            .'pour savoir de quoi il s\'agit — c\'est exactement le FOMO qu\'on voulait retirer.');

        $this->assertStringContainsString(
            route('organization.notifications.preferences.edit', ['organization' => $this->org]),
            $contenu['body'],
            'Aucun lien vers les reglages de notifications : le seul recours serait de chercher '
            .'dans l\'application, ou de ne plus lire nos courriels.');
    }

    /**
     * Le message est ECHAPPE, parce qu'il est ecrit par un membre.
     *
     * C'est la premiere fois que ce courriel porte du texte libre. Le chemin du
     * gabarit est couvert par `EmailerService::interpolate()`, qui passe chaque
     * variable par `e()` ; le chemin du REPLI ne le traverse pas et echappe a
     * la main. Les deux sont mesures ici, parce qu'un seul des deux l'etait
     * suffirait a laisser passer l'autre.
     */
    public function test_le_message_repris_est_echappe_sur_les_deux_chemins(): void
    {
        $this->rementionnerAvec('Coucou @MENTION <script>alert(1)</script>');

        $gabarit = $this->rendre();
        $this->assertStringNotContainsString('<script>alert(1)</script>', $gabarit['body'],
            'Le gabarit a injecte du balisage ecrit par un membre dans un courriel HTML.');
        $this->assertStringContainsString('alert(1)', $gabarit['body'],
            'Temoin positif : le texte doit bien etre la, echappe — sans lui, '
            .'cette assertion passerait aussi sur un courriel qui ne reprend RIEN.');

        SystemEmailTemplate::query()->where('slug', 'loop_mention_created')->update(['enabled' => false]);

        $repli = $this->rendre();
        $this->assertStringNotContainsString('<script>alert(1)</script>', $repli['body'],
            'Le REPLI de langue ne traverse pas `interpolate()` : il doit echapper lui-meme.');
        $this->assertStringContainsString('alert(1)', $repli['body']);
    }

    /**
     * La copie ARCHIVEE n'emporte pas la conversation.
     *
     * `body_for_log` finit dans `email_logs`, que le SuperAdmin peut relire. Le
     * lien y etait deja expurge parce qu'il OUVRE une porte ; l'extrait l'est
     * parce qu'il porte la parole d'un membre dans une Boucle privee. Le
     * journal prouve toujours que le courriel est parti, et avec quelle
     * structure.
     */
    public function test_la_copie_journalisee_n_archive_pas_le_message(): void
    {
        $this->rementionnerAvec('Coucou @MENTION, mon code de porte est 4821B');

        $contenu = $this->rendre();

        $this->assertStringContainsString('4821B', $contenu['body'],
            'Temoin : le courriel reellement envoye porte bien le message.');
        $this->assertStringNotContainsString('4821B', $contenu['body_for_log'],
            'La conversation est archivee dans `email_logs`.');
        $this->assertStringContainsString(
            \App\Services\Loops\LoopMentionMailer::EXTRAIT_EXPURGE,
            $contenu['body_for_log'],
            'Le journal doit DIRE qu\'un extrait a ete expurge, pas laisser un trou.');
        $this->assertStringNotContainsString(
            route('organization.notifications.preferences.edit', ['organization' => $this->org]),
            $contenu['body_for_log']);
    }

    /**
     * Un message sans texte — une photo seule — ne laisse pas un bloc vide.
     *
     * `loop_messages.body` peut etre vide : le composeur accepte une image
     * seule. Sans ce repli, le courriel afficherait une citation vide, et le
     * membre lirait « on vous a mentionne » suivi de rien.
     */
    public function test_un_message_sans_texte_affiche_une_mention_neutre(): void
    {
        $this->rementionnerAvec('@MENTION');

        LoopMessage::query()
            ->where('loop_id', $this->loop->id)
            ->where('type', 'user')
            ->update(['body' => '']);

        $contenu = $this->rendre();

        $this->assertStringContainsString(
            (string) __('notifications.mention_email_excerpt_empty', [], 'fr'),
            $contenu['body']);
    }
}
