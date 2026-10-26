<?php

use Database\Seeders\SystemEmailTemplateSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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
 *
 * ## TASK-1709 — la trace, et pourquoi l'aller etait irreversible
 *
 * TASK-1708 a mesure sur un jumeau de la PROD 1.676 que l'aller-retour ne rend
 * pas l'etat de depart. Les deux gestes de l'etape 1 laissaient chacun une
 * marque que le `down()` ne pouvait pas defaire :
 *
 * | geste de `up()` | ce que `down()` faisait | ecart apres aller-retour |
 * |---|---|---|
 * | ligne **supprimee** (elle repetait le catalogue) | rien | **la ligne avait disparu** |
 * | ligne **promue** (`locale` NULL -> `fr`) | rien | **`locale` restait `fr`** |
 *
 * Sur la PROD : 3 lignes supprimees (`new_message`,
 * `transaction_status_changed`, `ai_budget_exceeded`) et 1 promue (`welcome`).
 * Quatre lignes globales avant, une seule apres un aller-retour.
 *
 * Le docbloc du `down()` disait pourtant deja le probleme : « rien ne permet de
 * distinguer apres coup une ligne promue d'une ligne qui avait deja sa
 * langue ». C'etait vrai, et c'est precisement ce que
 * `system_email_templates_trace` change : la trace est ecrite AVANT le
 * geste, donc elle sait ce que l'apres ne peut plus deduire.
 *
 * Elle enregistre la **ligne entiere** — `id` compris — et le **geste**. Pas
 * seulement le slug : l'`id` est un `bigint` a sequence qu'aucune cle etrangere
 * ne reference, donc il peut etre rendu a l'identique, et c'est ce qui permet
 * une restauration fidele plutot qu'une recreation approximative.
 *
 * **Elle n'ecrase jamais rien.** Chaque passage ajoute un instantane horodate ;
 * le `down()` lit le plus recent par ligne d'origine. L'histoire est conservee,
 * comme dans la table d'archive soeur.
 *
 * **Portee** : uniquement `organization_id IS NULL`. Les lignes rattachees a une
 * Organization restent sous le contrat de
 * `2026_10_24_160000_archive_and_remove_per_organization_system_email_templates`,
 * qui n'est pas touchee. Organization = Tenant.
 *
 * **Limite assumee** : un environnement qui a DEJA execute cette migration n'a
 * aucune trace, et elle est irreconstituable de bonne foi — on ne peut pas
 * savoir quelles lignes globales sans langue y existaient. Le `down()` s'y
 * degrade proprement (`Schema::hasTable`). La PROD, elle, ne l'a jamais
 * executee : elle est integralement couverte.
 */
