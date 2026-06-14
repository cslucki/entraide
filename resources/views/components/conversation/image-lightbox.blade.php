@props([
    'key' => 'default',
])

<div
    x-data="{
        imageUrl: null,
        open(url) { this.imageUrl = url; },
        close() { this.imageUrl = null; },
    }"
    x-on:open-image.window="if ($event.detail.url) open($event.detail.url)"
    x-show="imageUrl"
    x-cloak
    class="fixed inset-0 z-50 flex items-center justify-center"
    role="dialog"
    aria-modal="true"
>
    <div
        x-show="imageUrl"
        x-transition.opacity.duration.200
        class="absolute inset-0 bg-black/80"
        x-on:click="close()"
    ></div>

    <button
        x-show="imageUrl"
        x-transition.opacity.duration.200
        x-on:click="close()"
        class="absolute top-4 right-4 z-10 w-10 h-10 rounded-full bg-black/50 hover:bg-black/70 text-white flex items-center justify-center transition"
        aria-label="Fermer"
    >
        <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
        </svg>
    </button>

    <img
        x-show="imageUrl"
        x-transition.scale.200
        :src="imageUrl"
        class="relative max-w-[90vw] max-h-[85vh] object-contain rounded-lg shadow-2xl"
        alt="Image"
    >

    <div x-show="imageUrl" x-on:keydown.escape.window="close()"></div>
</div>
