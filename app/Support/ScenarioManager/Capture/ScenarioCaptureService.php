<?php

namespace App\Support\ScenarioManager\Capture;

use App\Models\Organization;
use App\Models\ScenarioManifestVersion;
use App\Models\ScenarioPackLoad;
use App\Models\User;
use App\Support\ScenarioManager\SandboxGuard;
use App\Support\ScenarioManager\ScenarioVersionRefused;
use App\Support\ScenarioManifest\ScenarioManifestValidator;
use Illuminate\Support\Facades\DB;

/**
 * TASK-1652 — le geste complet : une sandbox vivante devient un DRAFT.
 *
 * ## Deux temps, et c'est volontaire
 *
 * 1. **Lecture seule** : provenance prouvee, serialisation, bornes, Validator.
 *    Rien n'est ecrit, et une transaction n'est pas ouverte pendant qu'on relit
 *    des fichiers depuis le stockage — une transaction longue pendant des
 *    entrees/sorties distantes est une transaction qu'on tient sans raison.
 * 2. **Materialisation** : une seule transaction, courte, qui ecrit le registre
 *    de Capture ET la nouvelle version. Si elle echoue, l'etat persistant est
 *    identique a avant la tentative.
 *
 * ## La provenance n'est pas une formalite
 *
 * Capture lit TRANSVERSALEMENT une Organization. Si la porte s'ouvrait sur une
 * Organization arbitraire, elle deviendrait un exfiltrateur de tenant. Les
 * quatre preconditions sont donc verifiees AVANT toute lecture metier, et
 * aucune n'est deductible d'une autre :
 *
 * - la version est LOADED ;
 * - son chargement est VIVANT (jamais reinitialise) ;
 * - ce chargement est bien celui de CETTE version ;
 * - l'Organization du chargement est bien une sandbox de scenario.
 *
 * Il n'existe aucun chemin « Organization cliente -> l'adopter -> capturer ».
 */
final class ScenarioCaptureService
{
    public function __construct(private readonly ScenarioManifestValidator $validator) {}

    /**
     * Ce que la Capture PRODUIRAIT, sans rien ecrire.
     *
     * Expose pour que l'inspection et les tests puissent mesurer le document
     * et les blockers sans fabriquer de version.
     */
    public function inspecter(ScenarioManifestVersion $version): ScenarioCaptureResult
    {
        [$sandbox, $load] = $this->prouverLaProvenance($version);

        $registre = ScenarioCaptureKeyRegistry::pour($sandbox);
        $registre->amorcerDepuisLeMoteur($load, (array) json_decode((string) $version->json_source, true));

        return $this->constater(
            (new ScenarioCaptureSerializer($sandbox, $load, $registre, (string) $version->json_source))->serialiser(),
            $version
        );
    }

    /**
     * Le constat COMPLET : ce que le serializer a vu, plus ce que le Validator
     * refuse — chaque erreur NOMMEE.
     *
     * ## Pourquoi le verdict technique devient un blocker
     *
     * La premiere version rendait un `captureInvalid` portant un blob JSON
     * d'erreurs. Or toute la classe est batie sur la promesse inverse :
     * « l'inventaire COMPLET de ce qui bloque », avec famille, limite et valeur
     * reelle.
     *
     * L'ecart n'etait pas cosmetique. Le domaine V1 est plus etroit que celui
     * du produit sur une vingtaine de champs : une Boucle de type `writing`,
     * une Decision enregistree APRES le chargement (`decided_day_offset` est
     * plafonne a 0), un evenement de cinq minutes, un article `audience:
     * public` — autant de gestes ordinaires qui rendaient la sandbox non
     * capturable avec un message que personne ne pouvait actionner.
     *
     * Confronter le document au Validator et traduire chaque erreur en blocker
     * couvre donc d'un seul geste tous les domaines, toutes les bornes de
     * longueur et les deux limites GLOBALES (`MAX_OBJECTS`,
     * `MAX_CONTENT_BYTES`) — qui echappaient au controle par collection.
     *
     * Ce n'est PAS une reparation apres Validator : rien n'est corrige, tout
     * est rapporte.
     */
    private function constater(ScenarioCaptureResult $resultat, ScenarioManifestVersion $version): ScenarioCaptureResult
    {
        if ($resultat->estBloquee()) {
            // Un document ampute n'a pas a etre confronte au Validator : ses
            // erreurs seraient les consequences des blockers, pas des causes.
            return $resultat;
        }

        $json = $this->encoder($resultat->document, $version);

        if (! is_string($json)) {
            return new ScenarioCaptureResult($resultat->document, [[
                'famille' => 'document',
                'raison' => 'encodage_impossible',
                'detail' => 'Le document capture n a pas pu etre encode en JSON.',
            ]]);
        }

        $verdict = $this->validator->validate($json);

        if ($verdict->isValid()) {
            return $resultat;
        }

        $blockers = [];

        foreach ($verdict->toArray()['errors'] ?? [] as $erreur) {
            $chemin = (string) ($erreur['path'] ?? '/');

            $blockers[] = [
                // La famille se lit dans le JSON Pointer : `/decisions/0/...`.
                'famille' => explode('/', ltrim($chemin, '/'))[0] ?: 'document',
                'raison' => mb_strtolower((string) ($erreur['code'] ?? 'invalid')),
                'detail' => sprintf('%s — %s', $chemin, (string) ($erreur['message'] ?? '')),
            ];
        }

        return new ScenarioCaptureResult($resultat->document, $blockers);
    }

