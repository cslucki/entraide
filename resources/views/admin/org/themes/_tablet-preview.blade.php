@props(['tokens' => []])
@php
    $page = $tokens['page'] ?? '#FFFFFF';
    $surface = $tokens['surface'] ?? '#F3F4F6';
    $surfaceSoft = $tokens['surface-soft'] ?? '#F9FAFB';
    $panel = $tokens['panel'] ?? '#FFFFFF';
    $primary = $tokens['primary'] ?? '#0B4DFF';
    $primaryDeep = $tokens['primary-deep'] ?? '#1237C9';
    $accent = $tokens['accent'] ?? '#F59E0B';
    $text = $tokens['text'] ?? '#101010';
    $muted = $tokens['muted'] ?? '#667085';
    $disabled = $tokens['disabled'] ?? '#9CA3AF';
    $border = $tokens['border'] ?? '#DDE3F0';
    $progress = $tokens['progress'] ?? '#8B5CF6';
    $validation = $tokens['validation'] ?? '#10B981';
    $info = $tokens['info'] ?? '#C7F2FF';
    $warning = $tokens['warning'] ?? '#FFF3CD';
    $cardWelcome = $tokens['card-welcome'] ?? '#F0F9FF';
    $cardLoop = $tokens['card-loop'] ?? '#DBEAFE';
    $cardExchange = $tokens['card-exchange'] ?? '#D1FAE5';
    $cardDirectory = $tokens['card-directory'] ?? '#FEF3C7';
    $cardNews = $tokens['card-news'] ?? '#EDE9FE';
