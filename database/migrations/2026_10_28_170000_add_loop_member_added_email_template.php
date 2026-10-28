<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * TASK-1720 volet C — « ajoute » n'est pas « invite ».
 *
 * ## Le defaut de langage
 *
 * L'ajout direct empruntait le gabarit `loop_invitation`, dont le sujet dit
 * « X vous invite a rejoindre la Boucle Y ». Or la personne EST membre quand
 * elle lit le message : il n'y a rien a accepter, aucun lien a suivre pour
 * entrer. Le texte decrivait une demarche qui n'existait pas.
 *
 * ## Un gabarit SEPARE, pas une retouche du premier
 *
 * Adoucir `loop_invitation` pour qu'il convienne aux deux cas produirait un
 * texte qui ne dit bien ni l'un ni l'autre — et le SuperAdmin ne pourrait
 * plus ecrire a une personne invitee ce qu'il veut lui ecrire, sans affecter
 * les personnes ajoutees.
 *
 * Deux faits distincts, deux gabarits, exactement comme le catalogue de
 * notifications porte deux cles (`loop.invitation` et `loop.member_added`).
 *
 * Aucun systeme nouveau : meme table, memes variables, meme resolveur. Le
 * repli en langue (`notifications.email.loop_member_added`) reste en place
 * sous celui-ci.
 *
 * ## L'accroche de la Boucle ne figure pas dans le corps
 *
 * Le premier jet reprenait `<p><em>{{ loop_tagline }}</em></p>` de
 * `loop_invitation`. En recette, `boucle2` porte « Accroche courte » : le
 * courriel affichait une ligne en italique qui n'apprend rien a quelqu'un
 * qu'on vient d'ajouter — et une Boucle sans accroche produisait un paragraphe
 * vide. MASTER : « Il faut supprimer "Accroche courte" ».
 *
 * La VARIABLE reste declaree. Le defaut ne l'emploie pas ; un SuperAdmin qui
 * veut l'accroche dans son texte peut toujours l'ecrire, comme dans
 * `loop_invitation`. Retirer la declaration la lui interdirait.
 *
 * ## `down()` ne conserve rien, et c'est justifie
 *
 * Contrairement a TASK-1711, ces deux lignes sont REGENERABLES a l'identique
 * par le `up()` : ce sont des textes par defaut, pas des decisions humaines.
 * Seule une PERSONNALISATION serait perdue — et le `down()` ne supprime que
 * les lignes restees identiques au defaut, precisement pour ne jamais
 * detruire un texte reecrit.
 */
return new class extends Migration
{
    private const SLUG = 'loop_member_added';

    /** @return array<string, array{name: string, subject: string, html: string}> */
    private function gabarits(): array
    {
        return [
            'fr' => [
                'name' => 'Ajout a une Boucle',
                'subject' => 'Vous avez été ajouté à la Boucle {{ loop_name }}',
                'html' => '<p>Bonjour {{ recipient_name }},</p>'
                    .'<p>{{ sender_name }} vous a ajouté à la Boucle <strong>{{ loop_name }}</strong> '
                    .'au sein de {{ organization_name }}. Vous en êtes désormais membre.</p>'
                    .'<p><a href="{{ invitation_url }}">Ouvrir la Boucle</a></p>'
                    .'<p>Si le lien ne fonctionne pas, copiez cette adresse dans votre navigateur :<br>'
                    .'{{ invitation_url }}</p>'
                    .'<p>À bientôt,<br>{{ app_name }}</p>',
            ],
            'en' => [
                'name' => 'Added to a Loop',
                'subject' => 'You have been added to the {{ loop_name }} Loop',
                'html' => '<p>Hello {{ recipient_name }},</p>'
                    .'<p>{{ sender_name }} added you to the <strong>{{ loop_name }}</strong> Loop '
                    .'in {{ organization_name }}. You are now a member.</p>'
                    .'<p><a href="{{ invitation_url }}">Open the Loop</a></p>'
                    .'<p>If the link does not work, copy this address into your browser:<br>'
                    .'{{ invitation_url }}</p>'
                    .'<p>See you soon,<br>{{ app_name }}</p>',
            ],
        ];
    }

    public function up(): void
    {
        foreach ($this->gabarits() as $locale => $g) {
            $existe = DB::table('system_email_templates')
                ->where('slug', self::SLUG)
                ->where('locale', $locale)
                ->whereNull('organization_id')
                ->exists();

            // Idempotent : une ligne deja posee — par un rejeu, ou reecrite
            // par le SuperAdmin — n'est jamais ecrasee.
            if ($existe) {
                continue;
            }

            // Pas d'`id` explicite : `system_email_templates.id` est un
            // BIGINT auto-incremente, pas un UUID. Verifie plutot que suppose
            // — le premier jet posait un UUID et PostgreSQL l'a refuse
            // (`22P02`, invalid input syntax for type bigint).
            DB::table('system_email_templates')->insert([
                'slug' => self::SLUG,
                'locale' => $locale,
                'organization_id' => null,
                'name' => $g['name'],
                'subject' => $g['subject'],
                'content_html' => $g['html'],
                'variables' => json_encode([
                    'recipient_name', 'sender_name', 'organization_name',
                    'loop_name', 'loop_tagline', 'invitation_url', 'app_name',
                ]),
                'enabled' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        foreach ($this->gabarits() as $locale => $g) {
            // Seules les lignes restees IDENTIQUES au defaut repartent. Une
            // personnalisation du SuperAdmin survit au rollback — la detruire
            // serait perdre une decision humaine que le `up()` ne sait pas
            // rendre (doctrine T1711).
            DB::table('system_email_templates')
                ->where('slug', self::SLUG)
                ->where('locale', $locale)
                ->whereNull('organization_id')
                ->where('subject', $g['subject'])
                ->where('content_html', $g['html'])
                ->delete();
        }
    }
};
