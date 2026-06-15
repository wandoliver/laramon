@props(['health'])

@php
    $color = match ($health) {
        'healthy' => 'bg-emerald-400',
        'degraded' => 'bg-amber-400',
        'down' => 'bg-rose-500',
        default => 'bg-zinc-600',
    };
@endphp

<span {{ $attributes->merge(['class' => 'relative flex size-2.5 shrink-0']) }} title="{{ ucfirst($health) }}">
    @if ($health === 'down')
        <span class="absolute inline-flex h-full w-full animate-ping rounded-full {{ $color }} opacity-60"></span>
    @endif
    <span class="relative inline-flex size-2.5 rounded-full {{ $color }}"></span>
</span>
