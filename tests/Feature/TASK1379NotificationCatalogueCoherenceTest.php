<?php

namespace Tests\Feature;

use App\Support\Notifications\NotificationCatalogue;
use App\Support\Notifications\NotificationTargetResolver;
use Illuminate\Support\Facades\Lang;
use ReflectionClass;
use Tests\TestCase;

/**
 * TASK-1379 — le catalogue tient-il ses promesses ?
 *
 * ## Le probleme que cette suite resout
 *
 * `NotificationCatalogue` annonce ne declarer que ce qui EXISTE. C'est une
 * regle ecrite dans son docblock, et **rien ne l'appliquait**. Declarer un
 * canal sans libelle, sans traduction d'email ou sans branche de resolveur
 * passait sans bruit — et se payait en PRODUCTION, sous forme d'un email
 * `failed / missing_email_translation`, d'une livraison `skipped_unreachable`,
 * ou d'un slug technique affiche a un membre.
 *
 * ## Le defaut qui a motive cette suite
 *
 * T1378 a ajoute le canal EMAIL au catalogue **sans son libelle**. L'ecran de
 * reglages affichait donc « email » a cote de « Dans l'application ». Aucun test
 * ne pouvait le voir : la couverture portait sur les comportements, jamais sur
 * la COHERENCE du registre avec ce qui l'entoure.
 *
 * ## Pourquoi ces tests sont differents des autres
 *
 * Ils ne mesurent pas un comportement, ils mesurent une CORRESPONDANCE entre
 * plusieurs autorites — registre, fichiers de langue, resolveur de cible. Il n'y
 * a donc pas de sabotage de code applicatif qui les fasse rougir : ce qui les
 * fait rougir, c'est une entree de catalogue incomplete. C'est exactement leur
 * role, et c'est dit ici plutot que de les faire passer pour des preuves de
 * comportement.
 *
 * Ils grandissent SEULS : chaque nouvelle cle ajoutee au registre est
 * automatiquement soumise a toutes ces exigences, sans une ligne de test
 * supplementaire.
 */
class TASK1379NotificationCatalogueCoherenceTest extends TestCase
{
    /**
     * Le slug de traduction d'une cle : les points deviennent des soulignes.
     *
     * Duplique a l'identique dans le livreur et dans deux vues — c'est la
     * duplication meme qui rend cette suite necessaire. Voir la note de dette
     * en fin de fichier.
     */
    private function slug(string $cle): string
    {
        return str_replace('.', '_', $cle);
    }

    /** Toute cle declaree a un libelle lisible, dans les DEUX langues. */
    public function test_every_key_has_a_human_label_in_both_locales(): void
    {
        foreach (NotificationCatalogue::keys() as $cle) {
            foreach (['fr', 'en'] as $locale) {
                $traduction = 'notifications.keys.'.$this->slug($cle);

                $this->assertTrue(
                    Lang::has($traduction, $locale),
                    "[{$locale}] la cle [{$cle}] n'a pas de libelle : le Centre affichera « Notification » a la place."
                );
            }
        }
    }

    /**
     * **Tout canal declare a un libelle, dans les DEUX langues.**
     *
     * C'est le defaut exact de T1378 : EMAIL a ete ajoute au catalogue sans son
     * libelle, et l'ecran de reglages montrait le slug technique `email` a un
     * membre. Sans repli lisible : la vue rend le slug tel quel.
     */
    public function test_every_declared_channel_has_a_label_in_both_locales(): void
    {
        $canaux = [];

        foreach (NotificationCatalogue::keys() as $cle) {
            foreach (NotificationCatalogue::channelsFor($cle) as $canal) {
                $canaux[$canal] = true;
            }
        }

        $this->assertNotEmpty($canaux, 'Le catalogue doit declarer au moins un canal.');

        foreach (array_keys($canaux) as $canal) {
            foreach (['fr', 'en'] as $locale) {
                $traduction = 'notifications.channel_'.$canal;

                $this->assertTrue(
                    Lang::has($traduction, $locale),
                    "[{$locale}] le canal [{$canal}] n'a pas de libelle : l'ecran de reglages affichera le slug technique."
                );
            }
        }
    }

    /**
     * **Une cle qui autorise EMAIL a son contenu d'email, dans les DEUX langues.**
     *
     * Sans lui, la livraison conclut `failed / missing_email_translation` — et
     * elle le fait en PRODUCTION, silencieusement, apres avoir traverse toute la
     * file. Le membre ne recoit rien et personne ne le sait.
     */
    public function test_every_email_key_has_its_content_in_both_locales(): void
    {
        foreach (NotificationCatalogue::keys() as $cle) {
            if (! NotificationCatalogue::allowsChannel($cle, NotificationCatalogue::CHANNEL_EMAIL)) {
                continue;
            }

            foreach (['fr', 'en'] as $locale) {
                foreach (['subject', 'body'] as $champ) {
                    $traduction = 'notifications.email.'.$this->slug($cle).'.'.$champ;

                    $this->assertTrue(
                        Lang::has($traduction, $locale),
                        "[{$locale}] la cle [{$cle}] autorise EMAIL sans [{$champ}] : la livraison echouera en production."
                    );
                }
            }
        }
    }

