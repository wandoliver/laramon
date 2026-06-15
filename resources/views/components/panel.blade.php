@props(['title' => null, 'subtitle' => null])

<section {{ $attributes->merge(['class' => 'rounded-2xl border border-zinc-800 bg-zinc-900/50 p-5']) }}>
    @if ($title)
        <header class="mb-4 flex items-baseline justify-between gap-3">
            <div>
                <h2 class="text-sm font-semibold tracking-wide text-zinc-200">{{ $title }}</h2>
                @if ($subtitle)
                    <p class="mt-0.5 text-xs text-zinc-500">{{ $subtitle }}</p>
                @endif
            </div>
            @isset($actions)
                <div>{{ $actions }}</div>
            @endisset
        </header>
    @endif

    {{ $slot }}
</section>
