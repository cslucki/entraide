<?php

namespace Tests\Feature;

use App\Models\BlogPost;
use App\Models\MemberNotification;
use App\Models\Organization;
use App\Models\User;
use App\Support\Notifications\NotificationCatalogue;
use App\Support\Notifications\NotificationTargetResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * TASK-1702 — on previent celui qu'on ajoute en co-ecriture.
 *
 * Cyril : « quand on ajoute un auteur X en co-ecriture, il doit recevoir une
 * notification dans l'application pour l'informer de cela. »
 *
 * ## Deux choses que ce correctif decide, et qu'il faut lire
 *
 * 1. **La destination est l'EDITEUR**, pas la page publique de l'article.
 *    `NotificationTargetResolver::blogComment()` exige `status ===
 *    'published'` ; appliquer la meme regle ici rendrait la notification morte
 *    dans le cas le plus courant, un article co-ecrit etant le plus souvent un
 *    brouillon.
 *
 * 2. **La notification n'est PAS desactivable** (`configurable => false`),
 *    comme une invitation et a la difference d'un commentaire. Etre ajoute a
 *    l'ecriture d'un article vous est ADRESSE et appelle un geste de vous ; un
 *    commentaire, non — c'est ce que dit deja l'entree de catalogue voisine.
 */
