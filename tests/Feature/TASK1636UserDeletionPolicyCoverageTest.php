<?php

namespace Tests\Feature;

use App\Services\UserDataLifecycleRegistry;
use App\Services\Users\UserDeletionExecutor;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * TASK-1636 — le registre et l'executeur ne peuvent pas diverger en silence.
 *
 * `UserDataLifecycleRegistry` reste la source de verite unique des politiques.
 * L'executeur ne redeclare rien : il nomme les CLES du registre qu'il prend en
 * charge. Ces tests verifient que les deux listes coincident exactement.
 *
 * Sans cette garde, ajouter demain une entree BLOCK au registre produirait un
 * executeur qui l'ignore : la donnee serait declaree bloquante, et supprimee
 * quand meme. C'est le genre de defaut qui ne se voit qu'une fois la donnee
 * perdue.
 */
class TASK1636UserDeletionPolicyCoverageTest extends TestCase
{
    /**
     * @return list<string>
     */
    private function registryKeysFor(string $policy): array
    {
        $keys = array_column(UserDeletionExecutor::entriesWithPolicy($policy), 'key');
        sort($keys);

        return $keys;
    }

    public function test_toute_entree_block_du_registre_est_prise_en_charge(): void
    {
        $declared = array_merge(
            UserDeletionExecutor::HARD_BLOCK_KEYS,
            UserDeletionExecutor::RESOLVED_BLOCK_KEYS,
        );
        sort($declared);

        $this->assertSame(
            $this->registryKeysFor(UserDataLifecycleRegistry::POLICY_BLOCK),
            $declared,
            "Une entree BLOCK du registre n'est ni refusee franchement par l'executeur, ".
            'ni resolue explicitement par lui. Elle serait ignoree en silence.'
        );
    }

    public function test_un_hard_block_et_un_block_resolu_ne_se_recouvrent_jamais(): void
    {
        // Une meme cle traitee des deux facons voudrait dire que l'executeur
        // refuse ET resout la meme donnee : l'un des deux chemins serait mort.
        $this->assertSame(
            [],
            array_intersect(
                UserDeletionExecutor::HARD_BLOCK_KEYS,
                UserDeletionExecutor::RESOLVED_BLOCK_KEYS
            )
        );
    }

    public function test_toute_entree_transfer_du_registre_est_prise_en_charge(): void
    {
        $declared = array_keys(UserDeletionExecutor::TRANSFERABLE);
        sort($declared);

        $this->assertSame(
            $this->registryKeysFor(UserDataLifecycleRegistry::POLICY_TRANSFER),
            $declared,
            "Une entree TRANSFER du registre n'est pas transferee par l'executeur : ".
            'la propriete serait bloquee par le RESTRICT sans que rien ne la deplace.'
        );
    }

    public function test_chaque_propriete_transferable_designe_une_colonne_reelle(): void
    {
        foreach (UserDeletionExecutor::TRANSFERABLE as $key => $spec) {
            $this->assertTrue(
                Schema::hasColumn($spec['table'], $spec['column']),
                "{$key} : {$spec['table']}.{$spec['column']} n'existe pas."
            );
        }
    }

    public function test_chaque_hard_block_designe_une_entree_du_registre_avec_sa_paire_sql(): void
    {
        $entries = collect(UserDataLifecycleRegistry::entries())->keyBy('key');

        foreach (UserDeletionExecutor::HARD_BLOCK_KEYS as $key) {
            $this->assertTrue($entries->has($key), "{$key} n'existe pas au registre.");

            $entry = $entries[$key];
            $this->assertArrayHasKey('table', $entry, "{$key} n'a pas de table : l'executeur ne pourrait rien compter.");
            $this->assertArrayHasKey('column', $entry, "{$key} n'a pas de colonne.");
        }
    }

    public function test_chaque_refus_porte_un_message_traduit_dans_les_deux_locales(): void
    {
        $keys = array_merge(UserDeletionExecutor::HARD_BLOCK_KEYS, [
            'loop_last_owner', 'transfer_required', 'user_missing',
            'transfer_target_missing', 'transfer_target_self',
            'transfer_target_banned', 'transfer_target_cross_tenant',
        ]);

        foreach (['fr', 'en'] as $locale) {
            $messages = __('admin.user_delete.block', [], $locale);

            foreach ($keys as $key) {
                $this->assertIsString(
                    $messages[$key] ?? null,
                    "admin.user_delete.block.{$key} manque en [{$locale}] : le refus s'afficherait comme une cle brute."
                );
            }
        }
    }
}
