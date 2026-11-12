<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * TASK-1737 — BouclePro est-elle REELLEMENT installable depuis un telephone ?
 *
 * ## Le defaut que ces tests ferment
 *
 * Mesure du 31/10/2026, sur le tunnel Cloudflare public (origine
 * `test.laravel`) :
 *
 *     GET /          -> 302 vers /org/main
 *     GET /org/main  -> 200, gabarit `hero-v2`
 *                       rel="manifest"               : 0
 *                       name="theme-color"           : 0
 *                       apple-mobile-web-app-capable : 0
 *                       enregistrement du SW         : 0
 *
 * Le manifest etait declare dans DEUX layouts sur QUINZE documents HTML
 * complets. La racine de BouclePro atterrissait donc sur une page ou
 * l'installation n'etait pas difficile : elle etait **impossible**.
 *
 * ## Pourquoi un inventaire STATIQUE et pas un parcours de routes
 *
 * Le premier jet interrogeait les routes en HTTP. Il mesurait mal : la plupart
 * des gabarits d'entree exigent une Organization, un `homepage_template`
 * precis, parfois une authentification — et un gabarit NON encore route serait
 * passe inapercu alors que c'est exactement celui qui cassera demain.
 *
 * L'inventaire porte donc sur les FICHIERS : tout document HTML complet des
 * vues doit declarer le contrat, et les exceptions sont NOMMEES ici. C'est la
 * forme qu'avait prise la meme garde en TASK-1471 pour `x-theme-tokens`, et
 * c'est elle qui rougit le jour ou quelqu'un ajoute un gabarit autonome.
 *
 * Deux tests HTTP viennent ensuite, sur les deux chemins d'entree reels, pour
 * prouver que le rendu porte bien ce que les fichiers declarent.
 */
class TASK1737PwaInstallationTest extends TestCase
{
    /**
     * Les documents HTML complets qui NE doivent PAS porter le contrat PWA.
     *
     * Liste NOMMEE, jamais un motif large : une exception qui s'explique est
     * une decision, une exception qui se devine est un oubli.
     *
     * @var array<string, string>
     */
    private const EXCEPTIONS = [
        // Un client de messagerie n'a rien a installer. Un `<link
        // rel="manifest">` dans un email est au mieux ignore, au pire suspect.
        'emails/blog-invitation.blade.php' => 'gabarit d\'email',
        'emails/referral-invitation.blade.php' => 'gabarit d\'email',
        // TASK-1809 — meme raison : aucun client de messagerie n'a rien a
        // installer. Ce fichier habille le HTML administre que
        // `NotificationEmailDeliverer` livre (voir son docbloc).
        'emails/notification-shell.blade.php' => 'gabarit d\'email',

        // Gabarit par defaut de Laravel, qu'AUCUNE route ne rend : verifie le
        // 31/10/2026, aucun `view('welcome')` dans `routes/` ni dans les
        // controleurs. Le declarer installable serait decorer un mort.
        'welcome.blade.php' => 'non route',

        // Administration PLATEFORME. `PwaInstallContext::PREFIXES_EXCLUS`
        // exclut `admin.*` : aucun ecran d'installation n'y est jamais emis.
        // Y poser le manifest et l'enregistrement du Service Worker n'achetait
        // donc rien — le Service Worker est de toute facon enregistre a
        // l'ECHELLE DE L'ORIGINE par les autres surfaces, pas par page.
        //
        // Et cela coutait : `<x-pwa-boot />` ajoute un `<script>`, ce qui a
        // rougi `TASK1079LoopAdminTest::test_the_overview_sanitises_the_manifesto`,
        // dont la garde XSS cherche `<script>` sur la PAGE ENTIERE. La garde
        // est trop large, mais la corriger pour faire passer du code qui
        // n'avait pas lieu d'etre la aurait ete l'arrangement de trop :
        // on retire le code.
        //
        // `layouts/org-admin.blade.php` GARDE la plomberie : les routes
        // d'administration d'une Organization sont nommees
        // `organization.admin.*`, qui ne commence pas par `admin.` — elles ne
        // sont donc pas exclues, et peuvent porter le bandeau.
        'layouts/admin.blade.php' => 'surface admin.* exclue par la politique',
    ];

    private function cheminDesVues(): string
    {
        return resource_path('views');
    }

