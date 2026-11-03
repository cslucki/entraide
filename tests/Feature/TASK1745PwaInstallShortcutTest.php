<?php

namespace Tests\Feature;

use App\Support\Pwa\PwaInstallContext;
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

        // TASK-1745, recette du 03/11 — « trop discret ». La carte dit
        // desormais les gestes UN PAR UN plutot qu'en une phrase : une liste
        // se parcourt du coin de l'oeil, une phrase continue oblige a tout
        // lire pour trouver ce qu'on doit faire.
        foreach (['installing_step_wait', 'installing_step_icon', 'installing_step_open'] as $etape) {
            $this->assertStringContainsString(
                __('pwa.'.$etape),
                $html,
                "l'etape [{$etape}] doit figurer dans la confirmation",
            );
        }

        // Et elle ne promet JAMAIS que c'est fini : un accord au dialogue
        // natif n'est pas une icône posée. Même doctrine qu'en T1740 — un
        // accusé de réception n'est pas une livraison.
        $this->assertStringNotContainsString('est installé', $html);
    }

    public function test_la_note_sur_les_notifications_suit_la_CAPACITE_REELLE(): void
    {
        // CDC 3.2 : ne rien promettre qui ne soit pas livré. La phrase
        // « BouclePro vous proposera d'activer les notifications » n'est vraie
        // que si le Push peut réellement partir.
        //
        // ## Ce test a ROUGI la CI le 03/11, et il avait tort
        //
        // Sa première version interrogeait le CATALOGUE SEUL, alors que la vue
        // demande `PwaInstallContext::annonceLesNotifications()` — qui exige
        // DEUX choses : le catalogue ouvert **et** `webpush.enabled`.
        //
        // Il posait donc la moitié de la question. En local il passait, parce
        // que le `.env` de développement porte `WEB_PUSH_ENABLED=true` ; en CI
        // la variable n'existe pas, la note disparaissait à juste titre, et le
        // test la réclamait quand même. Le produit avait raison, le test avait
        // tort — et l'écart n'est apparu qu'une fois T1744 fusionné, parce que
        // sur T1745 seule le catalogue était fermé et les deux moitiés
        // rendaient `false` ensemble, par accident.
        //
        // Leçon : un test qui RE-DÉRIVE la condition d'une vue au lieu de
        // demander à la MÊME autorité finit par diverger d'elle. Il interroge
        // désormais exactement ce que la vue interroge.
        //
        // ## Et il mesure les DEUX SENS
        //
        // Ne vérifier que la présence laisserait passer une note affichée en
        // permanence — c'est précisément le défaut `hidden` de T1744, où un
        // bouton restait visible dans tous les états faute d'avoir testé la
        // réciproque. L'interrupteur d'exploitation est donc basculé ici, et
        // la note doit suivre dans les deux positions.
        //
        // ## La note est cherchée dans les DEUX LANGUES, délibérément
        //
        // Second défaut du premier jet : il comparait `__('pwa.installing_note')`
        // — résolu dans la locale du PROCESSUS DE TEST — au HTML d'une page
        // rendue en `lang="en"`. Une chaîne française cherchée dans une page
        // anglaise ne se trouve jamais, et l'échec ne disait rien du produit.
        //
        // On cherche donc l'une OU l'autre, comme le font déjà les specs
        // navigateur de cette TASK : ce qu'on mesure ici est la PRÉSENCE de la
        // promesse, pas la langue dans laquelle elle est faite.
        $notes = [
            trans('pwa.installing_note', [], 'fr'),
            trans('pwa.installing_note', [], 'en'),
        ];

        $contient = static fn (string $html): bool => array_filter(
            $notes,
            static fn (string $n): bool => str_contains($html, $n),
        ) !== [];

        // Position HAUTE — la capacité existe-t-elle vraiment ?
        config()->set('webpush.enabled', true);
        config()->set('webpush.vapid.public_key', str_repeat('B', 87));

        $htmlAvecPush = $this->withHeader('User-Agent', self::UA_TELEPHONE)
            ->get('/about')->assertOk()->getContent();

        if (PwaInstallContext::annonceLesNotifications()) {
            $this->assertTrue(
                $contient($htmlAvecPush),
                'la capacité est annoncée : la note doit apparaître',
            );
        } else {
            // Catalogue fermé : même interrupteur allumé, on ne promet rien.
            $this->assertFalse(
                $contient($htmlAvecPush),
                'le catalogue est fermé : annoncer les notifications serait une promesse creuse',
            );
        }

        // Position BASSE — l'interrupteur d'exploitation ferme la promesse,
        // quoi que dise le catalogue. C'est la réciproque, et c'est elle qui
        // a manqué.
        config()->set('webpush.enabled', false);

        $this->assertFalse(
            PwaInstallContext::annonceLesNotifications(),
            'le Push éteint doit fermer la capacité annoncée',
        );

        $htmlSansPush = $this->withHeader('User-Agent', self::UA_TELEPHONE)
            ->get('/about')->assertOk()->getContent();

        $this->assertFalse(
            $contient($htmlSansPush),
            'Push éteint : la note ne doit pas être affichée',
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
