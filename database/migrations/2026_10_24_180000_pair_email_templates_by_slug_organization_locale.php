<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-1694 bug AA — un gabarit de l'Emailer s'identifie par
 * (slug, organisation, langue).
 *
 * ## La demande
 *
 * Cyril : « oui je veux un veritable appariement » — c'est-a-dire que
 * `relance` existe en FR et en EN, SOUS LE MEME SLUG, et que choisir la langue
 * a l'envoi designe la bonne ligne.
 *
 * ## Ce qui l'empechait
 *
 * `email_templates.slug` etait UNIQUE GLOBALEMENT. Deux versions linguistiques
 * d'un meme gabarit etaient donc impossibles — et la meme contrainte
 * interdisait un meme gabarit pour deux Organizations (demande 8 de Cyril).
 *
 * Le code le contournait deja, ce qui est le signe que la contrainte genait :
 * `CrmEmailTemplateService` fabrique des slugs NAMESPACES
 * (« {org-slug}-{nom} », d'ou `main-propal`) pour ne jamais collisionner. Ce
 * contournement devient inutile ; il n'est pas retire ici, c'est un autre geste
 * et les slugs deja poses ne doivent pas bouger.
 *
 * ## L'index reprend EXACTEMENT la forme de la maison
 *
 * `system_email_templates` porte deja
 * `unique (organization_id, locale, slug)`. Le meme triplet, dans le meme
 * ordre : deux tables qui expriment la meme idee doivent l'exprimer pareil.
 *
 * ## La limite, nommee plutot que tue
 *
 * `organization_id` et `locale` sont NULLABLES, et PostgreSQL comme SQLite
 * traitent deux NULL comme DISTINCTS dans un index unique. Deux lignes
 * (slug='x', organisation=NULL, langue=NULL) restent donc possibles.
 *
 * C'est exactement la limite que porte deja `system_email_templates`, et elle
 * est reprise sciemment : s'en ecarter ici creerait deux regles differentes
 * pour la meme idee. Le garde-fou reel est au-dessus — la validation du
 * formulaire refuse le doublon avant qu'il n'arrive en base.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_templates', function (Blueprint $table) {
            $table->dropUnique('email_templates_slug_unique');
            $table->unique(['organization_id', 'locale', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::table('email_templates', function (Blueprint $table) {
            $table->dropUnique(['organization_id', 'locale', 'slug']);
            $table->unique('slug');
        });
    }
};
