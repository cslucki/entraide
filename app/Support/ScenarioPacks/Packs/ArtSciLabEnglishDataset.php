<?php

namespace App\Support\ScenarioPacks\Packs;

/**
 * TASK-1351 — le CONTENU du dogfooding anglais, separe de la mecanique.
 *
 * ## Pourquoi un fichier a part
 *
 * {@see ArtSciLabEnglishPack} decrit COMMENT les entites sont ecrites (services
 * canoniques, registrar, idempotence). Ce fichier-ci decrit CE QUI est ecrit.
 * Melanger les deux rendrait les deux illisibles : on ne relit pas une histoire
 * de laboratoire avec les memes yeux qu'une regle d'ownership.
 *
 * ## La regle qui a guide l'ecriture
 *
 * Qualite narrative avant volume. Un fil de vingt messages qu'on peut lire
 * est une demonstration ; deux cents messages generes par gabarit sont un
 * remplissage — et se voient immediatement quand on montre le produit a
 * quelqu'un. Chaque message ci-dessous est ecrit une fois, pour dire quelque
 * chose de precis, dans la voix de la personne qui le dit.
 *
 * ## Le corpus n'explique jamais BouclePro
 *
 * Invariant du contrat : GUIDE PRODUIT != CORPUS RAG METIER. Les seize
 * documents decrivent le laboratoire fictif — ses methodes, ses donnees, ses
 * doutes. Aucun ne dit ce qu'est une Boucle ou comment demander de l'aide :
 * cette matiere-la appartient au Guide, pas aux Dossiers.
 *
 * ## Rien de reel
 *
 * Personnes fictives, laboratoire fictif, subvention fictive. Aucun contenu
 * d'ArtSciLab ou d'UT Dallas n'est repris. Tout est ecrit pour ce pack.
 */
