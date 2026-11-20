@props(['name'])
{{-- Inline SVG icon (Font Awesome Free, CC BY 4.0) from resources/svg/icons: no icon font on public pages. --}}
@php
    $file = resource_path('svg/icons/'.basename(str_replace('fa-', '', $name)).'.svg');
    $svg = is_file($file) ? (string) file_get_contents($file) : '';
    $svg = preg_replace('/<!--.*?-->/s', '', $svg);
    $svg = str_replace('<svg ', '<svg aria-hidden="true" focusable="false" '.$attributes->class(['cv-icon'])->toHtml().' ', $svg);
@endphp
{!! $svg !!}
