<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1760 — « Prestation » devient « Action ».
 *
 * Arbitrage Cyril du 05/11 : « Prestation » fait MARCHAND, on parle d'ACTIONS
 * d'entraide. C'etait la derniere valeur affichee portant le vocabulaire banni
 * par le critere n°1 de la TASK A — et elle avait echappe a tout le chantier
 * parce qu'elle vit dans `member_ai_profile`, hors de l'inventaire §2 du CDC.
 *
 * ## La cle reste `prestation`, et c'est deliberé
 *
 * Elle est STOCKEE dans le `help_types` des profils deja remplis, et sert
 * d'index dans `MemberProfileAgentResponder` et `InlineMemberAgent`. La
 * renommer demanderait une reprise de donnees pour zero gain : l'utilisateur ne
 * voit jamais la cle. Meme doctrine que §3.5 du CDC, qui refuse de renommer les
 * colonnes et les routes.
 */
class TASK1760LexiqueActionTest extends TestCase
{
    use RefreshDatabase;

    /** Le libelle affiche, dans les DEUX langues. */
    public function test_le_type_d_aide_se_dit_action_et_non_prestation(): void
    {
        foreach (['fr', 'en'] as $locale) {
            app()->setLocale($locale);

            $options = __('member_ai_profile.help_type_options');

            $this->assertIsArray($options, "Le catalogue doit exister en « {$locale} ».");
            $this->assertArrayHasKey('prestation', $options, 'La CLE ne change pas : elle est stockee en base.');
            $this->assertSame('Action', $options['prestation'], "Libelle attendu en « {$locale} ».");
        }
    }

    /**
     * 🔴 Et plus aucune VALEUR du vocabulaire marchand dans les fichiers de
     * langue — la reciproque, qui transforme ce test en garde.
     *
     * On mesure les valeurs, pas les cles : une cle n'est jamais affichee, et
     * les surfaces d'administration gardent le vocabulaire technique (critere
     * n°1 de la TASK A l'autorise explicitement).
     */
    public function test_aucun_fichier_de_langue_n_affiche_plus_le_vocabulaire_marchand(): void
    {
        $fautifs = [];

        foreach (['fr', 'en'] as $locale) {
            foreach (glob(lang_path($locale.'/*.php')) as $fichier) {
                // `admin.php` EST le lexique d'administration : le critere n°1
                // de la TASK A autorise explicitement le vocabulaire technique
                // sur ces surfaces (« Échanges en tant qu'acheteur » y est
                // juste, et plus clair qu'une periphrase).
                if (basename($fichier) === 'admin.php') {
                    continue;
                }

                $contenu = file_get_contents($fichier);

                // On capture la CLE et la VALEUR : les cles d'administration
                // vivent aussi dans des fichiers mixtes — `navigation.php`
                // porte la navigation membre ET les libelles `org_admin_*`.
                // Exclure le fichier entier aurait aveugle la garde sur ses
                // cles membre.
                preg_match_all("/'([a-z0-9_.]+)'\s*=>\s*'([^']*)'/i", $contenu, $m, PREG_SET_ORDER);

                foreach ($m as [, $cle, $valeur]) {
                    if (preg_match('/^(org_)?admin/i', $cle)) {
                        continue;
                    }

                    if (preg_match('/\b(prestataire|prestation|acheteur|vendeur)\b/i', $valeur)) {
                        $fautifs[] = basename($fichier).' : '.$cle.' = '.$valeur;
                    }
                }
            }
        }

        $this->assertSame([], $fautifs,
            "Vocabulaire marchand encore affiche :\n".implode("\n", $fautifs));
    }
}
