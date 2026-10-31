<?php

namespace Tests\Feature\ScenarioManifest;

use App\Models\Organization;
use App\Models\ScenarioPackLoad;
use App\Models\User;
use App\Support\ScenarioManifest\ScenarioManifestValidator;
use App\Support\ScenarioPacks\Contracts\ScenarioPackDefinition;
use App\Support\ScenarioPacks\Manifest\ManifestSandboxLoadService;
use App\Support\ScenarioPacks\Manifest\ManifestScenarioPack;
use App\Support\ScenarioPacks\Manifest\ScenarioManifest;
use App\Support\ScenarioPacks\Manifest\ScenarioSandboxProvisioner;
use App\Support\ScenarioPacks\ScenarioPackEntityRegistrar;
use App\Support\ScenarioPacks\ScenarioPackLoader;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Support\ScenarioPacks\T1729FailingProvisioningPack;
use Tests\TestCase;

/**
 * TASK-1729 phase 2 — les cinq `Organization::forceDelete()` du Scenario
 * Manager, et l'invariant d'orphelin.
 *
 * ## Le fait structurant
 *
 * `users.organization_id` porte `ON DELETE SET NULL`
 * (`2026_05_28_000002_drop_community_id_from_tables.php`). Un `forceDelete()`
 * d'Organization DETACHE donc ses membres, par la base, sans qu'aucune ligne de
 * PHP n'ecrive dans `users`. Aucun evenement de modele, aucune regle de
 * validation, aucun index ne peut l'arreter : seul un controle pose avant la
 * suppression le peut.
 *
 * ## Ce que l'audit de phase 1 avait etabli, site par site
 *
 * | site | atteignable avec des comptes rattaches |
 * |---|---|
 * | `ManifestSandboxLoadService::discard()` | **oui, prouve** — deux de ses trois appelants tournent apres un `load()` committe |
 * | `ScenarioPackDeleteCommand` | oui — aucun preflight, `forceDelete()` hors transaction |
 * | `ScenarioLifecycleService` (rattrapage) | oui — fenetre etroite, aucun preflight |
 * | `ScenarioLifecycleService` (retrait de version) | marginal — `refuserSiContenuEtranger()` filtre, mais hors transaction et aveugle aux `reused` |
 * | `ScenarioPackLoadCommand` | **non** — `apply()` est transactionnel |
 *
 * Le dernier est le seul qui n'a pas recu de garde. Une non-atteignabilite
 * AFFIRMEE ne vaut rien : elle est mesuree ici, par un pack sonde concu pour
 * etre le cas le plus defavorable possible.
 */
class TASK1729SandboxCleanupOrphanTest extends TestCase
{
    private function manifestJson(): string
    {
        return (string) file_get_contents(base_path('tests/Fixtures/ScenarioManifest/amt-formation-ia.json'));
    }

    private function approvedDigest(): string
    {
        return (string) (new ScenarioManifestValidator)->validate($this->manifestJson())->digest();
    }

    /**
     * Le service, avec une `apply()` qui fait GAGNER une autre invocation.
     *
     * Reprise exacte du harnais de `ManifestSandboxLoadTest` : le pack inscrit
     * le digest pour une seconde Organization avant de rendre la main, si bien
     * que l'inscription de CELLE-CI violera l'unique `(pack_id, digest)`. On
     * tombe donc dans le `discard()` de la course perdue — l'un des deux
     * appelants qui tournent APRES un chargement reussi et committe.
     *
     * @param  null|callable(Organization, ScenarioPackEntityRegistrar): void  $apresApply
     */
    private function serviceQuiPerdLaCourse(?callable $apresApply = null): ManifestSandboxLoadService
    {
        return new class(app(ScenarioSandboxProvisioner::class), app(ScenarioPackLoader::class), $apresApply) extends ManifestSandboxLoadService
        {
            public function __construct(
                ScenarioSandboxProvisioner $provisioner,
                ScenarioPackLoader $loader,
                private $apresApply,
            ) {
                parent::__construct($provisioner, $loader);
            }

            protected function makePack(ScenarioManifest $manifest): ScenarioPackDefinition
            {
                $apresApply = $this->apresApply;

                return new class($manifest, $apresApply) extends ManifestScenarioPack
                {
                    public function __construct(ScenarioManifest $manifest, private $apresApply)
                    {
                        parent::__construct($manifest);
                    }

                    public function apply(Organization $organization, ScenarioPackEntityRegistrar $registrar): void
                    {
                        parent::apply($organization, $registrar);

                        if ($this->apresApply !== null) {
                            ($this->apresApply)($organization, $registrar);
                        }

                        // Le gagnant inscrit le digest avant nous.
                        $gagnante = app(ScenarioSandboxProvisioner::class)->provision($this->manifest());

                        ScenarioPackLoad::query()->create([
                            'pack_id' => $this->packId(),
                            'pack_version' => $this->packVersion(),
                            'organization_id' => $gagnante->id,
                            'loaded_at' => now(),
                        ])->forceFill([
                            'manifest_digest' => $this->manifest()->digest(),
                            'organization_created_by_pack' => true,
                        ])->save();
                    }
                };
            }
        };
    }

