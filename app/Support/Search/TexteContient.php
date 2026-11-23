<?php

namespace App\Support\Search;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;

/**
 * TASK-1885 C8/C9 — « la colonne contient ce texte », insensible a la casse,
 * jokers `%` et `_` pris LITTERALEMENT.
 *
 * `ILIKE` sous PostgreSQL, `LIKE` ailleurs (SQLite des tests : insensible a la
 * casse en ASCII). La clause `ESCAPE '\'` est ecrite explicitement : PostgreSQL
 * prend l'antislash par defaut, SQLite n'a AUCUN caractere d'echappement par
 * defaut — sans elle, « 100% » ne se trouvait pas sous SQLite.
 */
final class TexteContient
{
    public static function motif(string $terme): string
    {
        return '%'.addcslashes($terme, '%_\\').'%';
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder  $query
     */
    public static function where($query, string $colonne, string $motif, string $boolean = 'and'): void
    {
        $base = $query instanceof EloquentBuilder ? $query->getQuery() : $query;
        $operateur = $base->getConnection()->getDriverName() === 'pgsql' ? 'ILIKE' : 'LIKE';
        $colonneSql = $base->getGrammar()->wrap($colonne);

        $query->whereRaw($colonneSql.' '.$operateur." ? ESCAPE '\\'", [$motif], $boolean);
    }

    public static function orWhere($query, string $colonne, string $motif): void
    {
        self::where($query, $colonne, $motif, 'or');
    }
}