    /**
     * Le contenu d'un gabarit, DEPOUILLE de ses commentaires Blade.
     *
     * Ce depouillement n'est pas une precaution de style : c'est la correction
     * d'une erreur reellement commise pendant cette TASK. Le script
     * d'insertion s'ancrait sur la premiere ligne contenant `<x-pwa-head />`,
     * et dans `layouts/app` cette chaine apparaissait d'abord **a l'interieur
     * d'un commentaire**. Le composant a donc ete insere dans le commentaire :
     * present dans le fichier, visible dans un `grep`, et **totalement
     * inerte**.
     *
     * C'est exactement le « no-op silencieux » que le docbloc de
     * `x-theme-tokens` appelle le pire des echecs. Chercher la balise dans le
     * texte brut aurait valide ce fichier casse.
     */
    private function sansCommentairesBlade(string $contenu): string
    {
        return preg_replace('/\{\{--.*?--\}\}/s', '', $contenu) ?? $contenu;
    }

    /**
     * Les deux composants qui, ENSEMBLE, rendent une page installable.
     *
     * Le manifest seul ne suffit pas : sans Service Worker enregistre, Chrome
     * ne declenche jamais `beforeinstallprompt`, et le bouton « Installer »
     * n'aurait rien a appeler. Mesure du 31/10/2026 : `/org/main` declarait
     * zero des deux.
     *
     * @var list<string>
     */
    private const COMPOSANTS_REQUIS = ['<x-pwa-head', '<x-pwa-boot'];

    /**
     * Tous les documents HTML complets des vues, en chemins relatifs.
     *
     * @return list<string>
     */
    private function documentsAutonomes(): array
    {
        $racine = $this->cheminDesVues();
        $trouves = [];

        $iterateur = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($racine, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterateur as $fichier) {
            if (! $fichier->isFile() || ! str_ends_with($fichier->getFilename(), '.blade.php')) {
                continue;
            }

            $contenu = file_get_contents($fichier->getPathname());

            if (! str_contains($contenu, '<!DOCTYPE html')) {
                continue;
            }

            $trouves[] = str_replace($racine.DIRECTORY_SEPARATOR, '', $fichier->getPathname());
        }

        sort($trouves);

        return $trouves;
    }

    /**
     * LA garde de cette TASK.
     *
     * Elle ne verifie pas que les douze fichiers du jour portent le composant —
     * ca, un `grep` le dirait. Elle verifie que **tout** document autonome le
     * porte, donc aussi celui qui n'existe pas encore.
     */
    public function test_every_standalone_html_document_declares_the_pwa_head(): void
    {
        $documents = $this->documentsAutonomes();

        // Fail-closed : si l'inventaire rend soudain presque rien, c'est le
        // balayage qui est casse, pas le depot qui est devenu parfait.
        $this->assertGreaterThanOrEqual(
            12,
            count($documents),
            'Le balayage des documents autonomes ne rend presque rien : le test est casse, pas le depot.'
        );

        $manquants = [];

        foreach ($documents as $relatif) {
            if (array_key_exists($relatif, self::EXCEPTIONS)) {
                continue;
            }

            // DEPOUILLE de ses commentaires : un composant ecrit dans un
            // commentaire est inerte, et doit compter comme absent.
            $contenu = $this->sansCommentairesBlade(
                file_get_contents($this->cheminDesVues().DIRECTORY_SEPARATOR.$relatif)
            );

            foreach (self::COMPOSANTS_REQUIS as $composant) {
                if (! str_contains($contenu, $composant)) {
                    $manquants[] = $relatif.'  (manque '.$composant.' />)';
                }
            }
        }

        $this->assertSame(
            [],
            $manquants,
            "Ces documents HTML complets ne sont PAS installables.\n"
            ."Il faut les DEUX composants dans le `<head>` : `<x-pwa-head />` declare le manifest,\n"
            ."`<x-pwa-boot />` enregistre le Service Worker — sans lui, aucun prompt d'installation.\n"
            ."Sinon, declarer l'exception dans self::EXCEPTIONS avec sa raison.\n"
            .'  - '.implode("\n  - ", $manquants)
        );
    }

    /** Les exceptions declarees doivent etre VRAIES, sinon la liste mentirait. */
    public function test_declared_exceptions_really_are_exceptions(): void
    {
        foreach (self::EXCEPTIONS as $relatif => $raison) {
            $chemin = $this->cheminDesVues().DIRECTORY_SEPARATOR.$relatif;

            $this->assertFileExists(
                $chemin,
                "L'exception [{$relatif}] ne designe aucun fichier : la retirer de self::EXCEPTIONS."
            );

            $contenu = $this->sansCommentairesBlade(file_get_contents($chemin));

            foreach (self::COMPOSANTS_REQUIS as $composant) {
                $this->assertStringNotContainsString(
                    $composant,
                    $contenu,
                    "[{$relatif}] est declare en exception ({$raison}) mais porte {$composant} /> : "
                    .'choisir — soit il est installable, soit il est une exception.'
                );
            }
        }
    }

