{{--
    TASK-1762 point 16 — `fullscreen` et `tall` sont ADDITIFS.

    `services/create` appelle ce composant sans ces attributs : sa sortie est
    inchangee a l'octet pres. Seul l'appelant qui les demande voit la difference
    — une regression visuelle sur un ecran qui n'a rien demande serait le pire
    resultat possible pour une amelioration d'ergonomie.
--}}
@props(['name' => 'description', 'value' => '', 'required' => false, 'minLength' => null, 'placeholder' => '', 'invalid' => false, 'rows' => 5, 'fullscreen' => false, 'tall' => false])

{{--
    TASK-1788 correctif 12 (demande Cyril) — « en plein ecran, les boutons
    deviennent noirs ». `.dark .bp-editeur-plein` posait un fond sombre SANS
    jamais poser de couleur de premier plan : tout ce qui compte sur
    `currentColor` (les icones de la barre d'outils, avant leur premiere
    interaction) heritait d'une couleur choisie pour un fond clair. Filet de
    securite ci-dessous ; la cause racine (l'icone sans couleur explicite au
    premier rendu) est corrigee dans `markdown-wysiwyg-editor.js`.

    ⚠️ Cette explication vit ICI, en commentaire BLADE (strip au compile),
    et non dans le commentaire CSS a cote de `color: #e5e7eb` plus bas :
    le `<style>` de ce composant est envoye TEL QUEL a chaque page qui
    l'utilise (services/create y compris, cf. le commentaire du haut de ce
    fichier) — un commentaire CSS qui cite un numero de TASK y fuit
    litteralement, et un test d'un AUTRE correctif qui verifie l'absence de
    fuite de commentaire de code sur ces pages en rougit. Mesure sur CI,
    pas en local.
--}}
<style>
    .ProseMirror-wrapper .ProseMirror {
        outline: none;
        padding: 0.75rem 1rem;
        min-height: 8rem;
        max-height: 24rem;
        overflow-y: auto;
        font-size: 0.875rem;
        line-height: 1.6;
        color: inherit;
        border-left: 1px solid #d1d5db;
        border-right: 1px solid #d1d5db;
        border-bottom: 1px solid #d1d5db;
        border-radius: 0 0 0.5rem 0.5rem;
        background-color: #fff;
    }
    .dark .ProseMirror-wrapper .ProseMirror {
        border-color: #4b5563;
        background-color: #1f2937;
        color: #f3f4f6;
    }
    .ProseMirror-wrapper .ProseMirror h2 {
        font-size: 1.25rem;
        font-weight: 700;
        margin-top: 0.75rem;
        margin-bottom: 0.25rem;
    }
    .ProseMirror-wrapper .ProseMirror h3 {
        font-size: 1.1rem;
        font-weight: 600;
        margin-top: 0.5rem;
        margin-bottom: 0.25rem;
    }
    .ProseMirror-wrapper .ProseMirror ul {
        list-style-type: disc;
        padding-left: 1.5rem;
    }
    .ProseMirror-wrapper .ProseMirror li {
        margin-bottom: 0.125rem;
    }
    .ProseMirror-wrapper .ProseMirror a {
        color: #4f46e5;
        text-decoration: underline;
    }
    .dark .ProseMirror-wrapper .ProseMirror a {
        color: #818cf8;
    }
    .ProseMirror-wrapper .ProseMirror p {
        margin-bottom: 0.25rem;
    }
    .ProseMirror-wrapper .ProseMirror p:empty::after {
        content: "\00a0";
    }
    .ProseMirror-wrapper .ProseMirror strong {
        font-weight: 700;
    }

    /* TASK-1762 point 16 — « un peu plus grand ». */
    .bp-editeur-grand .ProseMirror-wrapper .ProseMirror {
        min-height: 12rem;
        max-height: 32rem;
    }

    /* Le plein ecran. `position: fixed` plutot que l'API Fullscreen du
       navigateur : celle-ci sort l'element de la page, ce qui emporterait le
       <form> avec lui sur certains navigateurs et casserait l'envoi. Ici la
       saisie reste DANS le formulaire, elle occupe seulement tout l'ecran. */
    .bp-editeur-plein {
        position: fixed;
        inset: 0;
        z-index: 60;
        display: flex;
        flex-direction: column;
        padding: 1rem;
        background-color: #fff;
    }
    .dark .bp-editeur-plein {
        background-color: #111827;
        /* Filet de securite : un fond sombre SANS couleur de premier plan
           laisse tout ce qui compte sur `currentColor` heriter d'une teinte
           pensee pour un fond clair. La cause racine (l'icone de la barre
           d'outils sans couleur explicite au premier rendu) est corrigee
           dans markdown-wysiwyg-editor.js. */
        color: #e5e7eb;
    }
    .bp-editeur-plein .ProseMirror-wrapper {
        flex: 1;
        min-height: 0;
        display: flex;
        flex-direction: column;
    }
    .bp-editeur-plein .ProseMirror-wrapper .ProseMirror {
        flex: 1;
        min-height: 0;
        max-height: none;
        font-size: 1rem;
    }
    .bp-editeur-plein textarea {
        flex: 1;
    }
