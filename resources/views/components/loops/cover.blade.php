{{--
    Loop cover: the uploaded image when there is one, otherwise the BouclePro
    rings artwork (public/img/boucle-rings.svg) inlined and recoloured.

    Why inlined rather than <img> or a CSS mask: the artwork is built on three
    gradients (rings + two soft blobs). Inlining lets us recolour those gradient
    stops per Loop, which keeps the full depth of the original drawing — a mask
    would flatten it into a single-colour silhouette.

    The <defs> ids are suffixed per Loop: several covers share one page, and
    duplicated gradient ids would make every card fall back to the first
    definition found in the DOM.

    The variant is deterministic (crc32 of the immutable Loop id): a given Loop
    always renders the same colours, across pages and reloads.
--}}
{{--
    The ratio is applied as an inline `aspect-ratio` rather than a Tailwind
    `aspect-[x/y]` class because `ratio` is a prop that varies per call site
    (5 / 2 on catalog cards, 21 / 9 on the presentation page). Tailwind scans
    source files as plain text and never executes Blade, so a class built from
    an interpolated value would not be seen and the rule would not be emitted.
    An inline style keeps the placeholder and a real cover image in exactly the
    same box whatever the ratio.

    Arbitrary values themselves work fine here — tailwind.config.js is honoured
    through postcss.config.js and the built CSS does contain rules such as
    `aspect-[335/364]`. (`@tailwindcss/vite` sits in package.json but is never
    wired into vite.config.js, so it has no effect either way.)
--}}
@props(['loop', 'ratio' => '5 / 2', 'rounded' => 'rounded-t-2xl'])
@php
    // [background (Tailwind, literal so JIT keeps it), ring stops ×3, blob ×2]
    $coverVariants = [
        [
            'bg' => 'from-violet-100 to-pink-100 dark:from-violet-950 dark:to-pink-950',
            'ring' => ['#E9D5FF', '#FBF5FF', '#F9A8D4'],
            'blobs' => ['#F5D0FE', '#DDD6FE'],
        ],
        [
            'bg' => 'from-sky-100 to-cyan-100 dark:from-sky-950 dark:to-cyan-950',
            'ring' => ['#BFDBFE', '#F0FBFF', '#A5F3FC'],
            'blobs' => ['#BAE6FD', '#CFFAFE'],
        ],
        [
            'bg' => 'from-indigo-100 to-violet-100 dark:from-indigo-950 dark:to-violet-950',
            'ring' => ['#C7D2FE', '#F6F4FF', '#DDD6FE'],
            'blobs' => ['#C7D2FE', '#E9D5FF'],
        ],
        [
            'bg' => 'from-orange-100 to-rose-100 dark:from-orange-950 dark:to-rose-950',
            'ring' => ['#FED7AA', '#FFF7ED', '#FECDD3'],
            'blobs' => ['#FED7AA', '#FECDD3'],
        ],
        [
            'bg' => 'from-emerald-100 to-teal-100 dark:from-emerald-950 dark:to-teal-950',
            'ring' => ['#A7F3D0', '#F0FDFA', '#99F6E4'],
            'blobs' => ['#A7F3D0', '#99F6E4'],
        ],
    ];
    $variant = $coverVariants[abs(crc32((string) $loop->id)) % count($coverVariants)];
    $uid = substr(md5((string) $loop->id), 0, 8);
@endphp
<div {{ $attributes->merge(['class' => $rounded.' relative w-full overflow-hidden bg-gray-100 dark:bg-gray-800']) }}
     style="aspect-ratio: {{ $ratio }};">
    @if($loop->cover_image_url)
        <img src="{{ $loop->cover_image_url }}" alt="" class="absolute inset-0 h-full w-full object-cover">
    @else
        {{-- Decorative: the Loop name is rendered right next to it. --}}
        <div class="absolute inset-0 bg-gradient-to-br {{ $variant['bg'] }}" aria-hidden="true">
            <svg class="absolute inset-0 h-full w-full" viewBox="0 0 640 660" fill="none" preserveAspectRatio="xMidYMid slice" focusable="false">
                <defs>
                    <linearGradient id="ringGrad-{{ $uid }}" x1="20%" y1="0%" x2="80%" y2="100%">
                        <stop offset="0%" stop-color="{{ $variant['ring'][0] }}"></stop>
                        <stop offset="42%" stop-color="{{ $variant['ring'][1] }}"></stop>
                        <stop offset="100%" stop-color="{{ $variant['ring'][2] }}"></stop>
                    </linearGradient>
                    <radialGradient id="blobA-{{ $uid }}" cx="50%" cy="50%" r="50%">
                        <stop offset="0%" stop-color="{{ $variant['blobs'][0] }}" stop-opacity="0.7"></stop>
                        <stop offset="100%" stop-color="{{ $variant['blobs'][0] }}" stop-opacity="0"></stop>
                    </radialGradient>
                    <radialGradient id="blobB-{{ $uid }}" cx="50%" cy="50%" r="50%">
                        <stop offset="0%" stop-color="{{ $variant['blobs'][1] }}" stop-opacity="0.7"></stop>
                        <stop offset="100%" stop-color="{{ $variant['blobs'][1] }}" stop-opacity="0"></stop>
                    </radialGradient>
                    <filter id="soft-{{ $uid }}" x="-20%" y="-20%" width="140%" height="140%">
                        <feGaussianBlur stdDeviation="4"></feGaussianBlur>
                    </filter>
                </defs>

                <circle cx="230" cy="235" r="180" fill="url(#blobA-{{ $uid }})"></circle>
                <circle cx="448" cy="455" r="200" fill="url(#blobB-{{ $uid }})"></circle>

                <g filter="url(#soft-{{ $uid }})" stroke="url(#ringGrad-{{ $uid }})" fill="none" stroke-linecap="round">
                    <circle cx="322" cy="332" r="286" stroke-width="16" opacity="0.55"></circle>
                    <circle cx="336" cy="322" r="248" stroke-width="30" opacity="0.50"></circle>
                    <circle cx="306" cy="344" r="210" stroke-width="26" opacity="0.46"></circle>
                    <circle cx="326" cy="330" r="172" stroke-width="24" opacity="0.40"></circle>
                    <circle cx="314" cy="338" r="136" stroke-width="20" opacity="0.34"></circle>
                </g>
            </svg>
        </div>
    @endif
</div>