    /**
     * @param  array<string, mixed>  $document
     */
    private function encoder(array $document, ScenarioManifestVersion $version): ?string
    {
        // Le document DIT ce qu'il est.
        //
        // `duplicate()` reecrit `version` pour cette raison exacte : « le texte
        // JSON est l'unique source de verite (CDC 10.1) ». Une capture qui
        // garderait le numero de sa source porterait deux verites, dont une
        // fausse — la colonne dirait 1.1.0 et le document 1.0.0.
        $document['version'] = $this->prochaineVersion((string) $version->scenario_key, (string) $version->version);

        $json = json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return is_string($json) ? $json : null;
    }

    /**
     * Capture, et cree la nouvelle version DRAFT.
     *
     * @throws ScenarioVersionRefused si la provenance, les bornes, une fidelite
     *                                ou le Validator s'y opposent
     */
    public function capturer(ScenarioManifestVersion $version, User $auteur): ScenarioManifestVersion
    {
        [$sandbox, $load] = $this->prouverLaProvenance($version);

        $registre = ScenarioCaptureKeyRegistry::pour($sandbox);
        $registre->amorcerDepuisLeMoteur($load, (array) json_decode((string) $version->json_source, true));

        $resultat = $this->constater(
            (new ScenarioCaptureSerializer($sandbox, $load, $registre, (string) $version->json_source))->serialiser(),
            $version
        );

        if ($resultat->estBloquee()) {
            // Aucune troncature, aucune version partielle : on rend
            // l'inventaire COMPLET de ce qui bloque, chaque obstacle NOMME.
            throw ScenarioVersionRefused::captureBlocked($resultat->rapport(), count($resultat->blockers));
        }

        $json = $this->encoder($resultat->document, $version);

        if (! is_string($json)) {
            throw ScenarioVersionRefused::captureInvalid('Le document capture n a pas pu etre encode.');
        }

        // Le Validator COMPLET, sur le texte EXACT qui sera persiste. Aucun
        // champ n'est ajoute apres cette ligne : le document valide et le
        // document enregistre sont le meme.
        $verdict = $this->validator->validate($json);

        if (! $verdict->isValid()) {
            throw ScenarioVersionRefused::captureInvalid(json_encode($verdict->toArray()['errors'] ?? []) ?: '');
        }

        return DB::transaction(function () use ($version, $sandbox, $auteur, $json, $verdict, $registre): ScenarioManifestVersion {
            $registre->persister();

            $capturee = new ScenarioManifestVersion([
                // La MEME clef de scenario que la source : c'est le meme
                // scenario, a un autre moment de sa vie.
                'scenario_key' => (string) $version->scenario_key,
                'name' => (string) $version->name,
                'version' => $this->prochaineVersion((string) $version->scenario_key, (string) $version->version),
                'usage' => (string) $version->usage,
                'origin' => ScenarioManifestVersion::ORIGIN_CAPTURE,
                'json_source' => $json,
                'created_by' => $auteur->id,
                'parent_id' => $version->id,
            ]);

            // Les attributs SYSTEME, hors `$fillable` depuis T1646 : ils ne
            // peuvent etre poses qu'ici, explicitement.
            //
            // `state` reste DRAFT : une Capture est une MESURE, jamais une
            // approbation humaine. Aucun `approved_*` n'est recopie — sans quoi
            // un monde jamais relu deviendrait chargeable. Et aucun
            // `scenario_pack_load_id` : ce chargement appartient a la version
            // SOURCE, le recopier ferait croire que le nouveau DRAFT est
            // charge.
            $capturee->forceFill([
                'state' => ScenarioManifestVersion::STATE_DRAFT,
                'digest' => $verdict->digest(),
                'validation_summary' => $verdict->toArray(),
                'approved_digest' => null,
                'approved_by' => null,
                'approved_at' => null,
                'scenario_pack_load_id' => null,
                'captured_from_organization_id' => $sandbox->id,
            ])->save();

            return $capturee;
        });
    }

