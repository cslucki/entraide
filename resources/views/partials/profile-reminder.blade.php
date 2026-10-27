@if(session('profile_required'))
<div data-profile-reminder class="bg-amber-50 dark:bg-amber-900/20 border-b border-amber-200 dark:border-amber-700">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-3 flex items-start gap-3">
        <svg class="w-5 h-5 text-amber-500 flex-shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
        </svg>
        <p class="text-sm text-amber-800 dark:text-amber-200">
            <strong>{{ __('profile.profile_reminder_title') }}</strong>
            {{-- TASK-1707 F4 — les balises s'affichaient EN CLAIR.

                 `{{ }}` echappe tout ce qu'il rend, y compris le `<strong>`
                 que cette ligne vient d'inserer : le membre lisait
                 « Les champs &lt;strong&gt;Prenom, Nom…&lt;/strong&gt; sont
                 obligatoires ». Constate en recette OFSH le 25/10.

                 `{!! !!}` rend le balisage, et c'est sur ICI parce que les
                 deux morceaux sont a nous : la phrase vient de `lang/`, et la
                 liste des champs est `e()`-chappee avant insertion. Aucune
                 saisie d'utilisateur n'entre dans cette ligne. --}}
            {!! __('profile.profile_reminder_body', ['fields' => '<strong>'.e(__('profile.reminder_fields')).'</strong>']) !!}
        </p>
    </div>
</div>
@endif
