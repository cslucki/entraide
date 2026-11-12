<?php

namespace Database\Seeders;

use App\Models\SystemEmailTemplate;
use Illuminate\Database\Seeder;

class SystemEmailTemplateSeeder extends Seeder
{
    private array $templateDefinitions = [
        // TASK-1694 bug N — le gabarit de confirmation d'adresse entre au
        // CATALOGUE. Il etait ne par migration (bug D) et vivait hors de toute
        // liste : l'ecran d'administration ne savait donc pas afficher son
        // « modele systeme par defaut », et `store()` ne savait pas le
        // pre-remplir. Le texte est celui de la migration, a l'identique.
        'confirmation-email-suite-inscription' => [
            'name_fr' => 'Confirmation email suite inscription',
            'name_en' => 'Email confirmation after signup',
            'subject_fr' => 'Confirmez votre adresse email',
            'subject_en' => 'Confirm your email address',
            // TASK-1707 L1 + F5 — les accents, et l'URL EN CLAIR.
            //
            // La table ne stocke que `content_html` : la version texte brut de
            // l'email est derivee en retirant les balises, ce qui fait
            // disparaitre le `href`. Le destinataire dont le client affiche le
            // texte brut lisait donc « Confirmer mon adresse » SANS adresse, et
            // ne pouvait pas valider son compte. L'URL ecrite en toutes lettres
            // survit au deshabillage des balises.
            'content_html_fr' => '<h1>Bonjour {{ name }},</h1>
<p>Il ne reste qu\'une étape : confirmer votre adresse email.</p>
<p><a href="{{ url }}">Confirmer mon adresse</a></p>
<p>Si le lien ci-dessus ne fonctionne pas, copiez cette adresse dans votre navigateur :<br>{{ url }}</p>
<p>Ce lien est valable <strong>24 heures</strong>. Passé ce délai, vous pourrez en demander un nouveau depuis la page d\'attente.</p>
<p>Si vous n\'êtes pas à l\'origine de cette inscription, vous pouvez ignorer ce message.</p>',
            'content_html_en' => '<h1>Hello {{ name }},</h1>
<p>One last step: confirm your email address.</p>
<p><a href="{{ url }}">Confirm my address</a></p>
<p>If the link above does not work, copy this address into your browser:<br>{{ url }}</p>
<p>This link is valid for <strong>24 hours</strong>. After that, you can request a new one from the waiting page.</p>
<p>If you did not create this account, you can ignore this message.</p>',
            'variables' => ['name', 'url'],
        ],
        // TASK-1694 bug H — renomme : l'email annonce l'AFFECTATION DES POINTS.
        'affectation-points-suite-inscription' => [
            'name' => 'Affectation points suite inscription',
            'name_en' => 'Points granted after signup',
            'subject_fr' => 'Bienvenue sur {{ organization }}, {{ name }} !',
            'subject_en' => 'Welcome to {{ organization }}, {{ name }}!',
            'content_html_fr' => '<h1>Bienvenue sur {{ organization }}, {{ name }} !</h1>
<p>Votre compte a bien été créé. Vous avez reçu <strong>100 points de bienvenue</strong> pour démarrer vos premières entraides.</p>
<p><a href="{{ url }}">Découvrir les services</a></p>',
            'content_html_en' => '<h1>Welcome to {{ organization }}, {{ name }}!</h1>
<p>Your account has been created. You received <strong>100 welcome points</strong> to get started.</p>
<p><a href="{{ url }}">Explore services</a></p>',
            'variables' => ['name', 'url'],
        ],
        'new_message' => [
            'name_fr' => 'Nouveau message reçu',
            'name_en' => 'New message received',
            'subject_fr' => 'Nouveau message de {{ sender_name }}',
            'subject_en' => 'New message from {{ sender_name }}',
            'content_html_fr' => '<h1>Nouveau message de {{ sender_name }}</h1>
<p>Bonjour {{ name }},</p>
<p><strong>{{ sender_name }}</strong> vous a envoyé un message à propos de l\'entraide <strong>{{ transaction_title }}</strong>.</p>
<blockquote>{{ message_preview }}</blockquote>
<p><a href="{{ url }}">Voir la conversation</a></p>',
            'content_html_en' => '<h1>New message from {{ sender_name }}</h1>
<p>Hello {{ name }},</p>
<p><strong>{{ sender_name }}</strong> sent you a message about <strong>{{ transaction_title }}</strong>.</p>
<blockquote>{{ message_preview }}</blockquote>
<p><a href="{{ url }}">View conversation</a></p>',
            // `sender_first_name` est fourni par `NewMessageReceived` et donc
            // utilisable dans le gabarit ; l'ecran d'administration doit le dire.
            'variables' => ['name', 'sender_first_name', 'sender_name', 'transaction_title', 'message_preview', 'url'],
        ],

        // TASK-1795 correctif 2 — la DISCUSSION PRIVEE, distincte de l'entraide.
        //
        // `new_message` juste au-dessus reste le gabarit des messages d'un
        // ECHANGE : son texte parle d'echange et interpole
        // `{{ transaction_title }}`. Une discussion privee n'a ni service ni
        // demande — cette variable y valait « Echange », un mot invente par un
        // repli. `Transaction::isDirectConversation()` tranche entre les deux,
        // et `NewMessageReceived::toMail()` choisit le slug a partir d'elle.
        //
        // Pose AUSSI par la migration jumelle
        // `2026_11_09_160000_add_private_message_received_email_template`, qui
        // sert les bases DEJA installees qu'un seeder ne revisite pas. Les deux
        // textes sont identiques, et la migration a ete GENEREE depuis cette
        // entree — la divergence est impossible a la naissance. Ecrire la
        // migration seule avait fait rougir les deux gates en T1720 : une base
        // reconstruite n'aurait JAMAIS eu le gabarit.
        'private_message_received' => [
            'name_fr' => 'Message privé reçu',
            'name_en' => 'Private message received',
            'subject_fr' => 'Message privé de {{ sender_first_name }} {{ sender_name }}',
            'subject_en' => 'Private message from {{ sender_first_name }} {{ sender_name }}',
            'content_html_fr' => '<p>Bonjour {{ recipient_name }},</p><p>Vous avez reçu un message privé de <strong>{{ sender_first_name }} {{ sender_name }}</strong> sur {{ organization_name }}.</p><blockquote style="margin:16px 0;padding:12px 16px;border-left:3px solid #ccc;white-space:pre-line">{{ message_preview }}</blockquote><p><a href="{{ message_url }}" style="display:inline-block;padding:10px 18px;background:#4f46e5;color:#ffffff;text-decoration:none;border-radius:6px">Lire le message et répondre</a></p><p style="font-size:12px;color:#777">Lien : {{ message_url }}</p><p>À bientôt,<br>L’équipe {{ app_name }}</p>',
            'content_html_en' => '<p>Hello {{ recipient_name }},</p><p>You received a private message from <strong>{{ sender_first_name }} {{ sender_name }}</strong> on {{ organization_name }}.</p><blockquote style="margin:16px 0;padding:12px 16px;border-left:3px solid #ccc;white-space:pre-line">{{ message_preview }}</blockquote><p><a href="{{ message_url }}" style="display:inline-block;padding:10px 18px;background:#4f46e5;color:#ffffff;text-decoration:none;border-radius:6px">Read and reply</a></p><p style="font-size:12px;color:#777">Link: {{ message_url }}</p><p>See you soon,<br>The {{ app_name }} team</p>',
            // Obligatoire : `ligne()` lit `$def['variables']` SANS repli.
            // Meme liste que la migration jumelle.
            'variables' => [
                'sender_first_name', 'sender_name', 'recipient_name',
                'organization_name', 'message_preview', 'message_url', 'app_name',
            ],
        ],

        'transaction_status_changed' => [
            'name_fr' => 'Mise à jour de votre entraide',
            'name_en' => 'Mutual aid status update',
            'subject_fr' => 'Mise à jour de votre entraide — {{ status_label }}',
            'subject_en' => 'Your mutual aid has been updated — {{ status_label }}',
            'content_html_fr' => '<h1>Mise à jour de votre entraide</h1>
<p>Bonjour {{ name }},</p>
<p>Le statut de votre entraide <strong>{{ title }}</strong> a changé : <strong>{{ status_label }}</strong>.</p>
<p>Points : {{ points }} pts</p>
<p><a href="{{ url }}">Voir la conversation</a></p>',
            'content_html_en' => '<h1>Your mutual aid has been updated</h1>
<p>Hello {{ name }},</p>
<p>The status of your mutual aid <strong>{{ title }}</strong> changed to: <strong>{{ status_label }}</strong>.</p>
<p>Points: {{ points }} pts</p>
<p><a href="{{ url }}">View conversation</a></p>',
            'variables' => ['name', 'title', 'status_label', 'points', 'url'],
        ],
        'referral_invitation' => [
            'name_fr' => 'Invitation parrainage',
            'name_en' => 'Referral invitation',
            'subject_fr' => '{{ sender_name }} vous invite à rejoindre {{ organization }}',
            'subject_en' => '{{ sender_name }} invites you to join {{ organization }}',
            'content_html_fr' => '<h1>{{ sender_name }} vous invite à rejoindre {{ organization }}</h1>
<p>{{ sender_name }} vous a envoyé le message suivant :</p>
<blockquote>{{ sender_message }}</blockquote>
<p><a href="{{ referral_link }}">Rejoindre {{ organization }}</a></p>',
            'content_html_en' => '<h1>{{ sender_name }} invites you to join {{ organization }}</h1>
<p>{{ sender_name }} sent you the following message:</p>
<blockquote>{{ sender_message }}</blockquote>
<p><a href="{{ referral_link }}">Join {{ organization }}</a></p>',
            'variables' => ['sender_name', 'recipient_name', 'sender_message', 'referral_link'],
        ],
        'ai_budget_exceeded' => [
            'name_fr' => 'Alerte budget IA dépassé',
            'name_en' => 'AI budget exceeded alert',
            'subject_fr' => 'Alerte budget IA — {{ scenario_id }}',
            'subject_en' => 'AI budget alert — {{ scenario_id }}',
            'content_html_fr' => '<h1>Alerte budget IA</h1>
<p>Bonjour {{ name }},</p>
<p>Le scénario IA <strong>{{ scenario_id }}</strong> a dépassé son budget.</p>
<ul>
<li>Coût actuel : <strong>{{ current_cost }} €</strong></li>
<li>Limite : <strong>{{ budget_limit }} €</strong></li>
</ul>
<p><a href="{{ url }}">Voir les détails</a></p>',
            'content_html_en' => '<h1>AI Budget Alert</h1>
<p>Hello {{ name }},</p>
<p>The AI scenario <strong>{{ scenario_id }}</strong> has exceeded its budget.</p>
<ul>
<li>Current cost: <strong>{{ current_cost }} €</strong></li>
<li>Limit: <strong>{{ budget_limit }} €</strong></li>
</ul>
<p><a href="{{ url }}">See details</a></p>',
            'variables' => ['name', 'scenario_id', 'current_cost', 'budget_limit', 'url'],
        ],
        'blog_contribution_invitation' => [
            'name_fr' => 'Invitation contribuer article',
            'name_en' => 'Blog contribution invitation',
            'subject_fr' => '{{ sender_name }} vous invite à lire « {{ article_title }} »',
            'subject_en' => '{{ sender_name }} invites you to read « {{ article_title }} »',
            'content_html_fr' => '<h1>{{ sender_name }} vous invite à lire et contribuer</h1>
<p>Bonjour {{ recipient_name }},</p>
<blockquote>{{ sender_message }}</blockquote>
<p><a href="{{ article_url }}">Lire l\'article : {{ article_title }}</a></p>',
            'content_html_en' => '<h1>{{ sender_name }} invites you to read and contribute</h1>
<p>Hello {{ recipient_name }},</p>
<blockquote>{{ sender_message }}</blockquote>
<p><a href="{{ article_url }}">Read the article: {{ article_title }}</a></p>',
            'variables' => ['sender_name', 'recipient_name', 'sender_message', 'article_url', 'article_title', 'register_url'],
        ],
        /*
         * TASK-1720 volet C — « ajoute » n'est pas « invite ».
         *
         * L'ajout direct empruntait `loop_invitation`, dont le sujet dit
         * « X vous invite a rejoindre ». Or la personne EST membre quand elle
         * lit le message : il n'y a rien a accepter, aucun lien a suivre pour
         * entrer. Le texte decrivait une demarche qui n'existait pas.
         *
         * Pas d'accroche de Boucle ici, contrairement a l'invitation
         * ci-dessous : en recette, elle affichait une ligne en italique qui
         * n'apprend rien a quelqu'un qu'on vient d'ajouter, et un paragraphe
         * VIDE pour une Boucle sans accroche. La variable reste declaree — un
         * SuperAdmin qui la veut peut l'ecrire.
         *
         * `expires_at` n'est pas employe : un ajout n'expire pas.
         *
         * La migration `2026_10_28_170000` pose les deux memes lignes sur les
         * bases DEJA installees, qu'un seeder ne revisite pas. Les deux textes
         * doivent donc rester identiques.
         */
        'loop_member_added' => [
            'name_fr' => 'Ajout a une Boucle',
            'name_en' => 'Added to a Loop',
            'subject_fr' => 'Vous avez été ajouté à la Boucle {{ loop_name }}',
            'subject_en' => 'You have been added to the {{ loop_name }} Loop',
            'content_html_fr' => '<p>Bonjour {{ recipient_name }},</p><p>{{ sender_name }} vous a ajouté à la Boucle <strong>{{ loop_name }}</strong> au sein de {{ organization_name }}. Vous en êtes désormais membre.</p><p><a href="{{ invitation_url }}">Ouvrir la Boucle</a></p><p>Si le lien ne fonctionne pas, copiez cette adresse dans votre navigateur :<br>{{ invitation_url }}</p><p>À bientôt,<br>{{ app_name }}</p>',
            'content_html_en' => '<p>Hello {{ recipient_name }},</p><p>{{ sender_name }} added you to the <strong>{{ loop_name }}</strong> Loop in {{ organization_name }}. You are now a member.</p><p><a href="{{ invitation_url }}">Open the Loop</a></p><p>If the link does not work, copy this address into your browser:<br>{{ invitation_url }}</p><p>See you soon,<br>{{ app_name }}</p>',
            'variables' => [
                'recipient_name', 'sender_name', 'organization_name',
                'loop_name', 'loop_tagline', 'invitation_url', 'app_name',
            ],
        ],

        /**
         * TASK-1725 (demande Cyril, 29/10) — la demande d'adhesion ACCEPTEE.
         *
         * Son propre gabarit, pour la raison qui avait deja separe
         * `loop_member_added` de `loop_invitation` : « on vous a ajoute » n'est
         * pas « on a dit oui a votre demande ». Ici la personne a fait la
         * demarche et attend une reponse ; le texte repond, il n'annonce pas.
         *
         * Le defaut ne nomme PAS le decideur : une acceptation peut n'avoir
         * aucun acteur identifie (demande soldee par un ajout direct), et le
         * gabarit afficherait alors un nom vide en tete de phrase.
         * `sender_name` reste declaree pour qui veut l'ecrire.
         *
         * `loop_tagline` et `expires_at` sont declarees pour l'homogeneite de la
         * famille et non employees : une acceptation n'expire pas, et une
         * accroche n'apprend rien a qui vient d'entrer (recette TASK-1720).
         *
         * La migration `2026_10_29_140000` pose les deux memes lignes sur les
         * bases DEJA installees, qu'un seeder ne revisite pas. Les deux textes
         * doivent donc rester identiques.
         */
        'loop_join_request_accepted' => [
            'name_fr' => 'Demande d\'adhésion acceptée',
            'name_en' => 'Join request accepted',
            'subject_fr' => 'Votre demande pour rejoindre la Boucle {{ loop_name }} a été acceptée',
            'subject_en' => 'Your request to join the {{ loop_name }} Loop has been accepted',
            'content_html_fr' => '<p>Bonjour {{ recipient_name }},</p><p>Votre demande pour rejoindre la Boucle <strong>{{ loop_name }}</strong> au sein de {{ organization_name }} a été acceptée. Vous en êtes désormais membre.</p><p><a href="{{ invitation_url }}">Ouvrir la Boucle</a></p><p>Si le lien ne fonctionne pas, copiez cette adresse dans votre navigateur :<br>{{ invitation_url }}</p><p>À bientôt,<br>{{ app_name }}</p>',
            'content_html_en' => '<p>Hello {{ recipient_name }},</p><p>Your request to join the <strong>{{ loop_name }}</strong> Loop in {{ organization_name }} has been accepted. You are now a member.</p><p><a href="{{ invitation_url }}">Open the Loop</a></p><p>If the link does not work, copy this address into your browser:<br>{{ invitation_url }}</p><p>See you soon,<br>{{ app_name }}</p>',
            'variables' => [
                'recipient_name', 'recipient_email', 'sender_name', 'organization_name',
                'loop_name', 'loop_tagline', 'personal_message', 'invitation_url',
                'expires_at', 'app_name',
            ],
        ],

        /*
         * TASK-1751 — la proposition recue sur une annonce.
         *
         * Pose AUSSI par migration, pour les bases deja migrees. Cette entree
         * sert les bases FRAICHES — recette, CI, nouvelle installation — qui
         * ne rejouent pas l'historique des migrations. Les deux textes doivent
         * donc rester identiques.
         *
         * A ne pas confondre avec `transaction_status_changed` ci-dessus, qui
         * sert le chemin HERITE (approve/refuse/complete/confirm), hors
         * catalogue et hors preferences.
         */
        'transaction_proposal_created' => [
            'name_fr' => 'Réponse à votre demande ou proposition d’aide',
            'name_en' => 'Reply to your help request or offer',
            'subject_fr' => 'Quelqu’un répond à « {{ subject_title }} »',
            'subject_en' => 'Someone replied to “{{ subject_title }}”',
            'content_html_fr' => '<p>Bonjour {{ recipient_name }},</p><p>{{ sender_name }} vient de répondre à <strong>{{ subject_title }}</strong> au sein de {{ organization_name }}, pour {{ points_proposed }} points.</p><p>Vous pouvez accepter ou refuser cette proposition depuis la conversation.</p><p><a href="{{ exchange_url }}">Voir la proposition</a></p><p>Si le lien ne fonctionne pas, copiez cette adresse dans votre navigateur :<br>{{ exchange_url }}</p><p>À bientôt,<br>{{ app_name }}</p>',
            'content_html_en' => '<p>Hello {{ recipient_name }},</p><p>{{ sender_name }} has just replied to <strong>{{ subject_title }}</strong> in {{ organization_name }}, for {{ points_proposed }} points.</p><p>You can accept or decline this proposal from the conversation.</p><p><a href="{{ exchange_url }}">View the proposal</a></p><p>If the link does not work, copy this address into your browser:<br>{{ exchange_url }}</p><p>See you soon,<br>{{ app_name }}</p>',
            'variables' => [
                'recipient_name', 'recipient_email', 'sender_name', 'organization_name',
                'subject_title', 'points_proposed', 'exchange_url', 'app_name',
            ],
        ],

        /*
         * TASK-1761 point 1 — la DEMANDE d'adhesion, cote proprietaire.
         *
         * Pose AUSSI par la migration `2026_11_05_150000`, pour les
         * environnements deja deployes ou le seeder ne tourne pas. Les deux
         * doivent dire la meme chose : un gabarit pose par migration entre au
         * catalogue du seeder, sinon une base reconstruite de zero ne l'a pas.
         */
        // TASK-1772 — meme contenu que la migration
        // `2026_11_06_090000_add_loop_mention_created_email_template`.
        // Un gabarit pose par migration doit entrer ICI aussi : sans quoi une
        // base reconstruite de zero ne l'aurait pas.
        // TASK-1784 — « on a commente votre article ».
        //
        // Les deux points d'entree sont tenus : CE catalogue, qui fait autorite
        // pour une base fraiche, ET la migration
        // `2026_11_07_230000_add_blog_comment_created_email_template`, qui pose
        // les lignes sur les bases deja installees. Ecrire la migration seule
        // avait fait rougir les deux gates en T1720 : une base reconstruite
        // n'aurait JAMAIS eu le gabarit. Les deux textes sont identiques, et
        // compares programmatiquement avant commit — pas a l'oeil.
        // TASK-1786 — on a commente votre annonce d'entraide.
        //
        // Pose AUSSI par la migration jumelle
        // `2026_11_08_031000_add_entraide_comment_created_email_template`, qui
        // sert les bases DEJA installees qu'un seeder ne revisite pas. Les deux
        // textes sont identiques, et la migration a ete GENEREE depuis cette
        // entree — la divergence est donc impossible a la naissance. Ecrire la
        // migration seule avait fait rougir les deux gates en T1720 : une base
        // reconstruite n'aurait JAMAIS eu le gabarit.
        //
        // `listing_type` porte « proposition d'entraide » ou « demande d'aide ».
        // C'est ce qui permet a UN seul gabarit de servir les DEUX types
        // d'annonce, comme une seule cle de catalogue les sert.
        //
        // Le SUJET ne nomme personne : il se lit dans un apercu de boite mail,
        // sans deverrouiller. C'est le texte du CDC §8.
        // TASK-1808 §6, puis TASK-1809 §3 — l'invitation a evaluer l'aidant,
        // APRES la cloture.
        //
        // Pose AUSSI par la migration jumelle
        // `2026_11_12_110000_add_entraide_evaluation_invite_email_template`
        // (insertion) puis `2026_11_12_150000_update_..._immediate_copy`
        // (texte immediat), qui servent les bases DEJA installees qu'un
        // seeder ne revisite pas. Les textes sont IDENTIQUES, generes depuis
        // cette entree.
        //
        // Destinataire UNIQUE : l'aide (D1). Jamais l'aidant — MASTER
        // l'ecrit explicitement (« ne jamais envoyer a l'aidant un message
        // indiquant qu'une evaluation lui a ete demandee ou qu'elle n'a pas
        // ete donnee »). `EvaluationInviteMailer` est le seul emetteur, et
        // il ne connait que `transaction->buyer`.
        //
        // TASK-1809 §3 — MASTER a retire la relance PLANIFIEE (dix minutes
        // apres cloture, `entraide:invite-to-evaluate`, supprimee) :
        // « BouclePro lutte contre l'infobesite ». Desormais UN SEUL email,
        // envoye IMMEDIATEMENT par `TransactionController::complete()`,
        // borne a UNE fois par entraide par la MEME colonne
        // `transactions.evaluation_invited_at` (semantique inchangee, seul
        // le declencheur change : synchrone a la cloture, plus un balayage
        // planifie). `{{ points }}` (nouvelle variable) nomme le nombre de
        // points transferes.
        //
        // Pas de `preferences_url` : cette invitation n'est pas une
        // notification du catalogue (pas de canal in-app ni push a
        // desactiver).
        'entraide_evaluation_invite' => [
            'name_fr' => 'Invitation à évaluer l’aidant',
            'name_en' => 'Invitation to rate the helper',
            'subject_fr' => 'Terminer et évaluer l’entraide de {{ aidant_first_name }}',
            'subject_en' => 'Finish and rate your exchange with {{ aidant_first_name }}',
            'content_html_fr' => '<p>Bonjour {{ recipient_name }},</p><p>Vous avez indiqué que votre échange avec {{ aidant_first_name }} est terminé et {{ points }} points lui ont été transférés depuis votre compte.</p><p>Merci d’avance de lui laisser une évaluation pour reconnaître sa contribution. Ce sera sûrement apprécié !</p><p><a href="{{ evaluation_url }}">Évaluer l’entraide avec {{ aidant_first_name }}</a></p><p>Si le lien ne fonctionne pas, copiez cette adresse dans votre navigateur :<br>{{ evaluation_url }}</p>',
            'content_html_en' => '<p>Hello {{ recipient_name }},</p><p>You’ve marked your exchange with {{ aidant_first_name }} as complete, and {{ points }} points have been transferred from your account.</p><p>We’d really appreciate it if you could leave them a review to recognize their contribution!</p><p><a href="{{ evaluation_url }}">Rate your exchange with {{ aidant_first_name }}</a></p><p>If the link does not work, copy this address into your browser:<br>{{ evaluation_url }}</p>',
            'variables' => [
                'recipient_name', 'recipient_email', 'aidant_name', 'aidant_first_name',
                'organization_name', 'evaluation_url', 'app_name', 'points',
            ],
        ],

        // TASK-1809 §4 — l'email a l'aidant QUAND son evaluation arrive.
        // Destinataire UNIQUE : l'aidant (D2, `reviewed_id`). Declenche
        // SEULEMENT par `EvaluationEntraide::enregistrer()` (jamais par
        // `ReviewModeration` — modifier/masquer/demasquer/supprimer/
        // restaurer ne cree jamais de ligne et ne declenche donc jamais cet
        // envoi). Pose AUSSI par la migration jumelle
        // `2026_11_12_160000_add_entraide_review_received_email_template`.
        //
        // « Jean ne doit pas avoir besoin de revenir sur BouclePro pour
        // connaitre sa note et lire le commentaire » (MASTER) : la note
        // est portee en etoiles ET en chiffre, le commentaire s'il existe —
        // jamais de HTML non echappe issu d'un commentaire membre,
        // `EmailerService::interpolate()` applique `e()` a chaque variable.
        'entraide_review_received' => [
            'name_fr' => 'Vous avez reçu une évaluation',
            'name_en' => 'You received a review',
            'subject_fr' => '{{ aide_first_name }} vous a laissé une évaluation !',
            'subject_en' => '{{ aide_first_name }} left you a review!',
            'content_html_fr' => '<p>Bonjour {{ recipient_name }},</p><p>{{ aide_first_name }} vous a laissé une évaluation pour votre entraide.</p><p style="font-size:20px;letter-spacing:2px">{{ rating_stars }} <span style="font-size:14px;color:#777">({{ rating_fraction }})</span></p><p>{{ comment_text }}</p><p><a href="{{ conversation_url }}">Voir la conversation</a></p><p>Merci de faire vivre l’entraide sur {{ app_name }}.</p>',
            'content_html_en' => '<p>Hello {{ recipient_name }},</p><p>{{ aide_first_name }} left you a review for your mutual aid.</p><p style="font-size:20px;letter-spacing:2px">{{ rating_stars }} <span style="font-size:14px;color:#777">({{ rating_fraction }})</span></p><p>{{ comment_text }}</p><p><a href="{{ conversation_url }}">View the conversation</a></p><p>Thank you for keeping mutual aid alive on {{ app_name }}.</p>',
            'variables' => [
                'recipient_name', 'recipient_email', 'aide_name', 'aide_first_name',
                'rating_stars', 'rating_fraction', 'comment_text', 'conversation_url',
                'organization_name', 'app_name',
            ],
        ],

        'entraide_comment_created' => [
            'name_fr' => 'Commentaire sur votre entraide',
            'name_en' => 'Comment on your mutual aid',
            'subject_fr' => 'Un membre a commenté votre {{ listing_type }}',
            'subject_en' => 'A member commented on your {{ listing_type }}',
            'content_html_fr' => '<p>Bonjour {{ recipient_name }},</p><p><strong>{{ author_name }}</strong> a laissé un commentaire sur votre {{ listing_type }} <strong>{{ listing_title }}</strong>.</p><blockquote style="margin:16px 0;padding:12px 16px;border-left:3px solid #ccc;white-space:pre-line">{{ comment_excerpt }}</blockquote><p><a href="{{ comment_url }}">Voir le commentaire</a></p><p>Un commentaire n’engage rien : ni entraide engagée, ni acceptation d’aide, ni temps promis. Vous répondrez si vous le souhaitez.</p><p>À bientôt,<br>L’équipe {{ app_name }}</p><hr style="margin:24px 0;border:0;border-top:1px solid #eee"><p style="font-size:12px;color:#777">Pour ne plus recevoir cet email, allez dans <a href="{{ preferences_url }}">Réglages des notifications</a>.</p>',
            'content_html_en' => '<p>Hello {{ recipient_name }},</p><p><strong>{{ author_name }}</strong> left a comment on your {{ listing_type }} <strong>{{ listing_title }}</strong>.</p><blockquote style="margin:16px 0;padding:12px 16px;border-left:3px solid #ccc;white-space:pre-line">{{ comment_excerpt }}</blockquote><p><a href="{{ comment_url }}">View the comment</a></p><p>A comment commits to nothing: no mutual aid started, no accepted help, no promised time. You may reply if you wish.</p><p>See you soon,<br>The {{ app_name }} team</p><hr style="margin:24px 0;border:0;border-top:1px solid #eee"><p style="font-size:12px;color:#777">To stop receiving this email, go to <a href="{{ preferences_url }}">Notification settings</a>.</p>',
            // Obligatoire : `ligne()` lit `$def['variables']` SANS repli
            // (`SystemEmailTemplateSeeder:450`). L'omettre ne casse pas le
            // seeder tout seul — il casse TOUT ce qui l'invoque, soit 209 tests
            // en `ErrorException` et zero assertion (mesure en T1784). Meme
            // liste que la migration jumelle.
            'variables' => [
                'recipient_name', 'recipient_email', 'author_name',
                'organization_name', 'listing_type', 'listing_title',
                'comment_url', 'comment_excerpt', 'preferences_url', 'app_name',
            ],
        ],

        'blog_comment_created' => [
            'name_fr' => 'Commentaire sur votre article',
            'name_en' => 'Comment on your article',
            'subject_fr' => '{{ author_name }} a commenté « {{ post_title }} »',
            'subject_en' => '{{ author_name }} commented on “{{ post_title }}”',
            'content_html_fr' => '<p>Bonjour {{ recipient_name }},</p><p><strong>{{ author_name }}</strong> a laissé un commentaire sur votre article <strong>{{ post_title }}</strong>.</p><blockquote style="margin:16px 0;padding:12px 16px;border-left:3px solid #ccc;white-space:pre-line">{{ comment_excerpt }}</blockquote><p><a href="{{ comment_url }}">Lire le commentaire</a></p><p>Rien ne presse : le commentaire vous attend, vous y répondrez quand vous voudrez.</p><p>À bientôt,<br>L’équipe {{ app_name }}</p><hr style="margin:24px 0;border:0;border-top:1px solid #eee"><p style="font-size:12px;color:#777">Pour ne plus recevoir cet email, allez dans <a href="{{ preferences_url }}">Réglages des notifications</a>.</p>',
            'content_html_en' => '<p>Hello {{ recipient_name }},</p><p><strong>{{ author_name }}</strong> left a comment on your article <strong>{{ post_title }}</strong>.</p><blockquote style="margin:16px 0;padding:12px 16px;border-left:3px solid #ccc;white-space:pre-line">{{ comment_excerpt }}</blockquote><p><a href="{{ comment_url }}">Read the comment</a></p><p>No rush: the comment will wait for you.</p><p>See you soon,<br>The {{ app_name }} team</p><hr style="margin:24px 0;border:0;border-top:1px solid #eee"><p style="font-size:12px;color:#777">To stop receiving this email, go to <a href="{{ preferences_url }}">Notification settings</a>.</p>',
            // Obligatoire : `ligne()` lit `$def['variables']` SANS repli
            // (`SystemEmailTemplateSeeder:450`). L'omettre ne casse pas le
            // seeder tout seul — il casse TOUT ce qui l'invoque, soit 209
            // tests en `ErrorException` et zero assertion. Meme liste que la
            // migration jumelle.
            'variables' => [
                'recipient_name', 'recipient_email', 'author_name',
                'organization_name', 'post_title', 'comment_url',
                'comment_excerpt', 'preferences_url', 'app_name',
            ],
        ],

        // TASK-1810 (demande Cyril, 12/11) — « Commentaire sur le flux ».
        //
        // Pose AUSSI par la migration jumelle
        // `2026_11_12_170000_add_feed_post_comment_created_email_template`, qui
        // sert les bases DEJA installees qu'un seeder ne revisite pas. Les
        // deux textes sont IDENTIQUES.
        //
        // Le SUJET est litteral, SANS variable — demande explicite : « Sujet
        // : Commentaire sur le flux ». `feed_context` reprend EXACTEMENT ce
        // qui est affiche dans la carte du Flux (`FeedCommentMailer::
        // contexteDuFlux()`), pour que le destinataire comprenne de QUOI il
        // s'agit sans se connecter. `author_first_name`, pas `author_name` :
        // demande explicite (« $prenom_commentateur »).
        'feed_post_comment_created' => [
            'name_fr' => 'Commentaire sur le flux',
            'name_en' => 'Comment on the Feed',
            'subject_fr' => 'Commentaire sur le flux',
            'subject_en' => 'Comment on the Feed',
            'content_html_fr' => '<p>Bonjour {{ recipient_name }},</p><p><strong>{{ author_first_name }}</strong> vient de commenter le flux à propos d\'un sujet qui vous concerne : <strong>{{ feed_context }}</strong></p><blockquote style="margin:16px 0;padding:12px 16px;border-left:3px solid #ccc;white-space:pre-line">{{ comment_excerpt }}</blockquote><p><a href="{{ comment_url }}">Voir dans le Flux</a></p><p>Rien ne presse : vous n\'avez pas besoin de vous connecter pour en prendre connaissance.</p><p>À bientôt,<br>L’équipe {{ app_name }}</p><hr style="margin:24px 0;border:0;border-top:1px solid #eee"><p style="font-size:12px;color:#777">Pour ne plus recevoir cet email, allez dans <a href="{{ preferences_url }}">Réglages des notifications</a>.</p>',
            'content_html_en' => '<p>Hello {{ recipient_name }},</p><p><strong>{{ author_first_name }}</strong> just commented on the Feed about something that concerns you: <strong>{{ feed_context }}</strong></p><blockquote style="margin:16px 0;padding:12px 16px;border-left:3px solid #ccc;white-space:pre-line">{{ comment_excerpt }}</blockquote><p><a href="{{ comment_url }}">View in the Feed</a></p><p>No rush: you don\'t need to sign in to read it.</p><p>See you soon,<br>The {{ app_name }} team</p><hr style="margin:24px 0;border:0;border-top:1px solid #eee"><p style="font-size:12px;color:#777">To stop receiving this email, go to <a href="{{ preferences_url }}">Notification settings</a>.</p>',
            // Obligatoire : `ligne()` lit `$def['variables']` SANS repli — voir
            // le commentaire identique sur `blog_comment_created` ci-dessus.
            // Meme liste que la migration jumelle.
            'variables' => [
                'recipient_name', 'recipient_email', 'author_first_name',
                'organization_name', 'feed_context', 'comment_url',
                'comment_excerpt', 'preferences_url', 'app_name',
            ],
        ],

        'loop_mention_created' => [
            'name_fr' => 'Mention dans une Boucle',
            'name_en' => 'Mention in a Loop',
            'subject_fr' => '{{ author_name }} vous a mentionné dans « {{ loop_name }} »',
            'subject_en' => '{{ author_name }} mentioned you in “{{ loop_name }}”',
            // Le contenu suit la migration
            // `2026_11_06_150000_add_message_and_preferences_to_mention_email` :
            // une base reconstruite de zero recoit directement la version
            // qui porte l'extrait et le lien de desabonnement, sans passer
            // par la precedente. La suite TASK-1772 verifie que ces deux
            // chaines ne divergent pas.
            'content_html_fr' => '<p>Bonjour {{ recipient_name }},</p><p><strong>{{ author_name }}</strong> vous a mentionné dans un message de la Boucle <strong>{{ loop_name }}</strong>.</p><blockquote style="margin:16px 0;padding:12px 16px;border-left:3px solid #ccc;white-space:pre-line">{{ message_excerpt }}</blockquote><p><a href="{{ message_url }}">Lire le message dans la Boucle</a></p><p>Rien ne presse : le message vous attend, vous le lirez quand vous voudrez.</p><p>À bientôt,<br>L’équipe {{ app_name }}</p><hr style="margin:24px 0;border:0;border-top:1px solid #eee"><p style="font-size:12px;color:#777">Pour ne plus recevoir cet email, allez dans <a href="{{ preferences_url }}">Réglages des notifications</a>.</p>',
            'content_html_en' => '<p>Hello {{ recipient_name }},</p><p><strong>{{ author_name }}</strong> mentioned you in a message from the Loop <strong>{{ loop_name }}</strong>.</p><blockquote style="margin:16px 0;padding:12px 16px;border-left:3px solid #ccc;white-space:pre-line">{{ message_excerpt }}</blockquote><p><a href="{{ message_url }}">Read the message in the Loop</a></p><p>No rush: the message will wait for you.</p><p>See you soon,<br>The {{ app_name }} team</p><hr style="margin:24px 0;border:0;border-top:1px solid #eee"><p style="font-size:12px;color:#777">To stop receiving this email, go to <a href="{{ preferences_url }}">Notification settings</a>.</p>',
            'variables' => [
                'recipient_name', 'recipient_email', 'organization_name',
                'loop_name', 'author_name', 'message_url', 'message_excerpt',
                'preferences_url', 'app_name',
            ],
        ],

        // TASK-1797 — la mention COLLECTIVE (`@tous`), gabarit DISTINCT du
        // precedent pour la meme raison que le sujet distinct de
        // `notifications.keys_named` : « vous a mentionné » serait une
        // interpellation nominative abusive quand personne n'a ete nomme.
        // MEME liste de variables que `loop_mention_created` — aucune
        // nouvelle variable n'etait necessaire, seul le texte change.
        'loop_mention_all_created' => [
            'name_fr' => 'Mention collective dans une Boucle',
            'name_en' => 'Collective mention in a Loop',
            'subject_fr' => '{{ author_name }} a mentionné tous les membres de « {{ loop_name }} »',
            'subject_en' => '{{ author_name }} mentioned all the members of “{{ loop_name }}”',
            'content_html_fr' => '<p>Bonjour {{ recipient_name }},</p><p><strong>{{ author_name }}</strong> a mentionné <strong>tous les membres</strong> de la Boucle <strong>{{ loop_name }}</strong>, dont vous.</p><blockquote style="margin:16px 0;padding:12px 16px;border-left:3px solid #ccc;white-space:pre-line">{{ message_excerpt }}</blockquote><p><a href="{{ message_url }}">Lire le message dans la Boucle</a></p><p>Rien ne presse : le message vous attend, vous le lirez quand vous voudrez.</p><p>À bientôt,<br>L’équipe {{ app_name }}</p><hr style="margin:24px 0;border:0;border-top:1px solid #eee"><p style="font-size:12px;color:#777">Pour ne plus recevoir cet email, allez dans <a href="{{ preferences_url }}">Réglages des notifications</a>.</p>',
            'content_html_en' => '<p>Hello {{ recipient_name }},</p><p><strong>{{ author_name }}</strong> mentioned <strong>all the members</strong> of the Loop <strong>{{ loop_name }}</strong>, including you.</p><blockquote style="margin:16px 0;padding:12px 16px;border-left:3px solid #ccc;white-space:pre-line">{{ message_excerpt }}</blockquote><p><a href="{{ message_url }}">Read the message in the Loop</a></p><p>No rush: the message will wait for you.</p><p>See you soon,<br>The {{ app_name }} team</p><hr style="margin:24px 0;border:0;border-top:1px solid #eee"><p style="font-size:12px;color:#777">To stop receiving this email, go to <a href="{{ preferences_url }}">Notification settings</a>.</p>',
            'variables' => [
                'recipient_name', 'recipient_email', 'organization_name',
                'loop_name', 'author_name', 'message_url', 'message_excerpt',
                'preferences_url', 'app_name',
            ],
        ],

        'loop_join_request_created' => [
            'name_fr' => 'Demande pour rejoindre une Boucle',
            'name_en' => 'Request to join a Loop',
            'subject_fr' => 'Quelqu’un demande à rejoindre « {{ loop_name }} »',
            'subject_en' => 'Someone is asking to join “{{ loop_name }}”',
            'content_html_fr' => '<p>Bonjour {{ recipient_name }},</p><p><strong>{{ requester_name }}</strong> demande à rejoindre la Boucle <strong>{{ loop_name }}</strong>, dont vous êtes responsable.</p><p><a href="{{ request_url }}">Voir la demande et répondre</a></p><p>Tant que vous n’avez pas répondu, cette personne reste en attente.</p><p>À bientôt,<br>L’équipe {{ app_name }}</p>',
            'content_html_en' => '<p>Hello {{ recipient_name }},</p><p><strong>{{ requester_name }}</strong> is asking to join the Loop <strong>{{ loop_name }}</strong>, which you are responsible for.</p><p><a href="{{ request_url }}">View the request and reply</a></p><p>Until you reply, this person stays on hold.</p><p>See you soon,<br>The {{ app_name }} team</p>',
            'variables' => [
                'recipient_name', 'recipient_email', 'organization_name',
                'loop_name', 'requester_name', 'request_url', 'app_name',
            ],
        ],

        // TASK-1775 (CDC C3) — un gabarit pose par migration doit entrer au
        // catalogue du seeder, sinon une base reconstruite de zero ne
        // l'aurait pas (lecon deja payee sur ce fichier).
        'loop_help_request_created' => [
            'name_fr' => 'Demande d’aide publiée dans une Boucle',
            'name_en' => 'Help request published in a Loop',
            'subject_fr' => 'Une demande d’aide dans « {{ loop_name }} »',
            'subject_en' => 'A help request in “{{ loop_name }}”',
            'content_html_fr' => '<p>Bonjour {{ recipient_name }},</p><p><strong>{{ requester_name }}</strong> a publié une demande d’aide dans la Boucle <strong>{{ loop_name }}</strong>, dont vous êtes membre.</p><p><a href="{{ request_url }}">Ouvrir la Boucle</a></p><p>Rien ne presse : la demande vous attend, vous la lirez quand vous voudrez.</p><p>À bientôt,<br>L’équipe {{ app_name }}</p>',
            'content_html_en' => '<p>Hello {{ recipient_name }},</p><p><strong>{{ requester_name }}</strong> published a help request in the Loop <strong>{{ loop_name }}</strong>, which you are a member of.</p><p><a href="{{ request_url }}">Open the Loop</a></p><p>No rush: the request will wait for you.</p><p>See you soon,<br>The {{ app_name }} team</p>',
            'variables' => [
                'recipient_name', 'recipient_email', 'organization_name',
                'loop_name', 'requester_name', 'request_url', 'app_name',
            ],
        ],

        // TASK-1802 (demande Cyril) — un Evenement propose dans une Boucle.
        //
        // Pose AUSSI par la migration jumelle
        // `..._add_loop_event_created_email_template`, qui sert les bases
        // DEJA installees qu'un seeder ne revisite pas (meme doctrine que
        // `loop_help_request_created` juste au-dessus). `event_practical_info`
        // est COMPOSE par `LoopEventMailer` (en-ligne/sur-place/hybride) :
        // le gabarit administrable n'a pas de conditionnelle.
        'loop_event_created' => [
            'name_fr' => 'Nouvel évènement proposé dans une Boucle',
            'name_en' => 'New event proposed in a Loop',
            'subject_fr' => '{{ event_title }} — {{ event_date }}',
            'subject_en' => '{{ event_title }} — {{ event_date }}',
            'content_html_fr' => '<p>Bonjour {{ recipient_name }},</p><p><strong>{{ author_name }}</strong> a proposé un nouvel évènement dans la Boucle <strong>{{ loop_name }}</strong> : <strong>{{ event_title }}</strong>, le {{ event_date }}.</p><p>{{ event_practical_info }}</p><p>{{ event_description }}</p><p><a href="{{ event_url }}">Ouvrir la Boucle</a></p><p>Rien ne presse : l’évènement vous attend, vous y répondrez quand vous voudrez.</p><p>À bientôt,<br>L’équipe {{ app_name }}</p><hr style="margin:24px 0;border:0;border-top:1px solid #eee"><p style="font-size:12px;color:#777">Pour ne plus recevoir cet email, allez dans <a href="{{ preferences_url }}">Réglages des notifications</a>.</p>',
            'content_html_en' => '<p>Hello {{ recipient_name }},</p><p><strong>{{ author_name }}</strong> proposed a new event in the Loop <strong>{{ loop_name }}</strong>: <strong>{{ event_title }}</strong>, on {{ event_date }}.</p><p>{{ event_practical_info }}</p><p>{{ event_description }}</p><p><a href="{{ event_url }}">Open the Loop</a></p><p>No rush: the event will wait for you.</p><p>See you soon,<br>The {{ app_name }} team</p><hr style="margin:24px 0;border:0;border-top:1px solid #eee"><p style="font-size:12px;color:#777">To stop receiving this email, go to <a href="{{ preferences_url }}">Notification settings</a>.</p>',
            'variables' => [
                'recipient_name', 'recipient_email', 'author_name',
                'organization_name', 'loop_name', 'event_title', 'event_date',
                'event_description', 'event_practical_info', 'event_url',
                'preferences_url', 'app_name',
            ],
        ],

        'loop_invitation' => [
            'name_fr' => 'Invitation à rejoindre une Boucle',
            'name_en' => 'Invitation to join a Loop',
            'subject_fr' => '{{ sender_name }} vous invite à rejoindre la Boucle {{ loop_name }}',
            'subject_en' => '{{ sender_name }} invites you to join the {{ loop_name }} Loop',
            'content_html_fr' => '<h1>{{ sender_name }} vous invite à rejoindre {{ loop_name }}</h1>
<p>Bonjour {{ recipient_name }},</p>
<p><strong>{{ sender_name }}</strong> vous invite à rejoindre la Boucle <strong>{{ loop_name }}</strong> au sein de {{ organization_name }}.</p>
<p><em>{{ loop_tagline }}</em></p>
<blockquote>{{ personal_message }}</blockquote>
<p><a href="{{ invitation_url }}">Voir l\'invitation et rejoindre la Boucle</a></p>
<p>Cette invitation expire le {{ expires_at }}.</p>
<p>— {{ app_name }}</p>',
            'content_html_en' => '<h1>{{ sender_name }} invites you to join {{ loop_name }}</h1>
<p>Hello {{ recipient_name }},</p>
<p><strong>{{ sender_name }}</strong> invites you to join the <strong>{{ loop_name }}</strong> Loop within {{ organization_name }}.</p>
<p><em>{{ loop_tagline }}</em></p>
<blockquote>{{ personal_message }}</blockquote>
<p><a href="{{ invitation_url }}">View the invitation and join the Loop</a></p>
<p>This invitation expires on {{ expires_at }}.</p>
<p>— {{ app_name }}</p>',
            'variables' => [
                'recipient_name', 'recipient_email', 'sender_name', 'organization_name',
                'loop_name', 'loop_tagline', 'personal_message', 'invitation_url',
                'expires_at', 'app_name',
            ],
        ],
        // TASK-1659 — "Création de comptes en masse" : contrairement a
        // loop_invitation, ce lien cree DIRECTEMENT le compte au clic (pas
        // de formulaire d'inscription intermediaire, aucun mot de passe).
        'organization_invitation' => [
            'name_fr' => 'Invitation directe a une Organization',
            'name_en' => 'Direct Organization invitation',
            'subject_fr' => 'Votre accès à {{ organization_name }}',
            'subject_en' => 'Your access to {{ organization_name }}',
            // Styles INLINE a dessein : les clients de messagerie ignorent
            // une balise <style>. Ce gabarit a d'abord ete seme en HTML nu, et
            // comme il prend le pas sur le repli Blade, les courriels sont
            // partis sans mise en forme — regression vue par Cyril le 01/10.
            // La forme reprend donc celle du repli `emails/organization-invitation`.
            'content_html_fr' => '<div style="font-family: -apple-system, Segoe UI, Roboto, Helvetica, Arial, sans-serif; color: #1f2937; line-height: 1.6;">
<h1 style="font-size: 20px; font-weight: 700; margin: 0 0 16px;">Vous êtes invité·e à rejoindre {{ organization_name }}</h1>
<p style="margin: 0 0 12px;">Bonjour {{ recipient_name }},</p>
<p style="margin: 0 0 12px;"><strong>{{ sender_name }}</strong> vous invite à rejoindre <strong>{{ organization_name }}</strong> sur {{ app_name }}.</p>
<p style="margin: 0 0 20px;"><a href="{{ invitation_url }}" style="display: inline-block; padding: 12px 20px; border-radius: 10px; background: #4f46e5; color: #ffffff; text-decoration: none; font-weight: 600;">Rejoindre {{ organization_name }}</a></p>
<p style="margin: 0 0 8px; font-size: 13px; color: #6b7280;">Ce lien expire le {{ expires_at }}.</p>
<p style="margin: 0; font-size: 13px; color: #9ca3af;">— {{ app_name }}</p>
</div>',
            'content_html_en' => '<div style="font-family: -apple-system, Segoe UI, Roboto, Helvetica, Arial, sans-serif; color: #1f2937; line-height: 1.6;">
<h1 style="font-size: 20px; font-weight: 700; margin: 0 0 16px;">You are invited to join {{ organization_name }}</h1>
<p style="margin: 0 0 12px;">Hi {{ recipient_name }},</p>
<p style="margin: 0 0 12px;"><strong>{{ sender_name }}</strong> invites you to join <strong>{{ organization_name }}</strong> on {{ app_name }}.</p>
<p style="margin: 0 0 20px;"><a href="{{ invitation_url }}" style="display: inline-block; padding: 12px 20px; border-radius: 10px; background: #4f46e5; color: #ffffff; text-decoration: none; font-weight: 600;">Join {{ organization_name }}</a></p>
<p style="margin: 0 0 8px; font-size: 13px; color: #6b7280;">This link expires on {{ expires_at }}.</p>
<p style="margin: 0; font-size: 13px; color: #9ca3af;">— {{ app_name }}</p>
</div>',
            'variables' => [
                'recipient_name', 'recipient_email', 'sender_name', 'organization_name',
                'invitation_url', 'expires_at', 'app_name',
            ],
        ],
        // TASK-1734 — les deux emails de « Mes organisations ». GLOBAUX comme
        // l'email de verification : ils traversent les tenants, aucune
        // Organization n'a a les surcharger. L'URL figure EN CLAIR sous le
        // bouton (lecon T1707 L1/F5 : la version texte brut perd les href).
        'liaison-comptes-confirmation' => [
            'name_fr' => 'Liaison de comptes — confirmation',
            'name_en' => 'Account link — confirmation',
            'subject_fr' => 'Confirmez la liaison de vos deux comptes',
            'subject_en' => 'Confirm linking your two accounts',
            'content_html_fr' => '<h1>Bonjour {{ name }},</h1>
<p>Vous avez demandé à passer de <strong>{{ source_organization }}</strong> à <strong>{{ target_organization }}</strong>.</p>
<p>Si c\'est bien vous, confirmez : vous pourrez ensuite passer de l\'une à l\'autre en un clic, sans mot de passe.</p>
<p><a href="{{ url }}">Confirmer et continuer</a></p>
<p>Si le lien ci-dessus ne fonctionne pas, copiez cette adresse dans votre navigateur :<br>{{ url }}</p>
<p>Ce lien est valable <strong>15 minutes</strong> et ne peut servir qu\'une fois.</p>
<p>Ce n\'était pas vous ? Ignorez ce message : rien ne sera lié.</p>',
            'content_html_en' => '<h1>Hello {{ name }},</h1>
<p>You asked to switch from <strong>{{ source_organization }}</strong> to <strong>{{ target_organization }}</strong>.</p>
<p>If this was you, confirm it: you will then be able to switch between them in one click, without a password.</p>
<p><a href="{{ url }}">Confirm and continue</a></p>
<p>If the link above does not work, copy this address into your browser:<br>{{ url }}</p>
<p>This link is valid for <strong>15 minutes</strong> and can only be used once.</p>
<p>Wasn\'t you? Ignore this message: nothing will be linked.</p>',
            'variables' => ['name', 'url', 'source_organization', 'target_organization'],
        ],
        'liaison-comptes-creee' => [
            'name_fr' => 'Liaison de comptes — avis de création',
            'name_en' => 'Account link — created notice',
            'subject_fr' => 'Vos comptes sont maintenant liés',
            'subject_en' => 'Your accounts are now linked',
            'content_html_fr' => '<h1>Bonjour {{ name }},</h1>
<p>Votre compte et votre compte de <strong>{{ other_organization }}</strong> sont maintenant liés : vous pouvez passer de l\'un à l\'autre en un clic, depuis le menu de votre avatar.</p>
<p>Les données des deux organisations restent séparées.</p>
<p>Vous pouvez révoquer ce lien à tout moment, depuis l\'un ou l\'autre compte :</p>
<p><a href="{{ url }}">Gérer mes comptes liés</a></p>
<p>Si vous n\'êtes pas à l\'origine de cette liaison, révoquez-la et changez votre mot de passe.</p>',
            'content_html_en' => '<h1>Hello {{ name }},</h1>
<p>Your account and your <strong>{{ other_organization }}</strong> account are now linked: you can switch between them in one click from your avatar menu.</p>
<p>Data from both organizations remains separate.</p>
<p>You can revoke this link at any time, from either account:</p>
<p><a href="{{ url }}">Manage my linked accounts</a></p>
<p>If you did not create this link, revoke it and change your password.</p>',
            'variables' => ['name', 'url', 'other_organization'],
        ],
    ];