</style>
@if($fullscreen)
{{-- TASK-1762 point 16 — l'enveloppe du plein ecran.

     `x-data` porte l'etat, et `@keydown.escape.window` la sortie : un ecran
     qui prend toute la page DOIT pouvoir se fermer au clavier, sinon la seule
     issue est de recharger en perdant sa saisie. --}}
<div x-data="{ plein: false }"
     x-bind:class="plein ? 'bp-editeur-plein' : ''"
     @keydown.escape.window="plein = false"
     data-editeur-enveloppe>
    <div class="mb-1 flex justify-end">
        <button type="button" @click="plein = ! plein" data-editeur-plein-ecran
                class="inline-flex items-center gap-1.5 rounded-lg px-2 py-1 text-xs font-medium text-gray-500 transition hover:bg-gray-100 hover:text-[var(--bp-accent)] dark:text-gray-400 dark:hover:bg-gray-700">
            <svg x-show="! plein" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 8V4m0 0h4M4 4l5 5m11-5v4m0-4h-4m4 0l-5 5M4 16v4m0 0h4m-4 0l5-5m11 5v-4m0 4h-4m4 0l-5-5"/></svg>
            <svg x-show="plein" x-cloak class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 9V5m0 4H5m4 0L4 4m11 5h4m-4 0V5m0 4l5-5M9 15v4m0-4H5m4 0l-5 5m11-5h4m-4 0v4m0-4l5 5"/></svg>
            <span x-text="plein ? '{{ __('profile.editor_exit_fullscreen') }}' : '{{ __('profile.editor_fullscreen') }}'"></span>
        </button>
    </div>
@endif
<div data-tiptap-container
     class="markdown-wysiwyg-editor {{ $tall ? 'bp-editeur-grand' : '' }}"
     data-i18n-undo="{{ __('ai.markdown_undo') }}"
     data-i18n-redo="{{ __('ai.markdown_redo') }}"
     data-i18n-bold="{{ __('ai.markdown_bold') }}"
     data-i18n-link="{{ __('ai.markdown_link') }}"
     data-i18n-h2="{{ __('ai.markdown_h2') }}"
     data-i18n-h3="{{ __('ai.markdown_h3') }}"
     data-i18n-bullet-list="{{ __('ai.markdown_bullet_list') }}"
     data-i18n-youtube="{{ __('ai.markdown_youtube') }}"
     data-i18n-toolbar="{{ __('ai.markdown_toolbar') }}"
     data-i18n-url-prompt="{{ __('ai.markdown_url_prompt') }}"
     data-i18n-youtube-prompt="{{ __('ai.markdown_youtube_prompt') }}">
    <textarea
        id="{{ $name }}"
        name="{{ $name }}"
        data-tiptap-target
        rows="{{ $rows }}"
        @if($required) required @endif
        @if(!empty($minLength)) minlength="{{ $minLength }}" @endif
        placeholder="{{ $placeholder }}"
        class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-indigo-500 {{ $invalid ? 'border-red-500' : '' }}"
    >{{ $value }}</textarea>
</div>
@if($fullscreen)
</div>
@endif
