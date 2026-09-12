<?php

namespace App\Services\Knowledge;

use App\Models\DerivedKnowledgeNote;
use App\Models\Loop;
use App\Models\LoopMessage;
use App\Models\Organization;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * TASK-1540 — la memoire adressable par enonce.
 *
 * ## Aucune table neuve, et ce n'est pas une economie
 *
 * `derived_knowledge_notes` portait deja, PAR LIGNE, tout ce qu'un claim
 * reclame : une identite (`subject_key`), une version, un statut, une histoire
 * (`superseded_by_id`), deux temps distincts (`observed_at` / `derived_at`) et
 * des preuves (`provenance`). Ses index uniques incluent deja `subject_key`,
 * et `dossier_chunks.derived_knowledge_note_id` pointe deja une LIGNE.
 *
 * Un claim EST donc une ligne de cette table. Ce qui change n'est pas la
 * structure : c'est qu'on cesse de n'en ecrire qu'une seule.
 *
 * La consequence la plus utile est gratuite : un chunk par claim, topiquement
 * homogene, la ou le digest produisait un vecteur moyenne sur huit sujets que
 * le moindre Article generique devancait (mesure T1537).
 *
 * ## Le CAS de T1538, etendu
 *
 * En T1538, une compilation partie d'un etat ancien perdait si la note active
 * n'etait plus celle qu'elle avait lue. Ici la base n'est plus UNE note mais
 * l'ENSEMBLE des claims actifs : l'empreinte porte donc leurs identites et
 * leurs versions, triees. Si cet ensemble a bouge pendant l'appel au modele,
 * le patch entier perd — il ne peut ni ressusciter un claim retracte, ni
 * ecraser une correction, ni supprimer un claim ajoute depuis sa lecture.
 *
 * Tout ou rien : appliquer la moitie d'un patch batirait un etat que personne
 * n'a jamais decide.
 */
final class ClaimMemory
{
    public function __construct(
        private readonly DerivedKnowledgeNoteIndexer $indexer,
    ) {}

    /**
     * Le conteneur conversationnel de la Boucle — le « digest ».
     *
     * Il n'est plus compile par un modele : il est RECONSTRUIT localement a
     * partir des enonces, donc gratuitement. Il garde trois roles que le CDC
     * lui reconnait (§9) — conteneur, repli, provenance agregee — et en prend
     * un quatrieme, decisif : il porte l'empreinte de la source deja compilee.
     *
     * Sans ce porteur, chaque balayage rappellerait le modele, y compris quand
     * rien n'a change. La propriete « un « ok » ne coute rien » de T1539
     * tomberait, et avec elle le declenchement automatique.
     *
     * Il n'est pas indexe tant que des enonces existent : les deux porteraient
     * la meme connaissance, et le digest — vecteur moyenne — irait concurrencer
     * l'enonce precis qui repond vraiment.
     */
    public function conteneur(Organization $organization, Loop $loop): ?DerivedKnowledgeNote
    {
        return DerivedKnowledgeNote::query()
            ->where('organization_id', $organization->id)
            ->where('source_type', DerivedKnowledgeNote::SOURCE_LOOP_CONVERSATION)
            ->where('source_loop_id', $loop->id)
            ->where('kind', DerivedKnowledgeNote::KIND_DIGEST)
            ->active()
            ->first();
    }

    /**
     * Le marqueur qui distingue un conteneur d'enonces d'un digest historique.
     *
     * Il est lu par l'indexeur : un conteneur n'est JAMAIS servi, meme quand la
     * Boucle n'a plus aucun enonce actif. Sans ce marquage, une Boucle dont
     * tous les enonces ont ete retractes verrait reapparaitre, en retrieval, le
     * paragraphe qui les enoncait encore — une connaissance explicitement
     * retiree, ressuscitee par un repli.
     */
    public const CONTENEUR = 'claim_container';

