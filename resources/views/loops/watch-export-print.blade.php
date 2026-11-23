{{--
    TASK-1879 (demande Cyril, 22/11) — la Veille d'une Boucle en TABLEAU
    imprimable : « Imprimer » puis « Enregistrer en PDF » dans le navigateur.
    Page autonome, sans script tiers ; la boite d'impression s'ouvre seule.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ __('veille.export_print_title', ['loop' => $loop->name]) }}</title>
    <style>
        @page { size: A4 landscape; margin: 12mm; }
        body { font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; color: #111827; background: #fff; margin: 16px; font-size: 12px; }
        h1 { font-size: 18px; margin: 0 0 4px; }
        .meta { color: #6b7280; margin: 0 0 12px; }
        .actions { margin-bottom: 12px; }
        .actions button { min-height: 44px; padding: 0 16px; border-radius: 10px; border: 0; background: #0284c7; color: #fff; font-weight: 600; cursor: pointer; }
        table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        th, td { border: 1px solid #d1d5db; padding: 6px; vertical-align: top; text-align: left; word-wrap: break-word; overflow-wrap: anywhere; white-space: pre-line; }
        th { background: #f3f4f6; font-size: 11px; text-transform: uppercase; letter-spacing: .03em; }
        tr { page-break-inside: avoid; }
        .c-date { width: 8%; } .c-cat { width: 11%; } .c-sujet { width: 17%; } .c-info { width: 22%; } .c-impact { width: 20%; } .c-source { width: 12%; } .c-auteur { width: 10%; }
        .vide { color: #6b7280; padding: 24px 0; }
        @media print { .actions { display: none; } body { margin: 0; } }
    </style>
</head>
<body>
    <h1>{{ __('veille.export_print_title', ['loop' => $loop->name]) }}</h1>
    <p class="meta">
        {{ __('veille.export_print_meta', ['date' => now()->isoFormat('LL'), 'count' => $items->count()]) }}
        @if($categorie) · {{ __('veille.field_category') }} : {{ $categorie->name }} @endif
    </p>

    <div class="actions">
        <button type="button" onclick="window.print()">{{ __('veille.export_print_button') }}</button>
    </div>

    @if($items->isEmpty())
        <p class="vide">{{ __('loops.cards.veille.empty_title') }}</p>
    @else
        <table data-watch-export-table>
            <thead>
                <tr>
                    <th class="c-date">{{ __('veille.field_date') }}</th>
                    <th class="c-cat">{{ __('veille.field_category') }}</th>
                    <th class="c-sujet">{{ __('veille.field_subject') }}</th>
                    <th class="c-info">{{ __('veille.field_information') }}</th>
                    <th class="c-impact">{{ __('veille.field_impact') }}</th>
                    <th class="c-source">{{ __('veille.field_source') }}</th>
                    <th class="c-auteur">{{ __('veille.export_author') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach($items as $item)
                    <tr>
                        <td>{{ $item->observed_on->format('d/m/Y') }}</td>
                        <td>{{ $item->category?->name }}</td>
                        <td>{{ $item->subject }}</td>
                        <td>{{ $item->information }}</td>
                        <td>{{ $item->impact }}</td>
                        <td>{{ $item->source_url }}</td>
                        <td>{{ $item->author?->name ?? __('veille.author_unknown') }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <script>window.addEventListener('load', () => setTimeout(() => window.print(), 300));</script>
</body>
</html>