return new class extends Migration
{
    private const LANGUES = ['fr', 'en'];

    /** La trace des lignes globales touchees par l'etape 1 (TASK-1709). */
    private const TRACE = 'system_email_templates_trace';

    /** Qui a ecrit l'instantane : chaque `down()` ne relit que le sien. */
    private const MARQUE = '2026_10_24_140000_normalize_system_email_templates_to_global';

    private const GESTE_SUPPRIMEE = 'supprimee';

    private const GESTE_PROMUE = 'promue';

    /** Les colonnes d'une ligne de gabarit, dans l'ordre ou on les rend. */
    private const COLONNES = [
        'slug', 'name', 'subject', 'content_html', 'variables',
        'enabled', 'organization_id', 'locale', 'created_at', 'updated_at',
    ];

    public function up(): void
    {
        $catalogue = SystemEmailTemplateSeeder::definitions();

        $this->assurerLaTrace();

        // 1. Trier les lignes globales sans langue : promouvoir celles qui
        //    portent un texte propre, retirer celles qui ne font que repeter le
        //    catalogue.
        //
        //    TASK-1709 : chaque geste est TRACE avant d'etre fait. C'est la
        //    seule facon de le defaire — apres coup, plus rien ne distingue une
        //    ligne promue d'une ligne qui avait deja sa langue, ni ne dit
        //    qu'une ligne supprimee a existe.
        foreach ($this->lignesGlobalesSansLangue() as $ligne) {
            if (! array_key_exists($ligne->slug, $catalogue)) {
                continue;
            }

            if ($this->repeteLeCatalogue($ligne)) {
                $this->tracer($ligne, self::GESTE_SUPPRIMEE);

                DB::table('system_email_templates')->where('id', $ligne->id)->delete();

                continue;
            }

            $langueLibre = collect(self::LANGUES)->first(
                fn (string $langue) => ! $this->existeEnGlobal($ligne->slug, $langue),
            );

            if ($langueLibre === null) {
                continue;
            }

            $this->tracer($ligne, self::GESTE_PROMUE);

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
     * Defait l'aller : retire ce que l'etape 2 a pose, puis rend a l'etape 1 ce
     * qu'elle avait pris.
     *
     * L'ordre compte. On retire d'abord les lignes `fr`/`en` que l'etape 2 a
     * inserees — et uniquement celles que **personne n'a touchees**, car un
     * `down()` qui detruirait du travail humain serait pire que pas de `down()`
     * du tout. On restaure ensuite depuis la trace.
     *
     * TASK-1709 : la version precedente s'arretait a la premiere moitie, en
     * affirmant que « rien ne permet de distinguer apres coup une ligne promue
     * d'une ligne qui avait deja sa langue ». C'etait exact — et c'est pour cela
     * que la trace est ecrite AVANT le geste, et non deduite apres.
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

        $this->restaurerDepuisLaTrace();
    }

    /**
     * La trace partagee des gabarits d'email (TASK-1709).
     *
     * Trois migrations de ce lot mutent des lignes de `system_email_templates`
     * et ne savaient pas les rendre. Chacune ecrit desormais, AVANT de muter,
     * un instantane de la ligne entiere, et ne relit au `down()` que SES
     * propres instantanes — d'ou la colonne `migration`.
     *
     * Creee par la premiere des trois qui s'execute, et jamais supprimee : une
     * trace qui disparait au retour ne sert a rien le coup suivant. Meme
     * contrat que `system_email_templates_archive`, dont le docbloc dit
     * « elle garde la trace de ce qui a ete archive ».
     *
     * Aucune cle etrangere sur `organization_id` : une trace doit survivre a la
     * disparition de ce qu'elle decrit.
     *
     * Ce bloc est volontairement REPETE dans les trois migrations plutot que
     * sorti dans une classe. Une migration qui depend d'une classe applicative
     * casse le jour ou cette classe bouge ; elle doit pouvoir etre rejouee dans
     * dix ans telle quelle.
     */
    private function assurerLaTrace(): void
    {
        if (Schema::hasTable(self::TRACE)) {
            return;
        }

        Schema::create(self::TRACE, function (Blueprint $table) {
            $table->bigIncrements('trace_id');
            $table->string('migration');
            // Volontairement SANS unicite : chaque aller-retour ajoute son
            // instantane, et le `down()` lit le plus recent. Rien n'est ecrase.
            $table->unsignedBigInteger('template_id');
            $table->string('geste', 20);
            $table->string('slug');
            $table->string('name');
            $table->string('subject');
            $table->text('content_html');
            $table->text('variables')->nullable();
            $table->boolean('enabled')->default(true);
            $table->uuid('organization_id')->nullable();
            $table->string('locale')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->timestamp('archived_at');
            $table->index(['migration', 'template_id']);
        });
    }

    private function tracer(object $ligne, string $geste): void
    {
        $instantane = [
            'migration' => self::MARQUE,
            'template_id' => $ligne->id,
            'geste' => $geste,
            'archived_at' => now(),
        ];

        foreach (self::COLONNES as $colonne) {
            $instantane[$colonne] = $ligne->{$colonne} ?? null;
        }

        DB::table(self::TRACE)->insert($instantane);
    }

    /**
     * Le DERNIER instantane de chaque ligne, pour CETTE migration seulement.
     *
     * `trace_id` est une sequence : le plus grand est le plus recent, sans
     * dependre de l'horodatage — deux instantanes de la meme seconde ne
     * laisseraient pas la base departager (lecon T1079 sur `joined_at`).
     * C'est ce qui garantit qu'on ne restaure jamais depuis un instantane
     * perime.
     *
     * @return Collection<int, object>
     */
    private function derniersInstantanes(): Collection
    {
        if (! Schema::hasTable(self::TRACE)) {
            // Environnement migre AVANT TASK-1709 : il n'y a rien a rendre, et
            // inventer une trace serait fabriquer une preuve.
            return collect();
        }

        return DB::table(self::TRACE)
            ->where('migration', self::MARQUE)
            ->orderBy('trace_id')
            ->get()
            ->keyBy('template_id')
            ->values();
    }

    /**
     * Rend a l'etape 1 ce qu'elle avait pris, d'apres le dernier instantane.
     *
     * Deux refus explicites, et aucun des deux n'est silencieux au sens ou il
     * detruirait quelque chose :
     *
     * - une ligne `supprimee` n'est reinseree que si son `id` est LIBRE. S'il
     *   est occupe, c'est qu'une autre ligne vit la : on ne l'ecrase pas, on
     *   passe. Mieux vaut un retour incomplet qu'un retour destructeur.
     * - une ligne `promue` ne retrouve sa langue d'origine que si elle existe
     *   encore. Si elle a ete supprimee entre-temps, ce n'est plus notre sujet.
     *
     * Rejouable : la reinsertion est conditionnee a l'absence, et remettre deux
     * fois la meme `locale` ne change rien.
     */
    private function restaurerDepuisLaTrace(): void
    {
        if (! Schema::hasTable(self::TRACE)) {
            // Environnement migre AVANT TASK-1709 : il n'y a rien a rendre, et
            // inventer une trace serait fabriquer une preuve. Le recours est
            // `SystemEmailTemplateSeeder`, documente dans le TASK file.
            return;
        }

        foreach ($this->derniersInstantanes() as $instantane) {
            if ($instantane->geste === self::GESTE_SUPPRIMEE) {
                $dejaLa = DB::table('system_email_templates')
                    ->where('id', $instantane->template_id)
                    ->exists();

                if ($dejaLa) {
                    continue;
                }

                $ligne = ['id' => $instantane->template_id];

                foreach (self::COLONNES as $colonne) {
                    $ligne[$colonne] = $instantane->{$colonne};
                }

                DB::table('system_email_templates')->insert($ligne);

                continue;
            }

            // TASK-1709 — `locale` est la SEULE colonne que l'etape 1 a mutee
            // sur une ligne promue : la rendre, c'est rendre la ligne entiere,
            // donc l'horodatage revient avec elle. Les lignes `supprimee`
            // ci-dessus n'ont jamais eu ce probleme — elles sont reinserees
            // depuis l'instantane, dont `COLONNES` porte les deux horodatages.
            DB::table('system_email_templates')
                ->where('id', $instantane->template_id)
                ->update([
                    'locale' => $instantane->locale,
                    'updated_at' => $instantane->updated_at,
                ]);
        }
    }

    /**
     * @return Collection<int, object>
     */
    private function lignesGlobalesSansLangue(): Collection
    {
        // TASK-1709 : la ligne ENTIERE, pas quatre colonnes. La trace doit
        // pouvoir la rendre a l'identique.
        return DB::table('system_email_templates')
            ->whereNull('organization_id')
            ->whereNull('locale')
            ->get();
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
