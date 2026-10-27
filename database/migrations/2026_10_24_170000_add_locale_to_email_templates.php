<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-1694 bug Z — la langue d'un gabarit de l'Emailer.
 *
 * ## La demande
 *
 * Cyril : « il faudrait ajouter un champ pour indiquer si c'est une version FR
 * ou EN [...] et du coup quand on envoie l'email depuis /admin/users, qu'on
 * puisse choisir FR ou EN ».
 *
 * ## NULLABLE, et c'est deliberе
 *
 * `null` veut dire « langue non precisee », et c'est l'etat des lignes
 * existantes : personne ne leur a jamais pose la question. Les declarer
 * francaises d'office serait une supposition ecrite en base — plausible, mais
 * invérifiable, et fausse le jour ou quelqu'un a ecrit un gabarit en anglais.
 *
 * Consequence voulue : un gabarit sans langue reste propose dans les DEUX
 * langues au moment de l'envoi. Il vaut mieux montrer un gabarit dont on ignore
 * la langue que le faire disparaitre d'un ecran.
 *
 * ## Ce que cette colonne N'EST PAS
 *
 * Elle n'apparie pas deux versions d'un meme gabarit : `email_templates.slug`
 * est UNIQUE globalement (releve du 24/10), donc deux versions linguistiques
 * sont deux lignes a slugs distincts, sans lien entre elles. La langue est un
 * ATTRIBUT de la ligne, pas une clef de jumelage — contrairement a
 * `system_email_templates`, ou (slug, organization, locale) forme le triplet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_templates', function (Blueprint $table) {
            $table->string('locale', 5)->nullable()->after('name')->index();
        });
    }

    public function down(): void
    {
        Schema::table('email_templates', function (Blueprint $table) {
            $table->dropColumn('locale');
        });
    }
};