final class ArtSciLabEnglishDataset
{
    /**
     * Corpus documentaire : 16 pieces courtes, rattachees THEMATIQUEMENT au
     * Dossier racine de leur Boucle — jamais reparties au hasard.
     *
     * @return list<array{loop: string, key: string, name: string, body: string}>
     */
    public static function documents(): array
    {
        return [
            // --- Sonic Terrain : de quoi une question RAG credible peut naitre.
            [
                'loop' => 'sonic_terrain',
                'key' => 'sonification-method-notes',
                'name' => 'sonification-method-notes.md',
                'body' => <<<'MD'
                    # Sonification method notes

                    We map a monthly temperature anomaly series onto pitch, and the
                    confidence interval onto timbre. Warmer anomalies rise; wider
                    uncertainty is heard as a rougher, less focused tone.

                    Pitch is quantised to a seven-note scale so that listeners hear
                    change rather than absolute value. Two anomalies half a degree
                    apart should not sound like two unrelated events.

                    Duration is fixed at one second per month. We rejected an earlier
                    version where tempo also carried meaning: two variables in the time
                    axis made every passage ambiguous.

                    What the sound must never do is imply precision the data does not
                    have. When the interval is wide, the tone is deliberately hard to
                    pin down.
                    MD,
            ],
            [
                'loop' => 'sonic_terrain',
                'key' => 'dataset-provenance-and-gaps',
                'name' => 'dataset-provenance-and-gaps.md',
                'body' => <<<'MD'
                    # Dataset provenance and gaps

                    The working series covers 1959 to 2025 for four inland stations.
                    Two of them were relocated in the 1980s, and the record is spliced.
                    We keep the splice visible in the score rather than smoothing it.

                    Known gaps: eleven missing months between 1972 and 1974, and a
                    six-month instrument fault in 1991. Missing months are rendered as
                    silence, never interpolated. A listener should hear absence as
                    absence.

                    Any piece built on this series must state the station set and the
                    splice in its programme note.
                    MD,
            ],
            [
                'loop' => 'sonic_terrain',
                'key' => 'listening-test-protocol',
                'name' => 'listening-test-protocol.md',
                'body' => <<<'MD'
                    # Listening test protocol

                    Six listeners per session, no more. Each hears two passages without
                    being told which decade they cover.

                    We ask three questions, in this order: what changed; where were you
                    least sure; what would you want to see written down. The third one
                    is the useful one, and it only works if it comes last.

                    We do not ask whether people liked it. A piece that is pleasant and
                    misleading is a failure.
                    MD,
            ],
            [
                'loop' => 'sonic_terrain',
                'key' => 'exhibition-technical-rider',
                'name' => 'exhibition-technical-rider.txt',
                'body' => <<<'TXT'
                    Four channels, speakers at ear height, no subwoofer.
                    Room reverberation under 0.6 s or the uncertainty timbre is lost.
                    Continuous playback, twelve-minute loop, no visible transport controls.
                    Printed programme note within arm's reach of the listening position.
                    Seating for four, including one space without a fixed chair.
                    TXT,
            ],
            [
                'loop' => 'sonic_terrain',
                'key' => 'open-questions-on-uncertainty',
                'name' => 'open-questions-on-uncertainty.md',
                'body' => <<<'MD'
                    # Open questions on uncertainty

                    We still do not agree on how to render a confidence interval that
                    widens sharply. The current roughness mapping saturates, and after
                    that point wider is not audibly wider.

                    Second open question: whether silence for missing data reads as
                    "no data" or as "nothing happened". Early listeners split evenly.

                    Neither question should be closed by the person who wrote the
                    mapping.
                    MD,
            ],

            // --- NSF STEAM Bridge : la matiere de l'ACTE 4.
            [
                'loop' => 'nsf_steam_bridge',
                'key' => 'renewal-narrative-outline',
                'name' => 'renewal-narrative-outline.md',
                'body' => <<<'MD'
                    # Renewal narrative outline

                    1. What we said we would do, and what actually happened.
                    2. Three findings we did not expect, including one that cost us a
                       year.
                    3. Who the work reached, with numbers we can defend.
                    4. What the next period does that this one could not.
                    5. Risks, and what would make us stop.

                    Section 2 is the one reviewers remember. It is also the one we
                    rewrite most often, because the temptation is to present a failure
                    as a pivot.

                    Length target: fifteen pages. The current draft is twenty-two.
                    MD,
            ],
            [
                'loop' => 'nsf_steam_bridge',
                'key' => 'broader-impacts-evidence-log',
                'name' => 'broader-impacts-evidence-log.md',
                'body' => <<<'MD'
                    # Broader impacts evidence log

                    Every claim in the impact section needs a line here, with a date and
                    a source we can produce on request.

                    - Public sessions held, with attendance recorded at the door.
                    - Schools that reused the material, confirmed by the teacher.
                    - Fellows who continued the work after their stay.

                    Anything we cannot evidence goes in a separate list and does not
                    enter the narrative. That list is currently longer than we would
                    like.
                    MD,
            ],
            [
                'loop' => 'nsf_steam_bridge',
                'key' => 'budget-justification-notes',
                'name' => 'budget-justification-notes.txt',
                'body' => <<<'TXT'
                    Access costs are delivery costs: captioning, travel support and assistance
                    are budgeted as activities, not as goodwill.
                    Fellow stipends are the largest line and the easiest to cut; cutting them
                    removes the part of the programme that produced the follow-on work.
                    Equipment: replacement of two ageing interfaces, not a new studio.
                    Reviewer time inside partner institutions is real labour and is named.
                    TXT,
            ],
            [
                'loop' => 'nsf_steam_bridge',
                'key' => 'reviewer-feedback-digest',
                'name' => 'reviewer-feedback-digest.md',
                'body' => <<<'MD'
                    # Reviewer feedback digest — previous round

                    What was praised: the listening sessions, and the decision to publish
                    the method rather than only the pieces.

                    What was criticised, twice by different reviewers: the evaluation
                    plan read as a description of activities, not as a way of learning
                    anything.

                    One reviewer asked directly who is accountable when a piece
                    misrepresents the data. We did not have an answer then.
                    MD,
            ],

            // --- Consent & Ethics Review.
            [
                'loop' => 'consent_ethics',
                'key' => 'participant-consent-language',
                'name' => 'participant-consent-language.md',
                'body' => <<<'MD'
                    # Participant consent language

                    You can take part without agreeing to be recorded.

                    If you do agree, you choose separately whether the recording may be
                    used in a public piece, and whether it may be used to generate new
                    material. Those are different questions and we ask them separately.

                    You can withdraw a recording afterwards. Tell any facilitator; you
                    do not need to give a reason. Withdrawal removes the recording from
                    future use, and we will tell you what has already been published.
                    MD,
            ],
            [
                'loop' => 'consent_ethics',
                'key' => 'human-review-checkpoint',
                'name' => 'human-review-checkpoint.md',
                'body' => <<<'MD'
                    # Human review checkpoint

                    Before anything generated leaves the lab, one named person signs
                    off. Not a role, a person, recorded by name.

                    That person states: the intended use, the known limits, who could be
                    harmed if it is wrong, and what they checked themselves rather than
                    took on trust.

                    If the reviewer cannot name a limit, the review is not finished. No
                    piece of generated material has ever had no limits.
                    MD,
            ],
            [
                'loop' => 'consent_ethics',
                'key' => 'recording-and-reuse-boundaries',
                'name' => 'recording-and-reuse-boundaries.txt',
                'body' => <<<'TXT'
                    Recordings of minors: not used in generated material, in any circumstance.
                    Voices are not synthesised from a participant recording, even with consent.
                    Reuse beyond the stated project requires asking again, not a broad release.
                    Withdrawal requests are actioned within seven days and logged.
                    TXT,
            ],

            // --- Public Engagement & Listening Sessions.
            [
                'loop' => 'public_engagement',
                'key' => 'listening-session-facilitation-guide',
                'name' => 'listening-session-facilitation-guide.md',
                'body' => <<<'MD'
                    # Listening session facilitation guide

                    Start by saying what the session cannot do. People arrive expecting
                    either a concert or a consultation, and it is neither.

                    Play first, talk after. Ten minutes of listening before any framing,
                    or the framing becomes the thing being discussed.

                    Take down disagreement verbatim. A summary that reconciles two
                    opposed reactions has destroyed the only interesting data in the
                    room.

                    Close by saying what happens next, including what will not happen.
                    MD,
            ],
            [
                'loop' => 'public_engagement',
                'key' => 'what-we-heard-session-summary',
                'name' => 'what-we-heard-session-summary.md',
                'body' => <<<'MD'
                    # What we heard — spring session

                    Nineteen people attended. Eleven stayed for the discussion.

                    Three reactions came back repeatedly: the silence for missing data
                    was unsettling and people wanted it explained on the wall; the piece
                    felt longer than its twelve minutes; and two attendees asked who
                    chose the stations.

                    One person said the work made the subject feel less urgent, not
                    more. Nobody agreed with them out loud, and nobody had an answer.
                    We are keeping that sentence.
                    MD,
            ],

            // --- Visiting Fellows 2026.
            [
                'loop' => 'visiting_fellows',
                'key' => 'fellow-onboarding-checklist',
                'name' => 'fellow-onboarding-checklist.txt',
                'body' => <<<'TXT'
                    Before arrival: mentor named, desk assigned, building access requested.
                    First week: one hour with each active project, no deliverable attached.
                    First month: agree what the fellow wants to leave behind, in writing.
                    Access needs are asked before arrival, not discovered on the first day.
                    Last week: the leaving conversation happens, even when the stay was short.
                    TXT,
            ],

            // --- Circle Orientation : le laboratoire, jamais le produit.
            [
                'loop' => 'circle_orientation',
                'key' => 'how-this-lab-works',
                'name' => 'how-this-lab-works.md',
                'body' => <<<'MD'
                    # How this lab works

                    Projects are proposed by anyone and adopted by nobody until someone
                    agrees to carry them. Carrying a project means answering for it, not
                    doing all of it.

                    Decisions are written down with the reason, not just the outcome. If
                    you find a decision you disagree with, the reason is what you argue
                    with.

                    Disagreement is expected to be recorded rather than resolved
                    privately. A quiet room usually means someone stopped speaking.

                    Nobody here is expected to know the whole picture in their first
                    month. Asking a basic question in public is treated as useful work.
                    MD,
            ],
        ];
    }

