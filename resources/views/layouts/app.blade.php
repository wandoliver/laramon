<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? config('app.name') }}</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-zinc-950 font-sans text-zinc-100 antialiased">
    <nav class="sticky top-0 z-20 border-b border-zinc-800/80 bg-zinc-950/80 backdrop-blur">
        <div class="mx-auto flex h-14 max-w-7xl items-center gap-6 px-4 sm:px-6 lg:px-8">
            <a href="{{ route('fleet') }}" class="flex items-center gap-2.5 font-semibold tracking-tight">
                <span class="relative flex size-2.5">
                    <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-sky-400 opacity-60"></span>
                    <span class="relative inline-flex size-2.5 rounded-full bg-sky-400"></span>
                </span>
                <x-brand />
            </a>

            <div class="flex items-center gap-1 text-sm">
                <a href="{{ route('fleet') }}"
                   class="rounded-md px-3 py-1.5 transition {{ request()->routeIs('fleet') || request()->routeIs('instance') ? 'bg-zinc-800/80 text-zinc-100' : 'text-zinc-400 hover:text-zinc-100' }}">
                    Fleet
                </a>
                <a href="{{ route('alerts') }}"
                   class="rounded-md px-3 py-1.5 transition {{ request()->routeIs('alerts') ? 'bg-zinc-800/80 text-zinc-100' : 'text-zinc-400 hover:text-zinc-100' }}">
                    Alerts
                </a>
                <a href="{{ route('instances.settings') }}"
                   class="rounded-md px-3 py-1.5 transition {{ request()->routeIs('instances.settings') ? 'bg-zinc-800/80 text-zinc-100' : 'text-zinc-400 hover:text-zinc-100' }}">
                    Instances
                </a>
            </div>

            <div class="ml-auto flex items-center gap-3 text-sm text-zinc-400">
                <span class="hidden sm:inline">{{ auth()->user()?->name }}</span>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="rounded-md px-3 py-1.5 text-zinc-400 transition hover:bg-zinc-800/80 hover:text-zinc-100">
                        Sign out
                    </button>
                </form>
            </div>
        </div>
    </nav>

    <main class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
        {{ $slot }}
    </main>
</body>
</html>