class TASK1702CoAuthorNotificationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $proprietaire;

    private User $invite;

    private BlogPost $article;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create([
            'slug' => 'org-1702-co',
            'is_active' => true,
            'is_public' => true,
        ]);

        $this->proprietaire = User::factory()->complete()->create([
            'organization_id' => $this->organization->id,
            'email_verified_at' => now(),
        ]);

        $this->invite = User::factory()->complete()->create([
            'organization_id' => $this->organization->id,
            'email_verified_at' => now(),
        ]);

        $this->article = BlogPost::create([
            'organization_id' => $this->organization->id,
            'user_id' => $this->proprietaire->id,
            'title' => 'Article co-ecrit 1702',
            'content' => 'Un brouillon, comme la plupart des articles co-ecrits.',
            'status' => 'draft',
        ]);
    }

    private function ajouter(?User $qui = null): \Illuminate\Testing\TestResponse
    {
        app()->forgetInstance('current_organization');

        return $this->actingAs($this->proprietaire)->postJson(
            '/org/'.$this->organization->slug.'/blog/'.$this->article->fresh()->slug.'/co-authors',
            ['user_id' => ($qui ?? $this->invite)->id],
        );
    }

    private function notifications(User $destinataire): \Illuminate\Database\Eloquent\Collection
    {
        return MemberNotification::query()
            ->where('recipient_id', $destinataire->id)
            ->where('notification_key', NotificationCatalogue::BLOG_CO_AUTHOR_ADDED)
            ->get();
    }

    public function test_le_co_auteur_ajoute_recoit_une_notification(): void
    {
        $this->ajouter()->assertOk();

        $notifications = $this->notifications($this->invite);

        $this->assertCount(1, $notifications, 'Le co-auteur ajoute n\'a pas ete prevenu.');

        $notification = $notifications->first();
        $this->assertSame((string) $this->organization->id, (string) $notification->organization_id);
        $this->assertSame(NotificationCatalogue::OBJECT_BLOG_POST, $notification->object_type);
        $this->assertSame((string) $this->article->id, (string) $notification->object_id);
        $this->assertSame((string) $this->proprietaire->id, (string) $notification->actor_id);
    }

    /**
     * Celui qui ajoute n'est pas prevenu de son propre geste.
     *
     * Sans ce volet, une emission posee « a tout le monde » passerait le test
     * precedent.
     */
    public function test_celui_qui_ajoute_n_est_pas_prevenu(): void
    {
        $this->ajouter()->assertOk();

        $this->assertCount(0, $this->notifications($this->proprietaire));
    }

    /**
     * La notification mene a l'EDITEUR, et pas a la page publique.
     *
     * L'article est un BROUILLON : c'est precisement le cas ou une destination
     * calquee sur celle des commentaires ne menerait nulle part.
     */
    public function test_la_notification_mene_a_l_editeur_de_l_article(): void
    {
        $this->ajouter()->assertOk();

        $cible = app(NotificationTargetResolver::class)->resolve($this->notifications($this->invite)->first());

        $this->assertNotNull($cible, 'La notification ne mene nulle part.');
        $this->assertStringContainsString(
            '/org/'.$this->organization->slug.'/blog/rediger/'.$this->article->fresh()->slug.'/modifier',
            $cible
        );
    }

    /**
     * Retire de la co-ecriture, le membre ne va plus nulle part.
     *
     * C'est la doctrine FAIL-CLOSED du resolveur : la notification ne detient
     * aucune autorite, elle designe un objet, et le droit est reevalue AU CLIC.
     * Sans cela, elle menerait a un 403 — et une notification qui mene a un
     * refus est pire que pas de notification.
     */
    public function test_un_co_auteur_retire_ne_mene_plus_nulle_part(): void
    {
        $this->ajouter()->assertOk();

        $notification = $this->notifications($this->invite)->first();
        $this->assertNotNull(app(NotificationTargetResolver::class)->resolve($notification));

        $this->article->coAuthors()->detach($this->invite->id);

        $this->assertNull(
            app(NotificationTargetResolver::class)->resolve($notification->fresh()),
            'Un membre retire de la co-ecriture est encore envoye sur l\'editeur.'
        );
    }

    /**
     * Deux co-auteurs du MEME article recoivent chacun la leur.
     *
     * L'identifiant d'evenement ne porte que la clef et l'article : c'est
     * `UNIQUE(event_id, recipient_id)` qui separe les destinataires. Si le
     * destinataire devait entrer dans le nom, ce test le dirait.
     */
    public function test_deux_co_auteurs_recoivent_chacun_la_leur(): void
    {
        $second = User::factory()->complete()->create([
            'organization_id' => $this->organization->id,
            'email_verified_at' => now(),
        ]);

        $this->ajouter()->assertOk();
        $this->ajouter($second)->assertOk();

        $this->assertCount(1, $this->notifications($this->invite));
        $this->assertCount(1, $this->notifications($second));
    }

    /**
     * Retirer puis re-ajouter la meme personne ne la previent pas deux fois.
     *
     * L'identifiant est DETERMINISTE : c'est ce qui rend l'emission idempotente
     * et evite de harceler quelqu'un qu'on hesite a inclure.
     */
    public function test_reajouter_la_meme_personne_ne_la_previent_pas_deux_fois(): void
    {
        $this->ajouter()->assertOk();

        $this->article->coAuthors()->detach($this->invite->id);
        app()->forgetInstance('current_organization');

        $this->ajouter()->assertOk();

        $this->assertCount(1, $this->notifications($this->invite), 'La meme personne a ete prevenue deux fois.');
    }

    /**
     * Une panne du canal de notification ne defait PAS la co-ecriture.
     *
     * Elle est deja enregistree quand on previent. Faire echouer la requete
     * ferait perdre a l'utilisateur un geste reussi, pour une raison qui ne le
     * concerne pas.
     */
    public function test_une_panne_de_notification_ne_defait_pas_la_co_ecriture(): void
    {
        Log::spy();

        app()->bind(\App\Support\Notifications\NotificationEmitter::class, function () {
            throw new \RuntimeException('canal indisponible');
        });

        $this->ajouter()->assertOk();

        $this->assertTrue(
            $this->article->coAuthors()->where('users.id', $this->invite->id)->exists(),
            'La co-ecriture a ete perdue a cause du canal de notification.'
        );
        $this->assertCount(0, $this->notifications($this->invite));
    }

    // ── Le catalogue ────────────────────────────────────────────────────────

    public function test_la_notification_n_est_pas_desactivable(): void
    {
        // Comme une invitation, et a la difference d'un commentaire : elle vous
        // est adressee personnellement et appelle un geste de vous.
        $this->assertTrue(NotificationCatalogue::channelDefault(
            NotificationCatalogue::BLOG_CO_AUTHOR_ADDED,
            NotificationCatalogue::CHANNEL_IN_APP
        ));

        $this->assertFalse(NotificationCatalogue::channelIsConfigurable(
            NotificationCatalogue::BLOG_CO_AUTHOR_ADDED,
            NotificationCatalogue::CHANNEL_IN_APP
        ));
    }

    /**
     * La clef porte un libelle dans les DEUX locales.
     *
     * L'ecran de notifications retombe sinon sur un texte generique, et
     * personne ne saurait de quoi on le previent. La garde de TASK-1379 l'exige
     * pour toute clef du catalogue ; on le verifie ici au plus pres du
     * correctif.
     */
    public function test_la_clef_porte_un_libelle_dans_les_deux_locales(): void
    {
        $traduction = 'notifications.keys.'.str_replace('.', '_', NotificationCatalogue::BLOG_CO_AUTHOR_ADDED);

        foreach (['fr', 'en'] as $locale) {
            $this->assertTrue(
                \Illuminate\Support\Facades\Lang::has($traduction, $locale, false),
                "Libelle manquant en « {$locale} »."
            );
        }
    }
}
