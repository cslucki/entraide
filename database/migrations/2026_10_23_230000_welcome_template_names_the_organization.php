<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-1694 bug E — le gabarit « Bienvenue » nomme l'ORGANIZATION, plus la
 * marque.
 *
 * ## Le defaut, signale en recette
 *
 * L'email d'accueil disait « Bienvenue sur Entraide, {{ name }} ! » a quelqu'un
 * qui venait de rejoindre « Organismes de formation solidaires et humanistes ».
 * La marque n'est pas ce que la personne a rejoint.
 *
 * ## La racine : la ligne STOCKEE, pas le code
 *
 * `SystemEmailTemplateSeeder` definit pourtant
 * « Bienvenue sur {{ organization }}, {{ name }} ! ». La ligne globale en base
 * portait, elle, la marque en dur — un texte plus ancien que la definition, ou
 * edite depuis l'administration. Mesure : UNE seule ligne sur 28 citait
 * « Entraide ».
 *
 * ## Ce que cette migration ne fait pas
 *
 * Elle ne touche QUE la chaine exacte « Bienvenue sur Entraide, » et seulement
 * sur la ligne GLOBALE de `welcome`. Un remplacement large aurait reecrit des
 * textes que quelqu'un a pu vouloir ainsi — et une migration qui reecrit une
 * intention humaine detruit du travail sans le dire.
 *
 * ## TASK-1709 — son retour etait incomplet
 *
 * Mesure sur un jumeau de la PROD 1.676 : l'`up()` changeait le `subject` ET le
 * `content_html`, mais le `down()` ne rendait que le `subject`. Apres un
 * aller-retour, le corps gardait `{{ organisation }}` — soit un texte que
 * personne n'avait demande a cet etat de la base.
 *
 * La ligne entiere est donc tracee AVANT la mutation, et rendue au `down()`.
 */
