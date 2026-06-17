@props(['target' => 'content'])

<div class="flex flex-wrap gap-1 mb-1">
    <button type="button" x-on:click="insertMarkdown('bold')"
        class="rounded-lg px-2.5 py-1 text-xs font-bold text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800"
        title="Gras (sélectionnez du texte)">Gras</button>
    <button type="button" x-on:click="insertMarkdown('link')"
        class="rounded-lg px-2.5 py-1 text-xs font-semibold text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800"
        title="Insérer un lien">Lien</button>
    <button type="button" x-on:click="insertMarkdown('h2')"
        class="rounded-lg px-2.5 py-1 text-xs font-semibold text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800"
        title="Titre niveau 2">H2</button>
    <button type="button" x-on:click="insertMarkdown('h3')"
        class="rounded-lg px-2.5 py-1 text-xs font-semibold text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800"
        title="Titre niveau 3">H3</button>
    <button type="button" x-on:click="insertMarkdown('list')"
        class="rounded-lg px-2.5 py-1 text-xs font-semibold text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800"
        title="Liste à puces">Liste</button>
</div>
