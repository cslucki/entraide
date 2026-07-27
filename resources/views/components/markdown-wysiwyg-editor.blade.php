@props(['name' => 'description', 'value' => '', 'required' => false, 'minLength' => null, 'maxLength' => null, 'placeholder' => '', 'invalid' => false, 'rows' => 5])

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
    .ProseMirror-wrapper .ProseMirror-placeholder {
        color: #9ca3af;
        pointer-events: none;
    }
    .dark .ProseMirror-wrapper .ProseMirror-placeholder {
        color: #6b7280;
    }
</style>
<div data-tiptap-container
     class="markdown-wysiwyg-editor"
     x-data="{
         init() {
             // Listen for bp:markdown-editor:set-content event for AI suggestions
             const self = this;
             document.addEventListener('bp:markdown-editor:set-content', function(e) {
                 if (e.detail && e.detail.name === '{{ $name }}') {
                     const ta = $el.querySelector('textarea[data-tiptap-target]');
                     const ed = $el._tiptapEditor;
                     if (ed) {
                         ed.commands.setContent(e.detail.markdown, { contentType: 'markdown' });
                     } else if (ta) {
                         ta.value = e.detail.markdown;
                         ta.dispatchEvent(new Event('input', { bubbles: true }));
                     }
                 }
             });
         }
     }">
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
