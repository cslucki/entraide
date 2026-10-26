<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-1694 bug H — « welcome » devient « affectation-points-suite-inscription ».
 *
 * ## Pourquoi ce nom
 *
 * « welcome » datait d'une epoque ou l'email partait A L'INSCRIPTION. Ce qu'il
 * annonce reellement, c'est l'AFFECTATION DES 100 POINTS — et depuis le bug I
 * il part apres la confirmation d'adresse. Le slug dit desormais ce que l'email
 * ANNONCE, pas un moment qui a change.
 *
 * Nom et slug proposes par Cyril.
 *
 * ## Idempotente, et elle ne touche pas aux textes
 *
 * `update` sur les lignes qui portent encore l'ancien slug. Rejouee, elle ne
 * fait rien. Le contenu reste celui que quelqu'un a pu editer depuis
 * l'administration : seul l'etiquetage change.
 *
 * ## TASK-1709 — son retour ECRASAIT des noms, y compris par Organization
 *
 * Le `down()` ecrivait `name = 'Bienvenue'` **en dur**, sur TOUTES les lignes
 * portant le nouveau slug. Mesure sur un jumeau de la PROD 1.676 : cinq lignes
 * portent `welcome`, avec **trois noms distincts** —
 *
 * | id | portee | locale | nom d'origine |
 * |---|---|---|---|
 * | 1 | GLOBAL | NULL | `Bienvenue (inscription)` |
 * | 5, 13 | `main`, `launchpals` | fr | `Bienvenue` |
 * | 6, 14 | `main`, `launchpals` | en | **`Welcome`** |
 *
 * Apres un aller-retour, les cinq s'appelaient `Bienvenue`. La ligne globale
 * perdait « (inscription) », et **deux lignes par Organization perdaient leur
 * nom anglais** — une alteration de donnee de tenant, dans deux Organizations.
 *
 * Chaque ligne est donc tracee AVANT d'etre renommee, et son `slug` comme son
 * `name` lui sont rendus individuellement.
 */
return new class extends Migration
{
    private const AVANT = 'welcome';

    private const APRES = 'affectation-points-suite-inscription';

    private const NOMS = [
        'fr' => 'Affectation points suite inscription',
        'en' => 'Points granted after signup',
    ];

    private const TRACE = 'system_email_templates_trace';

    /** Qui a ecrit l'instantane : chaque `down()` ne relit que le sien. */
    private const MARQUE = '2026_10_24_100000_rename_welcome_template_to_points_grant';

    private const GESTE = 'renommee';

    private const COLONNES = [
        'slug', 'name', 'subject', 'content_html', 'variables',
        'enabled', 'organization_id', 'locale', 'created_at', 'updated_at',
    ];

    public function up(): void
    {
        $this->assurerLaTrace();

        // Une seule lecture, puis un geste par ligne : c'est la seule facon de
        // tracer CHAQUE ligne avec son propre nom d'origine. La version
        // precedente faisait trois `update` de masse, et ne pouvait donc rien
        // savoir de ce qu'elle ecrasait.
        $lignes = DB::table('system_email_templates')
            ->where('slug', self::AVANT)
            ->get();

        foreach ($lignes as $ligne) {
            $this->tracer($ligne, self::GESTE);

            // Les lignes sans locale (cas historique, dont la ligne GLOBALE)
            // gardent le nom francais : les laisser derriere avec l'ancien slug
            // les rendrait orphelines — plus personne ne les lirait.
            $nom = self::NOMS[$ligne->locale] ?? self::NOMS['fr'];

            DB::table('system_email_templates')
                ->where('id', $ligne->id)
                ->update(['slug' => self::APRES, 'name' => $nom, 'updated_at' => now()]);
        }
    }

    /**
     * Rend a CHAQUE ligne son slug et son nom d'origine.
     *
     * TASK-1709 : la version precedente ecrivait `name = 'Bienvenue'` en dur
     * sur toutes les lignes a la fois. Elle alterait la ligne globale
     * (`Bienvenue (inscription)`) et les deux lignes anglaises par Organization
     * (`Welcome`).
     *
     * Une ligne renommee depuis l'aller n'est pas touchee : on ne rend que ce
     * qui porte encore ce que l'aller a ecrit — le nouveau slug ET le nom que
     * l'aller avait pose. Sinon, quelqu'un a decide autre chose, et sa decision
     * vaut plus que notre retour.
     */
    public function down(): void
    {
        foreach ($this->derniersInstantanes() as $instantane) {
            $vivante = DB::table('system_email_templates')
                ->where('id', $instantane->template_id)
                ->first();

            if ($vivante === null) {
                continue;
            }

            if ((string) $vivante->slug !== self::APRES) {
                continue;
            }

            $nomPose = self::NOMS[$instantane->locale] ?? self::NOMS['fr'];

            if ((string) $vivante->name !== $nomPose) {
                // Le nom a ete change depuis : on rend le slug, pas le nom.
                DB::table('system_email_templates')
                    ->where('id', $instantane->template_id)
                    ->update(['slug' => $instantane->slug, 'updated_at' => now()]);

                continue;
            }

            DB::table('system_email_templates')
                ->where('id', $instantane->template_id)
                ->update([
                    'slug' => $instantane->slug,
                    'name' => $instantane->name,
                    'updated_at' => now(),
                ]);
        }
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
};
