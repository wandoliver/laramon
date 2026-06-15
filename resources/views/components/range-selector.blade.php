@props(['range'])

<div class="flex rounded-lg border border-zinc-800 bg-zinc-900/70 p-0.5 text-sm">
    @foreach (array_keys(\App\Support\TimeRange::RANGES) as $option)
        <button type="button" wire:click="$set('range', '{{ $option }}')"
                class="rounded-md px-3 py-1 transition {{ $range === $option ? 'bg-zinc-700/80 font-medium text-zinc-100' : 'text-zinc-400 hover:text-zinc-200' }}">
            {{ $option }}
        </button>
    @endforeach
</div>