    /**
     * L'empreinte de la source deja compilee EN ENONCES — jamais autre chose.
     *
     * La distinction est tout sauf formelle. Un digest historique porte une
     * empreinte calculee par le MEME algorithme sur les MEMES messages : la
     * comparer sans regarder d'ou elle vient ferait court-circuiter chaque
     * Boucle deja derivee en paragraphe, qui ne basculerait donc jamais en
     * enonces. C'est-a-dire toutes celles qui existent aujourd'hui.
     *
     * Une empreinte ne dit pas « cette source a ete lue » : elle dit « cette
     * source a ete compilee PAR CE CHEMIN-LA ».
     */
    public function empreinteCompilee(Organization $organization, Loop $loop): ?string
    {
        $conteneur = $this->conteneur($organization, $loop);

        if ($conteneur === null || ($conteneur->provenance['derived_by'] ?? null) !== self::CONTENEUR) {
            return null;
        }

        return (string) $conteneur->source_fingerprint;
    }

    /**
     * Reconstruit le conteneur apres une compilation reussie. Aucun appel.
     *
     * @param  list<DerivedKnowledgeNote>  $claims  la memoire telle qu'elle est apres le patch
     * @param  list<LoopMessage>  $messages  la source qui vient d'etre lue
     */
    public function rafraichirConteneur(
        Organization $organization,
        Loop $loop,
        string $dossierId,
        array $claims,
        array $messages,
        string $empreinteSource,
    ): void {
        $contenu = trim(implode(' ', array_map(
            static fn (DerivedKnowledgeNote $c): string => rtrim(trim((string) $c->content), '.').'.',
            $claims,
        )));

        // `observed_at` du conteneur = jusqu'ou la SOURCE a ete lue, et non la
        // date du dernier enonce.
        //
        // Le balayeur de T1539 compare cette date a la derniere activite
        // humaine pour decider si une Boucle est due. La caler sur les enonces
        // la ferait retarder des qu'un message assez long n'apporte aucun fait
        // — « attends, je verifie le chiffre avant qu'on acte » — et la Boucle
        // resterait due a chaque balayage, indefiniment. Le court-circuit
        // d'empreinte empecherait la depense, mais la file tournerait a vide et
        // la metrique de retard ne voudrait plus rien dire.
        //
        // C'est exactement ce que le digest faisait, et il avait raison.
        $observe = $this->observeA($messages);

        // Zero enonce actif n'autorise pas a garder l'ancien texte : ce serait
        // rendre a nouveau lisible ce qu'un RETRACT vient d'effacer.
        $contenu = $contenu !== '' ? $contenu : '(aucun enonce actif)';

        $provenance = ['derived_by' => self::CONTENEUR, 'claim_count' => count($claims)];

        $existant = $this->conteneur($organization, $loop);

        if ($existant !== null) {
            $existant->forceFill([
                'content' => $contenu,
                'source_fingerprint' => $empreinteSource,
                'observed_at' => $observe,
                'derived_at' => now(),
                'provenance' => $provenance,
            ])->save();

            return;
        }

        DerivedKnowledgeNote::create([
            'organization_id' => $organization->id,
            'source_type' => DerivedKnowledgeNote::SOURCE_LOOP_CONVERSATION,
            'kind' => DerivedKnowledgeNote::KIND_DIGEST,
            'source_loop_id' => $loop->id,
            'dossier_id' => $dossierId,
            'subject_key' => DerivedKnowledgeNote::SUBJECT_DIGEST,
            'content' => $contenu,
            'source_fingerprint' => $empreinteSource,
            'provenance' => $provenance,
            'observed_at' => $observe,
            'derived_at' => now(),
            // Jamais 1 en dur : un digest archive occupe deja cette version, et
            // `derived_notes_unique_version` porte (org, type, loop, sujet,
            // version). La collision serait silencieuse en test SQLite et
            // fatale en PostgreSQL.
            'version' => $this->prochaineVersion($organization, $loop, DerivedKnowledgeNote::SUBJECT_DIGEST),
            'status' => DerivedKnowledgeNote::STATUS_ACTIVE,
        ]);
    }

