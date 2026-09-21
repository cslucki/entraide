{{--
    Les PLUGINS de la conversation — TASK-1616.

    Rendu a cote de « Resume IA », jamais parmi les Cards : un plugin n'entre
    dans aucun socle de type, ne compte pas parmi les trois outils mis en
    avant, et sa disponibilite se decide un cran plus haut (SuperAdmin, par
    Organization). C'est pour cela qu'il a son propre etat et son propre droit.

    Ce partial est partage par les DEUX surfaces — /admin et /org — parce
    qu'elles montrent la meme chose et doivent montrer le meme refus.

    Attendus : $plugins (peut etre vide), $pluginLoop, $pluginActivation,
    $pluginAdminScope, $orgParam (null en portee admin).

    **La Boucle arrive sous le nom `$pluginLoop`, jamais `$loop`.** Blade
    RESERVE `$loop` : apres chaque `@foreach`, il y ecrit
    `$__env->getLastLoop()`, c'est-a-dire `null` hors de toute boucle. Un
    modele nomme `$loop` est donc detruit par la premiere boucle de la vue
    hote — ici la grille des outils, rendue juste au-dessus (lecon TASK-1585).

    Un tableau VIDE signifie « l'Organization n'a pas ce plugin » : la section
    entiere disparait. Elle ne s'affiche pas « eteinte » — « pas autorise » et
    « eteint » ne sont pas le meme etat, et les confondre ferait croire a un
    geste possible.
--}}
@if(($plugins ?? []) !== [])
    @php
        $peutRegler = fn (string $cle) => $pluginActivation->canConfigure(auth()->user(), $cle, $pluginLoop);
    @endphp

    <div class="mt-4 space-y-3">
        @foreach($plugins as $plugin)
            @php
                $reglable = $peutRegler($plugin['key']);
                $urlToggle = $pluginAdminScope
                    ? route('admin.loops.plugins.update', ['loop' => $pluginLoop->id, 'plugin' => $plugin['key']])
                    : route('organization.loops.plugins.update', ['organization' => $orgParam, 'loop' => $pluginLoop->id, 'plugin' => $plugin['key']]);
                $urlConfig = $pluginAdminScope
                    ? route('admin.loops.plugins.configure', ['loop' => $pluginLoop->id, 'plugin' => $plugin['key']])
                    : route('organization.loops.plugins.configure', ['organization' => $orgParam, 'loop' => $pluginLoop->id, 'plugin' => $plugin['key']]);
            @endphp

            <div class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900"
                 data-plugin="{{ $plugin['key'] }}">

                <div class="flex flex-wrap items-start justify-between gap-2">
                    <div class="min-w-0">
                        <p class="flex flex-wrap items-center gap-2 text-sm font-bold text-gray-900 dark:text-gray-100">
                            {{ $plugin['label'] }}
                            @if($plugin['experimental'])
                                <span data-plugin-status="experimental"
                                      class="rounded bg-amber-100 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-amber-800 dark:bg-amber-900/40 dark:text-amber-300">
                                    {{ __('loops.plugins_admin_status_experimental') }}
                                </span>
                            @endif
                        </p>
                        @if($plugin['description'])
                            <p class="mt-1 text-xs leading-5 text-gray-500 dark:text-gray-400">{{ $plugin['description'] }}</p>
                        @endif
                        @if($plugin['decision']?->updated_at)
                            <p class="mt-1 text-[11px] text-gray-400">
                                {{ $plugin['decision']->updatedBy
                                    ? __('loops.plugins_loop_last_change', [
                                        'date' => $plugin['decision']->updated_at->format('d/m/Y H:i'),
                                        'author' => $plugin['decision']->updatedBy->name,
                                      ])
                                    : __('loops.plugins_loop_last_change_anonymous', [
                                        'date' => $plugin['decision']->updated_at->format('d/m/Y H:i'),
                                      ]) }}
                            </p>
                        @endif
                    </div>

                    <span data-plugin-state="{{ $plugin['enabled'] ? 'on' : 'off' }}"
                          class="shrink-0 rounded-full px-2.5 py-1 text-xs font-medium
                                 {{ $plugin['enabled']
                                    ? 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-300'
                                    : 'bg-gray-100 text-gray-500 dark:bg-gray-700 dark:text-gray-400' }}">
                        {{ $plugin['enabled'] ? __('loops.plugins_loop_active') : __('loops.plugins_loop_inactive') }}
                    </span>
                </div>

                @if($reglable)
                    <div class="mt-3 flex flex-wrap items-center gap-2">
                        <form method="POST" action="{{ $urlToggle }}">
                            @csrf
                            @method('PUT')
                            <input type="hidden" name="enabled" value="{{ $plugin['enabled'] ? 0 : 1 }}">
                            <button type="submit"
                                    data-action="{{ $plugin['enabled'] ? 'disable' : 'enable' }}"
                                    class="min-h-[44px] rounded-xl px-4 text-sm font-medium
                                           {{ $plugin['enabled']
                                              ? 'border border-gray-300 text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700'
                                              : 'bg-indigo-600 text-white hover:bg-indigo-700' }}">
                                {{ $plugin['enabled'] ? __('loops.plugins_loop_disable') : __('loops.plugins_loop_enable') }}
                            </button>
                        </form>

                        <a href="{{ $urlConfig }}" data-action="configure"
                           class="inline-flex min-h-[44px] items-center rounded-xl border border-gray-300 px-4 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700">
                            {{ __('loops.plugins_loop_configure') }}
                        </a>
                    </div>
                @endif
            </div>
        @endforeach
    </div>
@endif