@endphp
<div class="rounded-lg" style="background-color: {{ $page }}; color: {{ $text }};">
    <div style="background-color: {{ $page }}; height: 4px;"></div>
    <div class="flex items-center justify-between px-4 py-2"
         style="background-color: {{ $surface }}; border-bottom: 1px solid {{ $border }}; color: {{ $text }};">
        <div class="flex items-center gap-1.5">
            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
            <span class="text-xs font-bold">BouclePro</span>
        </div>
        <div class="flex items-center gap-2">
            <span class="w-2 h-2 rounded-full" style="background-color: {{ $progress }}"></span>
            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
        </div>
    </div>
    <div class="px-4 py-2" style="background-color: {{ $page }}">
        <div class="flex items-center gap-1.5 rounded-lg px-2.5 py-1.5"
             style="background-color: {{ $surfaceSoft }}; border: 1px solid {{ $border }};">
            <svg class="w-3.5 h-3.5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
            <span class="text-[10px]" style="color: {{ $muted }};">Rechercher un service...</span>
        </div>
    </div>
    <div class="px-4 pb-3 space-y-2.5" style="background-color: {{ $page }}">
        <div class="grid grid-cols-2 gap-2.5">
            <div class="rounded-xl p-3 shadow-sm"
                 style="background-color: {{ $panel }}; border: 1px solid {{ $border }};">
                <div class="flex items-center justify-between mb-1.5">
                    <span class="rounded-full px-2 py-0.5 text-[9px] font-bold"
                          style="background-color: {{ $info }}; color: {{ $primaryDeep }};">Loop</span>
                    <span class="text-[10px] font-bold" style="color: {{ $accent }};">150 pts</span>
                </div>
                <h4 class="text-xs font-bold" style="color: {{ $text }};">Qui peut m'aider ?</h4>
                <p class="text-[10px] mt-0.5 leading-tight" style="color: {{ $muted }};">
                    Décrivez votre besoin et trouvez des experts disponibles.
                </p>
                <div class="flex flex-wrap gap-1 mt-2">
                    <span class="rounded px-1.5 py-0.5 text-[8px] font-semibold"
                          style="background-color: {{ $surfaceSoft }}; color: {{ $disabled }};">Design</span>
                    <span class="rounded px-1.5 py-0.5 text-[8px] font-semibold"
                          style="background-color: {{ $surfaceSoft }}; color: {{ $text }};">Web</span>
                </div>
                <div class="flex flex-wrap gap-1 mt-1.5">
                    <span class="rounded-full px-1.5 py-0.5 text-[8px] font-extrabold"
                          style="background-color: {{ $validation }}; color: {{ $text }};">Validé</span>
                    <span class="rounded-full px-1.5 py-0.5 text-[8px] font-extrabold"
                          style="background-color: {{ $progress }}; color: {{ $text }};">En cours</span>
                </div>
                <div class="mt-2 flex items-center gap-1.5" style="border-top: 1px solid {{ $border }}; padding-top: 6px;">
                    <div class="w-4 h-4 rounded-full" style="background-color: {{ $accent }}; opacity: 0.6;"></div>
                    <span class="text-[9px] font-medium" style="color: {{ $text }};">Marie</span>
                    <span class="text-[8px]" style="color: {{ $muted }};">· 2h</span>
                </div>
                <button type="button" class="mt-2.5 w-full rounded-lg py-1.5 text-[9px] font-bold text-white"
                        style="background-color: {{ $primary }};">
                    Proposer un échange
                </button>
                <button type="button" class="mt-1.5 w-full rounded-lg py-1 text-[8px] font-semibold"
                        style="border: 1px solid {{ $border }}; color: {{ $primaryDeep }};">
                    En savoir plus
                </button>
                <button type="button" disabled
                        class="mt-1.5 w-full rounded-lg py-1 text-[8px] font-semibold cursor-not-allowed"
                        style="background-color: {{ $surfaceSoft }}; color: {{ $disabled }};">
                    Action désactivée
                </button>
            </div>
            <div class="space-y-2">
                <div class="rounded-xl p-3 shadow-sm" style="background-color: {{ $cardWelcome }};">
                    <p class="text-[8px] font-bold text-black">Interface sobre</p>
                    <p class="mt-1 text-xs font-bold text-black">Que voulez-vous faire ?</p>
                    <p class="mt-0.5 text-[9px] leading-snug text-black">Entrez dans une boucle ou trouvez un échange.</p>
                    <div class="flex gap-1.5 mt-2">
                        <span class="rounded-full bg-black/10 px-2 py-0.5 text-[8px] font-semibold text-black">Créer</span>
                        <span class="rounded-full border border-black/20 px-2 py-0.5 text-[8px] font-semibold text-black">Découvrir</span>
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-1.5">
                    <div class="rounded-xl p-2 shadow-sm" style="background-color: {{ $cardLoop }};">
                        <p class="text-[9px] font-bold text-black">Boucles</p>
                        <p class="mt-0.5 text-[7px] leading-tight text-black">ChatLoop comme point de départ.</p>
                    </div>
                    <div class="rounded-xl p-2 shadow-sm" style="background-color: {{ $cardExchange }};">
                        <p class="text-[9px] font-bold text-black">Échanges</p>
                        <p class="mt-0.5 text-[7px] leading-tight text-black">Services et conversations.</p>
                    </div>
                    <div class="rounded-xl p-2 shadow-sm" style="background-color: {{ $cardDirectory }};">
                        <p class="text-[9px] font-bold text-black">Annuaire</p>
                        <p class="mt-0.5 text-[7px] leading-tight text-black">Membres, profils et contacts.</p>
                    </div>
                    <div class="rounded-xl p-2 shadow-sm" style="background-color: {{ $cardNews }};">
                        <p class="text-[9px] font-bold text-black">Actus</p>
                        <p class="mt-0.5 text-[7px] leading-tight text-black">Nouvelles de la communauté.</p>
                    </div>
                </div>
            </div>
        </div>
        <div class="flex items-center gap-2 rounded-lg px-3 py-2 text-[9px] font-medium"
             style="background-color: {{ $warning }}; color: {{ $text }};">
            <svg class="w-3.5 h-3.5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
            <span>Votre abonnement expire bientôt.</span>
        </div>
    </div>
    <div class="flex items-center justify-around px-3 py-1.5"
         style="background-color: {{ $surface }}; border-top: 1px solid {{ $border }};">
        <span class="flex flex-col items-center gap-0.5" style="color: {{ $muted }};">
            <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
            <span class="text-[7px] font-medium">Boucles</span>
        </span>
        <span class="flex flex-col items-center gap-0.5" style="color: {{ $muted }};">
            <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M7 16V4m0 0L3 8m4-4 4 4m6 0v12m0 0l4-4m-4 4l-4-4"/></svg>
            <span class="text-[7px] font-medium">Échanges</span>
        </span>
        <span class="flex flex-col items-center gap-0.5" style="color: {{ $accent }};">
            <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
            <span class="text-[7px] font-medium">Annuaire</span>
        </span>
        <span class="flex flex-col items-center gap-0.5" style="color: {{ $muted }};">
            <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M19 20H5a2 2 0 01-2-2V6a2 2 0 012-2h10a2 2 0 012 2v1m2 13a2 2 0 01-2-2V7m2 13a2 2 0 002-2V9a2 2 0 00-2-2h-2m-4-3H9M7 16h6M7 8h6v4H7V8z"/></svg>
            <span class="text-[7px] font-medium">Actus</span>
        </span>
    </div>
    <div class="flex justify-center py-1.5" style="background-color: {{ $page }};">
        <div class="w-16 h-1 rounded-full" style="background-color: {{ $muted }};"></div>
    </div>
</div>