    public function test_the_pwa_head_component_emits_the_installability_contract(): void
    {
        $rendu = Blade::render('<x-pwa-head />');

        $this->assertStringContainsString('rel="manifest"', $rendu);
        $this->assertStringContainsString(config('pwa.manifest_path'), $rendu);
        $this->assertStringContainsString('name="theme-color"', $rendu);
        $this->assertStringContainsString('apple-mobile-web-app-capable', $rendu);
        $this->assertStringContainsString('apple-mobile-web-app-status-bar-style', $rendu);
        $this->assertStringContainsString('apple-touch-icon', $rendu);

        // Doctrine TASK-1471 : ce composant DECLARE, il n'execute pas.
        // L'enregistrement du Service Worker reste dans `resources/js/app.js`.
        $this->assertStringNotContainsString(
            '<script',
            $rendu,
            'Le contrat PWA ne doit emettre aucun JavaScript (doctrine TASK-1471).'
        );
    }

    /**
     * L'autre moitie de l'installabilite, et celle qu'on oublie.
     *
     * Sans Service Worker enregistre, Chrome ne declenche pas
     * `beforeinstallprompt`. Le composant doit donc enregistrer `/sw.js`, et le
     * faire sans jamais pouvoir casser la page qui l'accueille : sur un
     * certificat auto-signe ou en navigation privee, `register()` echoue.
     */
    public function test_the_pwa_boot_component_registers_the_service_worker_defensively(): void
    {
        $rendu = Blade::render('<x-pwa-boot />');

        $this->assertStringContainsString('serviceWorker', $rendu);
        $this->assertStringContainsString("register('/sw.js')", $rendu);

        // Garde de capacite : `navigator.serviceWorker` est indefini hors
        // contexte securise, et y acceder sans garde leverait.
        $this->assertStringContainsString("'serviceWorker' in navigator", $rendu);

        // L'echec est avale : un Service Worker est une amelioration, jamais
        // une dependance de rendu.
        $this->assertStringContainsString('catch', $rendu);

        // Il n'enregistre QUE le Service Worker. Un composant d'amorcage qui
        // se mettrait a demander une permission ou a appeler le reseau
        // sortirait de son mandat.
        foreach (['requestPermission', 'pushManager', 'fetch(', 'XMLHttpRequest'] as $interdit) {
            $this->assertStringNotContainsString(
                $interdit,
                $rendu,
                "`<x-pwa-boot />` ne doit pas contenir [{$interdit}] : il enregistre, il ne sollicite pas."
            );
        }
    }

    /**
     * L'enregistrement a QUITTE `app.js`, il n'y est pas recopie.
     *
     * Deux autorites d'enregistrement finiraient par diverger — c'est
     * exactement ce qui est arrive au bloc PWA entre `layouts/app` et
     * `layouts/guest`.
     */
    public function test_the_service_worker_registration_no_longer_lives_in_the_bundle(): void
    {
        $bundle = file_get_contents(resource_path('js/app.js'));

        $this->assertStringNotContainsString(
            "navigator.serviceWorker.register('/sw.js')",
            $bundle,
            'L\'enregistrement du Service Worker est de retour dans `app.js` : '
            .'il doit vivre dans `<x-pwa-boot />` seulement, sinon les gabarits '
            .'autonomes qui ne chargent pas ce bundle redeviennent non installables.'
        );
    }

    /**
     * TASK-1805 — `/site.webmanifest` est desormais une route Laravel
     * (`PwaManifestController`), plus un fichier statique de `public/` : la
     * lecture passe par une vraie requete HTTP, comme un navigateur le
     * ferait, plutot que par une lecture directe du disque.
     *
     * @return array<string, mixed>
     */
    private function manifest(): array
    {
        $reponse = $this->get('/site.webmanifest')->assertOk();

        $manifest = json_decode($reponse->getContent(), true);

        $this->assertIsArray($manifest, 'site.webmanifest n\'est pas un JSON valide.');

        return $manifest;
    }

