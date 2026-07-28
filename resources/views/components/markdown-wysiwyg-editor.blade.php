@props(['name' => 'description', 'value' => '', 'required' => false, 'minLength' => null, 'placeholder' => '', 'invalid' => false, 'rows' => 5])

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
</style>
<div data-tiptap-container
     class="markdown-wysiwyg-editor"
     data-i18n-undo="{{ __('ai.markdown_undo') }}"
     data-i18n-redo="{{ __('ai.markdown_redo') }}"
     data-i18n-bold="{{ __('ai.markdown_bold') }}"
     data-i18n-link="{{ __('ai.markdown_link') }}"
     data-i18n-h2="{{ __('ai.markdown_h2') }}"
     data-i18n-h3="{{ __('ai.markdown_h3') }}"
     data-i18n-bullet-list="{{ __('ai.markdown_bullet_list') }}"
     data-i18n-toolbar="{{ __('ai.markdown_toolbar') }}"
     data-i18n-url-prompt="{{ __('ai.markdown_url_prompt') }}">
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
