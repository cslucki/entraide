<?php

use Database\Seeders\SystemEmailTemplateSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * TASK-1694 bug Q — la cible : 9 slugs x 2 langues, en GLOBAL.
 *
 * ## Ce que demande Cyril
 *
 * « Que tous les emails types soient dans le super admin. Il faudrait qu'on ait
 * uniquement des emails de type Global. Et avec une version EN et FR. »
 *
 * Le bug N a change ce que le SEEDER produit — mais un seeder ne repasse pas
 * sur une base en service. Cette migration porte la cible sur les bases DEJA
 * installees, la production comprise.
 *
 * ## Elle n'est SURE que depuis le bug P
 *
 * Ces lignes naissent ACTIVEES : c'est tout l'objet, qu'elles soient lues.
 * Avant le bug P, cela aurait deshabille tous les emails transactionnels d'un
 * coup — un gabarit de base partait en HTML nu (261 octets contre 10 923).
 * L'habillage etant desormais le meme des deux cotes, activer ne change que le
 * TEXTE.
 *
 * ## Deux gestes, et ce qu'ils preservent
 *
 * 1. **Les lignes globales SANS langue sont triees.** Mesure du 23/10 : il y en
 *    a quatre, et **une seule DIFFERE** du catalogue.
 *
 *    - celle qui differe est **promue** dans une langue libre : son texte est
 *      peut-etre le travail de quelqu'un, et inserer par-dessus l'aurait laissee
 *      derriere, inatteignable ;
 *    - les trois identiques au catalogue sont **retirees** : elles ne portent
 *      aucune information que l'insertion de l'etape 2 ne redonne, et les garder
 *      laisserait un cran mort dans l'ecran d'administration.
 *
 *    Ce tri rend aussi le `down()` exact : il ne retire que ce qui est identique
 *    au catalogue, c'est-a-dire exactement ce que l'aller a pose.
 *
 * 2. **Les lignes manquantes sont inserees** depuis le catalogue, qui est la
 *    source unique des textes (bug N).
 *
 * ## Ce qu'elle NE fait PAS
 *
 * Elle ne touche a AUCUNE ligne rattachee a une Organization. Leur suppression
 * est un geste a part, avec son archive et sa reversibilite — on ne detruit pas
 * dans la meme migration qui construit.
 *
 * ## Idempotente
 *
 * Chaque geste est conditionne a l'absence. Rejouee, elle ne fait rien, et elle
 * n'ecrase jamais un texte edite depuis l'administration.
 */
return new class extends Migration
{
    private const LANGUES = ['fr', 'en'];

    public function up(): void
    {
        $catalogue = SystemEmailTemplateSeeder::definitions();

        // 1. Trier les lignes globales sans langue : promouvoir celles qui
        //    portent un texte propre, retirer celles qui ne font que repeter le
        //    catalogue.
        foreach ($this->lignesGlobalesSansLangue() as $ligne) {
            if (! array_key_exists($ligne->slug, $catalogue)) {
                continue;
            }

            if ($this->repeteLeCatalogue($ligne)) {
                DB::table('system_email_templates')->where('id', $ligne->id)->delete();

                continue;
            }

            $langueLibre = collect(self::LANGUES)->first(
                fn (string $langue) => ! $this->existeEnGlobal($ligne->slug, $langue),
            );

            if ($langueLibre === null) {
                continue;
            }

            DB::table('system_email_templates')
                ->where('id', $ligne->id)
                ->update(['locale' => $langueLibre, 'updated_at' => now()]);
        }

        // 2. Completer les lignes manquantes depuis le catalogue.
        foreach ($catalogue as $slug => $definition) {
            foreach (self::LANGUES as $langue) {
                if ($this->existeEnGlobal($slug, $langue)) {
                    continue;
                }

                $ligne = SystemEmailTemplateSeeder::rowFor($slug, $langue);

                DB::table('system_email_templates')->insert([
                    'slug' => $slug,
                    'locale' => $langue,
                    'organization_id' => null,
                    'name' => $ligne['name'],
                    'subject' => $ligne['subject'],
                    'content_html' => $ligne['content_html'],
                    'variables' => json_encode($ligne['variables']),
                    'enabled' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    /**
     * Retire les lignes que cette migration a posees ET que personne n'a
     * touchees.
     *
     * Une ligne editee depuis l'administration depuis l'aller est gardee : un
     * `down()` qui detruirait du travail humain serait pire que pas de `down()`
     * du tout. La promotion de l'etape 1 n'est pas defaite — elle ne perd
     * aucune information, et rien ne permet de distinguer apres coup une ligne
     * promue d'une ligne qui avait deja sa langue.
     */
    public function down(): void
    {
        foreach (SystemEmailTemplateSeeder::definitions() as $slug => $definition) {
            foreach (self::LANGUES as $langue) {
                $ligne = SystemEmailTemplateSeeder::rowFor($slug, $langue);

                DB::table('system_email_templates')
                    ->where('slug', $slug)
                    ->where('locale', $langue)
                    ->whereNull('organization_id')
                    ->where('subject', $ligne['subject'])
                    ->where('content_html', $ligne['content_html'])
                    ->delete();
            }
        }
    }

    /** @return \Illuminate\Support\Collection<int, object> */
    private function lignesGlobalesSansLangue(): \Illuminate\Support\Collection
    {
        return DB::table('system_email_templates')
            ->whereNull('organization_id')
            ->whereNull('locale')
            ->get(['id', 'slug', 'subject', 'content_html']);
    }

    /**
     * Cette ligne ne fait-elle que repeter le catalogue ?
     *
     * Compare aux DEUX langues : une ligne sans langue peut aussi bien avoir
     * ete semee en francais qu'en anglais, et rien dans la ligne ne le dit.
     */
    private function repeteLeCatalogue(object $ligne): bool
    {
        foreach (self::LANGUES as $langue) {
            $reference = SystemEmailTemplateSeeder::rowFor($ligne->slug, $langue);

            if (trim((string) $ligne->subject) === trim((string) $reference['subject'])
                && trim((string) $ligne->content_html) === trim((string) $reference['content_html'])) {
                return true;
            }
        }

        return false;
    }

    private function existeEnGlobal(string $slug, string $langue): bool
    {
        return DB::table('system_email_templates')
            ->where('slug', $slug)
            ->where('locale', $langue)
            ->whereNull('organization_id')
            ->exists();
    }
};