    /**
     * Le fil de conversation : 21 messages ecrits un par un.
     *
     * `day` est un decalage en jours depuis la base du pack, pour que le fil ait
     * une chronologie lisible plutot qu'un empilement.
     *
     * @return list<array{loop: string, key: string, sender: string, day: int, body: string}>
     */
    public static function messages(): array
    {
        return [
            // Sonic Terrain — le travail avance et bute sur une vraie question.
            ['loop' => 'sonic_terrain', 'key' => 'st-1', 'sender' => 'priya', 'day' => 2,
                'body' => "I've rewritten the mapping so uncertainty is carried by timbre instead of volume. Volume was reading as importance, which is exactly what we did not want."],
            ['loop' => 'sonic_terrain', 'key' => 'st-2', 'sender' => 'sam', 'day' => 3,
                'body' => "That's better. One thing from the last session: two people asked who chose the four stations. We should answer that on the wall, not in the programme note nobody reads."],
            ['loop' => 'sonic_terrain', 'key' => 'st-3', 'sender' => 'priya', 'day' => 4,
                'body' => "Agreed. I'll add the station set and the 1980s splice. I'd rather show the splice than smooth it — the smoothing is the lie, not the gap."],
            ['loop' => 'sonic_terrain', 'key' => 'st-4', 'sender' => 'elena', 'day' => 6,
                'body' => "Before we commit to the November slot: is the roughness mapping still saturating on the wide intervals? If it is, we are exhibiting a piece that stops telling the truth after a threshold."],
            ['loop' => 'sonic_terrain', 'key' => 'st-5', 'sender' => 'priya', 'day' => 7,
                'body' => "It still saturates. I don't have a fix I believe in yet, and I don't think the person who wrote the mapping should be the one to decide it's good enough."],

            // NSF STEAM Bridge — la matiere de l'ACTE 4.
            ['loop' => 'nsf_steam_bridge', 'key' => 'nsf-1', 'sender' => 'marcus', 'day' => 3,
                'body' => "Renewal draft is at twenty-two pages against a fifteen-page target. Most of the excess is in section 2, where I keep explaining the lost year instead of stating it."],
            ['loop' => 'nsf_steam_bridge', 'key' => 'nsf-2', 'sender' => 'elena', 'day' => 4,
                'body' => "State it. A reviewer who reads a pivot where there was a failure trusts the rest of the document less, not more."],
            ['loop' => 'nsf_steam_bridge', 'key' => 'nsf-3', 'sender' => 'marcus', 'day' => 5,
                'body' => "Fair. I also need someone outside this loop to read the impact section cold. Everyone here already knows what we meant, so nobody can see what it actually says."],
            ['loop' => 'nsf_steam_bridge', 'key' => 'nsf-4', 'sender' => 'priya', 'day' => 8,
                'body' => "I can give you the listening-session numbers with sources attached. Anything I can't source, I'll leave out rather than round up."],
            ['loop' => 'nsf_steam_bridge', 'key' => 'nsf-5', 'sender' => 'marcus', 'day' => 9,
                'body' => "Last round a reviewer asked who is accountable when a piece misrepresents the data. We didn't answer. This time I want a sentence naming a person, not a committee."],

            // Consent & Ethics Review.
            ['loop' => 'consent_ethics', 'key' => 'ce-1', 'sender' => 'sam', 'day' => 2,
                'body' => "Splitting the consent form into two questions worked. Several people agreed to be recorded and declined generated reuse — which we would have read as blanket consent before."],
            ['loop' => 'consent_ethics', 'key' => 'ce-2', 'sender' => 'elena', 'day' => 5,
                'body' => "Do we have a withdrawal path that actually works? Promising withdrawal and then discovering it takes a month is worse than not promising it."],
            ['loop' => 'consent_ethics', 'key' => 'ce-3', 'sender' => 'sam', 'day' => 6,
                'body' => "Seven days, logged, and we tell the person what was already published. That last part is the one people ask about and the one we kept forgetting."],
            ['loop' => 'consent_ethics', 'key' => 'ce-4', 'sender' => 'priya', 'day' => 10,
                'body' => "For the record: I don't want a synthesised voice built from a participant recording, even where someone consented. Consent doesn't make it a good idea."],

            // Public Engagement & Listening Sessions.
            ['loop' => 'public_engagement', 'key' => 'pe-1', 'sender' => 'sam', 'day' => 4,
                'body' => "Spring session: nineteen came, eleven stayed to talk. The silence for missing months unsettled people — they wanted it explained where they were standing, not in a note."],
            ['loop' => 'public_engagement', 'key' => 'pe-2', 'sender' => 'elena', 'day' => 5,
                'body' => "Someone said the piece made the subject feel less urgent. Nobody agreed out loud and nobody answered them. Keep that sentence in the write-up exactly as it was said."],
            ['loop' => 'public_engagement', 'key' => 'pe-3', 'sender' => 'marcus', 'day' => 11,
                'body' => "If the autumn session happens before the renewal deadline, its attendance can be evidenced properly. If not, it stays out of the narrative."],

            // Visiting Fellows 2026.
            ['loop' => 'visiting_fellows', 'key' => 'vf-1', 'sender' => 'elena', 'day' => 7,
                'body' => "Two fellows confirmed for the spring. Mentors need naming before they arrive — last year we improvised it in week one and both stays started badly."],
            ['loop' => 'visiting_fellows', 'key' => 'vf-2', 'sender' => 'marcus', 'day' => 8,
                'body' => "I'll take one. I'd rather agree in writing what they want to leave behind than discover in the last week that we wanted different things."],

            // Circle Orientation — Wen n'ecrit rien : son silence est la donnee.
            ['loop' => 'circle_orientation', 'key' => 'co-1', 'sender' => 'elena', 'day' => 1,
                'body' => "Welcome to whoever is reading this first. Nobody here is expected to know the whole picture in their first month. Asking a basic question in public is useful work, not an interruption."],
            ['loop' => 'circle_orientation', 'key' => 'co-2', 'sender' => 'sam', 'day' => 2,
                'body' => "If you want a place to start: read a decision, not a project page. The reason written under it tells you more about how we work than any summary."],
        ];
    }

