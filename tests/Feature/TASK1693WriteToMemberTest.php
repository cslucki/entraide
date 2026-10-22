<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Transaction;
use App\Models\User;
use App\Services\UserSlugService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * TASK-1693, bug AA — ecrire a quelqu'un, depuis son profil ou depuis
 * l'annuaire ; et des liens de profil qui portent l'adresse LISIBLE.
 *
 * ## Le premier defaut : « on arrive bien sur la Messagerie, mais on ne peut
 * pas ecrire »
 *
 * La route `messages.with` n'existait QUE sans prefixe. Or
 * `ResolveUrlOrganization` est dans le groupe `web` GLOBAL et lie
 * l'Organization PAR DEFAUT sur une URL non prefixee ; la redirection
 * canonique, elle, ne s'applique qu'aux URL d'UN SEUL segment
 * (`ResolveUrlOrganization:256`), donc jamais a `/messages/with/{user}`.
 *
 * La garde inter-tenant de TASK-1289 refusait alors — A JUSTE TITRE, puisque
 * l'Organization resolue n'etait pas celle de l'acteur — et renvoyait vers la
 * liste des messages. **Le defaut n'etait pas la garde, c'etait le LIEN.**
 *
 * ## Le second : les liens restaient en UUID
 *
 * Le bug Z posait la redirection canonique, qui corrige la barre d'adresse
 * mais PAS les `href`. Les 44 points de generation passaient un modele, donc
 * `getRouteKey()`, donc l'identifiant. Ils passent desormais par
 * `profileRouteKey()`.
 *
 * On ne surcharge toujours pas `getRouteKey()` : plus de 20 noms de routes hors
 * profil prennent un `{user}`, et il faudrait les epingler un par un — plus de
 * sites a corriger que ce que l'on repare.
 */
