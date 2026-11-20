<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>{{ $title ? $title.' · ' : '' }}{{ config('app.name') }}</title>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Open+Sans:wght@300;400;600;700&display=swap" rel="stylesheet">
@include('layouts.partials.favicons')

{{-- Re-apply the remembered sidebar state before first paint (no flash). --}}
<script>
    try {
        if (window.localStorage.getItem('cv.sidebar.collapsed') === '1' && window.matchMedia('(min-width: 992px)').matches) {
            document.documentElement.classList.add('sidebar-collapsed');
        }
    } catch (e) {}
</script>

@vite(['resources/sass/app.scss', 'resources/js/app.js'])
@livewireStyles
