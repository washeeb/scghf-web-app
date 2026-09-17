{!! '<?xml version="1.0" encoding="UTF-8"?>' !!}
{{-- The index: one child sitemap per content type. See sitemap.blade.php
     for why the XML declaration is echoed. --}}
<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
@foreach ($sitemaps as $sitemap)
    <sitemap>
        <loc>{{ $sitemap['loc'] }}</loc>
@if ($sitemap['lastmod'])
        <lastmod>{{ $sitemap['lastmod'] }}</lastmod>
@endif
    </sitemap>
@endforeach
</sitemapindex>