    /**
     * L'INVARIANT lui-meme : aucun compte sans Organization ne partage son
     * adresse avec un autre compte.
     *
     * Formulation exacte de l'objectif MASTER, et elle n'est exprimable par
     * aucun index : la paire orphelin/membre porte sur deux lignes dont l'une a
     * `organization_id` nul et l'autre non.
     */
    private function refuterToutOrphelinAmbigu(): void
    {
        $ambigus = DB::table('users as orphelin')
            ->join('users as autre', function ($jointure) {
                $jointure->on(DB::raw('LOWER(orphelin.email)'), '=', DB::raw('LOWER(autre.email)'))
                    ->whereColumn('orphelin.id', '!=', 'autre.id');
            })
            ->whereNull('orphelin.organization_id')
            ->distinct()
            ->pluck('orphelin.email')
            ->all();

        $this->assertSame([], $ambigus,
            'INVARIANT ROMPU : un compte sans Organization partage son adresse — il est MUET');
    }

    // ── site 3 : le nettoyage de la course perdue ───────────────────────────

    /**
     * Le perdant de la course ne laisse AUCUN orphelin.
     *
     * Avant la correction, `discard()` ne supprimait que la ligne de
     * chargement : les 22 personas du monde perdant restaient, et la FK les
     * detachait. Le test existant `ManifestSandboxLoadTest` conduisait deja ce
     * chemin — il n'assertait rien sur les comptes.
     */
    public function test_le_nettoyage_d_une_course_perdue_ne_laisse_AUCUN_orphelin(): void
    {
        $avant = User::withoutGlobalScopes()->whereNull('organization_id')->count();

        $resultat = $this->serviceQuiPerdLaCourse()->load($this->manifestJson(), $this->approvedDigest());

        $this->assertTrue($resultat->wasReplay, 'le rejeu gagnant doit etre rendu, pas une erreur');

        $this->assertSame(
            $avant,
            User::withoutGlobalScopes()->whereNull('organization_id')->count(),
            'le nettoyage du perdant ne doit avoir orpheline AUCUN compte'
        );

        $this->assertSame(
            0,
            User::withoutGlobalScopes()->whereNull('organization_id')->where('email', 'like', '%amt-demo%')->count(),
            'aucun persona du monde perdant ne doit survivre sans Organization'
        );

        $this->refuterToutOrphelinAmbigu();
    }

