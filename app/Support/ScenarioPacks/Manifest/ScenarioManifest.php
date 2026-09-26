<?php

namespace App\Support\ScenarioPacks\Manifest;

use App\Support\ScenarioManifest\ManifestCanonicalJson;
use App\Support\ScenarioManifest\ManifestValidationResult;
use App\Support\ScenarioManifest\ScenarioManifestValidator;

/**
 * TASK-1642 — un manifeste VALIDE et son digest, prets a etre charges.
 *
 * C'est le `ScenarioManifest` de la forme conceptuelle de la spec 4.3. Le
 * type lui-meme porte la garantie : on ne peut pas en construire un a partir
 * d'un JSON simplement parsable. `fromApprovedJson()` revalide integralement
 * et recalcule le digest ; une instance qui existe est donc, par
 * construction, un document dont chaque regle normative a ete verifiee.
 *
 * C'est ce qui empeche un futur appelant — ecran SuperAdmin, commande, job —
 * de charger un document "presque valide" : il n'a aucun moyen d'obtenir
 * l'objet sans passer par la validation.
 */
final class ScenarioManifest
{
    private function __construct(
        private readonly \stdClass $document,
        private readonly string $digest,
    ) {}

    /**
     * Construit un manifeste chargeable a partir d'un JSON et du digest
     * APPROUVE par un humain (spec 5.2).
     *
     * Les deux conditions sont verifiees separement, et l'ordre compte :
     * d'abord la validite — un document invalide n'a pas de digest qui veuille
     * dire quelque chose — puis l'egalite au digest approuve. Une mutation
     * entre l'approbation et le Load change le digest canonique et fait donc
     * echouer cette seconde verification, AVANT toute ecriture metier.
     *
     * @throws ManifestNotLoadableException
     */
    public static function fromApprovedJson(string $json, string $approvedDigest): self
    {
        $result = (new ScenarioManifestValidator)->validate($json);

        if (! $result->isValid()) {
            throw ManifestNotLoadableException::invalidManifest($result);
        }

        $document = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
        $digest = ManifestCanonicalJson::digest($document);

        // Comparaison a temps constant : le digest est le secret d'integrite
        // qui autorise une ecriture metier, et rien n'oblige a le comparer
        // paresseusement.
        if (! hash_equals($approvedDigest, $digest)) {
            throw ManifestNotLoadableException::digestMismatch($approvedDigest, $digest);
        }

        return new self($document, $digest);
    }

    public function digest(): string
    {
        return $this->digest;
    }

    public function document(): \stdClass
    {
        return $this->document;
    }

    public function id(): string
    {
        return (string) $this->document->id;
    }

    public function version(): string
    {
        return (string) $this->document->version;
    }

    public function name(): string
    {
        return (string) $this->document->name;
    }

    public function purpose(): string
    {
        return (string) $this->document->purpose;
    }

    public function locale(): string
    {
        return (string) $this->document->locale;
    }

    public function organization(): \stdClass
    {
        return $this->document->organization;
    }

    /**
     * Le slug SUGGERE par le producteur. Jamais le slug final : BouclePro
     * choisit celui-ci au provisioning (spec 7.1).
     */
    public function proposedSlug(): string
    {
        return (string) $this->document->organization->proposed_slug;
    }

    /**
     * @return array<int, \stdClass>
     */
    public function collection(string $name): array
    {
        $node = $this->document->{$name} ?? null;

        return is_array($node) ? $node : [];
    }

    /**
     * Le rapport complet, pour un appelant qui veut afficher les compteurs
     * sans revalider.
     */
    public static function validate(string $json): ManifestValidationResult
    {
        return (new ScenarioManifestValidator)->validate($json);
    }
}
