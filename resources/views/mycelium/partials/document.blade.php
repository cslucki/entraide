{{--
    TASK-1349 — mise en page d'un texte de Constitution.

    Le texte est ADMINISTRABLE : quelqu'un l'a ecrit a la main, et on ne sait
    rien de sa forme. La mise en page reste donc purement presentationnelle et
    ne reecrit AUCUN mot : les lignes commencant par un tiret deviennent des
    items, les lignes vides separent les blocs, tout le reste est un
    paragraphe. Un texte qui n'utiliserait aucune de ces conventions s'affiche
    simplement en paragraphes — jamais en erreur.

    @param string $text
--}}
@php
    $blocs = [];
    foreach (preg_split('/\R/', trim((string) $text)) as $ligne) {
        $ligne = trim($ligne);

        if ($ligne === '') {
            $blocs[] = ['type' => 'saut'];

            continue;
        }

        if (preg_match('/^[-–—•*]\s+(.+)$/u', $ligne, $m)) {
            // Items consecutifs : une seule liste, pas une liste par ligne.
            if (($blocs[count($blocs) - 1]['type'] ?? null) === 'liste') {
                $blocs[count($blocs) - 1]['items'][] = $m[1];

                continue;
            }

            $blocs[] = ['type' => 'liste', 'items' => [$m[1]]];

            continue;
        }

        $blocs[] = ['type' => 'para', 'text' => $ligne];
    }

@endphp

<div class="bp-doc" data-mycelium-document>
    @foreach($blocs as $bloc)
        @if($bloc['type'] === 'liste')
            <ul class="mt-4 space-y-3">
                @foreach($bloc['items'] as $item)
                    <li class="flex gap-3 text-[15px] leading-7 text-[var(--bp-text)]">
                        <span class="mt-[0.6rem] h-1.5 w-1.5 flex-shrink-0 rounded-full"
                              style="background: var(--bp-primary); opacity: .65" aria-hidden="true"></span>
                        <span>{{ $item }}</span>
                    </li>
                @endforeach
            </ul>
        @elseif($bloc['type'] === 'para')
            {{-- Tous les paragraphes au meme rang : distinguer le premier
                 inverserait l'emphase des que le texte s'ouvre sur un titre
                 administratif plutot que sur sa phrase de fond. --}}
            <p class="mt-4 text-[15px] leading-7 text-[var(--bp-text)] first:mt-0">{{ $bloc['text'] }}</p>
        @endif
    @endforeach
</div>
