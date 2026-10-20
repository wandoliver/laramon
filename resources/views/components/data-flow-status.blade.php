@props(['flow'])

<dl {{ $attributes->merge(['class' => 'grid gap-3 text-xs sm:grid-cols-3']) }}>
    @foreach (['heartbeat' => 'Heartbeat', 'export' => 'Accepted export', 'coverage' => 'Export coverage'] as $key => $label)
        @php
            $status = $flow[$key]['state'];
            $text = match ($status) {
                'healthy' => $key === 'coverage' ? 'Confirmed' : 'Fresh',
                'stale' => 'Stale',
                'warming_up' => 'Building coverage',
                'transferring' => 'Export in progress',
                default => 'Unknown',
            };
        @endphp
        <div>
            <dt class="text-zinc-500">{{ $label }}</dt>
            <dd class="mt-0.5 {{ match ($status) { 'healthy' => 'text-zinc-300', 'transferring' => 'text-sky-300', default => 'text-amber-400' } }}">
                {{ $text }}
                @if ($key !== 'coverage' && $flow[$key]['timestamp'] !== null)
                    <span class="text-zinc-500">· {{ \Illuminate\Support\Carbon::createFromTimestamp($flow[$key]['timestamp'])->diffForHumans(short: true) }}</span>
                @endif
                @if ($key === 'coverage' && $flow[$key]['from'] !== null && $flow[$key]['through'] !== null && $flow[$key]['from'] < $flow[$key]['through'])
                    <span class="block text-zinc-500">{{ \Illuminate\Support\Carbon::createFromTimestamp($flow[$key]['from'])->format('d.m H:i') }}–{{ \Illuminate\Support\Carbon::createFromTimestamp($flow[$key]['through'])->format('d.m H:i') }} UTC</span>
                @endif
            </dd>
        </div>
    @endforeach
</dl>