    /**
     * Les quatre preconditions, verifiees AVANT toute lecture metier.
     *
     * @return array{0: Organization, 1: ScenarioPackLoad}
     */
    private function prouverLaProvenance(ScenarioManifestVersion $version): array
    {
        if (! $version->isLoaded()) {
            throw ScenarioVersionRefused::notLoaded();
        }

        // `withoutGlobalScopes()` serait ici une faute : on veut la ligne de
        // chargement, pas une lecture debridee. Le chargement porte son propre
        // `organization_id`, et c'est lui la borne.
        $load = ScenarioPackLoad::query()->find($version->scenario_pack_load_id);

        if (! $load instanceof ScenarioPackLoad) {
            throw ScenarioVersionRefused::notLoaded();
        }

        // `reset_at` n'ETEINT rien.
        //
        // Ma premiere version en faisait un predicat de vivacite et rendait
        // `notLoaded()` — une phrase FAUSSE : la migration de T1646 dit
        // explicitement que « `reset_at` n'eteint rien, ce n'est que
        // l'horodatage du dernier reset », et le resolveur canonique
        // `ScenarioLifecycleService::chargementDeLaVersion()` ne le regarde
        // pas. Un Reset — geste ordinaire qui CONSERVE la sandbox — rendait
        // donc la Capture impossible pour toujours, en pretendant qu'il n'y
        // avait aucun chargement.
        //
        // Mais il ne suffit pas de retirer la clause. `ScenarioPackResetter`
        // ne met PAS `loaded_at` a jour, alors que `ManifestScenarioPack::apply()`
        // reconstruit le monde avec un instant FRAIS : apres un Reset, l'ancre
        // du monde courant n'est plus celle du chargement, et capturer
        // produirait tous les offsets decales du delai entre Load et Reset,
        // silencieusement.
        //
        // On refuse donc, mais avec la VRAIE raison, et en disant quoi faire.
        // La dette « persister l'ancre du monde courant » est nommee au TASK
        // file.
        if ($load->reset_at !== null) {
            throw ScenarioVersionRefused::captureBlocked(
                "[sandbox] ancre_inconnue — Cette sandbox a ete reinitialisee : l ancre de temps de son monde courant n est pas conservee, "
                ."et capturer produirait des offsets decales. Rechargez le scenario avant de capturer.",
                1
            );
        }

        // Une sandbox en corbeille reste une sandbox : `withTrashed()`, sinon
        // l'ecran perdrait la Capture au moment ou elle est encore possible.
        $sandbox = Organization::query()->withTrashed()->find($load->organization_id);

        if (! SandboxGuard::estUneSandbox($sandbox)) {
            // Il n'existe AUCUN chemin « Organization cliente -> l'adopter ->
            // capturer ». C'est ici qu'il est ferme.
            throw ScenarioVersionRefused::notASandbox();
        }

        return [$sandbox, $load];
    }

    /**
     * Le numero de la version capturee : un bump MINEUR, et rien de plus.
     *
     * `(scenario_key, version)` est unique en base, et la clef est la meme que
     * la source : il faut donc un numero neuf. Le bump mineur dit ce qui s'est
     * passe — le meme scenario, une etape plus loin. En cas de collision on
     * avance, plutot que d'ecraser.
     *
     * T1652 ne livre pas d'editeur de version : c'est le mecanisme minimal.
     */
    private function prochaineVersion(string $scenarioKey, string $versionSource): string
    {
        $parties = array_map('intval', explode('.', $versionSource) + [0, 0, 0]);
        $majeure = $parties[0] ?? 1;
        $mineure = ($parties[1] ?? 0) + 1;

        $prises = ScenarioManifestVersion::query()
            ->where('scenario_key', $scenarioKey)
            ->pluck('version')
            ->all();

        while (in_array($majeure.'.'.$mineure.'.0', $prises, true)) {
            $mineure++;
        }

        return $majeure.'.'.$mineure.'.0';
    }
}