return new class extends Migration
{
    private const AVANT = 'Bienvenue sur Entraide, {{ name }} !';

    private const APRES = 'Bienvenue sur {{ organisation }}, {{ name }} !';

    private const TRACE = 'system_email_templates_trace';

    /** Qui a ecrit l'instantane : chaque `down()` ne relit que le sien. */
    private const MARQUE = '2026_10_23_230000_welcome_template_names_the_organization';

    private const GESTE = 'texte-reecrit';

    private const MARQUE_CORPS = 'Bienvenue sur {{ organisation }},';

    private const COLONNES = [
        'slug', 'name', 'subject', 'content_html', 'variables',
        'enabled', 'organization_id', 'locale', 'created_at', 'updated_at',
    ];

    public function up(): void
    {
        $this->assurerLaTrace();

        $ligne = DB::table('system_email_templates')
            ->where('slug', 'welcome')
            ->whereNull('organization_id')
            ->first();

        if ($ligne === null) {
            return;
        }

        $sujet = (string) $ligne->subject === self::AVANT ? self::APRES : (string) $ligne->subject;

        // DEUX marques, et c'est la mesure qui l'a dit : le SUJET portait
        // « Entraide », le CORPS « BouclePro ». Chercher la seule marque vue
        // dans la capture aurait laisse l'autre en place — et le titre de
        // l'email aurait continue de nommer la plateforme.
        $corps = (string) $ligne->content_html;

        foreach (['Bienvenue sur Entraide,', 'Bienvenue sur BouclePro,'] as $marque) {
            $corps = str_replace($marque, self::MARQUE_CORPS, $corps);
        }

        if ($sujet === (string) $ligne->subject && $corps === (string) $ligne->content_html) {
            // Rien a changer, donc rien a tracer : un instantane sans mutation
            // ferait croire au `down()` qu'il a quelque chose a rendre.
            return;
        }

        $this->tracer($ligne, self::GESTE);

        DB::table('system_email_templates')
            ->where('id', $ligne->id)
            ->update(['subject' => $sujet, 'content_html' => $corps, 'updated_at' => now()]);
    }

    /**
     * Rend le sujet ET le corps d'origine — champ par champ.
     *
     * TASK-1709 : la version precedente ne rendait que le `subject`. Le corps
     * gardait `{{ organisation }}` apres un aller-retour, mesure sur un jumeau
     * de la PROD 1.676.
     *
     * Chaque champ est rendu SEPAREMENT, et seulement s'il porte encore ce que
     * l'aller a ecrit. Quelqu'un qui a retouche le corps depuis le garde, et son
     * sujet revient quand meme s'il est reste celui de l'aller. Un `down()`
     * tout-ou-rien aurait fait perdre l'un pour sauver l'autre.
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

            $aRendre = [];

            if ((string) $vivante->subject === self::APRES) {
                $aRendre['subject'] = $instantane->subject;
            }

            if (str_contains((string) $vivante->content_html, self::MARQUE_CORPS)) {
                $aRendre['content_html'] = $instantane->content_html;
            }

            if ($aRendre === []) {
                continue;
            }

            // TASK-1709 — l'horodatage suit la FIDELITE de la restauration,
            // jugee sur la LIGNE ENTIERE et non sur les seuls champs mutes ici.
            //
            // Une ligne rendue a l'identique ne doit pas se declarer modifiee :
            // c'est le dernier ecart que la garde de fidelite globale a trouve.
            // Mais une ligne qui porte encore la retouche de quelqu'un ne doit
            // pas non plus etre ANTIDATEE a son etat d'avant l'aller, sans quoi
            // cette decision devient invisible.
            //
            // Ce `down()` tourne en DERNIER des trois, donc c'est lui qui
            // gouverne l'horodatage final — y compris quand c'est une AUTRE
            // migration du lot qui a conserve une valeur humaine. Juger sur
            // `$aRendre` seul ne le voyait pas : il faut comparer la ligne
            // projetee a l'instantane, champ par champ.
            DB::table('system_email_templates')
                ->where('id', $instantane->template_id)
                ->update($aRendre + [
                    'updated_at' => $this->redevientIdentique($vivante, $aRendre, $instantane)
                        ? $instantane->updated_at
                        : now(),
                ]);
        }
    }

    /**
     * La ligne, une fois `$aRendre` applique, redevient-elle INTEGRALEMENT
     * celle de l'instantane ?
     *
     * `updated_at` est exclu de la comparaison : c'est precisement lui qu'on
     * decide. Tout le reste entre, y compris les champs que cette migration ne
     * mute pas — un `name` laisse en place par le `down()` de `rename` parce
     * qu'un humain l'avait choisi est exactement le cas que ce predicat doit
     * voir.
     *
     * La comparaison est faite sur la ligne PROJETEE, pas relue apres coup : un
     * second `select` couterait un aller-retour par ligne et ne dirait rien de
     * plus.
     */
    private function redevientIdentique(object $vivante, array $aRendre, object $instantane): bool
    {
        foreach (self::COLONNES as $colonne) {
            if ($colonne === 'updated_at') {
                continue;
            }

            $apres = array_key_exists($colonne, $aRendre)
                ? $aRendre[$colonne]
                : ($vivante->{$colonne} ?? null);

            if ($this->normaliser($colonne, $apres) !== $this->normaliser($colonne, $instantane->{$colonne} ?? null)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Une valeur sous une forme comparable entre la table et la trace.
     *
     * Deux colonnes le demandent, et aucune des deux n'est un ecart de donnee :
     * `created_at` est un `timestamp` des deux cotes mais le pilote peut rendre
     * des precisions differentes, et `enabled` est un booleen que les moteurs
     * et les pilotes expriment differemment. Comparer les chaines brutes ferait
     * repondre NON a ce predicat pour une raison qui ne concerne pas la
     * fidelite — et la ligne serait horodatee `now()` alors qu'elle est bien
     * rendue.
     *
     * `enabled` ne passe PAS par `filter_var(…, FILTER_VALIDATE_BOOLEAN)` :
     * cette fonction rend `false` pour `'t'` **comme pour** `'f'`, donc deux
     * valeurs opposees se normaliseraient en `'0'` et le predicat les jugerait
     * egales — c'est-a-dire qu'il antidaterait une ligne qui a change. La
     * colonne est un `boolean` des deux cotes, donc la liste ci-dessous est
     * exhaustive, et tout ce qui n'y figure pas est faux.
     *
     * Avec le pilote de ce projet (`PostgresConnector` pose
     * `ATTR_STRINGIFY_FETCHES => false`), la valeur arrive en `bool` natif et
     * `'t'` ne se produit pas. Ce n'est donc pas un defaut observable qu'on
     * corrige ici, et aucun test ne peut le rougir : c'est le docblock
     * precedent qui promettait de couvrir `'t'` sans le faire.
     */
    private function normaliser(string $colonne, mixed $valeur): string
    {
        if ($valeur === null) {
            return '<NULL>';
        }

        if ($colonne === 'created_at') {
            return Carbon::parse((string) $valeur)->format('Y-m-d H:i:s');
        }

        if ($colonne === 'enabled') {
            return in_array($valeur, [true, 1, '1', 't', 'true'], true) ? '1' : '0';
        }

        return (string) $valeur;
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
