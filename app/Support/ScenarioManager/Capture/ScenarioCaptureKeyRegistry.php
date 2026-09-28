<?php

namespace App\Support\ScenarioManager\Capture;

use App\Models\Organization;
use App\Models\ScenarioCaptureKey;
use App\Models\ScenarioPackEntity;
use App\Models\ScenarioPackLoad;
use App\Support\ScenarioManager\ScenarioVisualEditor;

/**
 * TASK-1652 — « quelle stable key Manifest represente cet objet runtime ? ».
 *
 * ## Le contrat, et pourquoi il tient a deux phrases
 *
 * 1. Un objet venu du Manifest source GARDE sa clef d'origine. On ne la
 *    fabrique jamais : on la lit dans le registre MOTEUR, en lecture seule.
 * 2. Un objet ne depuis l'activite recoit une clef lisible, UNE fois. Un
 *    renommage ulterieur ne la change plus, et la Capture suivante retrouve
 *    exactement la meme.
 *
 * La seconde phrase est la seule qui rende deux Captures comparables. Sans
 * elle, renommer une Boucle suffirait a faire croire a un diff que l'objet a
 * disparu et qu'un autre est apparu.
 *
 * ## Deux registres, deux questions — ne pas les confondre
 *
 * `scenario_pack_entities` dit ce qu'un CHARGEMENT a cree, et le purger s'en
 * sert pour decider ce que Reset et Remove detruisent. Y inscrire les objets
 * nes de l'activite les rendrait destructibles par un Reset qui ne les a jamais
 * crees. Ce registre-ci ne repond qu'a l'identite, et n'a aucun effet sur
 * Reset ni sur Remove.
 *
 * Le registre moteur est lu, JAMAIS ecrit.
 *
 * ## Le piege du Dossier racine, mesure
 *
 * `applyDossiers()` ne tracke PAS le Dossier racine en `manifest_dossier` : il
 * le retrouve par `ensureRootDossier()` et sort de la boucle avant l'ecriture
 * du registre (`ManifestCoreApplier:145-155`). C'est `trackRootDossier()` qui
 * l'inscrit, en `manifest_root_dossier`, sous la clef de sa BOUCLE et non sous
 * la sienne.
 *
 * Amorcer naivement depuis `manifest_root_dossier` donnerait donc au Dossier
 * racine la clef de la Boucle — deux objets, une clef, et un Manifest qui ne
 * validerait plus. Sa vraie clef se lit dans le document SOURCE, a
 * `loops[<clefDeLaBoucle>].root_dossier`.
 */
final class ScenarioCaptureKeyRegistry
{
    /**
     * `entity_type` du registre moteur -> famille MANIFEST.
     *
     * Volontairement PARTIELLE, et c'est un choix :
     *
     * - `manifest_root_dossier` en est absent — voir le docblock de classe ;
     * - `manifest_root_document` aussi : le document racine est un objet
     *   EN LIGNE dans `dossiers[].root_document`, il n'a pas de clef propre ;
     * - `manifest_membership`, `manifest_course_progress` et
     *   `manifest_course_submission` aussi : ce sont des identites COMPOSEES,
     *   adressees par leurs composants. Leur fabriquer une clef serait inventer
     *   une identite que le Manifest n'a pas ;
     * - `manifest_article_placement` aussi : c'est un lien, pas un objet.
     *
     * `manifest_poll_option` n'y figure pas non plus, et ce n'est pas un
     * oubli : `loop_poll_options` ne porte AUCUN `organization_id`, et le
     * registrar refuse — a juste titre — d'inscrire une entite dont il ne peut
     * pas verifier le tenant. L'identite des options se resout donc
     * autrement ; voir `ScenarioCaptureSerializer::polls()`.
     */
    private const FAMILLE_PAR_TYPE_MOTEUR = [
        'manifest_user' => 'users',
        'manifest_loop' => 'loops',
        'manifest_dossier' => 'dossiers',
        'manifest_article' => 'articles',
        'manifest_file' => 'files',
        'manifest_message' => 'messages',
        'manifest_category' => 'categories',
        'manifest_skill' => 'skills',
        'manifest_service_request' => 'service_requests',
        'manifest_service' => 'services',
        'manifest_poll' => 'polls',
        'manifest_event' => 'events',
        'manifest_decision' => 'decisions',
        'manifest_roadmap_item' => 'roadmap_items',
        'manifest_course_module' => 'training.modules',
        'manifest_course_sequence' => 'training.sequences',
        'manifest_course_assignment' => 'training.assignments',
    ];

    /** @var array<string, string> "famille\0entityId" => stable key */
    private array $parEntite = [];

    /** @var array<string, true> "famille\0cle" => true */
    private array $clesPrises = [];

    /** @var list<array{entity_family: string, entity_id: string, stable_key: string}> */
    private array $aEcrire = [];

    private function __construct(private readonly Organization $sandbox) {}

    public static function pour(Organization $sandbox): self
    {
        $registre = new self($sandbox);
        $registre->chargerLeDeja();

        return $registre;
    }

    /**
     * Ce que des Captures precedentes ont deja fixe. C'est ce qui rend la
     * deuxieme Capture identique a la premiere.
     */
    private function chargerLeDeja(): void
    {
        ScenarioCaptureKey::query()
            ->where('organization_id', $this->sandbox->id)
            ->get(['entity_family', 'entity_id', 'stable_key'])
            ->each(function (ScenarioCaptureKey $ligne): void {
                $this->memoriser((string) $ligne->entity_family, (string) $ligne->entity_id, (string) $ligne->stable_key);
            });
    }

