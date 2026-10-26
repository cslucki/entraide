<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * TASK-1707 — l'email de confirmation d'adresse, relu en recette.
 *
 * Deux defauts constates dans MailHog pendant le parcours OFSH du 25/10 :
 *
 * 1. **L'URL n'existait que dans le `href`.** La table ne stocke que
 *    `content_html` ; la version texte brut est derivee en retirant les
 *    balises, et le lien disparaissait avec elles. Le destinataire dont le
 *    client affiche le texte brut lisait « Confirmer mon adresse » SANS
 *    adresse — et ne pouvait pas valider son compte.
 *
 * 2. **Les accents manquaient** : « une etape », « Passe ce delai », « vous
 *    n'etes pas a l'origine ». Pas un defaut d'encodage : le pied de page du
 *    meme email affiche « Tous droits réservés » correctement.
 *
 * ## Ce que ce fichier mesure, et pourquoi a ce niveau-la
 *
 * Sur la LIGNE EN BASE, pas sur le seeder ni sur la migration. C'est elle que
 * le produit envoie ; verifier la source sans verifier ce qui en resulte
 * laisserait passer une migration qui ne s'applique pas — exactement le piege
 * de TASK-1701, ou le chemin de test n'atteignait pas le chemin de code.
 */
class TASK1707VerificationEmailTest extends TestCase
{
    use RefreshDatabase;

    private const SLUG = 'confirmation-email-suite-inscription';

    private function gabarit(string $locale): string
    {
        $ligne = DB::table('system_email_templates')
            ->where('slug', self::SLUG)
            ->where('locale', $locale)
            ->first();

        $this->assertNotNull($ligne, "Le gabarit « ".self::SLUG." » est absent en « {$locale} ».");

        return (string) $ligne->content_html;
    }

    /**
     * L'adresse survit au retrait des balises — dans les DEUX langues.
     *
     * `strip_tags()` est exactement ce que fait la derivation du texte brut :
     * la mesure reproduit le traitement, elle ne le suppose pas.
     */
    public function test_l_adresse_survit_au_retrait_des_balises(): void
    {
        foreach (['fr', 'en'] as $locale) {
            $html = $this->gabarit($locale);

            // Non-vacuite : le lien cliquable doit exister par ailleurs.
            $this->assertStringContainsString('href="{{ url }}"', $html, "Le lien cliquable a disparu en « {$locale} ».");

            $brut = strip_tags(str_replace('<br>', "\n", $html));

            $this->assertStringContainsString(
                '{{ url }}',
                $brut,
                "En « {$locale} », l'adresse n'existe que dans le href : le texte brut ne permet pas de confirmer son compte."
            );
        }
    }

    /** Et le texte francais porte ses accents. */
    public function test_le_texte_francais_porte_ses_accents(): void
    {
        $html = $this->gabarit('fr');

        foreach (['étape', 'Passé ce délai', 'n\'êtes pas à l\'origine'] as $attendu) {
            $this->assertStringContainsString($attendu, $html, "« {$attendu} » manque ou est sans accent.");
        }

        foreach (['une etape', 'Passe ce delai', 'n\'etes pas a l\'origine'] as $fautif) {
            $this->assertStringNotContainsString($fautif, $html, "« {$fautif} » est revenu.");
        }
    }

    /**
     * Le seeder et la base disent la MEME chose.
     *
     * Le seeder sert les bases neuves, la migration les bases en service. S'ils
     * divergent, une installation neuve et une installation existante
     * enverraient deux emails differents — et personne ne le verrait.
     */
    public function test_le_seeder_et_la_base_ne_divergent_pas(): void
    {
        $seeder = file_get_contents(database_path('seeders/SystemEmailTemplateSeeder.php'));

        foreach (['fr' => 'copiez cette adresse', 'en' => 'copy this address'] as $locale => $repere) {
            $this->assertStringContainsString(
                $repere,
                $seeder,
                "Le seeder ne porte pas le repli d'adresse en « {$locale} » : une base neuve naitrait avec le defaut."
            );
            $this->assertStringContainsString($repere, $this->gabarit($locale));
        }
    }
}