    /**
     * Le slug LIBERE par un nettoyage est reutilise — et cela ne cree aucune
     * ambiguite.
     *
     * ## Ce que la sonde a corrige dans ce test
     *
     * Ma premiere ecriture enchainait deux chargements du meme manifeste en
     * affirmant qu'ils reproduisaient la reutilisation de slug. Une sonde a
     * montre que c'etait FAUX : la ligne de chargement du gagnant survit au
     * premier passage, donc le second trouve `(pack_id, digest)` deja inscrit
     * et rend un REJEU — sans rien provisionner. Le test etait vert et vide.
     *
     * Le scenario dangereux exige que le slug soit reellement repris. On
     * retire donc le monde gagnant entre les deux passages, ce qui est un
     * geste PRODUIT ordinaire (l'ecran Remove le fait), et pas un artifice :
     * sans sa ligne de chargement, le second passage est un vrai chargement.
     *
     * ## Le danger reproduit
     *
     * `sandboxEmail()` prefixe l'adresse du slug de la sandbox, et le
     * provisionneur ne reserve les slugs que parmi les Organizations VIVANTES.
     * Le second monde porte donc EXACTEMENT les adresses du premier. Si le
     * premier nettoyage avait laisse ses comptes derriere lui, on aurait un
     * orphelin par membre — et au nettoyage suivant, le `SET NULL` aurait
     * pousse ces lignes dans `users_email_orphan_unique` ou les adresses
     * existaient deja : 23505 non rattrapee, et sous PostgreSQL une
     * transaction avortee.
     */
    public function test_le_slug_libere_est_repris_sans_creer_d_ambiguite(): void
    {
        $premier = $this->serviceQuiPerdLaCourse()->load($this->manifestJson(), $this->approvedDigest());

        $slugDuGagnant = $premier->organization->slug;

        // Le monde gagnant est retire : sa ligne de chargement disparait, donc
        // le passage suivant n'est plus un rejeu.
        ScenarioPackLoad::query()->where('organization_id', $premier->organization->getKey())->delete();
        $premier->organization->forceDelete();

        $connues = Organization::withoutGlobalScopes()->withTrashed()->pluck('id')->all();

        $second = $this->serviceQuiPerdLaCourse()->load($this->manifestJson(), $this->approvedDigest());

        // La preuve que ce passage a REELLEMENT charge, et non rejoue : il a
        // provisionne une Organization qui n'existait pas avant lui. Un rejeu
        // n'ecrit rien, par contrat.
        $this->assertNotContains($second->organization->getKey(), $connues,
            'le second passage doit avoir PROVISIONNE, donc etre un vrai chargement');

        // Et la reutilisation du slug est bien ce qui s'est produit : le slug
        // libere par le premier nettoyage est repris. C'est de la que vient le
        // risque d'homonymie, puisque `sandboxEmail()` en derive les adresses.
        $this->assertSame($slugDuGagnant, $second->organization->slug,
            'le slug libere doit avoir ete REPRIS — sans quoi ce test ne mesure pas le scenario dangereux');

        $this->refuterToutOrphelinAmbigu();

        $this->assertSame(
            0,
            User::withoutGlobalScopes()->whereNull('organization_id')->count(),
            'aucun nettoyage n\'a laisse de compte sans Organization'
        );
    }

    /**
     * Un compte ETRANGER bloque le nettoyage, et RIEN n'est supprime.
     *
     * Arbitrage MASTER : « purger uniquement les utilisateurs dont l'ownership
     * `created` par ce chargement est prouve. Preserver les comptes `reused`,
     * etrangers ou reels. Si la securite de la purge n'est pas demontrable,
     * refuser l'operation sans suppression partielle. »
     *
     * Le compte etranger pose ici n'est inscrit dans AUCUN registre : le
     * remover ne peut donc pas le purger, et son adresse est deja portee
     * ailleurs. Le nettoyage doit s'arreter — et l'annulation doit rendre au
     * monde perdant ses personas, sans quoi on aurait une suppression
     * partielle.
     */
    public function test_un_compte_ETRANGER_bloque_le_nettoyage_sans_suppression_partielle(): void
    {
        // L'homonyme qui rend le detachement ambigu, pose AILLEURS.
        $ailleurs = Organization::factory()->create(['slug' => 't1729-ailleurs', 'is_active' => true]);
        $temoin = User::factory()->create([
            'organization_id' => $ailleurs->getKey(),
            'email' => 'intrus-t1729@exemple.test',
        ]);

        $intrusId = null;

        $resultat = $this->serviceQuiPerdLaCourse(
            apresApply: function (Organization $sandbox) use (&$intrusId): void {
                // Une personne REELLE dans la sandbox perdante, hors registre.
                $intrusId = User::query()->create([
                    'name' => 'Intrus reel',
                    'email' => 'intrus-t1729@exemple.test',
                    'password' => Hash::make('password'),
                    'organization_id' => $sandbox->getKey(),
                ])->getKey();
            }
        )->load($this->manifestJson(), $this->approvedDigest());

        // Le chargement gagnant est toujours rendu : le refus porte sur le
        // NETTOYAGE, pas sur le geste.
        $this->assertTrue($resultat->wasReplay);

        $intrus = User::withoutGlobalScopes()->find($intrusId);
        $this->assertNotNull($intrus, "le compte reel n'a PAS ete supprime");
        $this->assertNotNull($intrus->organization_id, "le compte reel n'a PAS ete detache");

        $sandboxPerdante = Organization::withoutGlobalScopes()->withTrashed()
            ->whereKey($intrus->organization_id)->first();
        $this->assertNotNull($sandboxPerdante, 'la sandbox perdante SURVIT, entiere');
        $this->assertNull($sandboxPerdante->deleted_at, 'et elle n\'est pas meme en corbeille');

        // Pas de suppression partielle : l'annulation a rendu ses personas.
        $this->assertGreaterThan(
            1,
            User::withoutGlobalScopes()->where('organization_id', $sandboxPerdante->getKey())->count(),
            'la purge du remover doit avoir ete ANNULEE avec le refus — sinon il y a suppression partielle'
        );

        // La ligne de chargement survit aussi : c'est elle qui rend la sandbox
        // retirable par l'ecran Remove.
        $this->assertTrue(
            ScenarioPackLoad::query()->where('organization_id', $sandboxPerdante->getKey())->exists(),
            'la provenance survit, donc la sandbox reste retirable par un geste produit'
        );

        $this->assertNotNull($temoin->fresh(), 'le temoin homonyme est intact');
        $this->refuterToutOrphelinAmbigu();
    }

