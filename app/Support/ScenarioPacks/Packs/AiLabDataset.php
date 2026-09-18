<?php

namespace App\Support\ScenarioPacks\Packs;

/**
 * TASK-1587 / CDC-03 L-A — le CONTENU du Lab : la conversation seedee des
 * quatre Loops. Les mecaniques (users, Loops, Dossiers, import du corpus,
 * registre) vivent dans `AiLabPack` ; ici, des tableaux purs.
 *
 * Le corpus documentaire n'est PAS ici : il est versionne sous
 * `database/scenario-packs/ai-lab/corpus/<loop>/…` (DOCX, PDF, XLSX, MD) et
 * importe par le chemin reel d'ingestion.
 *
 * Messages (CDC-03 §4.5, ≥ 30) : racines ; replies EXPLICITES (`reply_to` =
 * cle d'un message anterieur, `type` `user`) ; suites SANS reply qui suivent
 * semantiquement (mesure A2 du CDC-02) ; changement de sujet ; decisions ;
 * dates. Et, pour verifier l'hypothese §4.5, UNE reponse `type = ai` seedee
 * en reply — le tour 1 deterministe d'un scenario multi-turn.
 */
final class AiLabDataset
{
    /**
     * @return list<array{loop: string, key: string, sender: string, day: int, body: string, reply_to?: string, type?: string}>
     */
    public static function messages(): array
    {
        return [
            // ── L1 — Faits simples : racines, une reply, une suite sans reply.
            ['loop' => 'L1', 'key' => 'l1.01', 'sender' => 'lab.admin', 'day' => 0, 'body' => 'Bienvenue dans la Loop Faits simples. La fiche du projet Helios est dans le Dossier de la Loop.'],
            ['loop' => 'L1', 'key' => 'l1.02', 'sender' => 'lab.member.a', 'day' => 0, 'body' => 'Merci. Qui est responsable du projet Helios ?'],
            ['loop' => 'L1', 'key' => 'l1.03', 'sender' => 'lab.member.b', 'day' => 0, 'body' => 'Nadia Ferreira, c\'est écrit dans la fiche.', 'reply_to' => 'l1.02'],
            ['loop' => 'L1', 'key' => 'l1.04', 'sender' => 'lab.member.a', 'day' => 1, 'body' => 'Et le budget ?'],
            ['loop' => 'L1', 'key' => 'l1.05', 'sender' => 'lab.member.c', 'day' => 1, 'body' => 'Je découvre la Loop, je lis la fiche ce soir.'],
            ['loop' => 'L1', 'key' => 'l1.06', 'sender' => 'lab.admin', 'day' => 2, 'body' => 'Rappel : réunion de suivi Helios le 3 avril à 10h, salle Orion.'],
            ['loop' => 'L1', 'key' => 'l1.07', 'sender' => 'lab.member.b', 'day' => 2, 'body' => 'Noté pour le 3 avril.', 'reply_to' => 'l1.06'],
            ['loop' => 'L1', 'key' => 'l1.08', 'sender' => 'lab.member.a', 'day' => 5, 'body' => 'Changement de sujet : quelqu\'un a une recommandation de luxmètre ?'],

            // ── L2 — Personnes & suites : presentations, pronoms, references.
            ['loop' => 'L2', 'key' => 'l2.01', 'sender' => 'lab.member.a', 'day' => 0, 'body' => 'Bonjour à tous, je suis Alice Martin. Je coordonne les échanges avec les partenaires extérieurs.'],
            ['loop' => 'L2', 'key' => 'l2.02', 'sender' => 'lab.member.a', 'day' => 0, 'body' => 'Je vous présente Bob Okafor, qui nous rejoint pour l\'intégration technique des capteurs.'],
            ['loop' => 'L2', 'key' => 'l2.03', 'sender' => 'lab.member.b', 'day' => 0, 'body' => 'Merci Alice. Mon rôle : relier les capteurs des ateliers à la plateforme de données. Je travaille surtout en Python.', 'reply_to' => 'l2.02'],
            ['loop' => 'L2', 'key' => 'l2.04', 'sender' => 'lab.admin', 'day' => 1, 'body' => 'Alice est la personne qui sait toujours qui appeler quand un partenaire ne répond pas.'],
            ['loop' => 'L2', 'key' => 'l2.05', 'sender' => 'lab.member.b', 'day' => 1, 'body' => 'Elle m\'a déjà sauvé deux réunions cette semaine.'],
            ['loop' => 'L2', 'key' => 'l2.06', 'sender' => 'lab.member.a', 'day' => 3, 'body' => 'Bob présentera l\'architecture des capteurs le 20 mai 2026.'],
            ['loop' => 'L2', 'key' => 'l2.07', 'sender' => 'lab.member.b', 'day' => 3, 'body' => 'Confirmé pour le 20 mai. J\'apporte le schéma.', 'reply_to' => 'l2.06'],
            ['loop' => 'L2', 'key' => 'l2.08', 'sender' => 'lab.admin', 'day' => 4, 'body' => 'Décision : Alice rédige le compte rendu de chaque réunion du mardi.'],
            ['loop' => 'L2', 'key' => 'l2.09', 'sender' => 'lab.member.a', 'day' => 4, 'body' => 'D\'accord, je m\'en occupe à partir de mardi prochain.', 'reply_to' => 'l2.08'],
            ['loop' => 'L2', 'key' => 'l2.10', 'sender' => 'lab.member.a', 'day' => 6, 'body' => 'Quel est le rôle de Bob dans la Loop ?'],
            // Tour 1 SEEDE (hypothese §4.5) : une reponse IA en reply, sans provider.
            ['loop' => 'L2', 'key' => 'l2.11', 'sender' => 'lab.member.a', 'day' => 6, 'type' => 'ai', 'reply_to' => 'l2.10', 'body' => 'Bob Okafor est responsable de l\'intégration technique : il relie les capteurs des ateliers à la plateforme de données [S1].'],
            ['loop' => 'L2', 'key' => 'l2.12', 'sender' => 'lab.member.a', 'day' => 6, 'body' => 'Et pour Alice ?'],
            ['loop' => 'L2', 'key' => 'l2.13', 'sender' => 'lab.member.b', 'day' => 8, 'body' => 'Sujet différent : la salle Orion est-elle libre mardi ?'],

            // ── L3 — Documents structures : le tableau, la charte, le budget.
            ['loop' => 'L3', 'key' => 'l3.01', 'sender' => 'lab.admin', 'day' => 0, 'body' => 'Le Dossier de cette Loop contient le tableau des équipes, la charte et le budget 2026.'],
            ['loop' => 'L3', 'key' => 'l3.02', 'sender' => 'lab.member.b', 'day' => 0, 'body' => 'Qui est Team Lead de l\'Atelier Capteurs dans le tableau ?'],
            ['loop' => 'L3', 'key' => 'l3.03', 'sender' => 'lab.member.a', 'day' => 0, 'body' => 'C\'est toi, Bob — regarde la deuxième ligne.', 'reply_to' => 'l3.02'],
            ['loop' => 'L3', 'key' => 'l3.04', 'sender' => 'lab.admin', 'day' => 2, 'body' => 'Décision : le budget 2026 reste à 48 000 euros, aucune rallonge.'],
            ['loop' => 'L3', 'key' => 'l3.05', 'sender' => 'lab.member.a', 'day' => 2, 'body' => 'Combien de salariés compte l\'AI Lab au total ?'],
            ['loop' => 'L3', 'key' => 'l3.06', 'sender' => 'lab.member.b', 'day' => 2, 'body' => 'Ce n\'est écrit nulle part dans nos documents.', 'reply_to' => 'l3.05'],

            // ── L4 — Near miss : le leurre lexical Apollo.
            ['loop' => 'L4', 'key' => 'l4.01', 'sender' => 'lab.admin', 'day' => 0, 'body' => 'Cette Loop suit le programme Apollo de la salle municipale — rien à voir avec la NASA.'],
            ['loop' => 'L4', 'key' => 'l4.02', 'sender' => 'lab.member.b', 'day' => 1, 'body' => 'Quelle est la date d\'ouverture de la saison 2026 ?'],
            ['loop' => 'L4', 'key' => 'l4.03', 'sender' => 'lab.member.a', 'day' => 1, 'body' => 'Le 14 février, avec « Les Lanternes ».', 'reply_to' => 'l4.02'],
            ['loop' => 'L4', 'key' => 'l4.04', 'sender' => 'lab.member.b', 'day' => 3, 'body' => 'Quelqu\'un connaît la date du premier alunissage ? (question piège pour le Lab)'],
            ['loop' => 'L4', 'key' => 'l4.05', 'sender' => 'lab.admin', 'day' => 3, 'body' => 'Pas dans ce Dossier, et c\'est voulu.', 'reply_to' => 'l4.04'],
        ];
    }
}
