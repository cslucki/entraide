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
        User $author,
        ?string $usage = null
    ): ScenarioManifestVersion {
        $this->refuserSiBinaire($name);

        // `parent_id` porte la PROVENANCE (CDC 3.3) : sans lui, la copie
        // declare qu'elle est une copie en perdant DE QUOI — la seule chose
        // que cette colonne existe pour dire.
        return $this->creer(
            $scenarioKey,
            $name,
            $this->reidentifier((string) $source->json_source, $scenarioKey, $name),
            ScenarioManifestVersion::ORIGIN_DUPLICATE,
            $author,
            $source,
            // L'usage de la source par defaut — une copie sert le plus souvent
            // la meme chose. Mais il est MODIFIABLE : dupliquer un scenario de
            // QA pour en faire une demo est precisement un cas normal.
            $usage ?? (string) $source->usage
        );
    }

    /**
     * Remplacer le document d'une version (CDC 10.10 et 12.3).
     */
    /**
     * TASK-1656 — creer un scenario a partir d'un MODELE publie.
     *
     * Le modele n'est pas une ligne de base : c'est un fichier livre avec le
     * code. Il n'y a donc pas de `parent_id` a poser — la provenance est dite
     * par `origin = template`, et le modele lui-meme reste intouche.
     *
     * Tout le reste est le contrat de {@see duplicate()}, au mot pres, parce
     * que c'est la MEME reecriture d'identite : nouvelle clef, nouveau nom,
     * retour a `1.0.0`, `proposed_slug` aligne. Les deux passent par
     * {@see reidentifier()} pour qu'aucune ne puisse deriver de l'autre.
     *
     * Et comme `creer()` ne pose aucun attribut systeme, la copie nait DRAFT,
     * sans digest, sans approbation et sans sandbox : « utiliser un modele » ne
     * charge rien.
     */
    public function createFromTemplate(
        string $templateJson,
        string $scenarioKey,
        string $name,
        User $author,
        string $usage = ScenarioManifestVersion::USAGE_QA
    ): ScenarioManifestVersion {
        $this->refuserSiBinaire($name);
        $this->refuserSiBinaire($templateJson);

        return $this->creer(
            $scenarioKey,
            $name,
            $this->reidentifier($templateJson, $scenarioKey, $name),
            ScenarioManifestVersion::ORIGIN_TEMPLATE,
            $author,
            null,
            $usage
        );
    }

    public function updateDocument(ScenarioManifestVersion $version, string $json): ScenarioManifestVersion
    {
        $this->refuserSiChargee($version);
        $this->refuserSiBinaire($json);
        $this->refuserSiTropGros($json);

        $version->json_source = $json;

        // TASK-1656 — la colonne `name` SUIT le document.
        //
        // `json_source` est la source UNIQUE depuis T1651 ; la colonne n'est
        // qu'une copie denormalisee, pour la liste, la recherche et le titre.
        // Elle n'etait ecrite qu'a la CREATION : renommer un scenario depuis
        // l'editeur visuel changeait le document et laissait l'ecran afficher
        // l'ancien nom. Deux noms pour un scenario, et la liste montrait le
        // perime — un geste sans effet visible, alors qu'il avait bien eu lieu.
        //
        // Trouve par la recette navigateur du parcours produit, pas par un test :
        // aucun test n'avait renomme PUIS relu la bibliotheque.
        $nomDuDocument = self::nomDeclareDans($json);

        if ($nomDuDocument !== null) {
            $version->name = $nomDuDocument;
        }

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
     * TASK-1651 — revalider SANS jamais promouvoir.
     *
     * ## Trois choses distinctes, que le mot « valider » confondait
     *
     * - la VALIDATION TECHNIQUE : le Validator dit si le document est bien
     *   forme et coherent. C'est une mesure, pas une decision.
     * - l'APPROBATION HUMAINE : un SuperAdmin confirme un digest PRECIS
     *   (CDC 12.2). C'est la seule porte vers un Load, et T1650 l'a posee.
     * - l'etat VALID : il signifie « techniquement vert ET confirme par un
     *   humain ». Il ne se gagne donc jamais tout seul.
     *
     * {@see validate()} promeut, parce qu'il est declenche par un CLIC humain
     * sur « Valider ». Une mutation de l'editeur visuel, elle, n'est pas ce
     * clic : elle doit rafraichir le verdict technique et laisser l'etat en
     * DRAFT, meme quand le Validator est vert.
     *
     * Sans cette separation, enchainer des modifications visuelles
     * reconstituait un etat VALID que personne n'avait confirme — et la
     * premiere version de T1651 le faisait.
     *
     * Le MOTEUR est le meme : meme `$this->validator`, meme appel, meme
     * resultat. Seule la politique d'etat change. Il n'existe pas de second
     * Validator, et il ne doit jamais en exister.
     */
    public function revalidateAsDraft(ScenarioManifestVersion $version): ScenarioManifestVersion
    {
        $this->refuserSiChargee($version);

        $resultat = $this->validator->validate($version->json_source);

        $version->forceFill([
            'digest' => $resultat->digest(),
            'validation_summary' => $resultat->toArray(),
            'state' => ScenarioManifestVersion::STATE_DRAFT,
        ])->save();

        return $version;
    }

    /**
     * L'etape TECHNIQUE de la validation (CDC 12.1).
     *
     * Elle ecrit ce que le Validator a constate — digest, compteurs, erreurs —
     * et fait passer DRAFT -> VALID sur un verdict VALID. Elle n'APPROUVE
     * rien : `approved_digest`, `approved_by` et `approved_at` restent la
     * marque de l'etape HUMAINE (CDC 12.2), posee par T1650, et qui est la
     * seule porte vers un Load.
     *
     * Une version chargee n'est pas revalidee : son document ne peut pas avoir
     * change, puisqu'il ne peut pas etre modifie.
     *
     * {@see revalidateAsDraft()} partage le meme moteur et NE promeut pas :
     * c'est la porte des mutations qui ne sont pas un clic humain.
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

    /**
     * Reecrire l'IDENTITE d'un document repris : sa clef, son nom, son numero
     * de version et le slug propose de son organisation.
     *
     * Partage par {@see duplicate()} et {@see createFromTemplate()}. C'est
     * volontaire : ce sont deux portes d'entree sur le meme geste — « repartir
     * de ce contenu sous une nouvelle identite ». Deux implementations
     * auraient fini par differer sur un champ, et la difference n'aurait ete
     * decouverte que par un document devenu incoherent.
     *
     * Le CONTENU metier n'est pas touche. Seule l'identite change.
     */
    private function reidentifier(string $json, string $scenarioKey, string $name): string
    {
        $document = json_decode($json, true);

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

        return $this->encoder($document);
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

    /**
     * Le `name` declare par un document, s'il en porte un exploitable.
     *
     * Rend `null` des que le texte n'est pas un objet JSON, ou que son `name`
     * n'est pas une chaine non vide : un document invalide reste ENREGISTRABLE
     * comme brouillon (c'est tout l'interet d'un brouillon), et son etat ne doit
     * pas pouvoir effacer le nom sous lequel l'utilisateur retrouve sa ligne.
     */
    private static function nomDeclareDans(string $json): ?string
    {
        $document = json_decode($json, true);

        if (! is_array($document)) {
            return null;
        }

        $nom = $document['name'] ?? null;

        if (! is_string($nom)) {
            return null;
        }

        $nom = trim($nom);

        // La colonne est bornee a 120 : un document qui en declare plus ne doit
        // pas faire echouer l'enregistrement du brouillon.
        return ($nom === '' || mb_strlen($nom) > 120) ? null : $nom;
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