    public function test_manifest_declares_a_stable_identity_and_scope(): void
    {
        $manifest = $this->manifest();

        foreach (['id', 'name', 'short_name', 'start_url', 'scope', 'display', 'lang'] as $clef) {
            $this->assertArrayHasKey($clef, $manifest, "Le manifest n'a pas de `{$clef}`.");
            $this->assertNotSame('', $manifest[$clef], "Le manifest a un `{$clef}` vide.");
        }

        // `display: standalone` n'est pas cosmetique : c'est la condition que
        // WebKit exige pour qu'une web app d'ecran d'accueil iOS puisse un jour
        // recevoir du Web Push.
        $this->assertSame('standalone', $manifest['display']);

        // `start_url` doit tomber DANS `scope`, sinon le lancement de
        // l'application installee sort de son propre perimetre.
        $this->assertStringStartsWith(
            $manifest['scope'],
            $manifest['start_url'],
            'Le `start_url` du manifest est hors de son `scope`.'
        );
    }

    /**
     * LA coherence qui n'etait tenue par personne.
     *
     * Mesure du 31/10/2026 : la couleur de chrome etait declaree TROIS fois
     * avec TROIS valeurs — meta `#1B1FCC`, manifest `#050505`, `primary` des
     * themes `#0B4DFF`. Une application qui change de couleur en etant
     * installee est precisement ce que ce test interdit desormais.
     */
    public function test_manifest_theme_color_matches_the_rendered_meta(): void
    {
        $manifest = $this->manifest();
        $attendu = config('pwa.theme_color');

        $this->assertSame(
            strtolower((string) $attendu),
            strtolower((string) $manifest['theme_color']),
            'Le `theme_color` du manifest diverge de `config(\'pwa.theme_color\')` : '
            .'l\'application changerait de couleur en etant installee.'
        );

        $this->assertStringContainsString(
            'content="'.$attendu.'"',
            Blade::render('<x-pwa-head />'),
            'La meta `theme-color` rendue ne porte pas la couleur de configuration.'
        );
    }

    public function test_manifest_icons_exist_on_disk_in_both_purposes(): void
    {
        $manifest = $this->manifest();

        $this->assertArrayHasKey('icons', $manifest);

        $purposes = [];

        foreach ($manifest['icons'] as $icone) {
            $chemin = public_path(ltrim($icone['src'], '/'));

            $this->assertFileExists(
                $chemin,
                "L'icone [{$icone['src']}] est declaree au manifest mais absente du disque."
            );

            $purposes[$icone['purpose']][] = $icone['sizes'];
        }

        // `any` ET `maskable` : sans `maskable`, Android rogne l'icone dans sa
        // forme systeme et le logo se retrouve coupe.
        foreach (['any', 'maskable'] as $purpose) {
            $this->assertArrayHasKey($purpose, $purposes, "Aucune icone `{$purpose}` au manifest.");
            $this->assertContains('192x192', $purposes[$purpose]);
            $this->assertContains('512x512', $purposes[$purpose]);
        }
    }

    /**
     * Un raccourci doit mener quelque part, et ne rien reveler.
     *
     * CDC section 3.7 : « seulement si leurs destinations ne revelent pas
     * d'information privee et restent valides ». Le manifest est PUBLIC : y
     * ecrire le nom d'une Boucle privee la publierait.
     */
    public function test_manifest_shortcuts_point_to_existing_public_paths(): void
    {
        $manifest = $this->manifest();

        $this->assertArrayHasKey('shortcuts', $manifest);
        $this->assertNotEmpty($manifest['shortcuts']);

        foreach ($manifest['shortcuts'] as $raccourci) {
            foreach (['name', 'url'] as $clef) {
                $this->assertArrayHasKey($clef, $raccourci);
            }

            $url = $raccourci['url'];

            $this->assertStringStartsWith(
                $manifest['scope'],
                $url,
                "Le raccourci [{$url}] est hors du `scope` du manifest."
            );

            // La destination doit correspondre a une route declaree : un
            // raccourci vers une URL morte pose une icone qui echoue.
            $chemin = ltrim($url, '/');

            $existe = collect(Route::getRoutes()->getRoutesByMethod()['GET'] ?? [])
                ->keys()
                ->contains(fn (string $uri) => $uri === $chemin);

            $this->assertTrue(
                $existe,
                "Le raccourci [{$url}] ne correspond a aucune route GET declaree."
            );
        }
    }