    /**
     * Les claims actifs d'une Boucle, du plus recemment observe au plus ancien.
     *
     * @return list<DerivedKnowledgeNote>
     */
    public function actifs(Organization $organization, Loop $loop): array
    {
        return DerivedKnowledgeNote::query()
            ->where('organization_id', $organization->id)
            ->where('source_type', DerivedKnowledgeNote::SOURCE_LOOP_CONVERSATION)
            ->where('source_loop_id', $loop->id)
            ->claims()
            ->active()
            ->orderByDesc('observed_at')
            ->get()
            ->all();
    }

    /**
     * L'empreinte de l'ETAT de la memoire — identites et versions.
     *
     * Elle repond a une seule question : « est-ce toujours la memoire que j'ai
     * lue avant d'appeler le modele ? ». Le contenu n'y entre pas : deux
     * versions differentes d'un meme claim portent deja deux numeros.
     *
     * @param  list<DerivedKnowledgeNote>  $claims
     */
    public function empreinte(array $claims): string
    {
        $parts = array_map(
            static fn (DerivedKnowledgeNote $c): string => $c->subject_key.':'.$c->version,
            $claims,
        );

        sort($parts);

        return hash('sha256', implode('|', $parts));
    }

    /**
     * Applique un patch valide, sous verrou, tout ou rien.
     *
     * ## TASK-1548 — le second appelant
     *
     * Cette methode n'en avait qu'un, le compiler, et `preuves()` ecrivait son
     * nom en dur. `$origine` ouvre la porte a une correction HUMAINE sans rien
     * assouplir : la preuve reste obligatoire ({@see ClaimPatch::valider()}),
     * elle est simplement le message que la personne vient d'ecrire.
     *
     * L'origine porte aussi la garde anti-resurrection, qui ne s'applique
     * qu'aux ecritures du compiler — voir {@see ClaimResurrectionGuard}. Une
     * operation rejouant un passe deja corrige est ECARTEE, pas fatale : le
     * reste du patch est legitime et s'applique. Elle est comptee dans
     * `resurrections`, jamais passee sous silence.
     *
     * @param  list<LoopMessage>  $messages  la source lue pour ce tour
     * @param  ClaimWriteOrigin|null  $origine  defaut : le compiler
     * @return array{applique: bool, raison: ?string, ajoutes: int, modifies: int, retractes: int, conserves: int, resurrections: int}
     */
    public function appliquer(
        Organization $organization,
        Loop $loop,
        string $dossierId,
        ClaimPatch $patch,
        array $messages,
        string $empreinteDeDepart,
        ?string $correlationId,
        ?ClaimWriteOrigin $origine = null,
    ): array {
        $origine ??= ClaimWriteOrigin::compiler();

        /** @var list<DerivedKnowledgeNote> $aIndexer */
        $aIndexer = [];

        $bilan = DB::transaction(function () use ($organization, $loop, $dossierId, $patch, $messages, $empreinteDeDepart, $correlationId, $origine, &$aIndexer): array {
            // Le verrou porte sur TOUS les claims actifs de la Boucle : c'est
            // l'ensemble qui constitue la base, pas une ligne isolee.
            $courants = DerivedKnowledgeNote::query()
                ->where('organization_id', $organization->id)
                ->where('source_type', DerivedKnowledgeNote::SOURCE_LOOP_CONVERSATION)
                ->where('source_loop_id', $loop->id)
                ->claims()
                ->active()
                ->lockForUpdate()
                ->get();

            if (! hash_equals($this->empreinte($courants->all()), $empreinteDeDepart)) {
                // La memoire a bouge pendant l'appel au modele. Ce patch est
                // parti d'un etat qui n'existe plus : il ne gagne pas.
                return $this->bilan(false, 'base_perimee');
            }

            $parId = $courants->keyBy(static fn (DerivedKnowledgeNote $c): string => (string) $c->subject_key);

            $observeA = $this->observeA($messages);
            $preuvesDe = fn (array $op): array => $this->preuves($op, $messages, $correlationId, $origine);

            // TASK-1548 — la garde anti-resurrection, et elle ne s'applique
            // qu'au COMPILER. Un humain doit pouvoir re-affirmer ce qu'un
            // humain a corrige ; c'est la machine, relisant un corpus
            // inchange, qu'on empeche de defaire une correction.
            $messagesParId = [];

            foreach ($messages as $m) {
                $messagesParId[(string) $m->id] = $m;
            }

            $frontieres = $origine->isHuman() ? [] : ClaimResurrectionGuard::frontieresDeVerite(
                $this->corrigeesParHumain($organization, $loop),
            );

            $resurrections = 0;
            $estRejouee = function (array $op) use ($frontieres, $messagesParId, &$resurrections): bool {
                if (! ClaimResurrectionGuard::estUneResurrection($op, $frontieres, $messagesParId)) {
                    return false;
                }

                $resurrections++;

                return true;
            };

            // TASK-1541 — chaque enonce est date par SA PROPRE preuve.
            //
            // Le digest n'avait qu'une date possible : celle du tour. Un claim
            // en a une meilleure, et elle est deja calculee — le moment ou le
            // message qui l'etablit a ete ecrit. Prendre le max du tour
            // daterait d'aujourd'hui un fait dit il y a trois mois, et c'est
            // precisement la date que le lecteur voit (`derivedTitle`, T1536)
            // et sur laquelle il juge la fraicheur.
            $dateDe = function (array $provenance) use ($observeA): \DateTimeInterface {
                $depuisPreuve = $provenance['observed_from_evidence'] ?? null;

                return $depuisPreuve === null
                    ? $observeA
                    : Carbon::parse((string) $depuisPreuve);
            };

            $ajoutes = $modifies = $retractes = 0;

            foreach ($patch->operationsDe(ClaimPatch::OP_ADD) as $op) {
                if ($estRejouee($op)) {
                    continue;
                }

                $preuves = $preuvesDe($op);
                $this->creer($organization, $loop, $dossierId, (string) Str::uuid(), 1, (string) $op['text'],
                    $preuves, $dateDe($preuves), $aIndexer);
                $ajoutes++;
            }

            foreach ($patch->operationsDe(ClaimPatch::OP_UPDATE) as $op) {
                $ancien = $parId->get((string) $op['claim_id']);

                if ($ancien === null || $estRejouee($op)) {
                    continue;
                }

                $preuves = $preuvesDe($op);
                $this->archiver($ancien, frontiere: $this->frontiereDeVerite($ancien, $origine));
                $nouveau = $this->creer($organization, $loop, $dossierId, (string) $ancien->subject_key,
                    $this->prochaineVersion($organization, $loop, (string) $ancien->subject_key),
                    (string) $op['text'], $preuves, $dateDe($preuves), $aIndexer);

                $ancien->forceFill(['superseded_by_id' => $nouveau->id])->save();
                $this->indexer->forget($ancien);
                $modifies++;
            }

            foreach ($patch->operationsDe(ClaimPatch::OP_RETRACT) as $op) {
                $ancien = $parId->get((string) $op['claim_id']);

                if ($ancien === null) {
                    continue;
                }

                // Un RETRACT ne fabrique AUCUN remplacant. « Cette date n'est
                // plus valable » ne veut pas dire « voici la nouvelle date » :
                // inventer un successeur serait combler un trou avec une
                // certitude que personne n'a exprimee.
                $this->archiver($ancien, (string) ($op['reason'] ?? ''), $preuvesDe($op),
                    $this->frontiereDeVerite($ancien, $origine));
                $this->indexer->forget($ancien);
                $retractes++;
            }

            return $this->bilan(true, null, $ajoutes, $modifies, $retractes,
                count($patch->operationsDe(ClaimPatch::OP_KEEP)), $resurrections);
        });

        // L'indexation vit HORS de la transaction, et deliberement.
        //
        // `synchronize()` resout un provider et appelle le service d'embedding :
        // un aller-retour reseau par claim. Les tenir a l'interieur ferait
        // garder le verrou de la Boucle pendant N appels distants — un tour
        // lent bloquerait toute compilation concurrente, et un provider
        // injoignable ferait echouer des ecritures deja decidees.
        //
        // Les lignes sont ecrites ; leurs vecteurs suivent. Un claim non encore
        // indexe est simplement introuvable pendant un instant, ce qui est
        // exactement le comportement d'un index asynchrone — jamais une
        // incoherence.
        foreach ($aIndexer as $note) {
            $this->indexer->synchronize($note);
        }

        return $bilan;
    }

