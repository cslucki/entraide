<?php

namespace App\Support\Loops;

use App\Models\Loop;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Js;

/**
 * TASK-1864 — la condition d'affichage d'une Boucle dans le catalogue
 * `/loops`, ecrite UNE fois pour ses deux presentations (cartes et liste).
 *
 * Le catalogue filtre cote client (Alpine : `q`, `domain`, `type` portes par
 * `loops/index.blade.php`). Cette expression vivait dans la carte ; la liste
 * en a besoin a l'identique, et deux copies divergeraient au premier filtre
 * ajoute. Elle est rendue telle qu'avant TASK-1864, au caractere pres.
 *
 * `Js::from()` encode en JSON avec les drapeaux HEX (quotes, chevrons,
 * esperluettes) : la valeur se pose sans risque dans un attribut entre
 * guillemets doubles, d'ou l'`HtmlString` (non re-echappee par Blade).
 */
final class CatalogFilter
{
    public static function xShow(Loop $loop): HtmlString
    {
        $searchable = mb_strtolower($loop->name.' '.($loop->tagline ?? '').' '.($loop->description ?? ''));
        $domainIds = $loop->categories->pluck('id')->all();
        $type = app(LoopTypeRegistry::class)->resolve($loop->type);

        return new HtmlString(
            '(q === \'\' || '.Js::from($searchable).'.includes(q.toLowerCase()))'
            ."\n            && (domain === '' || ".Js::from($domainIds).'.includes(domain))'
            ."\n            && (type === '' || type === ".Js::from($type).')'
        );
    }
}
