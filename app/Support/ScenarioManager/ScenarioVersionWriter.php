<?php

namespace App\Support\ScenarioManager;

use App\Models\ScenarioManifestVersion;
use App\Models\User;
use App\Support\ScenarioManifest\ScenarioManifestValidator;

/**
 * TASK-1649 — la porte UNIQUE de toute ecriture administrative.
 *
 * ## Pourquoi une porte unique
 *
 * T1646 a mis huit attributs hors de `$fillable` pour qu'aucune requete ne
 * puisse s'auto-approuver. Cette garde ne tient que si les ecritures passent
 * toutes par le meme endroit : deux chemins d'ecriture, et le second oubliera
 * une regle que le premier applique. Le controleur ne touche donc jamais un
 * attribut systeme — il appelle cette classe.
 *
 * ## Les trois regles que cette classe fait respecter
 *
 * 1. **Aucune donnee metier n'est creee.** Le CRUD ecrit des lignes
 *    administratives et rien d'autre : ni Organization, ni User, ni Loop. Le
 *    monde d'un scenario ne naitra qu'au Load, en T1650.
 * 2. **Toute modification du document repasse DRAFT** (CDC 12.3), et efface
 *    l'approbation precedente AINSI QUE le resume de validation. Garder des
 *    compteurs calcules sur un texte qui a change les transformerait en
 *    mensonge : le Preview de T1648 les affiche tels quels.
 * 3. **Une version chargee ne se modifie pas** (CDC 8.6) et ne se supprime pas
 *    (CDC 14.3). Le refus est explicite et porte une raison, pour que l'ecran
 *    puisse orienter vers Capturer ou Dupliquer.
 *
 * ## Le texte invalide est CONSERVE
 *
 * CDC 9.2 : un import stocke un DRAFT et conserve le texte meme s'il est
 * invalide. C'est la meme promesse que le Preview de T1648 tient en lecture :
 * un brouillon qu'on ne peut plus ouvrir serait un brouillon perdu.
 */
class ScenarioVersionWriter
{
    public function __construct(private readonly ScenarioManifestValidator $validator) {}

    /**
     * Tout texte qui entre doit etre de l'UTF-8 SANS octet NUL.
     *
     * Ce n'est pas une precaution de principe. `json_source` est une colonne
     * `text` : PostgreSQL refuse net un octet NUL (« null character not
     * permitted ») et une sequence UTF-8 invalide (« invalid byte sequence for
     * encoding UTF8 »). Sans cette garde, importer une image produirait une
     * QueryException non attrapee — donc une 500 en PRODUCTION — la ou SQLite
     * enregistrerait tranquillement les octets binaires.
     *
     * Le meme defaut a deux visages selon le moteur, et c'est le visage de
     * production qui compte.
     */
    private function refuserSiBinaire(string $texte): void
    {
        if (! mb_check_encoding($texte, 'UTF-8') || str_contains($texte, "\0")) {
            throw ScenarioVersionRefused::binaryContent();
        }
    }

    /**
     * Encode, ou refuse — jamais une chaine vide en silence.
     *
     * `json_encode` rend `false` sur de l'UTF-8 mal forme, et un type de
     * retour `string` en mode non strict coerce ce `false` en `''` : le
     * document serait enregistre VIDE, sans exception ni avertissement.
     * `JSON_THROW_ON_ERROR` transforme ce silence en incident, que l'appelant
     * traduit en refus lisible.
     */
    private function encoder(array $document): string
    {
        try {
            return json_encode(
                $document,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            );
        } catch (\JsonException) {
            throw ScenarioVersionRefused::binaryContent();
        }
    }