    /**
     * Le CDC demande un ecran « utilisable en portrait et paysage » (3.2).
     *
     * `orientation: portrait` au manifest VERROUILLE l'application installee en
     * portrait — ce qui contredit cette exigence et penalise qui utilise un
     * clavier externe ou a besoin du mode paysage. La clef etait posee ; elle
     * est retiree, et ce test empeche son retour par inadvertance.
     */
    /**
     * Les captures sont VERIDIQUES, legeres, et ne montrent personne.
     *
     * CDC section 3.7 : « `screenshots` de bonne qualite, veridiques ». Le
     * generateur est `tests/e2e/pwa-screenshots.spec.js` : les captures sont
     * produites par Playwright sur les surfaces PUBLIQUES et ANONYMES, jamais
     * depuis une page authentifiee — la base locale est une copie de la
     * production, et un fichier de `public/` est public ET commite.
     */
    public function test_manifest_screenshots_are_real_light_and_declared(): void
    {
        $manifest = $this->manifest();

        $this->assertArrayHasKey('screenshots', $manifest);
        $this->assertNotEmpty($manifest['screenshots']);

        foreach ($manifest['screenshots'] as $capture) {
            foreach (['src', 'sizes', 'type', 'form_factor', 'label'] as $clef) {
                $this->assertArrayHasKey($clef, $capture, "Capture sans `{$clef}`.");
            }

            $chemin = public_path(ltrim($capture['src'], '/'));

            $this->assertFileExists(
                $chemin,
                "La capture [{$capture['src']}] est declaree mais absente : relancer "
                .'`npx playwright test tests/e2e/pwa-screenshots.spec.js`.'
            );

            // Un manifest est telecharge AVANT toute invite d'installation :
            // il ne doit pas trainer des megaoctets de vignettes.
            $this->assertLessThan(
                400_000,
                filesize($chemin),
                "La capture [{$capture['src']}] depasse 400 ko."
            );

            // Les dimensions declarees doivent etre les VRAIES : un navigateur
            // reserve la place avant de telecharger.
            [$largeur, $hauteur] = getimagesize($chemin);

            $this->assertSame(
                $capture['sizes'],
                $largeur.'x'.$hauteur,
                "La capture [{$capture['src']}] declare {$capture['sizes']} et mesure {$largeur}x{$hauteur}."
            );

            $this->assertSame('narrow', $capture['form_factor']);
        }
    }

    public function test_manifest_does_not_lock_orientation(): void
    {
        $this->assertArrayNotHasKey(
            'orientation',
            $this->manifest(),
            'Le manifest verrouille l\'orientation de l\'application installee : '
            .'le CDC 3.2 exige portrait ET paysage.'
        );
    }

    private function serviceWorker(): string
    {
        $chemin = public_path('sw.js');
        $this->assertFileExists($chemin);

        return file_get_contents($chemin);
    }

    /**
     * `addAll` est ATOMIQUE : une URL en 404 fait echouer l'installation du
     * Service Worker entier, donc l'installabilite de l'application.
     *
     * Une faute de frappe dans ce tableau est donc une panne complete, et
     * silencieuse — le navigateur n'installe simplement pas.
     */
    public function test_every_precached_shell_asset_exists_on_disk(): void
    {
        preg_match('/const SHELL_ASSETS = \[(.*?)\];/s', $this->serviceWorker(), $m);

        $this->assertNotEmpty($m, 'SHELL_ASSETS introuvable dans sw.js.');

        preg_match_all("/'([^']+)'/", $m[1], $urls);
        $chemins = $urls[1];

        $this->assertNotEmpty($chemins);

        // TASK-1805 — `/site.webmanifest` n'est plus un fichier statique mais
        // une route Laravel (`PwaManifestController`) : une existence sur
        // disque n'a plus de sens pour elle, `addAll` l'echouerait quand meme
        // sur un 404 HTTP, donc la garde devient une vraie requete.
        $cheminsStatiques = array_values(array_diff($chemins, ['/site.webmanifest']));

        foreach ($cheminsStatiques as $url) {
            $this->assertFileExists(
                public_path(ltrim($url, '/')),
                "SHELL_ASSETS precharge [{$url}], absent du disque : `addAll` etant atomique, "
                .'le Service Worker ne s\'installerait pas du tout.'
            );
        }

        if (in_array('/site.webmanifest', $chemins, true)) {
            $this->get('/site.webmanifest')->assertOk();
        }

        // Les quatre icones du manifest doivent etre prechargees : ce sont
        // elles que le navigateur affiche dans l'invite et pose sur l'ecran
        // d'accueil.
        foreach ($this->manifest()['icons'] as $icone) {
            $this->assertContains(
                $icone['src'],
                $chemins,
                "L'icone [{$icone['src']}] du manifest n'est pas prechargee par SHELL_ASSETS."
            );
        }
    }

