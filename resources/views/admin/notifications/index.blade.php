<x-admin-layout :title="__('admin.notifications_admin_title')">
    {{-- TASK-1788 correctif 11 — toutes les notifications, tous tenants.

         Ecrit sur le gabarit d'`admin/feed/comments` : memes filtres, meme
         tableau, memes gestes. Aucun second systeme.

         ## Les en-tetes sont des LIENS de tri

         Date, Type et Destinataire se trient, dans les deux sens. Le lien
         conserve les filtres courants : trier ne doit jamais elargir la
         selection qu'on vient de poser. --}}

    @if(session('success'))
        <div class="mb-5 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700 dark:border-emerald-800 dark:bg-emerald-900/20 dark:text-emerald-300">
            {{ session('success') }}
        </div>
    @endif

    {{-- Dit, une fois et en haut, ce que la colonne « Actions » ne propose
         pas : la correction des faits. Sans cette phrase, l'absence de bouton
         « Modifier » passe pour un oubli. --}}
    <p class="mb-5 rounded-xl border border-gray-200 bg-gray-50 px-4 py-3 text-sm text-gray-600 dark:border-gray-700 dark:bg-gray-800/60 dark:text-gray-300"
       data-marqueur="note-immuable">{{ __('admin.notifications_immutable_hint') }}</p>

    @php
        // TASK-1788 correctif 11 (demande Cyril) — le TYPE, en francais.
        // « transaction.proposal_created » ne parle a personne.
        //
        // Le libelle vient de `notifications.keys.<cle>`, la MEME autorite que
        // le centre de notifications du membre : deux ecrans qui nommeraient
        // differemment la meme chose feraient croire a deux evenements.
        // `Lang::has()` sans repli de locale, comme la-bas — une cle presente
        // dans une seule langue ne doit pas s'afficher dans l'autre — et le
        // meme repli `key_fallback` sinon.
        //
        // La cle TECHNIQUE reste affichee en dessous : c'est elle qu'on cherche
        // quand on lit un journal ou un catalogue.
        //
        // ⚠️ Commentaire PHP et non `{{--`, qui n'est PAS interprete a
        // l'interieur d'un bloc `@php` : il y produit une erreur d'analyse.
        // Paye sur place.
        $libelleType = function (string $cle) {
            $traduction = 'notifications.keys.'.str_replace('.', '_', $cle);

            return \Illuminate\Support\Facades\Lang::has($traduction, null, false)
                ? __($traduction)
                : __('notifications.key_fallback');
        };
        $lienTri = fn (string $colonne) => route('admin.notifications', array_filter([
            'organization_id' => $organizationId,
            'user_id' => $userId,
            'tri' => $colonne,
            // Recliquer la colonne active renverse le sens ; une autre colonne
            // repart du plus recent, qui est ce qu'on veut voir en premier.
            'sens' => $tri === $colonne && $sens === 'desc' ? 'asc' : 'desc',
        ]));
        $fleche = fn (string $colonne) => $tri === $colonne ? ($sens === 'asc' ? ' ↑' : ' ↓') : '';
    @endphp

    <form method="GET" class="mb-5 flex flex-wrap items-end gap-3">
        {{-- Le tri voyage avec les filtres : changer d'Organization ne doit pas
             renvoyer l'ordre a son defaut. --}}
        <input type="hidden" name="tri" value="{{ $tri }}">
        <input type="hidden" name="sens" value="{{ $sens }}">

        <div>
            <label for="organization_id" class="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400">{{ __('admin.feed_filter_organization') }}</label>
            <select name="organization_id" id="organization_id" data-marqueur="filtre-organisation"
                    class="rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100">
                <option value="">{{ __('admin.feed_all_organizations') }}</option>
                @foreach($organizations as $org)
                    <option value="{{ $org->id }}" {{ $organizationId === $org->id ? 'selected' : '' }}>{{ $org->name }}</option>
                @endforeach
            </select>
        </div>

        <div>
            <label for="user_id" class="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400">{{ __('admin.notifications_col_user') }}</label>
            <select name="user_id" id="user_id" data-marqueur="filtre-destinataire"
                    class="rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100">
                <option value="">{{ __('admin.notifications_filter_recipient') }}</option>
                @foreach($destinataires as $destinataire)
                    <option value="{{ $destinataire->id }}" {{ $userId === $destinataire->id ? 'selected' : '' }}>{{ $destinataire->publicDisplayName() }}</option>
                @endforeach
            </select>
        </div>

        <button type="submit" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-indigo-700">{{ __('admin.feed_filter') }}</button>
    </form>

    <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-900">
        <table class="min-w-full text-sm">
            <thead class="bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500 dark:bg-gray-800 dark:text-gray-400">
                <tr>
                    <th class="px-4 py-3"><a href="{{ $lienTri('date') }}" data-marqueur="tri-date" class="hover:underline">{{ __('admin.notifications_col_date') }}{{ $fleche('date') }}</a></th>
                    <th class="px-4 py-3"><a href="{{ $lienTri('type') }}" data-marqueur="tri-type" class="hover:underline">{{ __('admin.notifications_col_type') }}{{ $fleche('type') }}</a></th>
                    <th class="px-4 py-3"><a href="{{ $lienTri('user') }}" data-marqueur="tri-user" class="hover:underline">{{ __('admin.notifications_col_user') }}{{ $fleche('user') }}</a></th>
                    <th class="px-4 py-3">{{ __('admin.feed_col_organization') }}</th>
                    <th class="px-4 py-3">{{ __('admin.notifications_col_channels') }}</th>
                    <th class="px-4 py-3">{{ __('admin.notifications_col_state') }}</th>
                    <th class="px-4 py-3 text-right">{{ __('admin.feed_col_actions') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                @forelse($notifications as $notification)
                    <tr data-marqueur="ligne-notification" data-notification="{{ $notification->id }}">
                        <td class="px-4 py-3 align-top text-xs text-gray-400">{{ $notification->created_at?->isoFormat('lll') }}</td>
                        <td class="px-4 py-3 align-top">
                            <span class="block text-gray-800 dark:text-gray-100" data-marqueur="type-libelle">{{ $libelleType($notification->notification_key) }}</span>
                            <code class="mt-1 block text-[11px] text-gray-400 dark:text-gray-500"
                                  data-marqueur="type-notification">{{ $notification->notification_key }}</code>
                        </td>
                        <td class="px-4 py-3 align-top text-gray-700 dark:text-gray-200">{{ $notification->recipient?->publicDisplayName() ?? '—' }}</td>
                        <td class="px-4 py-3 align-top text-gray-600 dark:text-gray-300">{{ $notification->organization?->name ?? '—' }}</td>
                        <td class="px-4 py-3 align-top">
                            @include('admin.notifications._canaux', ['notification' => $notification])
                        </td>
                        <td class="px-4 py-3 align-top">
                            @if($notification->isRead())
                                <span class="rounded-full bg-gray-100 px-2 py-0.5 text-[11px] font-semibold text-gray-600 dark:bg-gray-800 dark:text-gray-300">{{ __('admin.notifications_read') }}</span>
                            @else
                                <span class="rounded-full bg-indigo-50 px-2 py-0.5 text-[11px] font-semibold text-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-200">{{ __('admin.notifications_unread') }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 align-top text-right">
                            <div class="inline-flex flex-wrap items-center justify-end gap-2">
                                <a href="{{ route('admin.notifications.show', $notification->id) }}"
                                   data-marqueur="voir-notification"
                                   class="rounded-lg border border-gray-300 px-3 py-1 text-xs font-semibold text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-800">
                                    {{ __('admin.notifications_view') }}
                                </a>

                                {{-- « Modifier » = l'etat de lecture. C'est le SEUL
                                     champ que le modele laisse evoluer. --}}
                                <form method="POST" action="{{ route('admin.notifications.read', $notification->id) }}">
                                    @csrf
                                    @method('PUT')
                                    <button type="submit" data-marqueur="basculer-lecture"
                                            class="rounded-lg border border-indigo-300 px-3 py-1 text-xs font-semibold text-indigo-600 hover:bg-indigo-50 dark:border-indigo-700 dark:text-indigo-300 dark:hover:bg-indigo-900/30">
                                        {{ $notification->isRead() ? __('admin.notifications_mark_unread') : __('admin.notifications_mark_read') }}
                                    </button>
                                </form>

                                <form method="POST" action="{{ route('admin.notifications.destroy', $notification->id) }}"
                                      onsubmit="return confirm('{{ __('admin.notifications_delete_confirm') }}')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" data-marqueur="supprimer-notification"
                                            class="rounded-lg border border-red-300 px-3 py-1 text-xs font-semibold text-red-600 hover:bg-red-50 dark:border-red-800 dark:hover:bg-red-900/30">
                                        {{ __('admin.notifications_delete') }}
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-4 py-8 text-center text-gray-500 dark:text-gray-400">{{ __('admin.notifications_empty') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-5">{{ $notifications->links() }}</div>
</x-admin-layout>
