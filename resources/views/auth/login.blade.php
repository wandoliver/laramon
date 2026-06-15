<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sign in — {{ config('app.name') }}</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css'])
</head>
<body class="flex min-h-screen items-center justify-center bg-zinc-950 font-sans text-zinc-100 antialiased">
    <div class="w-full max-w-sm px-6">
        <div class="mb-8 flex items-center justify-center gap-2.5 text-lg font-semibold tracking-tight">
            <span class="relative flex size-3">
                <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-sky-400 opacity-60"></span>
                <span class="relative inline-flex size-3 rounded-full bg-sky-400"></span>
            </span>
            <x-brand />
        </div>

        <form method="POST" action="/login" class="space-y-4 rounded-2xl border border-zinc-800 bg-zinc-900/60 p-6 shadow-xl shadow-black/40">
            @csrf

            <div>
                <label for="email" class="mb-1.5 block text-sm font-medium text-zinc-300">Email</label>
                <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus
                       class="w-full rounded-lg border border-zinc-700 bg-zinc-950 px-3 py-2 text-sm text-zinc-100 placeholder-zinc-500 outline-none transition focus:border-sky-500 focus:ring-2 focus:ring-sky-500/30">
                @error('email')
                    <p class="mt-1.5 text-sm text-rose-400">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="password" class="mb-1.5 block text-sm font-medium text-zinc-300">Password</label>
                <input id="password" name="password" type="password" required
                       class="w-full rounded-lg border border-zinc-700 bg-zinc-950 px-3 py-2 text-sm text-zinc-100 outline-none transition focus:border-sky-500 focus:ring-2 focus:ring-sky-500/30">
            </div>

            <button type="submit"
                    class="w-full rounded-lg bg-sky-500 px-4 py-2 text-sm font-semibold text-white transition hover:bg-sky-400 focus:outline-none focus:ring-2 focus:ring-sky-500/50">
                Sign in
            </button>
        </form>
    </div>
</body>
</html>