    // ── site 4 : la commande de retrait de pack ─────────────────────────────

    /**
     * Le retrait d'un pack REFUSE de supprimer l'Organization quand cela
     * rendrait un compte ambigu — proprement, et sans rien detacher.
     */
    public function test_la_commande_de_retrait_refuse_proprement_et_ne_detache_rien(): void
    {
        config(['scenario_packs.definitions' => [
            T1729FailingProvisioningPack::PACK_ID => T1729FailingProvisioningPack::class,
        ]]);

        // SANDBOX, et ce n'est pas un detail de decor : `remove()` commence par
        // `ScenarioPackOrganizationGuard::assertAllowed()`, qui refuse toute
        // Organization qui n'est ni sandbox ni declaree dans la configuration.
        //
        // Ma premiere ecriture posait une Organization ordinaire. La commande
        // echouait alors sur CE refus, bien avant d'atteindre la garde D1 — et
        // le test etait vert meme en retirant la garde qu'il pretend mesurer.
        // Un sabotage l'a montre ; sans lui, la garde du site 4 n'aurait eu
        // aucune couverture.
        $org = Organization::factory()->create([
            'slug' => 't1729-retrait',
            'is_active' => true,
            'scenario_sandbox_created_at' => now(),
        ]);

        // Une provenance qui autorise la suppression de l'Organization.
        ScenarioPackLoad::query()->create([
            'pack_id' => T1729FailingProvisioningPack::PACK_ID,
            'pack_version' => '1.0.0',
            'organization_id' => $org->getKey(),
            'loaded_at' => now(),
        ])->forceFill(['organization_created_by_pack' => true])->save();

        // Un compte rattache, homonyme d'un compte d'ailleurs.
        $ailleurs = Organization::factory()->create(['slug' => 't1729-retrait-ailleurs', 'is_active' => true]);
        User::factory()->create(['organization_id' => $ailleurs->getKey(), 'email' => 'retrait-t1729@exemple.test']);
        $rattache = User::factory()->create(['organization_id' => $org->getKey(), 'email' => 'retrait-t1729@exemple.test']);

        $this->artisan('scenario-pack:delete', [
            'pack' => T1729FailingProvisioningPack::PACK_ID,
            'organization' => $org->slug,
            '--yes' => true,
        ])->assertFailed();

        $this->assertNotNull(
            Organization::withoutGlobalScopes()->withTrashed()->whereKey($org->getKey())->first(),
            "l'Organization survit : son suppression aurait rendu un compte muet"
        );
        $this->assertSame($org->getKey(), $rattache->fresh()->organization_id, 'aucun detachement');
        $this->refuterToutOrphelinAmbigu();
    }

    // ── site 5 : non-atteignabilite, MESUREE ────────────────────────────────

    /**
     * Un `apply()` qui echoue APRES avoir cree un compte ne laisse aucun compte
     * rattache au moment du `forceDelete()`.
     *
     * C'est la preuve que le site 5 n'a pas besoin de garde : ce n'est pas une
     * affirmation, c'est une mesure sur le cas le plus defavorable qu'on puisse
     * construire.
     */
    public function test_le_site_5_ne_peut_PAS_supprimer_une_organization_portant_des_comptes(): void
    {
        config(['scenario_packs.definitions' => [
            T1729FailingProvisioningPack::PACK_ID => T1729FailingProvisioningPack::class,
        ]]);

        $this->artisan('scenario-pack:load', [
            'pack' => T1729FailingProvisioningPack::PACK_ID,
            'organization' => T1729FailingProvisioningPack::SLUG,
        ])->assertFailed();

        $this->assertNull(
            Organization::withoutGlobalScopes()->withTrashed()
                ->where('slug', T1729FailingProvisioningPack::SLUG)->first(),
            "l'Organization provisionnee doit avoir ete defaite"
        );

        $this->assertSame(
            0,
            User::withoutGlobalScopes()->where('email', T1729FailingProvisioningPack::EMAIL)->count(),
            'le compte ecrit par `apply()` doit avoir ete annule par la transaction du loader — '
            ."s'il survivait, le `forceDelete()` l'aurait orpheline"
        );

        $this->refuterToutOrphelinAmbigu();
    }