    /**
     * Un scenario vide (CDC 9.1) : toutes les collections V1, aucune donnee
     * metier.
     *
     * Le document produit est DELIBEREMENT invalide au sens du Validator, qui
     * exige au moins un user, une boucle, un membership et un dossier pour
     * qu'un manifeste soit chargeable. C'est le propre d'un brouillon : l'etat
     * DRAFT existe pour cela, et l'ecran doit le presenter comme un point de
     * depart, pas comme une faute.
     */
    public function createBlank(
        string $scenarioKey,
        string $name,
        string $locale,
        User $author,
        string $usage = ScenarioManifestVersion::USAGE_QA
    ): ScenarioManifestVersion {
        $this->refuserSiBinaire($name);

        return $this->creer(
            $scenarioKey,
            $name,
            $this->encoder(ScenarioManifestSkeleton::build($scenarioKey, $name, $locale)),
            ScenarioManifestVersion::ORIGIN_NEW,
            $author,
            null,
            $usage
        );
    }

    /**
     * Import ou Paste (CDC 9.2 / 9.3) : meme contrat. Un DRAFT, le texte
     * conserve tel quel, aucun Load declenche.
     */
    public function import(
        string $scenarioKey,
        string $name,
        string $json,
        User $author,
        string $usage = ScenarioManifestVersion::USAGE_QA
    ): ScenarioManifestVersion {
        // Le fichier importe peut etre n'importe quoi : une image, un PDF, du
        // Latin-1. `accept=".json"` sur le formulaire est un indice pour le
        // navigateur, pas une regle.
        $this->refuserSiBinaire($name);
        $this->refuserSiBinaire($json);

        return $this->creer($scenarioKey, $name, $json, ScenarioManifestVersion::ORIGIN_IMPORT, $author, null, $usage);
    }

    /**
     * Dupliquer (CDC 9.4).
     *
     * L'identite du document est REECRITE, pas seulement celle de la ligne
     * administrative : le texte JSON est l'unique source de verite (CDC 10.1),
     * donc une copie qui continuerait a declarer l'`id` de l'original serait
     * une copie qui ment sur ce qu'elle est.
     *
     * D'ou le refus sur un document illisible : on ne reecrit pas l'identite
     * d'un texte qu'on ne sait pas relire. Le contraire produirait une copie
     * portant l'identite de sa source, ce qui est pire qu'un refus.
     */
    public function duplicate(
        ScenarioManifestVersion $source,
        string $scenarioKey,
        string $name,
        User $author
    ): ScenarioManifestVersion {
        $this->refuserSiBinaire($name);

        $document = json_decode($source->json_source, true);

        if (! is_array($document)) {
            throw ScenarioVersionRefused::unparsableSource();
        }

        $document['id'] = $scenarioKey;
        $document['name'] = $name;
        // CDC 9.4 : la copie repart de 1.0.0. Une copie qui heriterait du
        // numero de sa source laisserait croire a une continuite de version
        // entre deux scenarios qui n'ont plus rien a voir.
        $document['version'] = '1.0.0';

        if (isset($document['organization']) && is_array($document['organization'])) {
            $document['organization']['proposed_slug'] = $scenarioKey;
        }

        // `parent_id` porte la PROVENANCE (CDC 3.3) : sans lui, la copie
        // declare qu'elle est une copie en perdant DE QUOI — la seule chose
        // que cette colonne existe pour dire.
        return $this->creer(
            $scenarioKey,
            $name,
            $this->encoder($document),
            ScenarioManifestVersion::ORIGIN_DUPLICATE,
            $author,
            $source,
            $source->usage
        );
    }

    /**
     * Remplacer le document d'une version (CDC 10.10 et 12.3).
     */
    public function updateDocument(ScenarioManifestVersion $version, string $json): ScenarioManifestVersion
    {
        $this->refuserSiChargee($version);
        $this->refuserSiBinaire($json);
        $this->refuserSiTropGros($json);

        $version->json_source = $json;

        // CDC 12.3 : toute modification repasse DRAFT, invalide l'approbation
        // precedente et exige une nouvelle validation. Le resume est efface
        // avec le reste : des compteurs calcules sur un texte qui a change
        // decriraient un document qui n'existe plus.
        $version->forceFill([
            'state' => ScenarioManifestVersion::STATE_DRAFT,
            'digest' => null,
            'validation_summary' => null,
            'approved_digest' => null,
            'approved_by' => null,
            'approved_at' => null,
        ])->save();

        return $version;
    }

