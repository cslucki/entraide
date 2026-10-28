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
<p>Votre compte a bien été créé. Vous avez reçu <strong>100 points de bienvenue</strong> pour démarrer vos premiers échanges.</p>
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
<p><strong>{{ sender_name }}</strong> vous a envoyé un message à propos de l\'échange <strong>{{ transaction_title }}</strong>.</p>
<blockquote>{{ message_preview }}</blockquote>
<p><a href="{{ url }}">Voir la conversation</a></p>',
            'content_html_en' => '<h1>New message from {{ sender_name }}</h1>
<p>Hello {{ name }},</p>
<p><strong>{{ sender_name }}</strong> sent you a message about <strong>{{ transaction_title }}</strong>.</p>
<blockquote>{{ message_preview }}</blockquote>
<p><a href="{{ url }}">View conversation</a></p>',
            'variables' => ['name', 'sender_name', 'transaction_title', 'message_preview', 'url'],
        ],
        'transaction_status_changed' => [
            'name_fr' => 'Mise à jour statut échange',
            'name_en' => 'Exchange status update',
            'subject_fr' => 'Mise à jour de votre échange — {{ status_label }}',
            'subject_en' => 'Your exchange has been updated — {{ status_label }}',
            'content_html_fr' => '<h1>Mise à jour de votre échange</h1>
<p>Bonjour {{ name }},</p>
<p>Le statut de votre échange <strong>{{ title }}</strong> a changé : <strong>{{ status_label }}</strong>.</p>
<p>Points : {{ points }} pts</p>
<p><a href="{{ url }}">Voir la conversation</a></p>',
            'content_html_en' => '<h1>Your exchange has been updated</h1>
<p>Hello {{ name }},</p>
<p>The status of your exchange <strong>{{ title }}</strong> changed to: <strong>{{ status_label }}</strong>.</p>
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
