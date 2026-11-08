<x-admin-layout :title="__('admin.notifications_show_title')">
    {{-- TASK-1788 correctif 11 — LA fiche d'une notification.

         Elle montre ce que la ligne PORTE, et rien de plus : pas de libelle
         reconstitue, pas de cible resolue. Le libelle d'une notification est
         calcule a l'affichage par le centre du membre, avec SA langue et SON
         contexte ; le recomposer ici donnerait une phrase voisine mais pas
         identique, et un administrateur comparerait deux textes differents en
         croyant lire le meme.

         Les champs sont en lecture seule parce qu'ils le sont VRAIMENT :
         `NotificationInvariants::IMMUTABLE` en fige sept, et un `update()`
         dessus leve une exception. --}}

    @if(session('success'))
        <div class="mb-5 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700 dark:border-emerald-800 dark:bg-emerald-900/20 dark:text-emerald-300">
            {{ session('success') }}
        </div>
    @endif

    <div class="mb-5">
        <a href="{{ route('admin.notifications') }}" class="text-sm font-semibold text-indigo-600 hover:underline dark:text-indigo-400">
            &larr; {{ __('admin.notifications_back') }}
        </a>
    </div>

    <div class="rounded-xl border border-gray-200 bg-white p-5 dark:border-gray-700 dark:bg-gray-900" data-marqueur="fiche-notification">
        <dl class="grid gap-4 sm:grid-cols-2">
            @foreach([
                ['admin.notifications_col_date', $notification->created_at?->isoFormat('LLLL'), 'date'],
                ['admin.notifications_col_type', (\Illuminate\Support\Facades\Lang::has('notifications.keys.'.str_replace('.', '_', $notification->notification_key), null, false)
                    ? __('notifications.keys.'.str_replace('.', '_', $notification->notification_key))
                    : __('notifications.key_fallback')).' — '.$notification->notification_key, 'type'],
                ['admin.notifications_col_user', $notification->recipient?->publicDisplayName() ?? '—', 'destinataire'],
                ['admin.feed_col_organization', $notification->organization?->name ?? '—', 'organisation'],
                ['admin.notifications_col_actor', $notification->actor?->publicDisplayName() ?? '—', 'acteur'],
                ['admin.notifications_col_object', trim(($notification->object_type ?? '—').' '.($notification->object_id ?? '')), 'objet'],
            ] as [$cle, $valeur, $marqueur])
                <div>
                    <dt class="text-xs font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500">{{ __($cle) }}</dt>
                    <dd class="mt-1 break-words text-sm text-gray-800 dark:text-gray-100" data-marqueur="fiche-{{ $marqueur }}">{{ $valeur }}</dd>
                </div>
            @endforeach

            <div>
                <dt class="text-xs font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500">{{ __('admin.notifications_col_channels') }}</dt>
                <dd class="mt-1">@include('admin.notifications._canaux', ['notification' => $notification])</dd>
            </div>

            <div>
                <dt class="text-xs font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500">{{ __('admin.notifications_col_state') }}</dt>
                <dd class="mt-1 text-sm" data-marqueur="fiche-lecture">
                    @if($notification->isRead())
                        <span class="rounded-full bg-gray-100 px-2 py-0.5 text-xs font-semibold text-gray-600 dark:bg-gray-800 dark:text-gray-300">{{ __('admin.notifications_read') }} — {{ $notification->read_at?->isoFormat('lll') }}</span>
                    @else
                        <span class="rounded-full bg-indigo-50 px-2 py-0.5 text-xs font-semibold text-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-200">{{ __('admin.notifications_unread') }}</span>
                    @endif
                </dd>
            </div>
        </dl>

        <p class="mt-5 border-t border-gray-100 pt-4 text-sm text-gray-500 dark:border-gray-800 dark:text-gray-400">{{ __('admin.notifications_immutable_hint') }}</p>

        <div class="mt-4 flex flex-wrap items-center gap-2">
            <form method="POST" action="{{ route('admin.notifications.read', $notification->id) }}">
                @csrf
                @method('PUT')
                <button type="submit" data-marqueur="basculer-lecture"
                        class="rounded-lg border border-indigo-300 px-3 py-1.5 text-xs font-semibold text-indigo-600 hover:bg-indigo-50 dark:border-indigo-700 dark:text-indigo-300 dark:hover:bg-indigo-900/30">
                    {{ $notification->isRead() ? __('admin.notifications_mark_unread') : __('admin.notifications_mark_read') }}
                </button>
            </form>

            <form method="POST" action="{{ route('admin.notifications.destroy', $notification->id) }}"
                  onsubmit="return confirm('{{ __('admin.notifications_delete_confirm') }}')">
                @csrf
                @method('DELETE')
                <button type="submit" data-marqueur="supprimer-notification"
                        class="rounded-lg border border-red-300 px-3 py-1.5 text-xs font-semibold text-red-600 hover:bg-red-50 dark:border-red-800 dark:hover:bg-red-900/30">
                    {{ __('admin.notifications_delete') }}
                </button>
            </form>
        </div>
    </div>
</x-admin-layout>
