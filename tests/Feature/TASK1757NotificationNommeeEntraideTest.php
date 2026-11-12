<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\MemberNotification;
use App\Models\Organization;
use App\Models\Service;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Notifications\NotificationCatalogue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1757 (§7 du CDC entraide) — la notification d'entraide se NOMME, mais
 * seulement la ou c'est sans danger.
 *
 * ## Le conflit que ce fichier tranche, et qu'il garde tranche
 *
 * Le §7 du CDC entraide demande « titre de notif T1751 avec prenom + annonce ».
 * T1751 avait garde le titre GENERIQUE a dessein : un Push s'affiche sur un
 * ECRAN VERROUILLE (CDC notifications §6.4/6.5).
 *
 * Les deux CDC ne se contredisent qu'en apparence — les SURFACES sont
 * differentes. Ce fichier mesure donc les DEUX sens :
 * - la liste en-appli (derriere l'authentification) **nomme** ;
 * - `push_title` et `push_body` **ne nomment pas**.
 *
 * Sans la seconde moitie, un correctif trop zele pourrait nommer partout et la
 * suite resterait verte.
 */
class TASK1757NotificationNommeeEntraideTest extends TestCase
{
    use RefreshDatabase;

    private Organization $orgA;

    private Organization $orgB;

    private User $auteurDeLAnnonce;

    private User $repondant;

    protected function setUp(): void
    {
        parent::setUp();

        // `locale` explicite : `SetLocale` passe par l'Organization et la
        // fabrique ne garantit pas le francais (lecon T1755).
        $this->orgA = Organization::factory()->create(['slug' => 'org-a-1757', 'is_active' => true, 'locale' => 'fr']);
        $this->orgB = Organization::factory()->create(['slug' => 'org-b-1757', 'is_active' => true, 'locale' => 'fr']);
        app()->instance('current_organization', $this->orgA);

        $this->auteurDeLAnnonce = User::factory()->create(['organization_id' => $this->orgA->id, 'first_name' => 'Ottoline']);
        $this->repondant = User::factory()->create(['organization_id' => $this->orgA->id, 'first_name' => 'Barnabe', 'points_balance' => 500]);
    }

    private function annonce(Organization $org, User $auteur, string $titre = 'Reparer un velo'): Service
    {
        return Service::factory()->create([
            'user_id' => $auteur->id,
            'organization_id' => $org->id,
            'category_id' => Category::factory()->create(['organization_id' => $org->id])->id,
            'status' => 'active',
            'points_cost' => 30,
            'title' => $titre,
        ]);
    }

    /** L'echange tel que `store()` le cree : le repondant devient `buyer`. */
    private function echange(Organization $org, Service $annonce, User $repondant, int $points = 30): Transaction
    {
        return Transaction::factory()->create([
            'organization_id' => $org->id,
            'service_id' => $annonce->id,
            'buyer_id' => $repondant->id,
            'seller_id' => $annonce->user_id,
            'status' => 'pending',
            'points_proposed' => $points,
            'points_agreed' => null,
        ]);
    }

    /**
     * La notification naît du VRAI geste, pas d'un `create()` a la main.
     *
     * Premiere version de ce fichier : la ligne etait fabriquee directement, et
     * les invariants de `NotificationInvariants` l'ont refusee (`recipient_id`
     * puis `event_id` manquants). C'etait un signal, pas un obstacle : une
     * notification fabriquee a la main ne prouve pas que le produit en emet
     * une. On passe donc par `transactions.store`, comme le membre.
     */
    private function repondreALAnnonce(Service $annonce, User $repondant, int $points = 30): Transaction
    {
        $this->actingAs($repondant)->post(route('transactions.store'), [
            'service_id' => $annonce->id,
            'points_proposed' => $points,
        ]);

        return Transaction::query()->withoutGlobalScopes()
            ->where('service_id', $annonce->id)
            ->where('buyer_id', $repondant->id)
            ->firstOrFail();
    }

    /** La notification emise pour cet echange. */
    private function notificationDe(Transaction $echange): MemberNotification
    {
        return MemberNotification::query()->withoutGlobalScopes()
            ->where('object_type', NotificationCatalogue::OBJECT_TRANSACTION)
            ->where('object_id', $echange->id)
            ->firstOrFail();
    }

    /**
     * Repointe une notification REELLE vers un autre objet ou destinataire.
     *
     * Les cas hostiles — un objet d'un autre tenant, un lecteur etranger a
     * l'echange — ne peuvent PAS naitre d'un vrai geste : le produit les
     * empeche en amont. C'est pourtant contre eux que la resolution doit tenir,
     * parce qu'une notification survit a des changements que son emission
     * n'avait pas prevus.
     *
     * Ecriture par le CONSTRUCTEUR DE REQUETES, et c'est voulu : les invariants
     * de `MemberNotification` refusent justement de muter ces colonnes. On ne
     * les contourne pas pour tricher, on fabrique un etat de base que le
     * RENDU doit savoir lire sans rien fuiter.
     */
    private function repointer(MemberNotification $notification, array $colonnes): void
    {
        MemberNotification::query()->withoutGlobalScopes()
            ->whereKey($notification->id)
            ->update($colonnes);
    }

    private function centre(User $lecteur): string
    {
        return $this->actingAs($lecteur)->get(route('notifications.index'))->assertOk()->getContent();
    }

    /** La demande du §7, sur la surface ou elle est sans danger. */
    public function test_la_liste_en_appli_nomme_la_personne_l_annonce_et_le_temps(): void
    {
        $annonce = $this->annonce($this->orgA, $this->auteurDeLAnnonce);
        $this->repondreALAnnonce($annonce, $this->repondant);

        $page = $this->centre($this->auteurDeLAnnonce);

        $this->assertStringContainsString('Barnabe', $page, 'Le prenom du repondant doit apparaitre.');
        $this->assertStringContainsString('Reparer un velo', $page, 'Le titre de l\'annonce doit apparaitre.');
        $this->assertStringContainsString('30 min', $page, 'Le temps doit apparaitre.');
        $this->assertStringNotContainsString('Quelqu’un répond à votre demande ou proposition d’aide', $page,
            'La forme generique ne doit plus servir quand l\'objet est nommable.');
    }

    /**
     * 🔴 LA MOITIE QUI PROTEGE — le Push ne nomme toujours personne.
     *
     * Un Push s'affiche sur un ecran VERROUILLE. Si ce test disparait, plus
     * rien n'empeche une relecture zelee de propager le prenom et le titre de
     * l'annonce jusque-la.
     */
    public function test_le_push_et_le_sujet_d_email_ne_nomment_toujours_personne(): void
    {
        foreach (['fr', 'en'] as $locale) {
            $titre = __('notifications.push_title.transaction_proposal_created', [], $locale);
            $corps = __('notifications.push_body.transaction_proposal_created', [], $locale);
            $sujet = __('notifications.email.transaction_proposal_created.subject', [], $locale);

            foreach ([$titre, $corps, $sujet] as $texte) {
                $this->assertStringNotContainsString(':prenom', $texte);
                $this->assertStringNotContainsString(':titre', $texte);
                $this->assertStringNotContainsString(':temps', $texte);
            }
        }
    }

    /**
     * 🔴 LA FRONTIERE DE TENANT — un echange d'une autre Organization ne nomme
     * rien.
     *
     * Meme doctrine que T1725 : « le nom n'est montre QUE si la cible est
     * joignable ». La resolution fait `withoutGlobalScopes()` pour pouvoir
     * borner elle-meme, et c'est precisement ce qui rend le
     * `where('organization_id')` explicite indispensable — lecon payee en
     * T1755.
     */
    public function test_un_echange_d_un_autre_tenant_retombe_sur_la_forme_generique(): void
    {
        // Le lecteur est PARTIE a cet echange de B — sans quoi ce test
        // passerait par la garde « partie a l'echange » et ne mesurerait PAS la
        // borne de tenant. Mesure faite : avec un echange ou le lecteur n'est
        // rien, retirer `where('organization_id')` ne rougissait pas.
        //
        // L'etat est anormal (une ligne de B dont une partie vient de A) et
        // c'est exactement ce contre quoi un rendu doit tenir : la campagne
        // T1692 a montre que de telles lignes existent.
        $etranger = User::factory()->create(['organization_id' => $this->orgB->id, 'first_name' => 'Zelda']);
        $annonceB = $this->annonce($this->orgB, $etranger, 'ANNONCE SECRETE DE B');
        $echangeB = Transaction::factory()->create([
            'organization_id' => $this->orgB->id,
            'service_id' => $annonceB->id,
            'buyer_id' => $etranger->id,
            'seller_id' => $this->auteurDeLAnnonce->id,
            'status' => 'pending',
            'points_proposed' => 30,
            'points_agreed' => null,
        ]);

        // La notification est posee dans l'Organization A, son objet vit en B.
        $echangeA = $this->repondreALAnnonce($this->annonce($this->orgA, $this->auteurDeLAnnonce), $this->repondant);
        $this->repointer($this->notificationDe($echangeA), ['object_id' => $echangeB->id]);

        $page = $this->centre($this->auteurDeLAnnonce);

        $this->assertStringNotContainsString('ANNONCE SECRETE DE B', $page, 'Fuite inter-tenant dans le centre de notifications.');
        $this->assertStringNotContainsString('Zelda', $page);
        $this->assertStringContainsString('Quelqu’un répond à votre demande ou proposition d’aide', $page, 'Le repli generique doit prendre le relais.');
    }

    /** Un lecteur qui n'est PARTIE a rien ne lit aucun nom. */
    public function test_un_lecteur_etranger_a_l_echange_ne_lit_aucun_nom(): void
    {
        $echange = $this->repondreALAnnonce($this->annonce($this->orgA, $this->auteurDeLAnnonce), $this->repondant);

        $curieux = User::factory()->create(['organization_id' => $this->orgA->id]);
        $this->repointer($this->notificationDe($echange), ['recipient_id' => $curieux->id]);

        $page = $this->centre($curieux);

        $this->assertStringNotContainsString('Barnabe', $page);
        $this->assertStringContainsString('Quelqu’un répond à votre demande ou proposition d’aide', $page);
    }

    /**
     * Une discussion DIRECTE n'a ni titre ni temps a dire.
     *
     * `subject` rendrait « Discussion directe » et le temps « Offert » : une
     * phrase grammaticalement correcte qui ne raconte rien.
     */
    public function test_une_discussion_directe_retombe_sur_la_forme_generique(): void
    {
        $directe = Transaction::factory()->create([
            'organization_id' => $this->orgA->id,
            'service_id' => null, 'request_id' => null,
            'buyer_id' => $this->repondant->id, 'seller_id' => $this->auteurDeLAnnonce->id,
            'status' => 'pending', 'points_proposed' => 0, 'points_agreed' => 0,
        ]);

        $echange = $this->repondreALAnnonce($this->annonce($this->orgA, $this->auteurDeLAnnonce), $this->repondant);
        $this->repointer($this->notificationDe($echange), ['object_id' => $directe->id]);

        $this->assertStringContainsString('Quelqu’un répond à votre demande ou proposition d’aide', $this->centre($this->auteurDeLAnnonce));
    }
}
