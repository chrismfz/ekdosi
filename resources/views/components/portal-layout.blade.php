@props(['title' => null])
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" class="antialiased">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? ($portalCompany ?? null)?->name ?? __('portal.nav.brand') }}</title>
    {{-- Built assets when a build exists (deploy runs `npm run build`); in a
         fresh/test env with no build the page still renders (unstyled). --}}
    @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    @endif
    @fluxAppearance
</head>
<body class="min-h-screen bg-zinc-50 text-zinc-800 dark:bg-zinc-900 dark:text-zinc-200">
    <header class="border-b border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-800">
        <div class="mx-auto flex h-14 max-w-4xl items-center justify-between px-4">
            <a href="{{ route('portal.home') }}" class="font-semibold">{{ ($portalCompany ?? null)?->name ?? __('portal.nav.brand') }}</a>
            @auth('portal')
                <nav class="flex items-center gap-3 text-sm">
                    <flux:link href="{{ route('portal.home') }}">{{ __('portal.common.my_documents') }}</flux:link>
                    <flux:link href="{{ route('portal.statement') }}">{{ __('portal.common.my_statement') }}</flux:link>
                    <flux:link href="{{ route('portal.tickets') }}">{{ __('portal.common.my_requests') }}</flux:link>
                    <flux:link href="{{ route('portal.profile') }}">{{ __('portal.nav.details') }}</flux:link>
                    <form method="POST" action="{{ route('portal.logout') }}">
                        @csrf
                        <flux:button type="submit" size="sm" variant="ghost">{{ __('portal.nav.sign_out') }}</flux:button>
                    </form>
                </nav>
            @endauth
        </div>
    </header>

    <main class="mx-auto max-w-4xl px-4 py-8">
        @if (session('status'))
            <div class="mb-4 rounded-lg bg-green-50 px-4 py-3 text-sm text-green-700 dark:bg-green-950 dark:text-green-300">
                {{ session('status') }}
            </div>
        @endif
        {{ $slot }}
    </main>

    @fluxScripts
</body>
</html>
