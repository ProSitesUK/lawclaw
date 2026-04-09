<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, minimum-scale=1, user-scalable=no, viewport-fit=cover">
        <meta name="mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <title>{{ config('app.name', 'LawClaw') }}</title>
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @livewireStyles
        <style>
            html, body { height: 100%; height: 100dvh; overflow: hidden; overscroll-behavior: none; touch-action: pan-y; }
            body { background: #0a0a0a; }
            * { -webkit-tap-highlight-color: transparent; }
        </style>
    </head>
    <body class="font-sans antialiased text-gray-100">
        <div class="fixed inset-0 flex flex-col bg-[#0a0a0a]" style="height:100dvh;">
            <main class="flex-1 min-h-0 overflow-hidden">
                {{ $slot }}
            </main>
        </div>
        @livewireScripts
    </body>
</html>