    /**
     * TASK-1681 — le catalogue CANONIQUE des modeles systeme, en lecture.
     *
     * L'ecran admin s'en sert pour montrer qu'un modele par defaut existe et
     * pour pre-remplir UNE personnalisation a la demande — sans jamais
     * rejouer ce seeder ni semer quoi que ce soit en masse.
     * `database/seeders` est dans l'autoload PRINCIPAL de composer (pas
     * autoload-dev) : l'appel est sur en production.
     */
    public static function definitions(): array
    {
        return (new self)->templateDefinitions;
    }

    /**
     * Les champs d'une surcharge pre-remplie depuis le catalogue, dans la
     * MEME correspondance que run() — une seule traduction slug+locale ->
     * colonnes, jamais deux.
     */
    public static function rowFor(string $slug, string $locale): array
    {
        $def = self::definitions()[$slug];

        return [
            'name' => $locale === 'fr' ? ($def['name_fr'] ?? $def['name']) : ($def['name_en'] ?? $def['name']),
            'subject' => $def["subject_{$locale}"] ?? $def['subject_fr'],
            'content_html' => $def["content_html_{$locale}"] ?? $def['content_html_fr'],
            'variables' => $def['variables'],
        ];
    }

    /**
     * TASK-1694 bug N — le catalogue se seme en GLOBAL, plus par Organization.
     *
     * ## Ce que ce seeder fabriquait
     *
     * Une ligne PAR Organization active, pour chaque slug et chaque langue.
     * C'est la mecanique qui a produit la pagaille constatee le 23/10 : 21
     * lignes rattachees a deux Organizations, et **aucune personnalisation
     * humaine dedans** — les 21 etaient identiques au catalogue, octet pour
     * octet. Du bruit, pas du travail.
     *
     * Et ce bruit avait un cout : une Organization creee APRES le seeder — OFSH
     * — n'avait aucune ligne, pendant que l'ecran d'administration en affichait
     * pour les autres. Deux Organizations servies differemment par la seule
     * grace de leur date de creation.
     *
     * ## Decision de Cyril
     *
     * « Il faudrait qu'on ait uniquement des emails de type Global. » Une ligne
     * par slug et par langue, independante du nombre d'Organizations. Une
     * Organization nouvelle est servie des sa creation, sans rien semer.
     *
     * ## Ce qui N'EST PAS supprime ici
     *
     * Les lignes par Organization deja en base restent, et le resolveur (bug M)
     * continue de les faire gagner. Leur suppression est un geste a part, avec
     * son archive et sa reversibilite.
     */
    public function run(): void
    {
        foreach ($this->templateDefinitions as $slug => $def) {
            foreach (['fr', 'en'] as $locale) {
                SystemEmailTemplate::firstOrCreate(
                    [
                        'organization_id' => null,
                        'locale' => $locale,
                        'slug' => $slug,
                    ],
                    self::rowFor($slug, $locale) + ['enabled' => true],
                );
            }
        }
    }
}