    /**
     * L'etape TECHNIQUE de la validation (CDC 12.1).
     *
     * Elle ecrit ce que le Validator a constate — digest, compteurs, erreurs —
     * et fait passer DRAFT -> VALID sur un verdict VALID. Elle n'APPROUVE
     * rien : `approved_digest`, `approved_by` et `approved_at` restent la
     * marque de l'etape HUMAINE (CDC 12.2), qui est la seule porte vers un
     * Load et qui arrive en T1650.
     *
     * Une version chargee n'est pas revalidee : son document ne peut pas avoir
     * change, puisqu'il ne peut pas etre modifie.
     */
    public function validate(ScenarioManifestVersion $version): ScenarioManifestVersion
    {
        $this->refuserSiChargee($version);

        $resultat = $this->validator->validate($version->json_source);

        $version->forceFill([
            'digest' => $resultat->digest(),
            'validation_summary' => $resultat->toArray(),
            'state' => $resultat->isValid()
                ? ScenarioManifestVersion::STATE_VALID
                : ScenarioManifestVersion::STATE_DRAFT,
        ])->save();

        return $version;
    }

    /**
     * Supprimer une version administrative (CDC 14.3).
     *
     * Delete et Remove sont deux gestes DISTINCTS : Remove detruit une sandbox
     * et arrive en T1650. Ici on ne supprime qu'une definition — raison pour
     * laquelle une version chargee est refusee : sa suppression laisserait une
     * sandbox vivante sans definition qui la decrive.
     */
    public function delete(ScenarioManifestVersion $version): void
    {
        $this->refuserSiChargee($version);

        $version->delete();
    }

    private function creer(
        string $scenarioKey,
        string $name,
        string $json,
        string $origin,
        User $author,
        ?ScenarioManifestVersion $parent = null,
        string $usage = ScenarioManifestVersion::USAGE_QA
    ): ScenarioManifestVersion {
        $this->refuserSiTropGros($json);

        // `(scenario_key, version)` est unique en base.
        //
        // Le pre-controle donne un message UTILE a qui vient de taper un nom :
        // « cette version existe deja », plutot qu'une erreur de contrainte.
        // Mais il ne FERME pas la fenetre — deux requetes simultanees le
        // passent toutes les deux. C'est la contrainte qui tranche, et son
        // echec est rattrape plus bas pour rendre la MEME phrase.
        if ($this->existeDeja($scenarioKey)) {
            throw ScenarioVersionRefused::keyAlreadyUsed($scenarioKey, '1.0.0');
        }

        $version = new ScenarioManifestVersion([
            'scenario_key' => $scenarioKey,
            'name' => $name,
            'version' => '1.0.0',
            'usage' => $usage,
            'origin' => $origin,
            'json_source' => $json,
            'created_by' => $author->id,
            'parent_id' => $parent?->id,
        ]);

        // `state` vient du defaut du modele (DRAFT). Aucun attribut systeme
        // n'est pose ici : une creation n'a ni digest, ni resume, ni
        // approbation, ni chargement.
        try {
            $version->save();
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            // La course perdue rend la meme phrase que le pre-controle : pour
            // la personne devant l'ecran, les deux cas sont le meme fait.
            throw ScenarioVersionRefused::keyAlreadyUsed($scenarioKey, '1.0.0');
        }

        return $version;
    }

    private function existeDeja(string $scenarioKey): bool
    {
        return ScenarioManifestVersion::query()
            ->where('scenario_key', $scenarioKey)
            ->where('version', '1.0.0')
            ->exists();
    }

    private function refuserSiChargee(ScenarioManifestVersion $version): void
    {
        if ($version->isLoaded()) {
            throw ScenarioVersionRefused::loadedVersion();
        }
    }

    private function refuserSiTropGros(string $json): void
    {
        $octets = strlen($json);

        if ($octets > ScenarioManifestVersion::MAX_JSON_BYTES) {
            throw ScenarioVersionRefused::documentTooLarge($octets, ScenarioManifestVersion::MAX_JSON_BYTES);
        }
    }
}