    /**
     * Le bump de `CACHE_NAME` n'est pas hygienique : c'est la SEULE facon de
     * retirer a un navigateur deja installe ce que l'ancienne coquille
     * contenait. `activate` ne supprime que les caches d'un AUTRE nom.
     */
    public function test_the_cache_name_was_bumped(): void
    {
        preg_match("/const CACHE_NAME = '([^']+)'/", $this->serviceWorker(), $m);

        $this->assertNotEmpty($m, 'CACHE_NAME introuvable dans sw.js.');
        $this->assertMatchesRegularExpression('/^bouclepro-v\d+$/', $m[1]);

        $this->assertNotSame(
            'bouclepro-v7',
            $m[1],
            'TASK-1805 a change le contenu de /site.webmanifest (nom par environnement) mais '
            .'CACHE_NAME est reste a v7 : les navigateurs deja installes garderaient '
            .'l\'ancien manifest fige sur "BouclePro" en cache.'
        );
    }

    /**
     * NON-REGRESSION TASK-1692 — la garde la plus importante de cette TASK.
     *
     * T1692 a mesure, sur un profil Chrome ordinaire, 129 entrees dans
     * `bouclepro-v4` dont 80 documents `text/html` et 28 chemins PRIVES
     * distincts — le tableau de bord, la messagerie et les points d'une
     * Organization — plus un export CSV. Le correctif a remplace la liste
     * noire par une LISTE BLANCHE de chemins.
     *
     * Cette TASK touche `sw.js`. Elle ne doit toucher QUE la coquille. Ce test
     * est ce qui le prouve, et la condition d'arret du CDC 12.3 — « cache prive
     * reintroduit » — est ce qu'il protege.
     */
    public function test_the_service_worker_still_caches_nothing_private(): void
    {
        $sw = $this->serviceWorker();

        // 1. La liste blanche de prefixes est intacte, et n'a RIEN gagne.
        preg_match('/const PUBLIC_ASSET_PREFIXES = \[(.*?)\];/s', $sw, $m);
        $this->assertNotEmpty($m, 'PUBLIC_ASSET_PREFIXES introuvable : la liste blanche de T1692 a disparu.');

        preg_match_all("/'([^']+)'/", $m[1], $p);

        $this->assertSame(
            ['/build/', '/brand/', '/css/', '/img/', '/js/'],
            $p[1],
            'La liste blanche de prefixes de T1692 a change. Toute entree supplementaire '
            .'doit etre justifiee par sa propre TASK, avec mesure du cache.'
        );

        // 2. Aucun chemin porteur d'identite ne peut y entrer.
        foreach (['/org/', '/storage/', '/admin/', '/api/', '/livewire/', '/dashboard', '/messages'] as $prive) {
            $this->assertNotContains(
                $prive,
                $p[1],
                "[{$prive}] est entre dans la liste blanche du Service Worker : "
                .'c\'est exactement la fuite que T1692 a fermee.'
            );
        }

        // 3. Les documents restent en RESEAU SEUL : ni lecture ni ecriture du
        //    cache. C'est la branche qui rangeait les 80 pages privees.
        $this->assertStringContainsString(
            'fetch(request).catch(() => offlineDocumentResponse())',
            $sw,
            'La branche document du Service Worker n\'est plus en reseau seul.'
        );

        // 4. Le script d'execution Livewire est nomme ENTIER, jamais par
        //    prefixe : `/livewire-<hash>/` sert aussi `preview-file/{filename}`,
        //    c'est-a-dire l'apercu d'un televersement en cours.
        $this->assertStringContainsString(
            'livewire(\\.min)?\\.js$',
            $sw,
            'Le motif Livewire ne nomme plus le script d\'execution seul : '
            .'les apercus de televersement redeviendraient cachables.'
        );

        // 5. Une seule ecriture de cache subsiste, celle des assets versionnes.
        $this->assertSame(
            1,
            preg_match_all('/cache\.put\(/', $sw),
            'Le Service Worker contient plus d\'une ecriture de cache : '
            .'T1692 n\'en laisse qu\'une, celle des assets de la liste blanche.'
        );
    }
}