    /**
     * @return array{loop: string, key: string, author: string, question: string, description: string, selection_type: string, labels: list<string>, votes: array<string, string>}
     */
    public static function poll(): array
    {
        return [
            'loop' => 'public_engagement',
            'key' => 'autumn-session-format',
            'author' => 'sam',
            'question' => 'Which format should we use for the autumn listening session?',
            'description' => 'Same room and same piece in each case; only the way we open the discussion changes.',
            'selection_type' => 'single',
            'labels' => [
                'Listen first, discuss after',
                'Short introduction, then listen',
                'Two shorter passages with a break',
            ],
            // Seuls des MEMBRES de la Boucle votent : le service refuse un
            // votant qui n'en est pas un, et un dataset qui l'ignore ne se
            // charge pas.
            'votes' => [
                'elena' => 'Listen first, discuss after',
                'marcus' => 'Two shorter passages with a break',
            ],
        ];
    }

    /**
     * @return list<array{loop: string, key: string, author: string, title: string, rationale: string, day: int}>
     */
    public static function decisions(): array
    {
        return [
            [
                'loop' => 'sonic_terrain',
                'key' => 'publish-the-gaps',
                'author' => 'priya',
                'title' => 'Render missing data as silence, never interpolate',
                'rationale' => 'Interpolation invents months that were never measured. Silence is uncomfortable and honest; we will explain it on the wall rather than remove it.',
                'day' => 12,
            ],
            [
                'loop' => 'consent_ethics',
                'key' => 'named-reviewer',
                'author' => 'sam',
                'title' => 'Nothing generated leaves the lab without a named human reviewer',
                'rationale' => 'A role cannot be accountable. One person signs off, states the known limits, and is recorded by name.',
                'day' => 13,
            ],
            [
                'loop' => 'nsf_steam_bridge',
                'key' => 'lead-with-listening',
                'author' => 'marcus',
                'title' => 'Lead the renewal with the listening sessions, not the technology',
                'rationale' => 'The sessions are what reviewers praised and what we can evidence. The technology section supports them; it does not open the document.',
                'day' => 14,
            ],
        ];
    }

