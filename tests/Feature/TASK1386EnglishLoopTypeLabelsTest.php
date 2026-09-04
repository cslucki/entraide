<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Support\Loops\LoopTypeRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Lang;
use Tests\TestCase;

/**
 * TASK-1386 — les types de Boucle disponibles parlent anglais en anglais.
 *
 * ## Le constat, MESURE
 *
 * `lang/en/loops.php` portait des valeurs FRANCAISES, identiques a celles de
 * `lang/fr/loops.php` : le fichier anglais avait ete copie sans etre traduit sur
 * ces lignes. Rendu en locale `en` avant correction :
 *
 * | type         | disponible | rendu EN        |
 * |--------------|------------|-----------------|
 * | general      | oui        | Community       |
 * | project      | **oui**    | **« Projets »** |
 * | coaching     | oui        | Coaching        |
 * | training     | **oui**    | **« Formation »** |
 * | peer_support | non        | « Pair-Aidance » |
 *
 * Deux types DISPONIBLES, donc traverses par la demonstration anglaise du 16/09 :
 * onglets du catalogue `/loops`, selecteur de type a la creation, badge de type
 * sur chaque carte, ligne de composition.
 *
 * ## Une seule source, quatre surfaces
 *
 * Mesure faite : les quatre vues appellent toutes `LoopTypeRegistry::label()`,
 * qui resout `label_key` par `__()`. Corriger le fichier de langue corrige donc
 * les quatre d'un coup — et c'est le REGISTRE qu'on mesure ici, pas chaque vue,
 * parce que c'est lui l'autorite.
 *
 * ## `peer_support` reste en francais, et c'est deliberе
 *
 * Le type est declare `available => false` : il n'apparait que sur l'ecran
 * d'administration des types, hors parcours de demonstration. Le corriger
 * sortirait du perimetre arbitre. Il est documente comme dette R3.
 */
class TASK1386EnglishLoopTypeLabelsTest extends TestCase
{
    use RefreshDatabase;

    /** Les deux types corriges par cette tranche, avec leur libelle attendu. */
    private const ATTENDUS_EN = [
        'project' => 'Projects',
        'training' => 'Training',
    ];

    /** Ce que le FRANCAIS doit continuer a dire — la tranche ne casse pas FR. */
    private const ATTENDUS_FR = [
        'project' => 'Projets',
        'training' => 'Formation',
    ];

    private function registre(): LoopTypeRegistry
    {
        return app(LoopTypeRegistry::class);
    }

    /**
     * En anglais, les deux types disponibles rendent un libelle ANGLAIS.
     *
     * Les valeurs attendues sont ecrites EN DUR, jamais relues par `__()` : les
     * comparer a la traduction reviendrait a interroger le meme oracle que le
     * code teste, et une cle cassee rendrait la meme chaine des deux cotes.
     */
    public function test_available_types_render_english_labels_in_english(): void
    {
        app()->setLocale('en');

        foreach (self::ATTENDUS_EN as $type => $attendu) {
            $this->assertSame($attendu, $this->registre()->label($type));
        }
    }

    /**
     * Et le francais n'a pas bouge.
     *
     * Sans ce contre-exemple, une correction qui aurait ecrase `lang/fr`
     * passerait le test precedent tout en cassant la langue majoritaire du
     * produit.
     */
    public function test_french_labels_are_untouched(): void
    {
        app()->setLocale('fr');

        foreach (self::ATTENDUS_FR as $type => $attendu) {
            $this->assertSame($attendu, $this->registre()->label($type));
        }
    }

    /**
     * Aucun type DISPONIBLE ne rend une cle brute en anglais.
     *
     * Garde de coherence, dans l'esprit de T1379 : un type ajoute demain sans
     * libelle rendrait `loops.types.<x>.label` a l'ecran. Le defaut ne leve
     * aucune erreur — il s'affiche.
     *
     * **Elle n'attrape que la cle absente des DEUX locales.** Mesure faite : en
     * renommant la cle dans `lang/en` seulement, ce test reste VERT — le repli
     * (`APP_FALLBACK_LOCALE=fr`) rend alors le libelle francais, qui n'est pas
     * une cle brute. C'est le test suivant qui couvre ce cas-la, et les deux
     * sont donc complementaires, pas redondants.
     */
    public function test_no_available_type_renders_a_raw_key_in_english(): void
    {
        app()->setLocale('en');

        foreach ($this->typesDisponibles() as $type) {
            $rendu = $this->registre()->label($type);

            $this->assertStringNotContainsString(
                'loops.types.',
                $rendu,
                "Le type disponible [{$type}] rend une cle brute en anglais."
            );
        }
    }

    /**
     * Chaque type disponible a bien une entree DANS le fichier anglais.
     *
     * `Lang::has()` honore la locale de repli : sans le troisieme argument
     * `false`, une cle presente en francais seulement repondrait `true` et cette
     * garde ne verrait rien. Lecon de T1382, appliquee ici.
     */
    public function test_every_available_type_has_its_own_english_entry(): void
    {
        foreach ($this->typesDisponibles() as $type) {
            $cle = $this->cleDeLibelle($type);

            $this->assertTrue(
                Lang::has($cle, 'en', false),
                "La cle [{$cle}] manque dans lang/en — le repli rendrait du francais."
            );
        }
    }

    /**
     * Le libelle suit l'Organization qu'on lui passe, sans contamination.
     *
     * `label()` accepte une Organization pour honorer d'eventuelles surcharges
     * par tenant (T1116). On verifie qu'en l'absence de surcharge, une
     * Organization anglophone recoit bien le libelle anglais — c'est le cas de
     * la demonstration.
     */
    public function test_an_english_organization_gets_the_english_label(): void
    {
        app()->setLocale('en');

        $organisation = Organization::factory()->create(['locale' => 'en']);

        foreach (self::ATTENDUS_EN as $type => $attendu) {
            $this->assertSame($attendu, $this->registre()->label($type, $organisation));
        }
    }

    /** @return list<string> */
    private function typesDisponibles(): array
    {
        $disponibles = [];

        foreach ($this->definitions() as $cle => $definition) {
            if (($definition['available'] ?? false) === true) {
                $disponibles[] = $cle;
            }
        }

        $this->assertNotEmpty($disponibles, 'Aucun type disponible : la mesure serait vide.');

        return $disponibles;
    }

    private function cleDeLibelle(string $type): string
    {
        return $this->definitions()[$type]['label_key'] ?? '';
    }

    /** @return array<string, array<string, mixed>> */
    private function definitions(): array
    {
        $brut = config('loop_types.types', config('loop_types', []));

        return array_filter($brut, fn ($d) => is_array($d) && isset($d['label_key']));
    }
}