    private function creer(
        Organization $organization,
        Loop $loop,
        string $dossierId,
        string $subjectKey,
        int $version,
        string $texte,
        array $provenance,
        \DateTimeInterface $observeA,
        ?array &$aIndexer = null,
    ): DerivedKnowledgeNote {
        $note = DerivedKnowledgeNote::create([
            'organization_id' => $organization->id,
            'source_type' => DerivedKnowledgeNote::SOURCE_LOOP_CONVERSATION,
            'kind' => DerivedKnowledgeNote::KIND_CLAIM,
            'source_loop_id' => $loop->id,
            'dossier_id' => $dossierId,
            'subject_key' => $subjectKey,
            'content' => $texte,
            // L'empreinte d'un CLAIM est celle de son propre contenu : deux
            // claims d'un meme tour n'ont aucune raison de partager la leur.
            'source_fingerprint' => hash('sha256', $subjectKey.'|'.$version.'|'.$texte),
            'provenance' => $provenance,
            'observed_at' => $observeA,
            'derived_at' => now(),
            'version' => $version,
            'status' => DerivedKnowledgeNote::STATUS_ACTIVE,
        ]);

        if ($aIndexer !== null) {
            $aIndexer[] = $note;
        }

        return $note;
    }

    /**
     * @param  list<string>  $preuves
     * @param  array<string, mixed>|null  $frontiere  TASK-1548 — la FRONTIERE
     *                                                TEMPORELLE DE VERITE posee par une
     *                                                correction HUMAINE, lue ensuite par
     *                                                la garde anti-resurrection
     */
    private function archiver(DerivedKnowledgeNote $claim, string $raison = '', array $preuves = [], ?array $frontiere = null): void
    {
        $provenance = $claim->provenance ?? [];

        if ($raison !== '') {
            $provenance['retracted_reason'] = $raison;
            $provenance['retracted_evidence'] = $preuves['source_loop_message_ids'] ?? [];
        }

        if ($frontiere !== null) {
            $provenance['human_correction'] = $frontiere;
        }

        $claim->forceFill([
            'status' => DerivedKnowledgeNote::STATUS_SUPERSEDED,
            'superseded_at' => now(),
            'provenance' => $provenance,
        ])->save();
    }

