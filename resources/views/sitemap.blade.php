<?php echo '<?xml version="1.0" encoding="UTF-8"?>'; ?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">

    <url>
        <loc>{{ url('/') }}</loc>
        <changefreq>daily</changefreq>
        <priority>1.0</priority>
    </url>

    <url>
        <loc>{{ route('explorer') }}</loc>
        <changefreq>hourly</changefreq>
        <priority>0.9</priority>
    </url>

    @foreach ($services as $service)
    <url>
        <loc>{{ route('services.show', $service->id) }}</loc>
        <lastmod>{{ $service->updated_at->toAtomString() }}</lastmod>
        <changefreq>weekly</changefreq>
        <priority>0.7</priority>
    </url>
    @endforeach

    @foreach ($users as $user)
    <url>
        <loc>{{ route('profile.show', $user->id) }}</loc>
        <lastmod>{{ $user->updated_at->toAtomString() }}</lastmod>
        <changefreq>weekly</changefreq>
        <priority>0.5</priority>
    </url>
    @endforeach

</urlset>
