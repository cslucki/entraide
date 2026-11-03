<?php

namespace Tests\Feature;

use App\Support\Notifications\NotificationCatalogue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1745 — le bandeau installe DIRECTEMENT, sans ecran intermediaire.
 *
 * ## Le parcours mesure avant cette TASK
 *
 * Bandeau inferieur -> ecran dedie plein format -> dialogue natif Android.
 * Trois surfaces, dont **une seule est obligatoire** : la derniere, qui
 * appartient au navigateur. Le bandeau ne proposait pas d'installer, il
 * proposait d'aller sur une page qui le proposerait.
 *
 * ## Ce que cette suite defend, et c'est un EQUILIBRE
 *
 * Le raccourci ne doit pas couter le repli. Le bandeau reste un vrai LIEN
 * vers l'ecran dedie : sans JavaScript, sur iOS, et sur tout navigateur qui
 * n'emet pas `beforeinstallprompt`, c'est la seule chose qui fonctionne —
 * et c'est la que vivent les instructions manuelles.
 *
 * Les deux moities comptent donc, et aucune ne peut etre retiree sans que
 * l'autre devienne fausse :
 *
 * - l'attribut `data-bp-pwa-action="install"`, qui permet au script de le
 *   promouvoir en bouton d'installation ;
 * - le `href` vers l'ecran dedie, qui reste le comportement par defaut.
 *
 * Le comportement AU CLIC, lui, exige un navigateur : il est mesure dans
 * `tests/e2e/task-1745-install-raccourci.spec.js`. Ici on ne mesure que le
 * contrat du HTML, et ce fichier le dit plutot que de laisser croire qu'il
 * prouve davantage.
 */
class TASK1745PwaInstallShortcutTest extends TestCase
{
    use RefreshDatabase;

    /** Un telephone Android : c'est la seule combinaison qui rend le bandeau. */
    private const UA_TELEPHONE = 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Mobile Safari/537.36';

    public function test_le_bandeau_porte_l_action_d_installation(): void
    {
        $html = $this->withHeader('User-Agent', self::UA_TELEPHONE)
            ->get('/about')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-bp-pwa-install="banner"', $html, 'le bandeau doit etre rendu');

        // L'attribut qui permet au script de l'intercepter. Sans lui, le
        // bandeau redevient un simple renvoi vers une page.
        $this->assertMatchesRegularExpression(
            '/<a[^>]*class="bp-pwa__bar-action"[^>]*data-bp-pwa-action="install"/s',
            $html,
            "le lien du bandeau doit porter l'action d'installation",
        );
    }

    public function test_le_bandeau_reste_un_LIEN_vers_l_ecran_dedie(): void
    {
        // L'autre moitie du contrat, et c'est elle qui protege iOS.
        //
        // `beforeinstallprompt` n'existe pas sur iOS, et n'est pas garanti
        // ailleurs. Si le raccourci avait transforme ce lien en bouton, le
        // bandeau n'aurait plus mene nulle part sur la moitie des appareils
        // — une regression silencieuse, et invisible depuis un Chromium.
        $html = $this->withHeader('User-Agent', self::UA_TELEPHONE)
            ->get('/about')
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression(
            '/<a[^>]*class="bp-pwa__bar-action"[^>]*href="'.preg_quote(route('pwa.install'), '/').'"/s',
            $html,
            "le lien du bandeau doit conserver sa destination",
        );
    }

    public function test_la_confirmation_existe_et_dit_ou_aller(): void
    {
        // Le défaut rapporté par Cyril : après avoir accepté le dialogue
        // natif, la personne reste dans son navigateur et ne comprend pas
        // qu'elle doit maintenant ouvrir l'application depuis son téléphone.
        //
        // Ma première version faisait DISPARAÎTRE le bandeau après un
        // accord. Disparaître, c'est précisément ne rien expliquer.
        $html = $this->withHeader('User-Agent', self::UA_TELEPHONE)
            ->get('/about')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-bp-pwa-confirm', $html);
        $this->assertStringContainsString(__('pwa.installing_title'), $html);
        $this->assertStringContainsString(__('pwa.installing_body'), $html);

        // Et elle ne promet JAMAIS que c'est fini : un accord au dialogue
        // natif n'est pas une icône posée. Même doctrine qu'en T1740 — un
        // accusé de réception n'est pas une livraison.
        $this->assertStringNotContainsString('est installé', $html);
    }

    public function test_la_note_sur_les_notifications_suit_le_CATALOGUE(): void
    {
        // CDC 3.2 : ne rien promettre qui ne soit pas livré. La phrase
        // « BouclePro vous proposera d'activer les notifications » n'est
        // vraie que si le canal `web_push` est réellement ouvert.
        //
        // La question est posée au catalogue plutôt qu'à un drapeau : il est
        // déjà l'autorité de ce qui peut partir, et la réponse se met à jour
        // seule. Ce test vaut donc sur une branche où le canal est ouvert
        // comme sur une où il ne l'est pas — et c'est tout l'intérêt, les
        // deux existent en ce moment même.
        $ouvert = array_filter(
            NotificationCatalogue::keys(),
            fn (string $cle) => NotificationCatalogue::allowsChannel($cle, NotificationCatalogue::CHANNEL_WEB_PUSH),
        ) !== [];

        $html = $this->withHeader('User-Agent', self::UA_TELEPHONE)
            ->get('/about')
            ->assertOk()
            ->getContent();

        if ($ouvert) {
            $this->assertStringContainsString(
                __('pwa.installing_note'),
                $html,
                'le canal est ouvert : la note doit être annoncée',
            );

            return;
        }

        $this->assertStringNotContainsString(
            __('pwa.installing_note'),
            $html,
            "le canal n'est pas ouvert : annoncer les notifications serait une promesse creuse",
        );
    }

    public function test_l_ecran_dedie_reste_atteignable_et_complet(): void
    {
        // Le raccourci ne supprime pas l'ecran : il le reserve aux cas ou le
        // dialogue natif n'existe pas. Il doit donc continuer de rendre ses
        // instructions manuelles.
        $html = $this->withHeader('User-Agent', self::UA_TELEPHONE)
            ->get('/installer')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-bp-pwa-install="full"', $html);
        $this->assertStringContainsString('data-bp-pwa-platform="ios-safari"', $html);
        $this->assertStringContainsString('data-bp-pwa-platform="ios-other"', $html);
        $this->assertStringContainsString('data-bp-pwa-platform="generic"', $html);
    }
}