class TASK1693WriteToMemberTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $alice;

    private User $bob;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create([
            'slug' => 'org-1693aa',
            'is_active' => true,
            'is_public' => true,
        ]);

        $this->alice = $this->membre('Alice', 'Martin');
        $this->bob = $this->membre('Bob', 'Durand');

        app()->instance('current_organization', $this->organization);

        Http::preventStrayRequests();
        Http::fake();
    }

    private function membre(string $prenom, string $nom): User
    {
        $user = User::factory()->complete()->create([
            'organization_id' => $this->organization->id,
            'first_name' => $prenom,
            'name' => $nom,
            'email_verified_at' => now(),
            'slug' => null,
        ]);

        app(UserSlugService::class)->attribuerDepuisLeNom($user);

        return $user->refresh();
    }

    private function profilRoute(User $qui): string
    {
        return route('organization.profile.show', [
            'organization' => $this->organization->slug,
            'user' => $qui->profileRouteKey(),
        ]);
    }

    private function urlEcrire(User $vers): string
    {
        return route('organization.messages.with', [
            'organization' => $this->organization->slug,
            'user' => $vers->profileRouteKey(),
        ]);
    }

    // ── Ecrire depuis le profil ──────────────────────────────────────────────

    /** LE defaut : la route n'existait pas sur la surface de l'Organization. */
    public function test_the_write_route_exists_on_the_organization_surface(): void
    {
        $this->assertTrue(\Illuminate\Support\Facades\Route::has('organization.messages.with'),
            'sans elle, le lien visait une surface ou l\'Organization ne peut pas etre la bonne');
    }

    /** Le profil pointe vers cette surface, pas vers la surface courte. */
    public function test_the_profile_points_at_the_organization_surface(): void
    {
        $html = $this->actingAs($this->alice)
            ->get(route('organization.profile.show', [
                'organization' => $this->organization->slug,
                'user' => $this->bob->profileRouteKey(),
            ]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(e($this->urlEcrire($this->bob)), $html);
    }

    /** Et le geste ABOUTIT : une conversation s'ouvre, avec un champ de saisie. */
    public function test_writing_from_the_profile_actually_opens_a_conversation(): void
    {
        $this->assertSame(0, Transaction::count());

        $reponse = $this->actingAs($this->alice)
            ->get($this->urlEcrire($this->bob))
            ->assertRedirect();

        $this->assertSame(1, Transaction::count(), 'la conversation directe doit etre creee');

        $html = $this->actingAs($this->alice)
            ->followRedirects($reponse)
            ->assertOk()
            ->getContent();

// Viser le FAIT — une zone de saisie — plutot qu'un libelle precis.
        // Le composeur est un composant Livewire et son texte d'invite ne vient
        // pas de la clef que je visais d'abord : une assertion sur ce libelle
        // aurait rougi pour une raison ETRANGERE au defaut.
        $this->assertStringContainsString('<textarea', $html,
            'arriver sur la Messagerie sans pouvoir ecrire etait precisement le defaut');

        $this->assertStringContainsString(
            e(route('organization.messages.show', [
                'organization' => $this->organization->slug,
                'transaction' => Transaction::sole()->id,
            ])),
            $html,
            'on doit se trouver dans la conversation avec cette personne, pas sur la liste',
        );
    }

    /**
     * LA mesure du defaut d'origine : la surface courte refuse toujours, et
     * c'est NORMAL.
     *
     * `ResolveUrlOrganization` y lie l'Organization par defaut, etrangere a
     * l'acteur. La garde de TASK-1289 fait son travail — on ne l'a pas
     * affaiblie, on a corrige le lien qui menait la.
     */
    public function test_the_short_surface_still_refuses_and_that_is_correct(): void
    {
        app()->forgetInstance('current_organization');

        Organization::factory()->create(['slug' => 'defaut-1693aa', 'is_default' => true, 'is_active' => true]);

        // Par l'IDENTIFIANT : un slug est borne a une Organization, et sur la
        // surface courte l'Organization liee est celle par defaut — le slug
        // n'y resout pas, ce qui donnerait un 404 et mesurerait la resolution
        // au lieu de la garde inter-tenant.
        $this->actingAs($this->alice)
            ->get(route('messages.with', $this->bob->id))
            ->assertRedirect(route('messages.index'));

        $this->assertSame(0, Transaction::count(),
            'aucune conversation ne doit naitre dans une Organization etrangere');
    }

    /** Une seconde visite ne cree pas une seconde conversation. */
    public function test_writing_twice_reuses_the_same_conversation(): void
    {
        $this->actingAs($this->alice)->get($this->urlEcrire($this->bob))->assertRedirect();
        $this->actingAs($this->alice)->get($this->urlEcrire($this->bob))->assertRedirect();

        $this->assertSame(1, Transaction::count());
    }

    // ── Ecrire depuis l'annuaire ─────────────────────────────────────────────

    /** LE second geste demande : une icone par membre. */
    public function test_the_directory_offers_a_write_icon_per_member(): void
    {
        $html = $this->actingAs($this->alice)
            ->get(route('organization.members.index', ['organization' => $this->organization->slug]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-member-write="'.$this->bob->id.'"', $html);
        $this->assertStringContainsString(e($this->urlEcrire($this->bob)), $html);
    }

    /** Mais pas en face de soi-meme : on ne s'ecrit pas. */
    public function test_the_directory_offers_no_write_icon_for_oneself(): void
    {
        $html = $this->actingAs($this->alice)
            ->get(route('organization.members.index', ['organization' => $this->organization->slug]))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('data-member-write="'.$this->alice->id.'"', $html);
    }

    /**
     * Le bouton est pose A COTE de la carte, jamais DEDANS.
     *
     * La carte entiere est un lien : un lien imbrique dans un lien est
     * invalide en HTML et le navigateur le deplace hors de son parent. Meme
     * famille que le formulaire imbrique corrige en TASK-1668 — invisible aux
     * tests qui ne regardent que le serveur, d'ou cette mesure explicite.
     */
    public function test_the_write_link_is_not_nested_inside_the_card_link(): void
    {
        $html = $this->actingAs($this->alice)
            ->get(route('organization.members.index', ['organization' => $this->organization->slug]))
            ->assertOk()
            ->getContent();

        $marqueur = 'data-member-write="'.$this->bob->id.'"';
        $avant = substr($html, 0, (int) strpos($html, $marqueur));

        // Mesurer LOCALEMENT, entre le lien de la carte et le bouton : il
        // doit s'y trouver un `</a>`.
        //
        // Compter les balises de toute la page ne marche pas — elle ferme un
        // `</a>` de plus qu'elle n'ouvre de `<a`, pour des raisons etrangeres
        // a ce correctif. Et comparer des POSITIONS, comme mon tout premier
        // jet, ne dit rien de l'imbrication.
        $debutCarte = (int) strpos($html, e($this->profilRoute($this->bob)));

        $this->assertStringContainsString(
            '</a>',
            substr($avant, $debutCarte),
            'le bouton « ecrire » est imbrique dans le lien de la carte : un lien dans un lien est invalide en HTML',
        );
    }

    // ── Les liens de profil portent l'adresse lisible ────────────────────────

    /** L'annuaire ne pointe plus vers l'identifiant. */
    public function test_the_directory_links_to_the_readable_address(): void
    {
        $html = $this->actingAs($this->alice)
            ->get(route('organization.members.index', ['organization' => $this->organization->slug]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('/profile/bob-durand', $html);
        $this->assertStringNotContainsString('/profile/'.$this->bob->id, $html,
            'l\'annuaire construisait son URL a la main, avec l\'identifiant');
    }

    /**
     * La garde de fond : AUCUN ecran ne doit plus construire un lien de profil
     * depuis un modele brut.
     *
     * Un test par ecran resterait vert pour celui qu'on aurait oublie. Celui-ci
     * lit la SOURCE, comme le fait deja `TASK1147EscapedNameAssertionsTest`.
     */
    public function test_no_screen_builds_a_profile_link_from_a_raw_model(): void
    {
        $fautifs = [];

        foreach ([resource_path('views'), app_path()] as $racine) {
            $fichiers = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($racine));

            foreach ($fichiers as $fichier) {
                if (! $fichier->isFile() || ! str_ends_with($fichier->getFilename(), '.php')) {
                    continue;
                }

                foreach (file($fichier->getPathname()) as $n => $ligne) {
                    if (! str_contains($ligne, "profile.show'")) {
                        continue;
                    }

                    // `route('…profile.show', $x)` ou `'user' => $x` sans
                    // `profileRouteKey()` ni identifiant explicite.
                    if (preg_match("/route\('(?:organization\.)?profile\.show', \\\$[A-Za-z_>-]+\)/", $ligne)
                        || preg_match("/'user' => \\\$[A-Za-z_>-]+\s*[\],]/", $ligne)) {
                        // Sont acceptes : `profileRouteKey()`, un `->id`
                        // explicite, ou une variable nommee `…Segment` /
                        // `…Id` — la convention qui dit « ceci est deja le
                        // segment d'URL, pas un modele ».
                        if (! str_contains($ligne, 'profileRouteKey') && ! str_contains($ligne, '->id')
                            && ! str_contains($ligne, 'Id') && ! str_contains($ligne, 'Segment')) {
                            $fautifs[] = basename($fichier->getPathname()).':'.($n + 1);
                        }
                    }
                }
            }
        }

        $this->assertSame([], $fautifs,
            "Ces liens de profil portent encore l'identifiant :\n".implode("\n", $fautifs));
    }
}