    /**
     * TASK-1548 — la FRONTIERE TEMPORELLE DE VERITE.
     *
     * Une correction humaine persistee etablit, pour ce `subject_key`, une
     * frontiere apres laquelle seules de NOUVELLES preuves humaines peuvent
     * justifier une nouvelle mutation automatique.
     *
     * Elle porte de quoi reconnaitre un rejeu SANS relire le modele : le texte
     * qu'on vient d'ecarter, les preuves sur lesquelles il reposait, et la
     * position canonique de la correction — le point a partir duquel une preuve
     * compte comme neuve.
     *
     * ## Ou elle vit, et pourquoi la elle survit
     *
     * Aucune colonne n'est ajoutee : `provenance` est un `jsonb`, et la LIGNE
     * ARCHIVEE est exactement l'endroit ou cette marque a un sens. C'est ce qui
     * la rend persistante dans les deux cas qui comptent :
     *
     *  - apres un UPDATE, la ligne archivee reste, chainee par
     *    `superseded_by_id` a la version qui l'a remplacee ;
     *  - apres un RETRACT, la ligne archivee reste AUSSI — c'est le seul
     *    endroit qui pourrait la porter, puisqu'il n'existe plus aucun enonce
     *    actif sur ce sujet.
     *
     * Rien n'est jamais supprime de cette table : la frontiere survit donc a
     * toute recompilation, et ne depend d'aucun etat de run.
     *
     * @return array<string, mixed>|null `null` quand l'ecriture vient du compiler
     */
    private function frontiereDeVerite(DerivedKnowledgeNote $claim, ClaimWriteOrigin $origine): ?array
    {
        if (! $origine->isHuman() || $origine->message === null) {
            return null;
        }

        return [
            'kind' => $origine->kind,
            'by' => (string) $origine->user?->id,
            'at' => now()->toIso8601String(),
            'message_id' => (string) $origine->message->id,
            'text_hash' => ClaimResurrectionGuard::empreinteTexte((string) $claim->content),
            'evidence_ids' => array_map('strval', (array) (($claim->provenance ?? [])['source_loop_message_ids'] ?? [])),
            'position' => ClaimResurrectionGuard::position($origine->message),
        ];
    }

