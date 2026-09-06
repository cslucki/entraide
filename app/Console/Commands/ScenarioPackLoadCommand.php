<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Models\ScenarioPackLoad;
use App\Support\ScenarioPacks\Contracts\ProvisionsItsOrganization;
use App\Support\ScenarioPacks\ScenarioPackCatalog;
use App\Support\ScenarioPacks\ScenarioPackLoader;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * TASK-1240 — charge un scenario pack dans une Organization. Idempotent :
 * rejouer sur le meme (pack, Organization) ne duplique rien.
 */
class ScenarioPackLoadCommand extends Command
{
    protected $signature = 'scenario-pack:load {pack : pack_id enregistre dans config(scenario_packs.definitions)} {organization : slug de l\'Organization cible}';

    protected $description = 'Charge un scenario pack (TASK-1240) dans une Organization qualifiee demonstration/dogfooding';

    public function handle(ScenarioPackCatalog $catalog, ScenarioPackLoader $loader): int
    {
        $organization = Organization::query()->where('slug', $this->argument('organization'))->first();

        $provisioned = false;

        try {
            $pack = $catalog->get($this->argument('pack'));

            // TASK-1351 — repli OPT-IN, jamais un comportement general : seul
            // un pack qui declare ProvisionsItsOrganization peut creer sa
            // cible, et seulement pour le slug auquel il est lie. Les deux
            // packs anterieurs n'implementent pas ce contrat : pour eux, une
            // Organization absente reste l'echec qu'elle a toujours ete.
            if ($pack instanceof ProvisionsItsOrganization) {
                if ($organization === null) {
                    if ($this->argument('organization') !== $pack->organizationSlug()) {
                        $this->error(
                            "Le pack '{$pack->packId()}' ne provisionne que l'Organization '{$pack->organizationSlug()}', ".
                            "reçu '{$this->argument('organization')}'."
                        );

                        return self::FAILURE;
                    }

                    $organization = $pack->provisionOrganization();
                    $provisioned = true;
                    $this->line("Organization provisionnée par le pack : {$organization->slug}.");
                } elseif (! $this->packAlreadyLoadedIn($pack->packId(), $organization)) {
                    // Organization preexistante DANS LAQUELLE ce pack n'a
                    // jamais ete charge : jamais adoptee sans preuve qu'elle
                    // ne porte aucune donnee metier.
                    //
                    // La condition compte autant que la regle : sans elle, le
                    // rechargement idempotent du pack dans SA PROPRE
                    // Organization serait refuse par les donnees qu'il vient
                    // lui-meme d'y ecrire.
                    $pack->assertOrganizationAdoptable($organization);
                }
            }

            if ($organization === null) {
                $this->error("Organization introuvable pour le slug '{$this->argument('organization')}'.");

                return self::FAILURE;
            }

            $result = $loader->load($pack, $organization);
        } catch (RuntimeException $e) {
            // Un chargement qui echoue APRES avoir provisionne ne doit pas
            // laisser derriere lui une Organization orpheline que plus rien ne
            // sait supprimer : on defait exactement ce que cette invocation a
            // cree, et rien d'autre.
            if ($provisioned && $organization !== null) {
                $organization->forceDelete();
            }

            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($provisioned) {
            // Provenance ecrite APRES un chargement reussi : une Organization
            // creee pour un chargement qui echoue ensuite ne doit pas se
            // declarer supprimable par un futur retrait.
            $result->load->forceFill(['organization_created_by_pack' => true])->save();
        }

        $this->info($result->wasFirstLoad ? 'Premier chargement.' : 'Rechargement idempotent (aucune duplication).');
        $this->line("Pack       : {$pack->packId()} v{$pack->packVersion()}");
        $this->line("Organization : {$organization->slug} ({$organization->id})");
        $this->line("Entites    : {$result->totalEntities()}");
        foreach ($result->entityCountsByType as $type => $count) {
            $this->line("  - {$type} : {$count}");
        }

        return self::SUCCESS;
    }

    /**
     * Ce pack a-t-il deja un chargement dans cette Organization ?
     *
     * C'est la seule preuve que l'Organization n'est pas « celle de quelqu'un
     * d'autre » : le contenu, lui, ne dit rien de son origine.
     */
    private function packAlreadyLoadedIn(string $packId, Organization $organization): bool
    {
        return ScenarioPackLoad::query()
            ->where('organization_id', $organization->id)
            ->where('pack_id', $packId)
            ->exists();
    }
}
