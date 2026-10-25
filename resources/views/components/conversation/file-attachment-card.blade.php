@props([
    'name' => '',
    'size' => null,
    'mime' => null,
    'downloadUrl' => '',
    'previewUrl' => null,
    'isSent' => false,
])

{{-- TASK-1704 — une piece jointe de conversation.

     Elle ne s'affiche PAS en vignette : le fichier vit sur un disque prive et
     n'a aucune URL publique. La carte annonce donc ce qu'on sait de lui — nom,
     type, poids — et mene a la route gardee.

     Le poids est affiche AVANT le telechargement, parce que sur un telephone
     en donnees mobiles, savoir qu'une piece pese 40 Mo change la decision de
     l'ouvrir. --}}

@php
    $estApercevable = $previewUrl !== null;
    $cadre = $isSent
        ? 'bg-indigo-500/20 border-indigo-400/30 hover:bg-indigo-500/30'
        : 'bg-gray-200 dark:bg-gray-600 border-gray-300 dark:border-gray-500 hover:bg-gray-300 dark:hover:bg-gray-500';
    $texte = $isSent ? 'text-white' : 'text-gray-900 dark:text-gray-100';
    $secondaire = $isSent ? 'text-indigo-200' : 'text-gray-500 dark:text-gray-400';
@endphp

<div data-message-attachment class="mt-1.5 flex items-center gap-2 rounded-lg border {{ $cadre }} p-2 transition">
    <span class="flex h-9 w-9 flex-shrink-0 items-center justify-center rounded {{ $isSent ? 'bg-indigo-500/30' : 'bg-gray-300 dark:bg-gray-500' }}">
        <svg class="h-4 w-4 {{ $texte }}" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
        </svg>
    </span>

    <div class="min-w-0 flex-1">
        <p class="truncate text-xs font-semibold {{ $texte }}" title="{{ $name }}">{{ $name }}</p>
        @if($size)
            <p class="text-[11px] {{ $secondaire }}">{{ $size }}</p>
        @endif
    </div>

    @if($estApercevable)
        <a href="{{ $previewUrl }}"
           target="_blank"
           rel="noopener noreferrer"
           data-attachment-preview
           aria-label="{{ __('messages.attachment_open') }}"
           title="{{ __('messages.attachment_open') }}"
           class="flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-full {{ $texte }} hover:bg-black/10 dark:hover:bg-white/10">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
            </svg>
        </a>
    @endif

    <a href="{{ $downloadUrl }}"
       data-attachment-download
       aria-label="{{ __('messages.attachment_download') }}"
       title="{{ __('messages.attachment_download') }}"
       class="flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-full {{ $texte }} hover:bg-black/10 dark:hover:bg-white/10">
        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
        </svg>
    </a>
</div>