    // ── garde de completude : aucun site oublie ──────────────────────────────

    /**
     * TOUT `Organization::forceDelete()` du code applicatif porte une garde.
     *
     * ## Pourquoi un inventaire, et pas seulement des tests de scenario
     *
     * Les sites 1 et 2 vivent dans des chemins d'echec etroits, couteux a
     * conduire depuis un test. Mais le risque reel n'est pas qu'ils cessent de
     * fonctionner : c'est qu'un SIXIEME site apparaisse demain sans garde, ou
     * qu'une garde soit retiree au passage d'un refactor. Aucun test de
     * scenario ne voit cela ; un inventaire le voit.
     *
     * C'est le patron que ce depot emploie deja pour les listes de completude
     * (`ScenarioSandboxPreflight::TABLES_HORS_PORTEE`, le registre de cycle de
     * vie) : la liste est declaree AVEC SA RAISON, et un test interdit qu'elle
     * derive du code en silence.
     *
     * ## La seule exemption, et ce qu'elle coute
     *
     * `ScenarioPackLoadCommand` n'a pas de garde parce qu'il ne peut pas
     * atteindre son `forceDelete()` avec un compte rattache —
     * `ScenarioPackLoader::load()` enveloppe `apply()` dans une transaction. Ce
     * n'est pas une affirmation : le test ci-dessus le MESURE sur le cas le
     * plus defavorable constructible. L'exemption est donc gagee sur un test,
     * pas sur un raisonnement.
     */
    public function test_tout_forceDelete_d_organization_porte_une_garde(): void
    {
        $gardes = [
            // chemin => ce qui doit y figurer
            'app/Support/ScenarioPacks/Manifest/ManifestSandboxLoadService.php' => 'verrouillerEtRefuserSiAmbigu',
            'app/Support/ScenarioManager/ScenarioLifecycleService.php' => 'verrouillerEtRefuserSiAmbigu',
            'app/Console/Commands/ScenarioPackDeleteCommand.php' => 'verrouillerEtRefuserSiAmbigu',
            // Les deux ecrans d'administration portent la garde D1 en ligne,
            // parce qu'ils doivent RENDRE un message a l'ecran et non lever.
            'app/Http/Controllers/Admin/AdminOrganizationController.php' => 'UserWriteLock::acquire',
        ];

        $exemptes = [
            // Non atteignable avec des comptes rattaches : `apply()` est
            // transactionnel. Mesure :
            // `test_le_site_5_ne_peut_PAS_supprimer_une_organization_portant_des_comptes`.
            'app/Console/Commands/ScenarioPackLoadCommand.php',
        ];

        $trouves = [];

        foreach ($this->fichiersPhpDeApp() as $chemin) {
            $contenu = (string) file_get_contents(base_path($chemin));

            // On ne cherche pas « forceDelete » en general : seules les
            // suppressions d'une Organization declenchent le `SET NULL` de
            // `users.organization_id`.
            if (! preg_match('/\$(?:[a-zA-Z_]*[oO]rganization|resultat->organization|premier->organization)[a-zA-Z_\->]*->forceDelete\(\)/', $contenu)) {
                continue;
            }

            $trouves[] = $chemin;
        }

        sort($trouves);
        $attendus = array_merge(array_keys($gardes), $exemptes);
        sort($attendus);

        $this->assertSame($attendus, $trouves,
            "l'inventaire des `Organization::forceDelete()` a derive. Un site AJOUTE doit recevoir "
            .'verrou + garde D1 (`OrganizationDetachGuard`) et etre declare ici ; un site RETIRE doit '
            .'disparaitre de cette liste. Ne JAMAIS exempter sans un test qui mesure la non-atteignabilite.');

        foreach ($gardes as $chemin => $attendu) {
            $this->assertStringContainsString(
                $attendu,
                (string) file_get_contents(base_path($chemin)),
                "{$chemin} supprime une Organization sans porter « {$attendu} » : la FK `ON DELETE SET NULL` "
                .'y detacherait des comptes sans aucun controle'
            );
        }
    }

    /** @return list<string> chemins relatifs a la racine */
    private function fichiersPhpDeApp(): array
    {
        $chemins = [];
        $racine = base_path('app');

        $iterateur = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($racine));

        foreach ($iterateur as $fichier) {
            if ($fichier->isFile() && $fichier->getExtension() === 'php') {
                $chemins[] = 'app'.str_replace($racine, '', $fichier->getPathname());
            }
        }

        return $chemins;
    }
}
