<!DOCTYPE html>
{{--
    Layout raíz de Inertia.

    El tema se estampa en `<html>` ANTES de la primera pintura. Si se aplicara
    desde JavaScript después de montar, habría un destello claro en cada carga
    para quien usa tema oscuro, y en una pantalla de exhibición que arranca sola
    ese destello se ve en cada reinicio.

    En el backoffice el atributo va SIEMPRE: manda la preferencia del usuario y,
    si no eligió, el tema predeterminado configurable (RF-CFG-004/005). La Web
    pública de F4 se renderiza sin atributo, y ahí sí decide el dispositivo
    (RF-THE-001).
--}}
@php
    // Tema EFECTIVO, no la preferencia cruda: si el usuario no eligió, manda el
    // predeterminado de la configuración (RF-CFG-005).
    //
    // `null` significa que esta ruta sigue al dispositivo, y entonces el atributo
    // NO va: ponerlo vacío no es lo mismo que no ponerlo. Ver `stampedThemeFor()`.
    $theme = \App\Http\Middleware\HandleInertiaRequests::stampedThemeFor(request());
@endphp
<html lang="es-AR" @if ($theme !== null) data-theme="{{ $theme }}" @endif>
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
