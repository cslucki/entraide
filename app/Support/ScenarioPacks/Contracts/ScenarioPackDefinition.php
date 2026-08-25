<?php

namespace App\Support\ScenarioPacks\Contracts;

use App\Models\Organization;
use App\Support\ScenarioPacks\ScenarioPackEntityRegistrar;

/**
 * Contrat qu'implemente une classe de scenario pack (TASK-1240), conforme a
 * `docs/specs/TASK-1239-scenario-pack-demonstration-product-spec.md`.
 *
 * Une implementation NE gere PAS elle-meme l'idempotence au niveau
 * enregistrement : elle applique son contenu avec les primitives Eloquent
 * habituelles du depot (ex. `updateOrCreate` sur une cle naturelle stable,
 * comme `ArtSciLabScenarioSeeder`), et declare chaque entite creee/reutilisee
 * au `$registrar` fourni via `internalKey()`. C'est le registrar — pas le
 * pack — qui transforme ces declarations en garanties d'idempotence, de
 * reset et de suppression bornee.
 *
 * `apply()` doit UNIQUEMENT ecrire dans l'Organization passee en argument :
 * jamais une autre Organization, jamais devinee depuis un contexte
 * d'execution ambiant.
 *
 * Ownership (TASK-1245) : le registrar note, a la premiere inscription, si
 * l'entite a ete physiquement creee par ce chargement (`created`, purgeable
 * au retrait) ou retrouvee preexistante (`reused`, seulement referencee).
 * Une entite `reused` ne doit PAS etre modifiee par le pack : `apply()` doit
 * la retrouver telle quelle (`firstOrCreate`, ou `updateOrCreate` avec des
 * valeurs identiques), sinon le chargement est refuse. Avant d'ecrire un
 * fichier de storage, appeler `$registrar->assertStoragePathAvailable()` :
 * un fichier preexistant n'est jamais ecrase.
 */
interface ScenarioPackDefinition
{
    /**
     * Identifiant stable et unique du pack (slug). Ne change jamais entre
     * deux versions du meme pack.
     */
    public function packId(): string;

    /**
     * Version semantique du pack, independante de la VERSION de
     * l'application.
     */
    public function packVersion(): string;

    /**
     * Nom lisible du pack.
     */
    public function packName(): string;

    /**
     * Une phrase : ce que ce pack demontre.
     */
    public function purpose(): string;

    /**
     * Applique le contenu du pack dans `$organization`. Doit etre rejouable
     * sans effet de bord destructeur (idempotence portee par le registrar) :
     * chaque entite creee ou retrouvee doit etre declaree via
     * `$registrar->track()`.
     */
    public function apply(Organization $organization, ScenarioPackEntityRegistrar $registrar): void;
}
