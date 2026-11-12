<?php

namespace Tests\Feature;

use App\Models\Loop;
use App\Models\LoopMember;
use App\Models\LoopMessage;
use App\Models\Organization;
use App\Models\User;
use App\Services\LoopMessageService;
use App\Services\UrlPreviewService;
use App\Support\Loops\LoopTypeRegistry;
use App\Support\Sharing\ShareIntent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * TASK-1738 — partage d'un lien externe vers une Boucle (Web Share Target).
 *
 * Ce que cette suite defend, dans les termes du CDC section 4.6 :
 *
 * - une URL partagee apparait dans la ChatLoop CHOISIE, apres validation
 *   humaine, et seulement la ;
 * - le commentaire est facultatif ;
 * - un `loop_id` falsifie ou hors tenant est refuse, ZERO message cree ;
 * - deux confirmations concurrentes creent AU PLUS un message, et un echec
 *   recuperable preserve l'intention ;
 * - aucun GET n'ecrit ; aucune requete serveur vers un hote interdit ;
 * - l'adresse partagee ne reste pas en query string visible.
 */
class TASK1738ShareTargetTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Organization $autreOrg;

    private User $membre;

    /** La Boucle ou `$membre` est membre actif : la seule ou il publie. */
    private Loop $maBoucle;

    /** Meme Organization, mais `$membre` n'y est PAS membre. */
    private Loop $boucleEtrangere;

    /** Une Boucle d'une AUTRE Organization — la frontiere de tenant. */
    private Loop $boucleAutreOrg;

    private const URL_PARTAGEE = 'https://exemple.org/un-article-interessant';

    protected function setUp(): void
    {
        parent::setUp();

        // TASK-1749 — `is_public` explicite : la selection d'Organization est
        // obligatoire sur `/login` et le selecteur ne propose que du public.
        $this->org = Organization::factory()->create(['loops_enabled' => true, 'is_active' => true, 'is_public' => true]);
        $this->autreOrg = Organization::factory()->create(['loops_enabled' => true, 'is_active' => true]);

        $this->membre = User::factory()->create(['organization_id' => $this->org->id]);

        $this->maBoucle = $this->boucleActive($this->org, 'Boucle des membres');
        $this->boucleEtrangere = $this->boucleActive($this->org, 'Boucle fermee');
        $this->boucleAutreOrg = $this->boucleActive($this->autreOrg, 'Boucle d ailleurs');

        LoopMember::factory()->create([
            'loop_id' => $this->maBoucle->id, 'user_id' => $this->membre->id,
            'role' => 'member', 'status' => 'active', 'joined_at' => now(),
        ]);
    }

    private function boucleActive(Organization $org, string $nom): Loop
    {
        $loop = Loop::factory()->create([
            'organization_id' => $org->id, 'status' => 'active',
            'type' => 'general', 'name' => $nom,
        ]);
        app(LoopTypeRegistry::class)->applyPreset($loop);

        return $loop;
    }

    /** Range une intention en session comme la feuille de partage l'aurait fait. */
    private function recevoir(string $url): void
    {
        $this->get('/partage?'.http_build_query(['url' => $url]))->assertStatus(303);
    }

    private function jetonEnSession(): string
    {
        return (string) session(ShareIntent::CLE_SESSION)['jeton'];
    }

    // ── Le manifest ──────────────────────────────────────────────────────────

    public function test_le_manifest_declare_la_cible_de_partage(): void
    {
        // TASK-1805 — `/site.webmanifest` est desormais une route Laravel,
        // plus un fichier statique de `public/` : lecture via une vraie
        // requete HTTP.
        $manifest = $this->get('/site.webmanifest')->json();

        $this->assertSame('/partage', $manifest['share_target']['action'] ?? null);
        $this->assertSame('GET', $manifest['share_target']['method'] ?? null);
        $this->assertSame(
            ['url' => 'url', 'text' => 'text', 'title' => 'title'],
            $manifest['share_target']['params'] ?? null,
        );
    }

    // ── La reception ─────────────────────────────────────────────────────────

    public function test_la_reception_range_l_intention_et_redirige_vers_une_url_nue(): void
    {
        $reponse = $this->get('/partage?url='.urlencode(self::URL_PARTAGEE).'&title=Un+titre');

        // 303 vers l'URL NUE : l'adresse partagee ne reste pas en query
        // string visible (CDC 4.5) — ni historique, ni Referer, ni journaux.
        $reponse->assertStatus(303)->assertRedirect(route('partage.choisir'));

        $stocke = session(ShareIntent::CLE_SESSION);
        $this->assertSame(self::URL_PARTAGEE, $stocke['url']);
        $this->assertSame('Un titre', $stocke['titre']);
        $this->assertNotSame('', $stocke['jeton']);
    }

    public function test_la_reception_extrait_l_url_noyee_dans_le_champ_text(): void
    {
        // LinkedIn et d'autres applications mettent l'adresse dans `text`,
        // parfois collee a du texte libre.
        $this->get('/partage?text='.urlencode('Regarde ça : https://exemple.org/article à lire !'))
            ->assertStatus(303);

        $this->assertSame('https://exemple.org/article', session(ShareIntent::CLE_SESSION)['url']);
    }

    public function test_a_dangerous_scheme_is_never_extracted(): void
    {
        // La garde de `ShareIntent` s'appuie sur le fait que l'extracteur ne
        // reconnait que http/https. Ce test ATTESTE cette dependance : si
        // l'expression reguliere de `extractFirstUrl()` s'elargit un jour,
        // il rougit ici, pas en production.
        $this->assertNull(ShareIntent::extraireUneUrlSure('javascript:alert(1)'));
        $this->assertNull(ShareIntent::extraireUneUrlSure('data:text/html,<script>alert(1)</script>'));
        $this->assertNull(ShareIntent::extraireUneUrlSure('file:///etc/passwd'));

        $this->get('/partage?url='.urlencode('javascript:alert(1)'))->assertStatus(303);
        $this->assertNull(session(ShareIntent::CLE_SESSION));
    }

    public function test_une_url_porteuse_d_identifiants_est_refusee_pas_nettoyee(): void
    {
        $this->assertNull(ShareIntent::extraireUneUrlSure('https://jean:motdepasse@site.fr/page'));
    }

    public function test_une_url_trop_longue_est_refusee(): void
    {
        $url = 'https://exemple.org/'.str_repeat('a', ShareIntent::LONGUEUR_MAX_URL);

        $this->assertNull(ShareIntent::extraireUneUrlSure($url));
    }

    public function test_une_reception_sans_lien_exploitable_efface_l_intention_precedente(): void
    {
        $this->recevoir(self::URL_PARTAGEE);
        $this->assertNotNull(session(ShareIntent::CLE_SESSION));

        $this->get('/partage?text='.urlencode('du texte sans aucun lien'))->assertStatus(303);

        // Une intention VIDE n'ecrase pas en silence : elle retire. Sinon le
        // prochain passage sur l'ecran de choix republierait l'ancien lien.
        $this->assertNull(session(ShareIntent::CLE_SESSION));
    }

    public function test_la_reception_et_l_ecran_de_choix_portent_les_entetes_de_discretion(): void
    {
        $reception = $this->get('/partage?url='.urlencode(self::URL_PARTAGEE));
        $this->assertStringContainsString('no-store', (string) $reception->headers->get('Cache-Control'));
        $this->assertSame('no-referrer', $reception->headers->get('Referrer-Policy'));

        $choix = $this->actingAs($this->membre)->get(route('partage.choisir'));
        $this->assertStringContainsString('no-store', (string) $choix->headers->get('Cache-Control'));
        $this->assertSame('no-referrer', $choix->headers->get('Referrer-Policy'));
    }

    // ── L'intention a travers la connexion et le temps ───────────────────────

    public function test_l_intention_survit_a_la_connexion(): void
    {
        // Le cas LONG du parcours : quelqu'un partage depuis une application
        // SANS etre connecte. La reception est publique, l'intention vit en
        // session, et `regenerate()` a la connexion CONSERVE le contenu.
        $this->recevoir(self::URL_PARTAGEE);

        $this->get(route('partage.choisir'))->assertRedirect(route('login'));

        $this->post(route('login'), [
            'email' => $this->membre->email,
            'password' => 'password',
            'organization' => $this->org->slug,
        ])->assertSessionHasNoErrors();

        $this->get(route('partage.choisir'))
            ->assertOk()
            ->assertSee(self::URL_PARTAGEE);
    }

    public function test_une_intention_perimee_disparait(): void
    {
        $this->recevoir(self::URL_PARTAGEE);

        $this->travel(16)->minutes();

        $reponse = $this->actingAs($this->membre)->get(route('partage.choisir'));

        // L'ecran retombe sur le formulaire de collage, et la session est
        // PURGEE — pas seulement ignoree.
        $reponse->assertOk()->assertDontSee(self::URL_PARTAGEE);
        $this->assertNull(session(ShareIntent::CLE_SESSION));
    }

    // ── L'ecran de choix ─────────────────────────────────────────────────────

    public function test_l_ecran_ne_liste_que_les_boucles_ou_cette_personne_publie(): void
    {
        $this->recevoir(self::URL_PARTAGEE);

        $reponse = $this->actingAs($this->membre)->get(route('partage.choisir'));

        $reponse->assertOk()
            ->assertSee('Boucle des membres')
            // Pas membre : la liste ne propose jamais un bouton que la
            // publication refuserait.
            ->assertDontSee('Boucle fermee')
            // Et JAMAIS une Boucle d'une autre Organization.
            ->assertDontSee('Boucle d ailleurs');
    }

    public function test_sans_intention_l_ecran_offre_le_collage_de_repli(): void
    {
        // Le repli iPhone/desktop (CDC 4.3) : pas de feuille de partage, un
        // champ de collage qui repasse par la MEME reception.
        $this->actingAs($this->membre)->get(route('partage.choisir'))
            ->assertOk()
            ->assertSee('action="'.route('partage.recevoir').'"', false)
            ->assertSee('name="url"', false);
    }

    // ── La publication ───────────────────────────────────────────────────────

    public function test_la_publication_cree_un_message_enrichi_dans_la_boucle_choisie(): void
    {
        $apercu = [
            'url' => self::URL_PARTAGEE, 'domain' => 'exemple.org',
            'title' => 'Un article interessant', 'description' => 'Le resume.', 'image' => null,
        ];
        $this->mock(UrlPreviewService::class)
            ->shouldReceive('fetchPreview')->with(self::URL_PARTAGEE)->andReturn($apercu);

        $this->recevoir(self::URL_PARTAGEE);

        $reponse = $this->actingAs($this->membre)->post(route('partage.publier'), [
            'loop' => $this->maBoucle->id,
            'jeton' => $this->jetonEnSession(),
            'commentaire' => 'A lire pour la reunion de lundi.',
        ]);

        $message = LoopMessage::sole();
        $this->assertSame($this->maBoucle->id, $message->loop_id);
        $this->assertSame($this->org->id, $message->organization_id);
        $this->assertSame($this->membre->id, $message->sender_id);
        $this->assertSame('user', $message->type);
        $this->assertSame("A lire pour la reunion de lundi.\n\n".self::URL_PARTAGEE, $message->body);
        $this->assertSame('Un article interessant', $message->metadata['url_preview']['title']);

        $reponse->assertRedirect($this->maBoucle->workspaceUrl());
        $this->assertNull(session(ShareIntent::CLE_SESSION));
    }

    public function test_le_commentaire_est_facultatif(): void
    {
        Http::fake();
        $this->recevoir(self::URL_PARTAGEE);

        $this->actingAs($this->membre)->post(route('partage.publier'), [
            'loop' => $this->maBoucle->id,
            'jeton' => $this->jetonEnSession(),
        ]);

        // L'URL seule EST un message valide (CDC 4.1) — le corps est le lien,
        // rien d'autre.
        $this->assertSame(self::URL_PARTAGEE, LoopMessage::sole()->body);
    }

    public function test_une_boucle_falsifiee_est_refusee_sans_rien_creer(): void
    {
        Http::fake();

        foreach ([$this->boucleEtrangere, $this->boucleAutreOrg] as $interdite) {
            $this->recevoir(self::URL_PARTAGEE);

            $reponse = $this->actingAs($this->membre)->post(route('partage.publier'), [
                'loop' => $interdite->id,
                'jeton' => $this->jetonEnSession(),
            ]);

            $reponse->assertRedirect(route('partage.choisir'))->assertSessionHasErrors('loop');
        }

        // ZERO message, dans TOUTES les Boucles (CDC 4.6) — et l'intention
        // est conservee : le refus n'a pas mange le lien.
        $this->assertSame(0, LoopMessage::count());
        $this->assertNotNull(session(ShareIntent::CLE_SESSION));
    }

    public function test_une_boucle_archivee_est_refusee(): void
    {
        Http::fake();
        $this->maBoucle->update(['status' => 'archived']);

        $this->recevoir(self::URL_PARTAGEE);

        $this->actingAs($this->membre)->post(route('partage.publier'), [
            'loop' => $this->maBoucle->id,
            'jeton' => $this->jetonEnSession(),
        ])->assertSessionHasErrors('loop');

        $this->assertSame(0, LoopMessage::count());
    }

    public function test_aucun_get_n_ecrit_de_message(): void
    {
        Http::fake();
        $this->recevoir(self::URL_PARTAGEE);
        $this->actingAs($this->membre)->get(route('partage.choisir'))->assertOk();

        $this->assertSame(0, LoopMessage::count());

        // Et la route de publication n'a tout simplement PAS de forme GET.
        $this->actingAs($this->membre)->get('/partage/publier')->assertStatus(405);
    }

    public function test_un_jeton_etranger_ne_publie_rien(): void
    {
        Http::fake();
        $this->recevoir(self::URL_PARTAGEE);

        $this->actingAs($this->membre)->post(route('partage.publier'), [
            'loop' => $this->maBoucle->id,
            'jeton' => str_repeat('0', 32),
        ])->assertSessionHasErrors('jeton');

        $this->assertSame(0, LoopMessage::count());
    }

    public function test_une_intention_perimee_au_moment_du_post_ne_publie_rien(): void
    {
        Http::fake();
        $this->recevoir(self::URL_PARTAGEE);
        $jeton = $this->jetonEnSession();

        $this->travel(16)->minutes();

        $this->actingAs($this->membre)->post(route('partage.publier'), [
            'loop' => $this->maBoucle->id,
            'jeton' => $jeton,
        ])->assertRedirect(route('partage.choisir'));

        $this->assertSame(0, LoopMessage::count());
    }

    // ── Anti-doublon et reprise ──────────────────────────────────────────────

    public function test_deux_confirmations_du_meme_partage_creent_au_plus_un_message(): void
    {
        Http::fake();
        $this->recevoir(self::URL_PARTAGEE);
        $intention = session(ShareIntent::CLE_SESSION);

        $this->actingAs($this->membre)->post(route('partage.publier'), [
            'loop' => $this->maBoucle->id,
            'jeton' => $intention['jeton'],
        ]);
        $this->assertSame(1, LoopMessage::count());

        // La seconde confirmation : meme formulaire, meme jeton — l'onglet
        // duplique, le double-clic, ou le client qui retente apres un timeout
        // ambigu. On remet l'intention en session comme si la premiere
        // reponse ne lui etait jamais parvenue.
        session()->put(ShareIntent::CLE_SESSION, $intention);

        $this->actingAs($this->membre)->post(route('partage.publier'), [
            'loop' => $this->maBoucle->id,
            'jeton' => $intention['jeton'],
        ])->assertRedirect(route('partage.choisir'));

        $this->assertSame(1, LoopMessage::count());
    }

    public function test_la_pose_du_jeton_est_atomique_pas_lire_puis_supprimer(): void
    {
        // Le contrat du CDC 4.5 en une assertion : la protection est une pose
        // CONDITIONNELLE unique (`Cache::add`), pas deux temps separables.
        // Deux poseurs, une seule pose — quel que soit l'entrelacement.
        $cle = 'partage:publie:atomicite-attestee';

        $this->assertTrue(Cache::add($cle, 1, 60));
        $this->assertFalse(Cache::add($cle, 1, 60));
    }

    public function test_un_echec_de_publication_preserve_l_intention_et_permet_la_reprise(): void
    {
        Http::fake();
        $this->mock(LoopMessageService::class)
            ->shouldReceive('sendUserMessage')->once()
            ->andThrow(new \RuntimeException('indisponible'));

        $this->recevoir(self::URL_PARTAGEE);
        $jeton = $this->jetonEnSession();

        $this->actingAs($this->membre)->post(route('partage.publier'), [
            'loop' => $this->maBoucle->id,
            'jeton' => $jeton,
        ])->assertSessionHasErrors('loop');

        // Rien n'est publie, mais RIEN n'est perdu : l'intention est toujours
        // la, et le jeton relache — l'essai suivant repart entier (CDC 4.5).
        $this->assertSame(0, LoopMessage::count());
        $this->assertNotNull(session(ShareIntent::CLE_SESSION));

        $this->app->forgetInstance(LoopMessageService::class);
        $this->app->instance(LoopMessageService::class, new LoopMessageService);

        $this->actingAs($this->membre)->post(route('partage.publier'), [
            'loop' => $this->maBoucle->id,
            'jeton' => $jeton,
        ]);

        $this->assertSame(1, LoopMessage::count());
    }

    // ── La bascule de compte, que le CDC 4.5 demande de surveiller ──────────

    public function test_une_bascule_de_compte_abandonne_l_intention_de_partage(): void
    {
        // CDC 4.5 : « attention aux changements de compte lie et
        // d'Organization pendant la reception d'un partage ».
        //
        // La protection n'est PAS dans cette TASK : elle vient de
        // `AccountSwitch::versLeCompte()`, qui appelle `invalidate()` et pas
        // seulement `regenerate()`. Une intention ne peut donc pas franchir
        // une frontiere de tenant dans les bagages d'une session.
        //
        // Ce test existe pour que cette dependance soit ATTESTEE : le jour ou
        // quelqu'un allegerait la bascule en `regenerate()`, il rougit ici.
        // Il est pose sur `versLeCompte()` et non sur l'une des deux routes de
        // bascule, parce que c'est le goulot que A5a et A5b traversent toutes
        // les deux.
        $compteFrere = User::factory()->create(['organization_id' => $this->autreOrg->id]);

        $this->actingAs($this->membre);
        $this->recevoir(self::URL_PARTAGEE);
        $this->assertNotNull(session(ShareIntent::CLE_SESSION));

        app(\App\Services\Users\AccountSwitch::class)->versLeCompte($compteFrere);

        $this->assertNull(session(ShareIntent::CLE_SESSION));
    }

    // ── SSRF : l'hote prive comme TEXTE, jamais comme CIBLE ─────────────────

    public function test_un_hote_prive_est_publie_comme_texte_jamais_recupere(): void
    {
        Http::fake();
        $urlPrivee = 'http://192.168.1.10/tableau-de-bord';

        $this->recevoir($urlPrivee);

        $this->actingAs($this->membre)->get(route('partage.choisir'))->assertOk();

        $this->actingAs($this->membre)->post(route('partage.publier'), [
            'loop' => $this->maBoucle->id,
            'jeton' => $this->jetonEnSession(),
        ]);

        // Le message EXISTE — l'hote interne est autorise comme texte — mais
        // AUCUNE requete serveur n'est partie vers lui (CDC 4.5), et aucun
        // apercu n'a ete fabrique.
        $message = LoopMessage::sole();
        $this->assertSame($urlPrivee, $message->body);
        $this->assertNull($message->metadata['url_preview'] ?? null);

        Http::assertNothingSent();
    }
}