    /**
     * Les enonces de cette Boucle qu'une personne a corriges.
     *
     * Le filtre sur `human_correction` se fait en PHP et non en SQL : un
     * operateur `jsonb` serait vert sur les six voies PostgreSQL de la CI et
     * faux sur SQLite, qui tourne a cote.
     *
     * @return list<DerivedKnowledgeNote>
     */
    private function corrigeesParHumain(Organization $organization, Loop $loop): array
    {
        return DerivedKnowledgeNote::query()
            ->where('organization_id', $organization->id)
            ->where('source_type', DerivedKnowledgeNote::SOURCE_LOOP_CONVERSATION)
            ->where('source_loop_id', $loop->id)
            ->claims()
            ->where('status', DerivedKnowledgeNote::STATUS_SUPERSEDED)
            ->whereNotNull('superseded_at')
            ->orderByDesc('superseded_at')
            ->limit(500)
            ->get()
            ->filter(static fn (DerivedKnowledgeNote $c): bool => is_array(($c->provenance ?? [])['human_correction'] ?? null))
            ->values()
            ->all();
    }

    private function prochaineVersion(Organization $organization, Loop $loop, string $subjectKey): int
    {
        return 1 + (int) DerivedKnowledgeNote::query()
            ->where('organization_id', $organization->id)
            ->where('source_type', DerivedKnowledgeNote::SOURCE_LOOP_CONVERSATION)
            ->where('source_loop_id', $loop->id)
            ->where('subject_key', $subjectKey)
            ->max('version');
    }

    /**
     * @param  list<LoopMessage>  $messages
     * @return array<string, mixed>
     */
    private function preuves(array $op, array $messages, ?string $correlationId, ClaimWriteOrigin $origine): array
    {
        $ids = (array) ($op['evidence'] ?? []);
        $parId = [];

        foreach ($messages as $m) {
            $parId[(string) $m->id] = $m;
        }

        $observe = null;

        foreach ($ids as $id) {
            $m = $parId[(string) $id] ?? null;
            $at = $m?->edited_at ?? $m?->created_at;

            if ($at !== null && ($observe === null || $at->greaterThan($observe))) {
                $observe = $at;
            }
        }

        return [
            'source_loop_message_ids' => array_values(array_map('strval', $ids)),
            'source_message_count' => count($ids),
            'correlation_id' => $correlationId,
            'observed_from_evidence' => $observe?->toIso8601String(),
            // TASK-1548 — `derived_by` n'est plus une constante : une correction
            // humaine se NOMME, sans quoi elle entrerait en base sous la
            // signature du deriver, indistinguable d'une phrase de modele.
            ...$origine->provenanceFields(),
        ];
    }

    /**
     * @param  list<LoopMessage>  $messages
     */
    private function observeA(array $messages): \DateTimeInterface
    {
        $observe = null;

        foreach ($messages as $m) {
            $at = $m->edited_at ?? $m->created_at;

            if ($at !== null && ($observe === null || $at->greaterThan($observe))) {
                $observe = $at;
            }
        }

        return $observe ?? now();
    }

    /**
     * @return array{applique: bool, raison: ?string, ajoutes: int, modifies: int, retractes: int, conserves: int, resurrections: int}
     */
    private function bilan(bool $applique, ?string $raison, int $a = 0, int $m = 0, int $r = 0, int $k = 0, int $res = 0): array
    {
        return ['applique' => $applique, 'raison' => $raison,
            'ajoutes' => $a, 'modifies' => $m, 'retractes' => $r, 'conserves' => $k,
            // TASK-1548 — les operations refusees parce qu'elles rejouaient un
            // passe deja corrige. Comptees, jamais silencieuses.
            'resurrections' => $res];
    }
}