    /**
     * Amorce depuis le registre MOTEUR, en LECTURE SEULE.
     *
     * @param  array<string, mixed>  $documentSource le Manifest qui a ete charge
     */
    public function amorcerDepuisLeMoteur(ScenarioPackLoad $load, array $documentSource): void
    {
        $lignes = ScenarioPackEntity::query()
            ->where('scenario_pack_load_id', $load->id)
            // Defense en profondeur : la colonne est dupliquee sur la ligne
            // exactement pour permettre ce controle sans jointure.
            ->where('organization_id', $this->sandbox->id)
            ->get(['entity_type', 'internal_key', 'entity_id']);

        foreach ($lignes as $ligne) {
            $famille = self::FAMILLE_PAR_TYPE_MOTEUR[(string) $ligne->entity_type] ?? null;

            if ($famille === null) {
                continue;
            }

            $this->inscrire($famille, (string) $ligne->entity_id, (string) $ligne->internal_key);
        }

        // Le Dossier racine, par la seule voie qui donne sa VRAIE clef.
        foreach ($lignes as $ligne) {
            if ((string) $ligne->entity_type !== 'manifest_root_dossier') {
                continue;
            }

            $clefDeLaBoucle = (string) $ligne->internal_key;
            $clefDuDossier = $this->clefDuDossierRacine($documentSource, $clefDeLaBoucle);

            if ($clefDuDossier !== null) {
                $this->inscrire('dossiers', (string) $ligne->entity_id, $clefDuDossier);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $document
     */
    private function clefDuDossierRacine(array $document, string $clefDeLaBoucle): ?string
    {
        foreach ($document['loops'] ?? [] as $boucle) {
            if (! is_array($boucle) || ($boucle['key'] ?? null) !== $clefDeLaBoucle) {
                continue;
            }

            $racine = $boucle['root_dossier'] ?? null;

            return is_string($racine) && $racine !== '' ? $racine : null;
        }

        return null;
    }

    /**
     * La clef d'un objet DEJA connu, ou `null`.
     *
     * Ne fabrique rien : sert aux references, ou designer un objet non
     * capturable doit rester detectable au lieu d'inventer une cible.
     */
    public function clefConnue(string $famille, string $entityId): ?string
    {
        return $this->parEntite[$famille."\0".$entityId] ?? null;
    }

    /**
     * La clef d'un objet, en la FIXANT a la premiere rencontre.
     *
     * `$nomPropose` ne sert QUE la premiere fois. Un renommage ulterieur ne
     * change plus rien : c'est tout le contrat.
     */
    public function clefDe(string $famille, string $entityId, string $nomPropose): string
    {
        $deja = $this->clefConnue($famille, $entityId);

        if ($deja !== null) {
            return $deja;
        }

        $clef = $this->clefLibre($famille, $nomPropose);
        $this->inscrire($famille, $entityId, $clef);
        $this->aEcrire[] = ['entity_family' => $famille, 'entity_id' => $entityId, 'stable_key' => $clef];

        return $clef;
    }

    private function clefLibre(string $famille, string $source): string
    {
        // La MEME derivation que l'editeur visuel, et la meme borne : deux
        // regles de troncature au depot finiraient par diverger, et c'est
        // precisement le defaut que T1651 a repare.
        $base = ScenarioVisualEditor::slug($source);
        $clef = $base;
        $suffixe = 2;

        while (isset($this->clesPrises[$famille."\0".$clef])) {
            $marque = (string) $suffixe;
            $clef = ScenarioVisualEditor::borner($base, 64 - 1 - mb_strlen($marque)).'-'.$marque;
            $suffixe++;
        }

        return $clef;
    }

    private function inscrire(string $famille, string $entityId, string $clef): void
    {
        $this->memoriser($famille, $entityId, $clef);
    }

    private function memoriser(string $famille, string $entityId, string $clef): void
    {
        $this->parEntite[$famille."\0".$entityId] = $clef;
        $this->clesPrises[$famille."\0".$clef] = true;
    }

    /**
     * Ecrit les clefs NOUVELLES. Appele dans la transaction de materialisation,
     * jamais pendant la lecture (section 21 du GO).
     */
    public function persister(): void
    {
        if ($this->aEcrire === []) {
            return;
        }

        $maintenant = now();

        // `upsert` et non `insert` : deux Captures concurrentes sur la meme
        // sandbox calculent les MEMES clefs pour les memes objets, et un
        // `insert` sec rendait alors une `UniqueConstraintViolationException`
        // non traduite — un 500 la ou il n'y a rien d'anormal. La clef d'un
        // objet est stable par construction : reecrire la meme valeur est sans
        // effet.
        ScenarioCaptureKey::query()->upsert(array_map(fn (array $ligne): array => [
            'id' => (string) \Illuminate\Support\Str::uuid7(),
            'organization_id' => $this->sandbox->id,
            'entity_family' => $ligne['entity_family'],
            'entity_id' => $ligne['entity_id'],
            'stable_key' => $ligne['stable_key'],
            'first_seen_at' => $maintenant,
            'created_at' => $maintenant,
            'updated_at' => $maintenant,
        ], $this->aEcrire), ['organization_id', 'entity_family', 'entity_id'], ['stable_key', 'updated_at']);

        $this->aEcrire = [];
    }

    /**
     * Les familles que le registre MOTEUR sait amorcer. Expose pour que les
     * tests puissent confronter cette carte a la realite des appliers plutot
     * que de la recopier.
     *
     * @return array<string, string>
     */
    public static function famillesAmorcables(): array
    {
        return self::FAMILLE_PAR_TYPE_MOTEUR;
    }
}
