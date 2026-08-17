<!DOCTYPE html>
{{--
    Layout raíz de Inertia.

    El tema se estampa en `<html>` ANTES de la primera pintura. Si se aplicara
    desde JavaScript después de montar, habría un destello claro en cada carga
    para quien usa tema oscuro, y en una pantalla de exhibición que arranca sola
    ese destello se ve en cada reinicio.

    Tres estados (ver resources/js/theme.js): con sesión manda `theme_preference`
    del usuario (RF-CFG-004); sin sesión, el atributo se omite y decide el
    dispositivo (RF-THE-001).
--}}
@php
    $theme = $theme ?? optional(auth()->user())->theme_preference ?? 'system';
@endphp
<html
    lang="es-AR"
    @if ($theme === 'light' || $theme === 'dark') data-theme="{{ $theme }}" @endif
>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title inertia>{{ config('app.name') }}</title>

    {{-- Los subsets `latin` de los pesos que se usan en el primer render se
         precargan: sin esto el texto salta de fuente de sistema a Inter a mitad
         de carga (RNF-PER-002). --}}
    <link rel="preload" as="font" type="font/woff2" crossorigin
          href="{{ Vite::asset('resources/rds/fonts/inter-400-latin.woff2') }}">
    <link rel="preload" as="font" type="font/woff2" crossorigin
          href="{{ Vite::asset('resources/rds/fonts/inter-600-latin.woff2') }}">
    <link rel="preload" as="font" type="font/woff2" crossorigin
          href="{{ Vite::asset('resources/rds/fonts/poppins-600-latin.woff2') }}">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @inertiaHead
</head>
<body>
    @inertia
</body>
</html>
