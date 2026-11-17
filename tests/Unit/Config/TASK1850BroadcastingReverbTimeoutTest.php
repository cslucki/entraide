<?php

namespace Tests\Unit\Config;

use Tests\TestCase;

/**
 * TASK-1850 — garde de NON-REGRESSION sur un reglage de configuration,
 * PAS un test de minuterie reseau reel (volontairement absent de la
 * suite automatisee : un socket qui ne repond jamais serait fragile en
 * CI). La preuve dynamique (panne Reverb reelle, mesure du delai) a ete
 * faite manuellement et est documentee dans le TASK file et le commit —
 * ce test garantit seulement que personne ne reintroduit silencieusement
 * les defauts Laravel (connect_timeout 10s, timeout 30s) en vidant ces
 * deux cles.
 *
 * Les DEUX cles sont necessaires (verifie empiriquement, TASK-1850) :
 * `client_options.connect_timeout` borne la connexion TCP (configuration
 * du client Guzzle partage) ; `options.timeout` est OBLIGATOIRE EN PLUS
 * car chaque methode de `Pusher.php` (vendor) ecrase explicitement
 * l'option de requete Guzzle `timeout` avec sa propre valeur interne,
 * lue UNIQUEMENT depuis ce second tableau — jamais depuis
 * `client_options`.
 */
class TASK1850BroadcastingReverbTimeoutTest extends TestCase
{
    public function test_la_connexion_reverb_porte_des_timeouts_courts_explicites(): void
    {
        $clientOptions = config('broadcasting.connections.reverb.client_options');
        $this->assertIsFloat($clientOptions['connect_timeout'] ?? null);
        $this->assertLessThanOrEqual(5.0, $clientOptions['connect_timeout']);
        $this->assertIsFloat($clientOptions['timeout'] ?? null);
        $this->assertLessThanOrEqual(5.0, $clientOptions['timeout']);

        // La cle qui compte REELLEMENT pour chaque appel Pusher.php — voir
        // le docblock de classe.
        $options = config('broadcasting.connections.reverb.options');
        $this->assertIsFloat($options['timeout'] ?? null);
        $this->assertLessThanOrEqual(5.0, $options['timeout']);
    }

    public function test_la_connexion_pusher_externe_reste_intacte(): void
    {
        // TASK-1850 ne touche QUE la connexion `reverb` — `pusher` doit
        // rester exactement comme livree (mandat : « Ne modifier ni
        // Pusher ni les autres drivers »).
        $this->assertSame([], config('broadcasting.connections.pusher.client_options'));
    }
}
