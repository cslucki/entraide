<?php

namespace Tests\Support\ScenarioPacks;

use App\Models\Loop;
use App\Models\Organization;
use App\Models\User;
use App\Services\Loops\LoopRootDocumentService;
use App\Support\ScenarioPacks\Contracts\ScenarioPackDefinition;
use App\Support\ScenarioPacks\ScenarioPackEntityRegistrar;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * Pack fictif de TASK-1388 : il MESURE la locale sous laquelle il s'execute,
 * et il fabrique du contenu reellement traduit.
 *
 * Les deux comptent, et separement :
 *
 *  - `$localeObservee` est la mesure DIRECTE du contrat — la locale posee au
 *    moment ou `apply()` court. Elle rougit meme si aucun `__()` n'etait
 *    appele, donc elle ne depend pas du contenu du pack.
 *  - le document racine cree via `LoopRootDocumentService` est la mesure de
 *    l'EFFET — un titre et des sections **persistes** depuis `__()`. C'est
 *    exactement le chemin qui produisait du francais dans une Organization
 *    anglaise (`Test20260822DogfoodingPack` appelle `ensureRootDocument()`
 *    dans son propre `apply()`).
 *
 * Une seule des deux mesures suffirait a passer sur un correctif partiel :
 * poser la locale sans que le contenu la suive, ou traduire un contenu en
 * laissant la locale ambiante fuiter ailleurs. Les deux ensemble, non.
 *
 * N'est enregistre dans aucun `config('scenario_packs.definitions')`.
 */
class LocaleProbeScenarioPack implements ScenarioPackDefinition
{
    /**
     * La locale applicative telle que `apply()` l'a vue. `null` tant que le
     * pack n'a pas tourne — distinguer « pas execute » de « execute sous une
     * locale vide » evite un vert par accident.
     */
    public ?string $localeObservee = null;

    /**
     * Le slug de la Boucle creee, pour retrouver son document racine sans
     * dependre d'un ordre de creation.
     */
    public ?string $loopSlug = null;

    public function __construct(
        private readonly string $version = '1.0.0',
        private readonly bool $echoueApresMesure = false,
    ) {}

    public function packId(): string
    {
        return 'task1388-locale-probe';
    }

    public function packVersion(): string
    {
        return $this->version;
    }

    public function packName(): string
    {
        return 'TASK-1388 locale probe (test only)';
    }

    public function purpose(): string
    {
        return 'Mesurer la locale sous laquelle un pack est applique, et le contenu qu\'elle produit.';
    }

    public function apply(Organization $organization, ScenarioPackEntityRegistrar $registrar): void
    {
        $this->localeObservee = app()->getLocale();

        if ($this->echoueApresMesure) {
            // Apres la mesure, pour que le test de restauration porte sur le
            // chemin d'exception ET dispose quand meme de la locale vue.
            throw new RuntimeException('TASK-1388 echec volontaire du pack');
        }

        $persona = User::query()->updateOrCreate(
            ['email' => 'locale-probe-'.$organization->slug.'@task1388-demo.test'],
            [
                'organization_id' => $organization->id,
                'name' => 'Locale Probe Persona',
                'first_name' => 'Locale',
                'password' => Hash::make('password'),
                'points_balance' => 0,
            ],
        );
        $registrar->track('persona', 'persona-1', $persona);

        $this->loopSlug = 'task1388-locale-probe-'.$organization->slug;

        $loop = Loop::query()->updateOrCreate(
            ['organization_id' => $organization->id, 'slug' => $this->loopSlug],
            [
                'name' => 'Locale Probe Loop',
                // Laissee VIDE a dessein : `initialContent()` ne recopie la
                // description que si elle est remplie. Vide, il tombe sur
                // `loops.root_document_intro_placeholder` — une chaine
                // traduite, donc mesurable.
                'description' => null,
                'type' => 'project',
                'status' => 'active',
                'visibility' => 'private',
                'access_mode' => Loop::ACCESS_REQUEST,
                'created_by' => $persona->id,
            ],
        );
        $registrar->track('loop', 'loop-1', $loop);

        app(LoopRootDocumentService::class)->ensureRootDocument($loop, $persona);
    }
}