    /**
     * @return list<array{loop: string, key: string, author: string, title: string}>
     */
    public static function roadmapItems(): array
    {
        return [
            ['loop' => 'sonic_terrain', 'key' => 'fix-saturation', 'author' => 'priya', 'title' => 'Find a roughness mapping that does not saturate on wide intervals'],
            ['loop' => 'sonic_terrain', 'key' => 'wall-text', 'author' => 'sam', 'title' => 'Write the wall text explaining the station set and the splice'],
            ['loop' => 'nsf_steam_bridge', 'key' => 'cut-to-fifteen', 'author' => 'marcus', 'title' => 'Cut the renewal narrative from twenty-two pages to fifteen'],
            ['loop' => 'consent_ethics', 'key' => 'withdrawal-path', 'author' => 'sam', 'title' => 'Test the withdrawal path end to end, with a real request'],
        ];
    }

    /**
     * L'evenement est FUTUR par construction : sa date est calculee au
     * chargement, jamais figee dans le code. Un pack de demonstration dont
     * l'unique evenement est passe se demode tout seul.
     *
     * @return array{loop: string, key: string, author: string, title: string, description: string, format: string, location: string, in_days: int}
     */
    public static function event(): array
    {
        return [
            'loop' => 'public_engagement',
            'key' => 'autumn-listening-session',
            'author' => 'sam',
            'title' => 'Autumn listening session',
            'description' => 'One twelve-minute passage, then an open discussion. We record disagreement as it was said.',
            'format' => 'in_person',
            'location' => 'Community hall, ground floor, step-free access',
            'in_days' => 45,
        ];
    }

