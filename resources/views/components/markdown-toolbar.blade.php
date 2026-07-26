@props(['target' => 'content', 'namespace' => 'feed-post'])

@php
    $tb = fn (string $key) => __("$namespace.toolbar_$key");
@endphp

<div x-data="{
    insertMarkdown(type) {
        const ta = document.getElementById('{{ $target }}');
        if (!ta) return;
        const start = ta.selectionStart;
        const end = ta.selectionEnd;
        const val = ta.value;
        const sel = val.substring(start, end);
        let before = '', after = '';
        if (type === 'bold') { before = '**'; after = '**'; }
        else if (type === 'link') { before = '['; after = '](url)'; }
        else if (type === 'h2') { before = '## '; }
        else if (type === 'h3') { before = '### '; }
        else if (type === 'list') { before = '\n- '; }
        let newVal;
        if (sel && type !== 'h2' && type !== 'h3' && type !== 'list') {
            newVal = val.substring(0, start) + before + sel + after + val.substring(end);
        } else {
            newVal = val.substring(0, start) + before + after + val.substring(end);
        }
        ta.value = newVal;
        ta.dispatchEvent(new Event('input', { bubbles: true }));
        ta.focus();
        const pos = start + before.length;
        ta.setSelectionRange(pos, pos);
    }
}" class="flex flex-wrap gap-1 mb-1">
    <button type="button" x-on:click="insertMarkdown('bold')"
        class="rounded-lg px-2.5 py-1 text-xs font-bold text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800"
        title="{{ $tb('bold_title') }}">{{ $tb('bold') }}</button>
    <button type="button" x-on:click="insertMarkdown('link')"
        class="rounded-lg px-2.5 py-1 text-xs font-semibold text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800"
        title="{{ $tb('link_title') }}">{{ $tb('link') }}</button>
    <button type="button" x-on:click="insertMarkdown('h2')"
        class="rounded-lg px-2.5 py-1 text-xs font-semibold text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800"
        title="{{ $tb('h2_title') }}">H2</button>
    <button type="button" x-on:click="insertMarkdown('h3')"
        class="rounded-lg px-2.5 py-1 text-xs font-semibold text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800"
        title="{{ $tb('h3_title') }}">H3</button>
    <button type="button" x-on:click="insertMarkdown('list')"
        class="rounded-lg px-2.5 py-1 text-xs font-semibold text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800"
        title="{{ $tb('list_title') }}">{{ $tb('list') }}</button>
</div>
