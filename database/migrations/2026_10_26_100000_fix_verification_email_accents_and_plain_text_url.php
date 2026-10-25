<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * TASK-1707 L1 + F5 — l'email de confirmation : ses accents, et son URL.
 *
 * Recette OFSH du 25/10. Deux defauts dans le meme gabarit :
 *
 * 1. **Les accents manquent** — « une etape », « Passe ce delai », « vous
 *    n'etes pas a l'origine ». Ce n'est pas un defaut d'encodage : le pied de
 *    page du meme email affiche « Tous droits réservés » correctement.
 *
 * 2. **L'URL n'existe que dans le `href`.** La table ne stocke que
 *    `content_html` ; la version texte brut est derivee en retirant les
 *    balises, et le lien disparait avec elles. Le destinataire dont le client
 *    affiche le texte brut lisait « Confirmer mon adresse » SANS adresse — et
 *    ne pouvait pas valider son compte. Constate dans MailHog pendant la
 *    recette.
 *
 * ## Pourquoi une migration, et pas une retouche de celle de TASK-1694
 *
 * Une migration raconte ce qui s'est passe a sa date ; on ne la reecrit pas
 * apres coup. Celle-ci corrige les bases DEJA installees ; le seeder, corrige
 * en parallele, sert les bases neuves.
 *
 * ## Elle n'ecrase AUCUNE edition humaine
 *
 * L'ecran `/admin/system-email-templates` permet de reecrire ce texte. La mise
 * a jour ne s'applique donc QUE si la ligne porte encore, mot pour mot, le
 * texte d'origine. Quelqu'un qui l'a personnalise garde sa version — quitte a
 * garder aussi les fautes, ce qui est son droit.
 */
return new class extends Migration
{
    private const SLUG = 'confirmation-email-suite-inscription';

    /** Le texte d'ORIGINE, tel que TASK-1694 l'a insere. */
    private const AVANT = [
        'fr' => '<h1>Bonjour {{ name }},</h1>
<p>Il ne reste qu\'une etape : confirmer votre adresse email.</p>
<p><a href="{{ url }}">Confirmer mon adresse</a></p>
<p>Ce lien est valable <strong>24 heures</strong>. Passe ce delai, vous pourrez en demander un nouveau depuis la page d\'attente.</p>
<p>Si vous n\'etes pas a l\'origine de cette inscription, vous pouvez ignorer ce message.</p>',
        'en' => '<h1>Hello {{ name }},</h1>
<p>One last step: confirm your email address.</p>
<p><a href="{{ url }}">Confirm my address</a></p>
<p>This link is valid for <strong>24 hours</strong>. After that, you can request a new one from the waiting page.</p>
<p>If you did not create this account, you can ignore this message.</p>',
    ];

    private const APRES = [
        'fr' => '<h1>Bonjour {{ name }},</h1>
<p>Il ne reste qu\'une étape : confirmer votre adresse email.</p>
<p><a href="{{ url }}">Confirmer mon adresse</a></p>
<p>Si le lien ci-dessus ne fonctionne pas, copiez cette adresse dans votre navigateur :<br>{{ url }}</p>
<p>Ce lien est valable <strong>24 heures</strong>. Passé ce délai, vous pourrez en demander un nouveau depuis la page d\'attente.</p>
<p>Si vous n\'êtes pas à l\'origine de cette inscription, vous pouvez ignorer ce message.</p>',
        'en' => '<h1>Hello {{ name }},</h1>
<p>One last step: confirm your email address.</p>
<p><a href="{{ url }}">Confirm my address</a></p>
<p>If the link above does not work, copy this address into your browser:<br>{{ url }}</p>
<p>This link is valid for <strong>24 hours</strong>. After that, you can request a new one from the waiting page.</p>
<p>If you did not create this account, you can ignore this message.</p>',
    ];

    public function up(): void
    {
        $this->remplacer(self::AVANT, self::APRES);
    }

    /** Symetrique : on ne defait que ce que `up()` a pose. */
    public function down(): void
    {
        $this->remplacer(self::APRES, self::AVANT);
    }

    private function remplacer(array $depuis, array $vers): void
    {
        foreach ($depuis as $locale => $ancien) {
            DB::table('system_email_templates')
                ->where('slug', self::SLUG)
                ->where('locale', $locale)
                ->where('content_html', $ancien)
                ->update([
                    'content_html' => $vers[$locale],
                    'updated_at' => now(),
                ]);
        }
    }
};
