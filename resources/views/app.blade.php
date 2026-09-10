<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $company->title ?? 'App' }}</title>

    <meta name="description" content="{{ $company->title ?? 'BMS POS' }} — Point of Sale & Business Management System">
    <meta name="theme-color" content="#0ea5e9">
    @php
        $faviconSizes = optional($company)->favicon_sizes ?? [];
        $fallbackIcon = optional($company)->favicon ? '/' . $company->favicon : '/favicon.ico';
        $icon16 = isset($faviconSizes['16']) ? '/' . $faviconSizes['16'] : $fallbackIcon;
        $icon32 = isset($faviconSizes['32']) ? '/' . $faviconSizes['32'] : $fallbackIcon;
        $icon180 = isset($faviconSizes['180']) ? '/' . $faviconSizes['180'] : $fallbackIcon;
    @endphp
    <link rel="icon" type="image/png" sizes="16x16" href="{{ $icon16 }}">
    <link rel="icon" type="image/png" sizes="32x32" href="{{ $icon32 }}">
    <link rel="shortcut icon" href="{{ $icon32 }}">
    <link rel="apple-touch-icon" sizes="180x180" href="{{ $icon180 }}">
    <link rel="manifest" href="/manifest.webmanifest">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="{{ $company->title ?? 'BMS POS' }}">
    <meta name="mobile-web-app-capable" content="yes">
    <script>
        (function () {
            try {
                var stored = localStorage.getItem('theme');
                var isDark = stored === 'dark' || (stored !== 'light' && window.matchMedia('(prefers-color-scheme: dark)').matches);
                document.documentElement.classList.toggle('dark', isDark);
            } catch (e) {}
            try {
                var locale = localStorage.getItem('locale');
                var supported = ['bn', 'en', 'hi', 'ar'];
                if (supported.indexOf(locale) === -1) locale = 'bn';
                document.documentElement.setAttribute('lang', locale);
                document.documentElement.setAttribute('dir', locale === 'ar' ? 'rtl' : 'ltr');
            } catch (e) {}
        })();
    </script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @inertiaHead
</head>

<body class="bg-slate-50 dark:bg-slate-900">
    @inertia
</body>

</html>