    /**
     * **Le corps d'un email porte `:url`.**
     *
     * C'est ce parametre que le livreur rend DEUX FOIS — la vraie adresse pour
     * l'envoi, un marqueur expurge pour l'archivage. Un corps qui ne l'emploie
     * pas prive le destinataire de son lien, et un corps qui ecrirait l'adresse
     * en dur contournerait l'expurgation : le jeton finirait dans `email_logs`.
     */
    public function test_every_email_body_uses_the_url_placeholder(): void
    {
        foreach (NotificationCatalogue::keys() as $cle) {
            if (! NotificationCatalogue::allowsChannel($cle, NotificationCatalogue::CHANNEL_EMAIL)) {
                continue;
            }

            foreach (['fr', 'en'] as $locale) {
                $corps = (string) __('notifications.email.'.$this->slug($cle).'.body', [], $locale);

                $this->assertStringContainsString(
                    ':url',
                    $corps,
                    "[{$locale}] le corps de [{$cle}] n'emploie pas :url — le lien n'atteindrait pas le destinataire."
                );

                // Aucune adresse en dur : elle echapperait a l'expurgation.
                $this->assertDoesNotMatchRegularExpression(
                    '#https?://#i',
                    $corps,
                    "[{$locale}] le corps de [{$cle}] contient une adresse en dur : elle echapperait a l'expurgation du jeton."
                );
            }
        }
    }

    /**
     * **Le type d'objet de chaque cle a une branche dans le resolveur de cible.**
     *
     * Sans branche, `resolve()` retombe sur son `default => null` fail-closed :
     * la livraison conclut `skipped_unreachable` et l'email ne part jamais. Le
     * comportement est sur, mais la cle est morte — et rien ne le disait.
     *
     * La mesure porte sur le CODE du resolveur, faute de pouvoir l'interroger :
     * `resolve()` exige une notification complete, donc un tenant, un
     * destinataire et un objet metier vivant. Construire tout cela pour verifier
     * une correspondance de chaine couterait plus que ce que ca prouverait.
     */
    public function test_every_object_type_has_a_branch_in_the_target_resolver(): void
    {
        $source = file_get_contents((new ReflectionClass(NotificationTargetResolver::class))->getFileName());

        foreach (NotificationCatalogue::keys() as $cle) {
            $type = NotificationCatalogue::objectTypeFor($cle);

            $this->assertNotNull($type, "La cle [{$cle}] n'a pas d'object_type.");

            $this->assertStringContainsString(
                'OBJECT_'.strtoupper($this->slug($type)),
                $source,
                "Le type [{$type}] n'a pas de branche dans le resolveur : la cle [{$cle}] serait emise puis jamais livree."
            );
        }
    }

    /**
     * **Un canal obligatoire est actif par defaut.**
     *
     * `configurable => false` signifie « le membre ne peut pas le couper ».
     * Combine a `default => false`, cela donnerait un canal que PERSONNE ne peut
     * activer — ni le membre, ni un reglage : une cle morte, declaree active.
     */
    public function test_a_mandatory_channel_is_on_by_default(): void
    {
        foreach (NotificationCatalogue::keys() as $cle) {
            foreach (NotificationCatalogue::channelsFor($cle) as $canal) {
                if (NotificationCatalogue::channelIsConfigurable($cle, $canal)) {
                    continue;
                }

                $this->assertTrue(
                    (bool) NotificationCatalogue::channelDefault($cle, $canal),
                    "[{$cle}/{$canal}] est obligatoire ET inactif par defaut : personne ne pourrait jamais l'activer."
                );
            }
        }
    }

    /**
     * Les longueurs declarees tiennent dans le schema.
     *
     * SQLite IGNORE les longueurs de `varchar` : une cle trop longue y passerait
     * sans bruit et serait tronquee ou refusee sur PostgreSQL. Le catalogue est
     * ecrit a la main, donc cette verification est la seule qui existe.
     */
    public function test_declared_identifiers_fit_the_schema(): void
    {
        foreach (NotificationCatalogue::keys() as $cle) {
            $this->assertLessThanOrEqual(80, mb_strlen($cle), "La cle [{$cle}] depasse notification_key varchar(80).");

            $type = (string) NotificationCatalogue::objectTypeFor($cle);
            $this->assertLessThanOrEqual(40, mb_strlen($type), "Le type [{$type}] depasse object_type varchar(40).");
        }
    }
}
