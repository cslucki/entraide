<?php

namespace Tests\Feature;

use App\Models\CrmContact;
use App\Models\CrmContactEvent;
use App\Models\CrmStatus;
use App\Models\Organization;
use App\Models\User;
use App\Services\Crm\CrmContactService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1753 — « Ajouter au suivi » et « Saisir une note » depuis `/admin/users`.
 *
 * Ce fichier ne mesure QUE ce que la surface plateforme ajoute. Le metier
 * (deduplication, idempotence, restauration, garde d'auteur) vit dans
 * `App\Services\Crm\*` et est deja garde par T1413/T1414/T1430/T1431 : le
 * re-tester ici ne prouverait rien de neuf.
 *
 * Les deux gardes ROUGES du CDC sont celles qui tomberaient en SILENCE :
 *
 * 1. §3.3 — un compte sans Organization fait un 500 sur TOUTE la page, pas sur
 *    le bouton. Il est invisible sur la base de recette (zero compte concerne).
 * 2. §3.6 — le layout admin ne rend ni `$errors` ni `@error` : une note refusee
 *    rechargerait un ecran d'apparence normale, sans rien ecrire et sans rien
 *    dire.
 */
class TASK1753SuperAdminFollowAndNoteTest extends TestCase
{
    use RefreshDatabase;

    private Organization $orgA;

    private Organization $orgB;

    private User $superAdmin;

    private User $membreA;

    private User $membreB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orgA = Organization::factory()->create(['slug' => 'org-a-1753', 'is_active' => true, 'locale' => 'fr']);
        $this->orgB = Organization::factory()->create(['slug' => 'org-b-1753', 'is_active' => true, 'locale' => 'fr']);

        $this->superAdmin = User::factory()->create(['is_admin' => true, 'organization_id' => $this->orgA->id]);

        // Noms FIXES : jamais de valeur Faker dans une assertion.
        $this->membreA = User::factory()->create([
            'organization_id' => $this->orgA->id,
            'first_name' => 'Ottoline', 'name' => 'Kaminski', 'email' => 'ottoline.k@example.com',
        ]);
        $this->membreB = User::factory()->create([
            'organization_id' => $this->orgB->id,
            'first_name' => 'Brunhilde', 'name' => 'Bartholomew', 'email' => 'brunhilde.b@example.com',
        ]);
    }

    private function urlSuivi(Organization $organization, User $user): string
    {
        return route('admin.crm.members.follow', ['organization' => $organization->slug, 'user' => $user->id]);
    }

    private function urlNote(Organization $organization, User $user): string
    {
        return route('admin.crm.members.notes.store', ['organization' => $organization->slug, 'user' => $user->id]);
    }

    // ── La frontiere de locataire et l'autorite ──────────────────────────────

    /**
     * Un membre vu depuis l'URL d'une AUTRE Organization n'existe pas : 404.
     *
     * Jamais 403 — un 403 confirmerait que ce compte existe ailleurs.
     * `CrmContact` n'a AUCUN scope global : c'est l'URL qui porte la frontiere
     * et le controleur qui la verifie. Cette garde est donc la seule.
     */
    public function test_suivre_un_membre_d_une_autre_organization_est_un_404(): void
    {
        $this->actingAs($this->superAdmin)
            ->post($this->urlSuivi($this->orgA, $this->membreB))
            ->assertNotFound();

        $this->assertSame(0, CrmContact::count(), 'Un 404 ne doit laisser AUCUNE ecriture derriere lui.');
    }

    /** Idem pour la note : la frontiere est verifiee avant toute ecriture. */
    public function test_noter_un_membre_d_une_autre_organization_est_un_404(): void
    {
        $this->actingAs($this->superAdmin)
            ->post($this->urlNote($this->orgA, $this->membreB), ['body' => 'Tentative.'])
            ->assertNotFound();

        $this->assertSame(0, CrmContactEvent::where('type', CrmContactEvent::TYPE_NOTE)->count());
    }

    /**
     * Un administrateur d'Organization n'est PAS un SuperAdmin plateforme.
     *
     * `AdminMiddleware` exige `is_admin`. Un OrgAdmin, meme de l'Organization
     * visee, n'atteint pas ces routes.
     */
    public function test_un_administrateur_d_organization_est_refuse(): void
    {
        $adminOrg = User::factory()->create(['organization_id' => $this->orgA->id, 'is_admin' => false]);
        $this->orgA->forceFill(['admin_id' => $adminOrg->id])->save();

        $this->actingAs($adminOrg)->post($this->urlSuivi($this->orgA, $this->membreA))->assertForbidden();
        $this->actingAs($adminOrg)->post($this->urlNote($this->orgA, $this->membreA), ['body' => 'x'])->assertForbidden();
    }

    // ── 🔴 §3.3 — le compte SANS Organization ────────────────────────────────

    /**
     * 🔴 La page se rend en 200 avec un compte sans Organization, et cette
     * ligne ne porte AUCUNE des deux actions.
     *
     * `users.organization_id` est NULLABLE et les deux routes portent
     * `{organization}` en parametre OBLIGATOIRE. Sans le `@if($u->organization)`
     * de la vue, `route()` leve une `UrlGenerationException` qui emporte le
     * rendu de la PAGE ENTIERE — pas seulement le bouton.
     *
     * SABOTAGE DE VERIFICATION, fait et observe le 05/11 : en remplacant la
     * garde de la vue par `@if(true)`, ce test rougit sur
     *
     *     Expected response status code [200] but received 500.
     *     ErrorException: Attempt to read property "slug" on null
     *     (View: resources/views/admin/users.blade.php)
     *
     * Note sur l'ECART avec le CDC, qui annoncait une `UrlGenerationException:
     * Missing required parameter`. Les deux sont vraies, mais pas au meme
     * endroit : la vue ecrit `$u->organization->slug`, qui echoue sur la
     * relation nulle AVANT que `route()` ne soit appele. Avec `?->`, on
     * atteindrait bien l'exception du CDC. L'essentiel est identique et c'est
     * ce que le sabotage confirme : ce n'est pas le bouton qui tombe, c'est la
     * PAGE ENTIERE.
     */
    public function test_un_compte_sans_organization_ne_casse_pas_la_page_et_ne_porte_aucune_action(): void
    {
        $orphelin = User::factory()->create(['organization_id' => null, 'first_name' => 'Sans', 'name' => 'Organisation']);

        $html = $this->actingAs($this->superAdmin)
            ->get(route('admin.users'))
            ->assertOk()
            ->getContent();

        // La ligne existe bien — sinon le test passerait pour la mauvaise raison.
        $this->assertStringContainsString($orphelin->email, $html,
            'Le compte sans Organization doit figurer dans la liste.');

        $this->assertStringNotContainsString('data-crm-follow="'.$orphelin->id.'"', $html,
            'Un compte sans Organization ne doit porter aucun bouton de suivi.');
        $this->assertStringNotContainsString('data-crm-note-trigger="'.$orphelin->id.'"', $html,
            'Un compte sans Organization ne doit porter aucun declencheur de note.');

        // Et la reciproque : un compte NORMAL les porte, sur la meme page.
        $this->assertStringContainsString('data-crm-follow="'.$this->membreA->id.'"', $html);
        $this->assertStringContainsString('data-crm-note-trigger="'.$this->membreA->id.'"', $html);
    }

    // ── Le geste de suivi ────────────────────────────────────────────────────

    /**
     * Deux clics ne font qu'UN contact, et le second le DIT.
     *
     * `member_linked` est un fait de vie : il entre une seule fois.
     */
    public function test_suivre_deux_fois_ne_cree_qu_un_contact(): void
    {
        $this->actingAs($this->superAdmin)->post($this->urlSuivi($this->orgA, $this->membreA))
            ->assertSessionHas('success', __('crm.flash.member_followed'));

        $this->actingAs($this->superAdmin)->post($this->urlSuivi($this->orgA, $this->membreA))
            ->assertSessionHas('success', __('crm.flash.member_already_followed'));

        $this->assertSame(1, CrmContact::where('user_id', $this->membreA->id)->count());
        $this->assertSame(1, CrmContactEvent::where('type', CrmContactEvent::TYPE_MEMBER_LINKED)->count());
    }

    /**
     * On RESTE sur `/admin/users` — decision n°3 de Cyril, et c'est aussi la
     * reparation du §3.2.
     *
     * La methode heritee redirige EN DUR vers `organization.admin.crm.contacts`.
     * Sans la surcharge, ce test montrerait un SuperAdmin projete dans la
     * console OrgAdmin du tenant. Le test mesure la DESTINATION, pas l'intention.
     */
    public function test_le_suivi_ramene_sur_admin_users_en_conservant_les_filtres(): void
    {
        $origine = route('admin.users', ['search' => 'Ottoline', 'sort' => 'name', 'page' => 2]);

        $reponse = $this->actingAs($this->superAdmin)
            ->from($origine)
            ->post($this->urlSuivi($this->orgA, $this->membreA));

        $reponse->assertRedirect($origine);

        $this->assertStringNotContainsString('/org/', $reponse->headers->get('Location'),
            'Le SuperAdmin a ete projete dans la console OrgAdmin d\'un tenant (defaut §3.2).');
    }

    /**
     * Le flash porte le lien « voir la fiche », et par une cle DISTINCTE.
     *
     * Le bandeau rend `{{ session('success') }}` — echappe. Une ancre glissee
     * dans le message sortirait en texte brut ; l'URL doit donc voyager a part.
     */
    public function test_le_flash_porte_le_lien_vers_la_fiche_plateforme(): void
    {
        $this->actingAs($this->superAdmin)->post($this->urlSuivi($this->orgA, $this->membreA));

        $contact = CrmContact::where('user_id', $this->membreA->id)->firstOrFail();

        $this->assertSame(
            route('admin.crm.contacts.show', ['organization' => $this->orgA->slug, 'contact' => $contact->id]),
            session('flash_action')['url'] ?? null,
        );
    }

    /**
     * §3.4 — le premier suivi sur une Organization VIERGE seme son pipeline.
     *
     * Ce n'est pas un defaut, c'est le comportement voulu depuis T1414. Mais
     * c'est une ecriture de six lignes dans un tenant que personne n'avait
     * ouvert, declenchee depuis un ecran qui n'est pas Relations : elle doit
     * etre mesuree, et bornee a CE tenant.
     */
    public function test_le_premier_suivi_seme_le_pipeline_de_cette_organization_seulement(): void
    {
        $this->assertSame(0, CrmStatus::count(), 'Les deux Organizations partent vierges.');

        $this->actingAs($this->superAdmin)->post($this->urlSuivi($this->orgA, $this->membreA));

        $this->assertSame(6, CrmStatus::where('organization_id', $this->orgA->id)->count());
        $this->assertSame(0, CrmStatus::where('organization_id', $this->orgB->id)->count(),
            'Le pipeline a ete seme chez un tenant qui n\'etait pas vise.');

        $contact = CrmContact::where('user_id', $this->membreA->id)->firstOrFail();
        $this->assertNotNull($contact->status_id, 'Le contact doit porter le statut par defaut.');
    }

    /**
     * §4.2 / risque n°5 — une fiche SUPPRIMEE est restauree, et le flash le dit.
     *
     * `match()` scanne `withTrashed()` : restaurer plutot que doublonner est le
     * bon comportement, mais depuis `/admin/users` personne ne verrait qu'une
     * corbeille vient d'etre videe. C'est ce silence que le flash ferme.
     */
    public function test_une_fiche_supprimee_est_restauree_et_le_flash_le_dit(): void
    {
        $contact = app(CrmContactService::class)->followMember($this->orgA, $this->membreA, $this->superAdmin);
        $contact->delete();

        $this->assertTrue($contact->fresh()->trashed());

        $this->actingAs($this->superAdmin)->post($this->urlSuivi($this->orgA, $this->membreA))
            ->assertSessionHas('success', __('admin.crm_followed_and_restored'));

        $this->assertSame(1, CrmContact::withTrashed()->where('organization_id', $this->orgA->id)->count(),
            'La fiche a ete DOUBLONNEE au lieu d\'etre restauree.');
        $this->assertFalse($contact->fresh()->trashed());
    }

    // ── 🔴 §3.6 — la note, et son echec MUET ─────────────────────────────────

    /**
     * 🔴 Une note vide revient avec une erreur VISIBLE, et n'ecrit rien.
     *
     * Le layout admin ne rend NI `$errors` NI `@error` : un sac d'erreurs
     * standard rechargerait `/admin/users` sans un mot, le modal referme, et
     * rien ne dirait que la note n'a pas ete ecrite. C'est le pire des retours
     * possibles — l'ecran a l'air normal.
     *
     * `assertSessionHasErrors()` ne mesurerait PAS ce defaut : il passerait au
     * vert sur le comportement par defaut, celui-la meme qui est invisible.
     * C'est `session('error')` qu'il faut exiger.
     */
    public function test_une_note_vide_renvoie_une_erreur_visible_et_n_ecrit_rien(): void
    {
        $this->actingAs($this->superAdmin)
            ->post($this->urlNote($this->orgA, $this->membreA), ['body' => ''])
            ->assertSessionHas('error');

        $this->assertSame(0, CrmContactEvent::where('type', CrmContactEvent::TYPE_NOTE)->count());
        $this->assertSame(0, CrmContact::count(),
            'Une note refusee ne doit pas avoir ajoute la personne au suivi au passage.');
    }

    /** Et la RECIPROQUE : une note valide est bien ecrite. */
    public function test_une_note_valide_est_ecrite_et_ajoute_la_personne_au_suivi(): void
    {
        $this->actingAs($this->superAdmin)
            ->post($this->urlNote($this->orgA, $this->membreA), ['body' => 'Appel de 20 minutes.'])
            ->assertSessionHas('success', __('admin.crm_note_added_and_followed'))
            ->assertSessionMissing('error');

        $contact = CrmContact::where('user_id', $this->membreA->id)->firstOrFail();

        $note = CrmContactEvent::where('crm_contact_id', $contact->id)
            ->where('type', CrmContactEvent::TYPE_NOTE)->firstOrFail();

        $this->assertSame('Appel de 20 minutes.', $note->payload['body']);
    }

    /**
     * La note du SuperAdmin porte SA signature, dans le tenant du MEMBRE.
     *
     * `CrmTimelineService::guardAuthor()` laisse passer `is_admin` : c'est
     * l'acquis de T1431, pas une breche ouverte ici. Ce qui doit rester vrai,
     * c'est que la donnee tombe chez le membre, pas chez l'auteur.
     */
    public function test_la_note_est_signee_du_superadmin_dans_le_tenant_du_membre(): void
    {
        $superAdminAilleurs = User::factory()->create(['is_admin' => true, 'organization_id' => $this->orgB->id]);

        $this->actingAs($superAdminAilleurs)
            ->post($this->urlNote($this->orgA, $this->membreA), ['body' => 'Signee depuis ailleurs.']);

        $contact = CrmContact::where('user_id', $this->membreA->id)->firstOrFail();
        $note = CrmContactEvent::where('crm_contact_id', $contact->id)
            ->where('type', CrmContactEvent::TYPE_NOTE)->firstOrFail();

        $this->assertSame($this->orgA->id, $contact->organization_id, 'Le contact doit vivre chez le MEMBRE.');
        $this->assertSame($this->orgA->id, $note->organization_id);
        $this->assertSame($superAdminAilleurs->id, $note->author_user_id);
    }

    /**
     * Un canal renseigne avance « Dernier contact » ; sans canal, il ne bouge pas.
     *
     * Le selecteur du pop-up n'est pas decoratif : c'est la seule commande de
     * cet ecran qui modifie une colonne du contact.
     */
    public function test_le_canal_est_facultatif_et_gouverne_dernier_contact(): void
    {
        $this->actingAs($this->superAdmin)
            ->post($this->urlNote($this->orgA, $this->membreA), ['body' => 'Sans canal.']);

        $this->assertNull(CrmContact::where('user_id', $this->membreA->id)->firstOrFail()->last_interaction_at);

        $this->actingAs($this->superAdmin)
            ->post($this->urlNote($this->orgA, $this->membreA), ['body' => 'Avec canal.', 'channel' => 'call']);

        $this->assertNotNull(CrmContact::where('user_id', $this->membreA->id)->firstOrFail()->last_interaction_at);
    }

    /** Un canal inconnu est refuse par la validation, et ne devient pas `null` en silence. */
    public function test_un_canal_hors_catalogue_est_refuse(): void
    {
        $this->actingAs($this->superAdmin)
            ->post($this->urlNote($this->orgA, $this->membreA), ['body' => 'Texte.', 'channel' => 'pigeon'])
            ->assertSessionHas('error');

        $this->assertSame(0, CrmContactEvent::where('type', CrmContactEvent::TYPE_NOTE)->count());
    }

    // ── §3.5 — le message qui mentait sur la cause ───────────────────────────

    /**
     * Un pipeline entierement DESACTIVE ne doit plus annoncer un conflit d'email.
     *
     * Trois `LogicException` remontaient au meme `catch`, qui n'en decrivait
     * qu'une. Celle-ci — « aucun statut actif » — devient atteignable depuis
     * `/admin/users`, et l'exploitant cherchait alors un doublon d'email qui
     * n'existe pas.
     */
    public function test_sans_statut_actif_le_message_nomme_la_vraie_cause(): void
    {
        // Le pipeline existe mais tout est desactive : `ensureDefaultPipeline()`
        // ne re-seme pas, et `defaultStatus()` echoue.
        $this->actingAs($this->superAdmin)->post($this->urlSuivi($this->orgA, $this->membreA));
        CrmStatus::where('organization_id', $this->orgA->id)->update(['is_active' => false]);

        $autre = User::factory()->create(['organization_id' => $this->orgA->id, 'email' => 'autre@example.com']);

        $this->actingAs($this->superAdmin)
            ->post($this->urlSuivi($this->orgA, $autre))
            ->assertSessionHas('error', __('crm.flash.follow_no_active_status'));
    }

    // ── §8.8 — la donnee arrive la ou Cyril l'attend ─────────────────────────

    /**
     * Le bout en bout de la demande, mot pour mot : apres les deux gestes du
     * SuperAdmin, l'OrgAdmin du tenant « se retrouve » avec le contact et la
     * note dans SA propre surface Relations.
     *
     * C'est le seul test qui mesure la demande telle qu'elle a ete formulee —
     * les autres mesurent des gardes.
     */
    public function test_l_orgadmin_du_tenant_retrouve_le_contact_et_la_note(): void
    {
        $adminOrg = User::factory()->create(['organization_id' => $this->orgA->id, 'is_admin' => false]);
        $this->orgA->forceFill(['admin_id' => $adminOrg->id])->save();

        $this->actingAs($this->superAdmin)
            ->post($this->urlNote($this->orgA, $this->membreA), ['body' => 'Vu par son OrgAdmin.']);

        $contact = CrmContact::where('user_id', $this->membreA->id)->firstOrFail();

        $this->actingAs($adminOrg)
            ->get(route('organization.admin.crm.contacts', ['organization' => $this->orgA->slug, 'search' => $this->membreA->email]))
            ->assertOk()
            ->assertSee('Kaminski', false);

        $this->actingAs($adminOrg)
            ->get(route('organization.admin.crm.contacts.show', ['organization' => $this->orgA->slug, 'contact' => $contact->id]))
            ->assertOk()
            ->assertSee('Vu par son OrgAdmin.', false);
    }

    // ── La liste : l'etat « deja suivi » ─────────────────────────────────────

    /**
     * Un membre suivi porte le LIEN « Deja suivi » et plus le bouton ; le bouton
     * de note, lui, ne change jamais d'etat (decision n°2 de Cyril).
     */
    public function test_un_membre_suivi_porte_le_lien_vers_sa_fiche_et_garde_le_bouton_de_note(): void
    {
        $contact = app(CrmContactService::class)->followMember($this->orgA, $this->membreA, $this->superAdmin);

        $html = $this->actingAs($this->superAdmin)->get(route('admin.users'))->assertOk()->getContent();

        $this->assertStringContainsString('data-crm-followed="'.$this->membreA->id.'"', $html);
        $this->assertStringNotContainsString('data-crm-follow="'.$this->membreA->id.'"', $html,
            'Le bouton de suivi aurait du ceder la place au lien.');
        $this->assertStringContainsString('data-crm-note-trigger="'.$this->membreA->id.'"', $html,
            'Le bouton de note ne change JAMAIS d\'etat.');

        $this->assertStringContainsString(
            e(route('admin.crm.contacts.show', ['organization' => $this->orgA->slug, 'contact' => $contact->id])),
            $html,
            'Le libelle « Deja suivi » doit mener a la fiche PLATEFORME.',
        );
    }

    /**
     * La colonne « deja suivi » coute UNE requete pour toute la page, pas une
     * par ligne.
     *
     * `TASK1640UserDeleteFromListTest` mesure deja la PENTE de cet ecran ; cette
     * garde-ci nomme la cause precise que T1753 pourrait introduire — un
     * `CrmContact::where('user_id', …)->first()` dans la boucle `@forelse`.
     */
    public function test_l_etat_deja_suivi_ne_coute_qu_une_requete_quel_que_soit_le_nombre_de_comptes(): void
    {
        $compter = function (): int {
            \DB::flushQueryLog();
            \DB::enableQueryLog();
            $this->actingAs($this->superAdmin)->get(route('admin.users'))->assertOk();
            $total = count(\DB::getQueryLog());
            \DB::disableQueryLog();

            return $total;
        };

        $avant = $compter();

        User::factory()->count(9)->create(['organization_id' => $this->orgA->id]);

        $this->assertLessThan(10, $compter() - $avant,
            'Neuf comptes de plus ont coute trop de requetes : un precheck est calcule PAR LIGNE.');
    }
}
