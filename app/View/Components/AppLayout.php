<?php

namespace App\View\Components;

use Illuminate\View\Component;
use Illuminate\View\View;

/**
 * TASK-1611 — le layout applicatif accepte desormais les METADONNEES d'une
 * page, en plus de son titre.
 *
 * `layouts.app` savait deja les rendre depuis TASK-1610 (`$ogTitle`,
 * `$ogDescription`, `$ogUrl`, `$ogImage`), mais AUCUNE page ne pouvait les lui
 * transmettre : ce composant ne portait que `title`, et le layout ne voit que
 * les proprietes publiques du composant. Toutes les pages tombaient donc sur
 * les replis de marque, y compris la page publique d'un atelier.
 *
 * La doctrine de TASK-1610 est conservee entiere : ces valeurs sont
 * DECLARATIVES. Le layout n'en deduit aucune de la requete — `$ogUrl` et
 * `$canonicalUrl` restent `null` tant qu'une page ne les fournit pas, et
 * `@isset` les tait. Une page de refus d'acces, qui ne recoit pas la
 * ressource, ne peut toujours rien en dire.
 */
class AppLayout extends Component
{
    public function __construct(
        public string $title = '',
        public string $description = '',
        public ?string $ogTitle = null,
        public ?string $ogDescription = null,
        public ?string $ogUrl = null,
        public ?string $ogImage = null,
        public ?string $ogImageAlt = null,
        public ?int $ogImageWidth = null,
        public ?int $ogImageHeight = null,
        public ?string $ogImageType = null,
        public ?string $twitterCard = null,
        public ?string $canonicalUrl = null,
    ) {}

    public function render(): View
    {
        return view('layouts.app');
    }
}
