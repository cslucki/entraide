<?php

namespace App\Services\Knowledge;

use App\Ai\Context\ReferenceQuestionShape;
use App\Ai\Context\TemporalQuestionShape;
use App\Models\DerivedKnowledgeNote;
use App\Models\Loop;
use App\Models\LoopMessage;
use App\Models\User;
use App\Services\Dossiers\DerivedChunkEligibility;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * TASK-1544 — « le projet dont Roger parlait mardi » se resout par la PROVENANCE.
 *
 * ## Ce que ce service n'est pas
 *
 * Pas un Entity Resolver. Il ne relie pas des mentions a des entites, ne
 * fusionne rien, et ne construit aucun graphe. Il pose une question de
 * jointure, et une seule :
 *
 *     parmi les Boucles que CETTE personne peut lire, lesquelles ont appris
 *     quelque chose dont la PREUVE a ete ecrite par la personne nommee,
 *     dans la fenetre demandee ?
 *
 * Les trois signaux — qui, quand, quoi — existent deja et sont structures :
 * `provenance.source_loop_message_ids` pointe des messages, un message a un
 * `sender_id`, et un enonce porte son `observed_at` depuis T1541.
 *
 * ## Pourquoi PAS une retouche de prompt
 *
 * Le banc de T1541 a mesure le probleme de face : sur quatre enonces compiles
 * par un vrai modele, UN SEUL nommait le projet. Une consigne d'ancrage
 * explicite a ete mesuree — ancrage toujours 1/4, aucun rang gagne — et
 * retiree. Faire repeter le nom du projet dans chaque enonce ne marche pas, et
 * n'aurait de toute facon pas resolu « dont Roger parlait » : le nom de
 * l'auteur n'est pas dans le texte de l'enonce, il est dans sa provenance.
 *
 * ## Le serveur garde l'univers
 *
 * L'ACL est celle de la Boucle, via l'autorite unique
 * `DerivedChunkEligibility::authorizedLoopIds()` — et c'est celle de qui
 * DEMANDE, jamais celle de la personne citee. Demander « le projet de Roger »
 * ne donne aucun acces aux Boucles de Roger.
 *
 * ## L'ambiguite ne se tranche pas toute seule
 *
 * Si deux projets repondent, les deux sont rendus. Choisir « le plus recent »
 * ou « le mieux classe » serait fabriquer une certitude que personne n'a
 * exprimee — et un utilisateur ne sait pas qu'on a choisi pour lui.
 */
final class LoopReferenceResolver
{
    /** Au-dela, la question est trop vague pour qu'une liste aide encore. */
    public const MAX_CANDIDATS = 4;

    /** Par candidat : de quoi reconnaitre le projet, pas de quoi le resumer. */
    private const MAX_PREUVES = 3;

    public function __construct(
        private readonly DerivedChunkEligibility $eligibility,
    ) {}

    /**
     * Les projets que la question peut designer, avec leurs preuves.
     *
     * @return array{personne: ?User, candidats: list<array{loop_id: string, loop_name: string, enonces: list<array{texte: string, quand: Carbon}>}>}
     */
    public function resoudre(string $organizationId, User $demandeur, string $question): array
    {
        $vide = ['personne' => null, 'candidats' => []];

        if (! ReferenceQuestionShape::isIndirect($question)) {
            return $vide;
        }

        $personne = $this->personneNommee($organizationId, $question, $demandeur);

        if ($personne === null) {
            return $vide;
        }

        // L'univers AUTORISE est celui du DEMANDEUR. Nommer quelqu'un ne
        // donne aucun droit sur ce qu'il voit : demander « le projet de
        // Roger » depuis une Organization ou l'on ne partage qu'une Boucle ne
        // peut designer que cette Boucle-la.
        $autorisees = $this->eligibility->authorizedLoopIds($organizationId, $demandeur);

        if ($autorisees === []) {
            return ['personne' => $personne, 'candidats' => []];
        }

        $depuis = TemporalQuestionShape::anchor($question);

        $claims = DerivedKnowledgeNote::query()
            ->where('organization_id', $organizationId)
            ->where('source_type', DerivedKnowledgeNote::SOURCE_LOOP_CONVERSATION)
            ->whereIn('source_loop_id', $autorisees)
            ->claims()
            ->active()
            ->orderByDesc('observed_at')
            ->get();

        if ($claims->isEmpty()) {
            return ['personne' => $personne, 'candidats' => []];
        }

        $auteurs = $this->auteursDesPreuves($claims);

        $parLoop = [];

        foreach ($claims as $claim) {
            if ($depuis !== null && ($claim->observed_at === null || $claim->observed_at->lessThan($depuis))) {
                continue;
            }

            $preuves = array_map('strval', (array) (($claim->provenance ?? [])['source_loop_message_ids'] ?? []));
            $ecritParElle = false;

            foreach ($preuves as $messageId) {
                if (($auteurs[$messageId] ?? null) === (string) $personne->id) {
                    $ecritParElle = true;
                    break;
                }
            }

            if (! $ecritParElle) {
                continue;
            }

            $loopId = (string) $claim->source_loop_id;
            $parLoop[$loopId] ??= [];

            if (count($parLoop[$loopId]) < self::MAX_PREUVES) {
                $parLoop[$loopId][] = [
                    'texte' => trim((string) $claim->content),
                    'quand' => Carbon::instance($claim->observed_at),
                ];
            }
        }

        if ($parLoop === []) {
            return ['personne' => $personne, 'candidats' => []];
        }

        $noms = Loop::query()->whereIn('id', array_keys($parLoop))->pluck('name', 'id');
        $candidats = [];

        foreach ($parLoop as $loopId => $enonces) {
            $candidats[] = [
                'loop_id' => $loopId,
                'loop_name' => (string) ($noms[$loopId] ?? ''),
                'enonces' => $enonces,
            ];
        }

        // Du projet dont on a parle le plus recemment au plus ancien : c'est
        // un ORDRE D'AFFICHAGE, jamais un arbitrage. Deux candidats restent
        // deux candidats.
        usort($candidats, static fn (array $a, array $b): int => $b['enonces'][0]['quand'] <=> $a['enonces'][0]['quand']);

        return ['personne' => $personne, 'candidats' => array_slice($candidats, 0, self::MAX_CANDIDATS)];
    }

