{{--
    Le contenu du geste « ajouter quelqu'un ».

    Rendu dans une fenetre depuis le panneau du workspace, ou la place manque, et
    a plat sur l'ecran qui suit la creation d'une Boucle, dont c'est le sujet
    meme. Un seul exemplaire : les deux ne peuvent pas diverger.
--}}
{{-- Le champ unique : un nom, ou une adresse. --}}
<input type="search" wire:model.live.debounce.250ms="search"
       placeholder="{{ __('loops.members_search_placeholder') }}"
       class="w-full rounded-xl border-[var(--bp-border)] bg-[var(--bp-surface)] px-3 py-2 text-sm text-[var(--bp-text)] focus:border-[var(--bp-primary)] focus:ring-[var(--bp-primary)]">

@if($openEmail)
    {{-- Le processus continue dans la meme fenetre : on ne renvoie
         pas ailleurs quelqu'un qui a juste tape une adresse. --}}
    <div class="mt-3 space-y-2 rounded-2xl border border-[var(--bp-border)] bg-[var(--bp-surface)] p-3">
        <p class="text-xs text-[var(--bp-muted)]">{{ __('loops.members_invite_help') }}</p>

        <input type="email" wire:model="inviteEmail" placeholder="{{ __('loops.members_invite_email_placeholder') }}"
               class="w-full rounded-xl border-[var(--bp-border)] bg-[var(--bp-panel)] text-sm text-[var(--bp-text)] focus:border-[var(--bp-primary)] focus:ring-[var(--bp-primary)]">
        @error('inviteEmail') <p class="text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror

        <input type="text" wire:model="inviteName" maxlength="255" placeholder="{{ __('loops.members_invite_name_placeholder') }}"
               class="w-full rounded-xl border-[var(--bp-border)] bg-[var(--bp-panel)] text-sm text-[var(--bp-text)] focus:border-[var(--bp-primary)] focus:ring-[var(--bp-primary)]">
        @error('inviteName') <p class="text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror

        <button type="button" wire:click="sendInvitation" wire:loading.attr="disabled"
                class="inline-flex w-full items-center justify-center gap-2 rounded-xl bg-[var(--bp-primary)] px-4 py-2.5 text-sm font-semibold text-white transition hover:opacity-90 disabled:opacity-50">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 12 3.269 3.125A59.769 59.769 0 0 1 21.485 12 59.768 59.768 0 0 1 3.27 20.875L5.999 12Zm0 0h7.5"/></svg>
            <span wire:loading.remove wire:target="sendInvitation">{{ __('loops.members_invite_submit') }}</span>
            <span wire:loading wire:target="sendInvitation">{{ __('loops.members_sending') }}</span>
        </button>
    </div>
@elseif($offerEmailInvite)
    <button type="button" wire:click="inviteTyped"
            class="mt-3 flex w-full items-center gap-2.5 rounded-xl border border-dashed border-[var(--bp-primary)]/50 bg-[var(--bp-primary)]/5 px-3 py-2.5 text-left transition hover:bg-[var(--bp-primary)]/10">
        <svg class="h-4 w-4 shrink-0 text-[var(--bp-primary)]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 0 1-2.25 2.25h-15a2.25 2.25 0 0 1-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25m19.5 0v.243a2.25 2.25 0 0 1-1.07 1.916l-7.5 4.615a2.25 2.25 0 0 1-2.36 0L3.32 8.91a2.25 2.25 0 0 1-1.07-1.916V6.75"/></svg>
        <span class="min-w-0 flex-1 text-xs text-[var(--bp-text)]">
            {{ __('loops.members_invite_typed', ['email' => trim($search)]) }}
        </span>
    </button>
@elseif($candidates->isEmpty())
    <p class="mt-3 text-xs text-[var(--bp-muted)]">
        {{ trim($search) === '' ? __('loops.invite_no_candidate') : __('loops.invite_search_no_result') }}
    </p>
@else
    <div class="mt-3 max-h-72 space-y-0.5 overflow-y-auto">
        @foreach($candidates as $candidate)
            <label wire:key="candidate-{{ $candidate->id }}"
                   class="flex cursor-pointer items-center gap-2.5 rounded-xl px-2 py-1.5 transition hover:bg-[var(--bp-surface)] has-[:checked]:bg-[var(--bp-primary)]/10">
                <input type="checkbox" wire:model.live="selected" value="{{ $candidate->id }}"
                       class="rounded border-[var(--bp-border)] text-[var(--bp-primary)] focus:ring-[var(--bp-primary)]">
                <x-user-avatar :user="$candidate" size="sm" />
                <span class="min-w-0 flex-1 truncate text-sm text-[var(--bp-text)]">{{ $candidate->publicDisplayName() }}</span>
            </label>
        @endforeach
    </div>

    <button type="button" wire:click="add" wire:loading.attr="disabled"
            @disabled($selected === [])
            class="mt-3 inline-flex w-full items-center justify-center gap-2 rounded-xl bg-[var(--bp-primary)] px-4 py-2.5 text-sm font-semibold text-white transition hover:opacity-90 disabled:cursor-not-allowed disabled:opacity-40">
        <span wire:loading.remove wire:target="add">
            {{ __('loops.invite_add_submit') }}@if($selected !== []) ({{ count($selected) }})@endif
        </span>
        <span wire:loading wire:target="add">{{ __('loops.members_adding') }}</span>
    </button>
@endif
