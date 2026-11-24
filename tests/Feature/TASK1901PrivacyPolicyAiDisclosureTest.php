<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * TASK-1901 — la politique de confidentialite publique dit ce que la Data
 * Safety Google Play (scenario B) declare : OpenRouter achemine vers des
 * fournisseurs de modeles tiers, qui peuvent conserver les donnees ; ce qui
 * part (noms des auteurs, profil, indexation automatique des Dossiers) ; et
 * que certaines fonctions IA ne dependent pas du reglage de l'organisation.
 *
 * Elle ne doit plus promettre un usage « uniquement pour faire fonctionner
 * le service » ni conditionner toute l'IA a l'activation par l'organisation.
 */
class TASK1901PrivacyPolicyAiDisclosureTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{string, list<string>, list<string>}> */
    public static function langues(): array
    {
        return [
            'fr' => ['fr', [
                'les fournisseurs de modèles vers lesquels OpenRouter achemine les requêtes',
                'ils peuvent les conserver',
                'le nom de leurs auteurs',
                'les informations de votre profil',
                'transmis automatiquement, pour être indexé',
                'indépendamment de ce réglage',
                'conservées par les fournisseurs d’IA selon leurs propres politiques',
            ], [
                'uniquement pour faire fonctionner le service',
                'Certaines fonctions facultatives, lorsque votre organisation les a activées',
            ]],
            'en' => ['en', [
                'the model providers to which OpenRouter routes requests',
                'they may keep it',
                'their authors’ names',
                'your profile information',
                'also sent automatically so that it can be indexed',
                'regardless of that setting',
                'kept by the AI providers under their own policies',
            ], [
                'solely to run the service',
                'Some optional features, when your organisation has enabled them',
            ]],
        ];
    }

    /**
     * @param  list<string>  $presents
     * @param  list<string>  $absents
     */
    #[DataProvider('langues')]
    public function test_la_politique_decrit_les_transmissions_a_l_ia_du_scenario_b(string $langue, array $presents, array $absents): void
    {
        $page = $this->withHeader('Accept-Language', $langue)->get(route('confidentialite'))->assertOk();

        foreach ($presents as $texte) {
            $page->assertSee($texte);
        }

        foreach ($absents as $texte) {
            $page->assertDontSee($texte);
        }
    }
}
