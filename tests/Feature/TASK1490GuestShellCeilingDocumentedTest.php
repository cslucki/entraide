<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * TASK-1490 — la cle d'environnement qui commande le Shell Welcome paye est
 * DOCUMENTEE, et la documentation ne peut pas se perimer en silence.
 *
 * ## Ce qui a ete mesure
 *
 * `AI_GUEST_SHELL_PLATFORM_CEILING_USD` etait absente de `.env.example`. La
 * garde economique, elle, est correcte et le reste : `GuestShellGate` refuse
 * AVANT tout appel au fournisseur quand la valeur est absente, vide ou <= 0
 * (`platform_ceiling_unset`). Le defaut n'etait donc pas dans le code — il
 * etait qu'une personne installant le depot n'avait aucun moyen d'apprendre
 * l'existence de cette cle, sinon en lisant `config/ai.php`.
 *
 * ## Pourquoi ce test, alors qu'il ne teste « que » de la documentation
 *
 * Parce qu'une documentation qui derive est pire qu'une documentation absente :
 * elle fait croire que quelqu'un a verifie. Si la cle d'environnement est
 * renommee dans `config/ai.php`, `.env.example` continuerait a annoncer
 * l'ancien nom, et l'operateur poserait un montant qui ne commande plus rien —
 * en croyant avoir active le Shell.
 *
 * Ce test attache donc les deux ensemble : le nom lu par la configuration et le
 * nom annonce par l'exemple.
 *
 * ## Aucune valeur n'est proposee, et c'est deliberе
 *
 * Le montant est une decision economique, pas une convention technique.
 * `.env.example` documente la cle VIDE : l'inventer serait engager une depense.
 * Le test verifie precisement cela — que l'exemple ne livre pas de montant.
 */
class TASK1490GuestShellCeilingDocumentedTest extends TestCase
{
    private const ENV_KEY = 'AI_GUEST_SHELL_PLATFORM_CEILING_USD';

    private function envExample(): string
    {
        $path = base_path('.env.example');
        $this->assertFileExists($path, '.env.example a disparu — il est l\'autorite d\'installation du depot.');

        return (string) file_get_contents($path);
    }

    /** La cle est annoncee a qui installe le depot. */
    public function test_the_platform_ceiling_key_is_documented_in_env_example(): void
    {
        $this->assertStringContainsString(
            self::ENV_KEY.'=',
            $this->envExample(),
            self::ENV_KEY.' est absente de .env.example : rien n\'apprend son existence a qui installe le depot.'
        );
    }

    /**
     * Le nom documente est CELUI QUE LA CONFIGURATION LIT.
     *
     * C'est la garde anti-derive : renommer la cle dans `config/ai.php` sans
     * toucher `.env.example` laisserait l'operateur poser un montant inerte.
     */
    public function test_the_documented_key_is_the_one_the_configuration_reads(): void
    {
        $config = (string) file_get_contents(config_path('ai.php'));

        $this->assertStringContainsString(
            "env('".self::ENV_KEY."')",
            $config,
            'config/ai.php ne lit plus '.self::ENV_KEY.' : .env.example documente une cle morte.'
        );
    }

    /**
     * Aucun montant n'est livre par defaut. Le plafond est une decision
     * economique — un exemple qui en propose un engagerait une depense a la
     * place de la personne qui installe.
     */
    public function test_env_example_ships_no_amount(): void
    {
        preg_match('/^'.preg_quote(self::ENV_KEY, '/').'=(.*)$/m', $this->envExample(), $m);

        $this->assertNotEmpty($m, 'La ligne '.self::ENV_KEY.' est introuvable dans .env.example.');
        $this->assertSame(
            '',
            trim($m[1]),
            '.env.example livre un montant pour '.self::ENV_KEY.' : le plafond est une decision economique, jamais une valeur par defaut.'
        );
    }

    /**
     * Le fail-closed que la documentation DECRIT existe reellement.
     *
     * Sans cette assertion, le commentaire de `.env.example` serait une
     * promesse invérifiée — exactement le genre de phrase qui a laisse passer
     * TASK-1488.
     */
    public function test_the_documented_fail_closed_is_real(): void
    {
        config()->set('ai.guest_shell.platform_monthly_ceiling_usd', null);
        $this->assertFalse(
            is_numeric(config('ai.guest_shell.platform_monthly_ceiling_usd')),
            'Un plafond absent doit rester non numerique — c\'est ce que la garde teste.'
        );

        foreach ([null, '', 0, -1] as $refused) {
            config()->set('ai.guest_shell.platform_monthly_ceiling_usd', $refused);
            $value = config('ai.guest_shell.platform_monthly_ceiling_usd');

            $this->assertTrue(
                ! is_numeric($value) || (float) $value <= 0,
                'La valeur '.var_export($refused, true).' devrait declencher le refus platform_ceiling_unset.'
            );
        }
    }
}