    /**
     * La personne nommee dans la question, cherchee parmi les membres REELS.
     *
     * Le serveur confronte les mots candidats a l'annuaire du tenant : aucune
     * heuristique de nom propre, aucune invention. Une personne qui n'est pas
     * membre de cette Organization n'existe pas pour cette question.
     *
     * Deux homonymes rendent `null` : la reference est alors ambigue DES LA
     * PERSONNE, et resoudre sur l'un des deux au hasard serait pire que ne
     * rien resoudre.
     */
    private function personneNommee(string $organizationId, string $question, User $demandeur): ?User
    {
        $candidats = ReferenceQuestionShape::candidatsDeNom($question);

        if ($candidats === []) {
            return null;
        }

        $membres = User::query()
            ->where('organization_id', $organizationId)
            ->whereNull('banned_at')
            ->get(['id', 'name', 'organization_id', 'banned_at']);

        // Le SCORE, et non la simple presence : combien de morceaux du nom de
        // cette personne la question porte-t-elle ?
        //
        // Mesure sur un tenant reel : trois membres partageaient le jeton
        // « sentinel » de leur nom d'organisation. Une garde d'homonymie qui
        // compte les correspondances declarait alors ambigu un nom COMPLET et
        // unique — « SENTINEL-A Admin » — que personne n'aurait hesite a lire.
        //
        // On garde donc le meilleur score, et l'ambiguite ne subsiste que sur
        // une EGALITE a ce meilleur score : deux personnes reellement
        // designees aussi bien l'une que l'autre.
        $scores = [];

        foreach ($membres as $membre) {
            // Le demandeur lui-meme n'est jamais « la personne dont on
            // parle » : « le projet dont je parlais » n'est pas une reference
            // a autrui, et l'inclure ferait remonter ses propres Boucles sur
            // n'importe quelle question qui contient son prenom.
            if ((string) $membre->id === (string) $demandeur->id) {
                continue;
            }

            $score = count(array_intersect($this->morceauxDuNom($membre->name), $candidats));

            if ($score > 0) {
                $scores[] = ['membre' => $membre, 'score' => $score];
            }
        }

        if ($scores === []) {
            return null;
        }

        $meilleur = max(array_column($scores, 'score'));
        $exaequo = array_values(array_filter($scores, static fn (array $s): bool => $s['score'] === $meilleur));

        // Ex aequo au meilleur score : la reference est ambigue DES LA
        // PERSONNE, et trancher au hasard serait pire que ne rien resoudre.
        return count($exaequo) === 1 ? $exaequo[0]['membre'] : null;
    }

    /**
     * @return list<string>
     */
    private function morceauxDuNom(?string $nom): array
    {
        $normalise = mb_strtolower(trim((string) $nom));

        $normalise = strtr($normalise, [
            'à' => 'a', 'â' => 'a', 'ä' => 'a', 'á' => 'a', 'ç' => 'c',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'î' => 'i',
            'ï' => 'i', 'ô' => 'o', 'ö' => 'o', 'ù' => 'u', 'û' => 'u',
            'ü' => 'u', 'ÿ' => 'y', 'ñ' => 'n', 'œ' => 'oe', 'æ' => 'ae',
        ]);

        $normalise = (string) preg_replace('/[^a-z0-9]+/u', ' ', $normalise);

        return array_values(array_filter(
            explode(' ', trim($normalise)),
            static fn (string $m): bool => mb_strlen($m) >= 3,
        ));
    }

    /**
     * L'auteur de chaque message cite en preuve, en UNE requete.
     *
     * @param  Collection<int, DerivedKnowledgeNote>  $claims
     * @return array<string, string> identifiant du message -> identifiant de l'auteur
     */
    private function auteursDesPreuves($claims): array
    {
        $ids = [];

        foreach ($claims as $claim) {
            foreach ((array) (($claim->provenance ?? [])['source_loop_message_ids'] ?? []) as $id) {
                $ids[] = (string) $id;
            }
        }

        if ($ids === []) {
            return [];
        }

        return LoopMessage::query()
            ->whereIn('id', array_unique($ids))
            ->pluck('sender_id', 'id')
            ->map(static fn ($id): string => (string) $id)
            ->all();
    }
}