    /**
     * @return list<array{key: string, author: string, title: string, description: string, budget_min: int, budget_max: int, in_days: int}>
     */
    public static function requests(): array
    {
        return [
            [
                'key' => 'review-grant-narrative',
                'author' => 'marcus',
                'title' => 'Read our renewal narrative cold',
                'description' => 'Fifteen pages, and everyone here already knows what we meant. I need someone outside the project to tell me what the impact section actually says, not what we intended it to say.',
                'budget_min' => 40,
                'budget_max' => 70,
                'in_days' => 21,
            ],
            [
                'key' => 'second-opinion-on-mapping',
                'author' => 'priya',
                'title' => 'Second opinion on an uncertainty mapping',
                'description' => 'Our roughness mapping saturates on wide confidence intervals. I wrote it, so I should not be the one deciding it is good enough. Two hours of critical listening and a written opinion.',
                'budget_min' => 30,
                'budget_max' => 55,
                'in_days' => 30,
            ],
        ];
    }

    /**
     * @return list<array{key: string, author: string, title: string, description: string, points_cost: int}>
     */
    public static function offers(): array
    {
        return [
            [
                'key' => 'data-visualisation-review',
                'author' => 'priya',
                'title' => 'Data visualisation and sonification review',
                'description' => 'I can look at how your data becomes an image or a sound, and tell you where the representation claims more precision than the data supports.',
                'points_cost' => 60,
            ],
            [
                'key' => 'session-facilitation',
                'author' => 'sam',
                'title' => 'Facilitating a public listening session',
                'description' => 'Preparing and hosting a session where people react to work in progress, and writing up what was actually heard — disagreement included.',
                'points_cost' => 45,
            ],
        ];
    }

    /**
     * Deux categories suffisent : une demande ou une offre en exige une, et
     * inventer une taxonomie complete pour quatre lignes serait du remplissage.
     *
     * @return list<array{key: string, name: string, slug: string, color: string}>
     */
    public static function categories(): array
    {
        return [
            ['key' => 'research-practice', 'name' => 'Research practice', 'slug' => 'artscilab-en-research-practice', 'color' => '#0f766e'],
            ['key' => 'public-programmes', 'name' => 'Public programmes', 'slug' => 'artscilab-en-public-programmes', 'color' => '#b45309'],
        ];
    }
}
