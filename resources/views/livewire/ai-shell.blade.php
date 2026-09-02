{{--
    TASK-1315 — Shell « BouclePro IA ».

    Ce qui survit a la navigation n'est PAS l'etat de ce composant : chaque page
    est un rechargement complet (aucun `wire:navigate` dans l'application), donc
    le composant est remonte a chaque fois. Ce qui survit, c'est le FIL, relu en
    base a chaque montage. L'ouverture du panneau, elle, est une commodite de
    confort : memorisee en `localStorage` sur desktop uniquement — sur mobile le
    Shell est une feuille plein ecran, et la rouvrir automatiquement a chaque
    page emprisonnerait l'utilisateur devant sa propre navigation.

    T1244.BUG : aucun `x-transition` sur un `x-show`. Alpine ferait alors
    dependre le basculement de `display` d'une sequence requestAnimationFrame,
    gelee tant que `document.hidden` est vrai — le panneau resterait
    `display: none` malgre `open = true`.
--}}
<div data-bp-shell-mount>
@if($shell === null)
    <span data-ai-shell-disabled hidden></span>
@else
<div x-data="{
        open: false,
        desktop() { return window.matchMedia('(min-width: 768px)').matches; },
        init() {
            try { this.open = this.desktop() && window.localStorage.getItem('bp-ai-shell-open') === '1'; } catch (e) { this.open = false; }
            this.$watch('open', (value) => {
                try { window.localStorage.setItem('bp-ai-shell-open', value && this.desktop() ? '1' : '0'); } catch (e) {}
                if (value) { this.toEnd(); }
            });
            if (this.open) { this.toEnd(); }
        },
        toEnd() {
            this.$nextTick(() => { if (this.$refs.log) { this.$refs.log.scrollTop = this.$refs.log.scrollHeight; } });
        },
        show() { this.open = true; this.$nextTick(() => this.$refs.composer?.focus()); },
        close() { this.open = false; },
    }"
    @bp-open-ai-shell.window="show()"
    @ai-shell-updated.window="toEnd()"
    @keydown.escape.window="close()"
    data-ai-shell
    :data-ai-shell-open="open ? 'true' : 'false'"
    data-ai-shell-context-kind="{{ $shell['context']['kind'] ?? 'other' }}"
    data-ai-shell-conversation="{{ $shell['conversation_id'] }}"
    data-ai-shell-thread-count="{{ $shell['messages']->count() }}"
    class="print:hidden">

    <div x-show="open" x-cloak
         role="dialog"
         aria-modal="false"
         aria-labelledby="ai-shell-title"
         data-ai-shell-panel
         class="fixed inset-x-0 bottom-0 top-14 z-50 flex flex-col overflow-hidden rounded-t-2xl border border-gray-200 bg-white shadow-2xl dark:border-gray-700 dark:bg-gray-800
                md:inset-x-auto md:top-auto md:right-6 md:bottom-40 md:h-[34rem] md:w-[26rem] md:rounded-2xl">

        {{-- En-tete : qui parle, et OU l'on se trouve. --}}
        <div class="flex items-start justify-between gap-3 border-b border-gray-100 px-4 pt-4 pb-3 dark:border-gray-700">
            <div class="min-w-0">
                <p id="ai-shell-title" class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ __('ai.shell_title') }}</p>
                <p class="mt-0.5 flex items-center gap-1.5 text-xs text-gray-500 dark:text-gray-400" data-ai-shell-context-label>
                    <svg class="h-3.5 w-3.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 21s7-4.35 7-10a7 7 0 1 0-14 0c0 5.65 7 10 7 10Z"/><circle cx="12" cy="11" r="2.5"/></svg>
                    <span class="truncate">{{ $shell['context']['label'] ?? '' }}</span>
                </p>
            </div>
            <button type="button" @click="close()" data-ai-shell-close
                    class="rounded-md p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-600 dark:hover:bg-gray-700 dark:hover:text-gray-200"
                    aria-label="{{ __('ai.shell_close') }}">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/></svg>
            </button>
        </div>

        {{-- TASK-1326 — le contexte epingle : visible, retirable, borne. La
             liste rendue ici est EXACTEMENT celle que le prochain tour recevra
             (memes pins, re-resolus par AiShellPinnedContext au meme rendu) —
             aucune source cachee. Noms et URLs sont relus a l'instant, jamais
             stockes ; un pin dont l'objet ne passe plus sa garde a deja ete
             retire avant d'arriver ici. --}}
        @if(count($shell['pins']) > 0 || $shell['pinnable'] !== null)
            <div class="border-b border-gray-100 px-4 py-2 dark:border-gray-700" data-ai-shell-pins data-ai-shell-pins-count="{{ count($shell['pins']) }}">
                <div class="flex items-center justify-between gap-2">
                    <p class="text-[11px] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('ai.shell_pins_title') }}</p>
                    @if($shell['pinnable'] !== null)
                        <button type="button"
                                wire:click="pin('{{ $shell['pinnable']['kind'] }}', '{{ $shell['pinnable']['id'] }}')"
                                data-ai-shell-pin-add
                                class="inline-flex max-w-[60%] items-center gap-1 rounded-full border border-indigo-200 bg-indigo-50 px-2.5 py-1 text-[11px] font-semibold text-indigo-700 hover:bg-indigo-100 dark:border-indigo-800/60 dark:bg-indigo-900/30 dark:text-indigo-200">
                            <svg class="h-3 w-3 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 17v5m-5-5 1.2-6.2a2 2 0 0 0-.6-1.9L6 7.4a1 1 0 0 1 .7-1.7h10.6a1 1 0 0 1 .7 1.7l-1.6 1.5a2 2 0 0 0-.6 1.9L17 17H7Z"/></svg>
                            <span class="truncate">{{ __('ai.shell_pin_add') }}</span>
                        </button>
                    @endif
                </div>
                @if(count($shell['pins']) > 0)
                    <ul class="mt-1.5 flex flex-wrap gap-1.5">
                        @foreach($shell['pins'] as $pin)
                            <li wire:key="ai-shell-pin-{{ $pin['kind'] }}-{{ $pin['id'] }}"
                                data-ai-shell-pin="{{ $pin['kind'] }}:{{ $pin['id'] }}"
                                class="inline-flex max-w-full items-center gap-1.5 rounded-full border border-gray-200 bg-gray-50 py-0.5 pl-1.5 pr-1 dark:border-gray-600 dark:bg-gray-700/60">
                                <span class="rounded bg-white px-1 py-0.5 text-[9px] font-semibold uppercase tracking-wide text-gray-500 dark:bg-gray-800 dark:text-gray-300">
                                    @if($pin['kind'] === \App\Support\Ai\AiShellPageContext::KIND_LOOP){{ __('ai.shell_card_loop_badge') }}@elseif($pin['kind'] === \App\Support\Ai\AiShellPageContext::KIND_DOSSIER){{ __('ai.shell_card_document_badge_dossier') }}@else{{ __('ai.shell_card_document_badge_article') }}@endif
                                </span>
                                <a href="{{ $pin['url'] }}" class="max-w-[11rem] truncate text-xs font-medium text-gray-700 hover:underline dark:text-gray-200">{{ $pin['label'] }}</a>
                                <button type="button"
                                        wire:click="unpin('{{ $pin['kind'] }}', '{{ $pin['id'] }}')"
                                        data-ai-shell-pin-remove
                                        aria-label="{{ __('ai.shell_pin_remove') }}"
                                        class="rounded-full p-0.5 text-gray-400 hover:bg-gray-200 hover:text-gray-600 dark:hover:bg-gray-600 dark:hover:text-gray-200">
                                    <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/></svg>
                                </button>
                            </li>
                        @endforeach
                    </ul>
                    <p class="mt-1 text-[10px] leading-4 text-gray-400 dark:text-gray-500" data-ai-shell-pins-note>{{ __('ai.shell_pins_note') }}</p>
                @endif
            </div>
        @endif

        {{-- Le fil. --}}
        <div class="flex-1 overflow-y-auto px-4 py-3" x-ref="log" data-ai-shell-log>
            @if($shell['messages']->isEmpty())
                <div class="py-8 text-center" data-ai-shell-empty>
                    <p class="text-sm font-medium text-gray-700 dark:text-gray-200">{{ __('ai.shell_empty_title') }}</p>
                    <p class="mx-auto mt-1 max-w-xs text-xs text-gray-500 dark:text-gray-400">{{ __('ai.shell_empty_hint') }}</p>
                </div>
            @else
                <ul class="space-y-3">
                    @foreach($shell['messages'] as $message)
                        @php
                            $turnCards = $shell['cards'][(string) $message->id] ?? [];

                            $meta = is_array($message->metadata) ? $message->metadata : [];
                            $isAnswered = ($meta['status'] ?? null) === \App\Services\Ai\AiShellResponder::STATUS_ANSWERED;

                            // TASK-1350 (P0) — un tour qui porte une INTENTION valide.
                            // Le brouillon du clarificateur est ecrit a la premiere
                            // personne : c'est le futur texte DE L'UTILISATEUR, pas la
                            // parole de BouclePro IA. On le sort donc de la bulle de
                            // l'assistant pour l'attribuer.
                            //
                            // La recette a montre que la coupe ne pouvait pas s'arreter
                            // aux demandes : le modele peut qualifier « Je cherche un
                            // relecteur » en `service_offer`, et la branche OFFRE
                            // reaffichait alors le brouillon comme parole de l'IA —
                            // exactement le defaut qu'on ferme. Une offre est ecrite a la
                            // premiere personne tout autant qu'une demande. Seuls le
                            // cadrage, l'intitule et l'appel a l'action changent.
                            //
                            // La condition exige la PRESENCE de `intent` : les tours
                            // ecrits avant TASK-1350 n'en portent pas et gardent leur
                            // rendu, conformement au scope fige (« messages historiques
                            // ANSWERED : inchanges »).
                            $isUserDraft = $message->role === \App\Models\AiShellMessage::ROLE_ASSISTANT
                                && $isAnswered
                                && array_key_exists('intent', $meta);

                            $isOfferDraft = $isUserDraft
                                && $meta['intent'] === \App\Support\Ai\AiShellTurnCards::INTENT_OFFER;

                            $requestDraftBody = $isUserDraft
                                ? trim((string) ($meta['message_draft'] ?: $message->content))
                                : '';
                        @endphp
                        <li wire:key="ai-shell-msg-{{ $message->id }}"
                            data-ai-shell-message="{{ $message->role }}"
                            class="flex flex-col {{ $message->role === \App\Models\AiShellMessage::ROLE_USER ? 'items-end' : 'items-start' }}">
                            <div class="max-w-[85%] rounded-2xl px-3 py-2 text-sm leading-5 {{ $message->role === \App\Models\AiShellMessage::ROLE_USER
                                    ? 'bg-indigo-600 text-white'
                                    : 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-100' }}">
                                <span class="sr-only">{{ $message->role === \App\Models\AiShellMessage::ROLE_USER ? __('ai.shell_you') : __('ai.shell_assistant') }} :</span>
                                {{-- TASK-1350 — gate supplementaire : un titre ne s'affiche
                                     que sur un tour ANSWERED. La metadata d'un tour
                                     NON_INTERACTION n'en porte deja aucun ; ce test rend la
                                     regle VISIBLE a l'endroit du rendu, et protege le fil
                                     deja ecrit comme celui qu'on ecrira demain.

                                     TASK-1350 (P0) — et jamais sur un tour d'intention de
                                     demande : son titre appartient au brouillon de
                                     l'utilisateur, rendu plus bas dans sa propre carte. --}}
                                @if(! $isUserDraft
                                    && $message->role === \App\Models\AiShellMessage::ROLE_ASSISTANT
                                    && $isAnswered
                                    && filled($meta['title'] ?? null))
                                    <span class="mb-1 block font-semibold" data-ai-shell-answer-title>{{ $meta['title'] }}</span>
                                @endif

                                {{-- TASK-1350 (P0) — la bulle de l'assistant ne dit que ce
                                     que l'assistant dit vraiment : il a compris, et il
                                     propose. Le texte a la premiere personne, lui, quitte
                                     cette bulle. --}}
                                <span class="block whitespace-pre-line">{{ $isUserDraft ? ($isOfferDraft ? __('ai.shell_offer_framing') : __('ai.shell_request_framing')) : $message->content }}</span>
                            </div>

                            {{-- TASK-1350 (P0) — la carte du brouillon, visuellement
                                 distincte de la bulle et EXPLICITEMENT attribuee a
                                 l'utilisateur. Le texte a la premiere personne y est
                                 alors juste : c'est sa demande, pas la voix de l'IA.

                                 Sous la carte, le choix est HUMAIN et binaire. Aucun des
                                 deux boutons ne publie : « Continuer a discuter » ne fait
                                 que rendre le focus au composeur — aucun aller-retour
                                 serveur, aucun provider, aucune ecriture, et le brouillon
                                 saisi n'est pas efface ; « Preparer une demande d'aide »
                                 emprunte le pipeline EXISTANT `prepareRequest($messageId)`,
                                 qui depose un brouillon hors session et ouvre le
                                 formulaire canonique. L'humain relit et valide ensuite. --}}
                            @if($isUserDraft && $requestDraftBody !== '')
                                <div class="mt-2 w-full max-w-[92%] rounded-xl border border-dashed border-indigo-300 bg-indigo-50/60 p-3 dark:border-indigo-700/60 dark:bg-indigo-900/20"
                                     data-ai-shell-request-draft="{{ $message->id }}">
                                    <p class="flex items-center gap-1.5">
                                        <span class="rounded bg-white px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-indigo-700 dark:bg-gray-800 dark:text-indigo-200" data-ai-shell-request-draft-heading>{{ $isOfferDraft ? __('ai.shell_offer_draft_heading') : __('ai.shell_request_draft_heading') }}</span>
                                    </p>
                                    @if(filled($meta['title'] ?? null))
                                        <p class="mt-1.5 text-sm font-semibold text-gray-900 dark:text-gray-100" data-ai-shell-request-draft-title>{{ $meta['title'] }}</p>
                                    @endif
                                    <p class="mt-1 whitespace-pre-line text-sm leading-5 text-gray-700 dark:text-gray-200" data-ai-shell-request-draft-body>{{ $requestDraftBody }}</p>

                                    <div class="mt-3 flex flex-col gap-2 sm:flex-row sm:flex-wrap">
                                        <button type="button"
                                                @click="$refs.composer?.focus()"
                                                data-ai-shell-request-continue
                                                class="inline-flex items-center justify-center rounded-full border border-gray-200 bg-white px-3 py-1.5 text-xs font-semibold text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 dark:hover:bg-gray-600">
                                            {{ __('ai.shell_request_continue') }}
                                        </button>
                                        {{-- Une OFFRE ne se prepare jamais en demande : elle
                                             mene au parcours canonique « Proposer de
                                             l'aide », deja construit cote serveur. --}}
                                        @if($isOfferDraft)
                                            @if(filled($shell['offer_help_url'] ?? null))
                                                <a href="{{ $shell['offer_help_url'] }}"
                                                   data-ai-shell-offer-prepare
                                                   class="inline-flex items-center justify-center rounded-full bg-indigo-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-indigo-700">
                                                    {{ __('ai.shell_card_offer_help') }}
                                                </a>
                                            @endif
                                        @else
                                            <button type="button"
                                                    wire:click="prepareRequest('{{ $message->id }}')"
                                                    data-ai-shell-request-prepare
                                                    class="inline-flex items-center justify-center rounded-full bg-indigo-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-indigo-700">
                                                {{ __('ai.shell_request_prepare') }}
                                            </button>
                                        @endif
                                    </div>

                                    {{-- Le tenant, dit discretement et SOUS les boutons :
                                         le nom vient de l'Organization deja resolue par le
                                         composant, sans nouveau resolver, et n'allonge
                                         aucun libelle sur mobile. --}}
                                    @if(filled($shell['organization_name'] ?? null))
                                        <p class="mt-1.5 text-[11px] leading-4 text-gray-500 dark:text-gray-400" data-ai-shell-request-tenant>{{ __('ai.shell_request_tenant', ['organization' => $shell['organization_name']]) }}</p>
                                    @endif
                                </div>
                            @endif

                            {{-- TASK-1325 — les cartes structurees de CE tour. Chaque
                                 carte a ete re-resolue et re-autorisee au rendu
                                 (AiShellTurnCards) : nom et URL sont relus a
                                 l'instant, jamais depuis le fil. Une carte dont
                                 l'objet ne passe plus sa garde n'arrive pas ici. --}}
                            @if(count($turnCards) > 0)
                                <div class="mt-2 w-full max-w-[92%] space-y-2" data-ai-shell-cards data-ai-shell-cards-turn="{{ $message->id }}">
                                    @foreach($turnCards as $card)
                                        <div data-ai-shell-card="{{ $card['type'] }}"
                                             data-ai-shell-card-turn="{{ $card['turn_id'] }}"
                                             class="rounded-xl border border-gray-200 bg-white p-3 shadow-sm dark:border-gray-600 dark:bg-gray-800/80">
                                            @if($card['type'] === \App\Support\Ai\AiShellTurnCards::TYPE_LOOP)
                                                <p class="flex items-center gap-1.5">
                                                    <span class="rounded bg-indigo-50 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-200">{{ __('ai.shell_card_loop_badge') }}</span>
                                                    <span class="truncate text-sm font-semibold text-gray-900 dark:text-gray-100">{{ $card['title'] }}</span>
                                                </p>
                                                @if($card['ai_wording'])
                                                    <p class="mt-1 text-xs italic text-gray-600 dark:text-gray-300" data-ai-shell-card-ai-wording>
                                                        « {{ $card['ai_wording'] }} »
                                                        <span class="not-italic text-[10px] text-gray-400 dark:text-gray-500">— {{ __('ai.shell_card_ai_wording_note') }}</span>
                                                    </p>
                                                @else
                                                    <p class="mt-1 text-xs text-gray-600 dark:text-gray-300">{{ __('ai.shell_card_loop_reason_membership') }}</p>
                                                @endif
                                                <div class="mt-2 flex flex-wrap gap-2">
                                                    <a href="{{ $card['url'] }}" data-ai-shell-card-action="open_loop"
                                                       class="inline-flex items-center rounded-full border border-indigo-200 bg-indigo-50 px-3 py-1 text-xs font-semibold text-indigo-700 hover:bg-indigo-100 dark:border-indigo-800/60 dark:bg-indigo-900/30 dark:text-indigo-200">
                                                        {{ __('ai.shell_card_open') }}
                                                    </a>
                                                    {{-- TASK-1350 — sur une OFFRE, l'appel a l'action
                                                         est « Proposer de l'aide », jamais
                                                         « Preparer ma demande ». L'URL est construite
                                                         cote serveur (AiShellTurnCards), tenant-aware,
                                                         sans preremplissage. --}}
                                                    @if(($card['cta'] ?? null) === \App\Support\Ai\AiShellTurnCards::CTA_OFFER_HELP)
                                                        @if(filled($card['cta_url'] ?? null))
                                                            <a href="{{ $card['cta_url'] }}" data-ai-shell-card-action="offer_help"
                                                               class="inline-flex items-center rounded-full border border-gray-200 bg-white px-3 py-1 text-xs font-semibold text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 dark:hover:bg-gray-600">
                                                                {{ __('ai.shell_card_offer_help') }}
                                                            </a>
                                                        @endif
                                                    @else
                                                        <button type="button" wire:click="prepareRequest('{{ $card['turn_id'] }}')" data-ai-shell-card-action="prepare_request"
                                                                class="inline-flex items-center rounded-full border border-gray-200 bg-white px-3 py-1 text-xs font-semibold text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 dark:hover:bg-gray-600">
                                                            {{ __('ai.shell_card_prepare_here') }}
                                                        </button>
                                                    @endif
                                                </div>
                                            @elseif($card['type'] === \App\Support\Ai\AiShellTurnCards::TYPE_PERSON)
                                                <p class="flex items-center gap-2">
                                                    @if($card['avatar'])
                                                        <img src="{{ $card['avatar'] }}" alt="" class="h-6 w-6 flex-shrink-0 rounded-full object-cover" />
                                                    @endif
                                                    <span class="truncate text-sm font-semibold text-gray-900 dark:text-gray-100">{{ $card['title'] }}</span>
                                                    <span class="rounded bg-emerald-50 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-200">{{ __('ai.shell_card_person_badge') }}</span>
                                                </p>
                                                @if(count($card['reasons']) > 0)
                                                    <ul class="mt-1 space-y-0.5" data-ai-shell-card-reasons>
                                                        @foreach($card['reasons'] as $reason)
                                                            <li class="text-xs text-gray-600 dark:text-gray-300">{{ $reason }}</li>
                                                        @endforeach
                                                    </ul>
                                                @endif
                                                <div class="mt-2">
                                                    <a href="{{ $card['url'] }}" data-ai-shell-card-action="view_profile"
                                                       class="inline-flex items-center rounded-full border border-indigo-200 bg-indigo-50 px-3 py-1 text-xs font-semibold text-indigo-700 hover:bg-indigo-100 dark:border-indigo-800/60 dark:bg-indigo-900/30 dark:text-indigo-200">
                                                        {{ __('ai.shell_card_view_profile') }}
                                                    </a>
                                                </div>
                                            @elseif($card['type'] === \App\Support\Ai\AiShellTurnCards::TYPE_PEOPLE_EMPTY)
                                                {{-- TASK-1360 : un refus n'est jamais un vide silencieux. --}}
                                                <p class="text-sm text-gray-600 dark:text-gray-300" data-ai-shell-card-people-empty>{{ $card['label'] }}</p>
                                                @if($card['cta_url'] !== '')
                                                    <div class="mt-2">
                                                        <a href="{{ $card['cta_url'] }}" data-ai-shell-card-action="publish_ai_profile"
                                                           class="inline-flex items-center rounded-full border border-indigo-200 bg-indigo-50 px-3 py-1 text-xs font-semibold text-indigo-700 hover:bg-indigo-100 dark:border-indigo-800/60 dark:bg-indigo-900/30 dark:text-indigo-200">
                                                            {{ $card['cta_label'] }}
                                                        </a>
                                                    </div>
                                                @endif
                                            @elseif($card['type'] === \App\Support\Ai\AiShellTurnCards::TYPE_DOCUMENT)
                                                <p class="flex items-center gap-1.5">
                                                    <span class="rounded bg-amber-50 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-amber-700 dark:bg-amber-900/40 dark:text-amber-200">{{ $card['kind'] === \App\Support\Ai\AiShellPageContext::KIND_DOSSIER ? __('ai.shell_card_document_badge_dossier') : __('ai.shell_card_document_badge_article') }}</span>
                                                    <span class="truncate text-sm font-semibold text-gray-900 dark:text-gray-100">{{ $card['title'] }}</span>
                                                </p>
                                                <p class="mt-1 text-xs text-gray-600 dark:text-gray-300">{{ __('ai.shell_card_document_origin_page') }}</p>
                                                <div class="mt-2">
                                                    <a href="{{ $card['url'] }}" data-ai-shell-card-action="open_document"
                                                       class="inline-flex items-center rounded-full border border-indigo-200 bg-indigo-50 px-3 py-1 text-xs font-semibold text-indigo-700 hover:bg-indigo-100 dark:border-indigo-800/60 dark:bg-indigo-900/30 dark:text-indigo-200">
                                                        {{ __('ai.shell_card_open') }}
                                                    </a>
                                                </div>
                                            @endif
                                        </div>
                                    @endforeach
                                </div>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
            {{-- Les actions contextuelles. Elles ne dependent PAS du fil : sur une
                 page Boucle, « interroger les Dossiers » vaut des l'ouverture du
                 Shell, meme avant le premier tour. --}}
            @if(count($shell['actions']) > 0)
                <div class="mt-3 flex flex-wrap gap-2" data-ai-shell-actions>
                    @foreach($shell['actions'] as $action)
                        @if($action['kind'] === 'link')
                            <a href="{{ $action['url'] }}" data-ai-shell-action="{{ $action['key'] }}"
                               class="inline-flex items-center gap-1.5 rounded-full border border-indigo-200 bg-indigo-50 px-3 py-1.5 text-xs font-semibold text-indigo-700 hover:bg-indigo-100 dark:border-indigo-800/60 dark:bg-indigo-900/30 dark:text-indigo-200">
                                {{ $action['label'] }}
                            </a>
                        @elseif($action['kind'] === 'method')
                            <button type="button" wire:click="{{ $action['method'] }}" data-ai-shell-action="{{ $action['key'] }}"
                                    class="inline-flex items-center gap-1.5 rounded-full border border-gray-200 bg-white px-3 py-1.5 text-xs font-semibold text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 dark:hover:bg-gray-600">
                                {{ $action['label'] }}
                            </button>
                        @else
                            {{-- TASK-1363 : le `detail` de l'action est TRANSMIS.
                                 Il etait ecrase par `{}` — le Resume de Boucle
                                 partait sans le nom de la Card a ouvrir, donc
                                 le bouton ne faisait rien. --}}
                            <button type="button"
                                    @click="close(); window.dispatchEvent(new CustomEvent('{{ $action['event'] }}', { detail: @js($action['detail'] ?? []) }))"
                                    data-ai-shell-action="{{ $action['key'] }}"
                                    class="inline-flex items-center gap-1.5 rounded-full border border-gray-200 bg-white px-3 py-1.5 text-xs font-semibold text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 dark:hover:bg-gray-600">
                                {{ $action['label'] }}
                            </button>
                        @endif
                    @endforeach
                </div>
            @endif

            <div wire:loading wire:target="send" class="mt-3 text-xs italic text-gray-500 dark:text-gray-400" data-ai-shell-pending>
                {{ __('ai.shell_sending') }}
            </div>
        </div>

        {{-- Pied : refus economique, ou composeur. --}}
        <div class="border-t border-gray-100 px-4 py-3 dark:border-gray-700">
            @if($shell['refusal'])
                <div class="rounded-xl border border-rose-200 bg-rose-50 p-3 text-sm leading-5 text-rose-800 dark:border-rose-800/50 dark:bg-rose-900/20 dark:text-rose-200" data-ai-shell-refusal>
                    {{ $shell['refusal'] }}
                </div>
                @if($shell['offers_url'])
                    <a href="{{ $shell['offers_url'] }}" data-ai-shell-offers
                       class="mt-2 inline-flex w-full items-center justify-center rounded-xl bg-indigo-600 px-3 py-2 text-sm font-semibold text-white hover:bg-indigo-700">
                        {{ __('ai.credit_see_offers') }}
                    </a>
                @endif
            @else
                @if($notice)
                    <p class="mb-2 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:bg-amber-900/25 dark:text-amber-200" data-ai-shell-notice>{{ $notice }}</p>
                @endif

                <form wire:submit="send" class="flex items-end gap-2">
                    <label for="ai-shell-composer" class="sr-only">{{ __('ai.shell_placeholder') }}</label>
                    <textarea id="ai-shell-composer"
                              x-ref="composer"
                              wire:model="draft"
                              rows="2"
                              maxlength="{{ $shell['max_input_chars'] }}"
                              data-ai-shell-composer
                              placeholder="{{ __('ai.shell_placeholder') }}"
                              class="min-h-[2.75rem] flex-1 resize-none rounded-xl border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100"></textarea>
                    <button type="submit"
                            wire:loading.attr="disabled"
                            wire:target="send"
                            data-ai-shell-send
                            class="inline-flex h-11 w-11 flex-shrink-0 items-center justify-center rounded-xl bg-indigo-600 text-white hover:bg-indigo-700 disabled:opacity-50">
                        <span class="sr-only">{{ __('ai.shell_send') }}</span>
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m5 12 14-7-4 7 4 7-14-7Z"/></svg>
                    </button>
                </form>

                <div class="mt-2 flex items-center justify-between gap-3">
                    <p class="text-[11px] leading-4 text-gray-500 dark:text-gray-400" data-ai-shell-no-publication>{{ __('ai.shell_no_publication_note') }}</p>

                    @if(! $shell['messages']->isEmpty())
                        @if($confirmingClear)
                            <span class="flex flex-shrink-0 items-center gap-2 text-[11px]">
                                <span class="text-gray-600 dark:text-gray-300">{{ __('ai.shell_clear_confirm') }}</span>
                                <button type="button" wire:click="clearThread" data-ai-shell-clear-confirm class="font-semibold text-rose-600 hover:underline dark:text-rose-300">{{ __('ai.shell_clear_yes') }}</button>
                                <button type="button" wire:click="cancelClear" data-ai-shell-clear-cancel class="text-gray-500 hover:underline dark:text-gray-400">{{ __('ai.shell_clear_no') }}</button>
                            </span>
                        @else
                            <button type="button" wire:click="askForClear" data-ai-shell-clear class="flex-shrink-0 text-[11px] text-gray-500 hover:underline dark:text-gray-400">{{ __('ai.shell_clear') }}</button>
                        @endif
                    @endif
                </div>

                <p class="mt-1 text-[11px] leading-4 text-gray-400 dark:text-gray-500" data-ai-shell-context-note>{{ __('ai.shell_context_note') }}</p>
            @endif
        </div>
    </div>
</div>
@endif
</div>
