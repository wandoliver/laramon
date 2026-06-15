<div>
    <div class="mb-6 flex flex-wrap items-start gap-4">
        <div class="min-w-0">
            <a href="{{ route('instance', $instance) }}" class="text-sm text-zinc-500 transition hover:text-zinc-300">← {{ $instance->name }}</a>
            <h1 class="mt-1 text-lg font-semibold">Slow query</h1>
        </div>
        <div class="ml-auto">
            <x-range-selector :range="$range" />
        </div>
    </div>

    <x-panel title="Query" class="mb-4">
        <pre class="overflow-x-auto rounded-xl border border-zinc-800 bg-zinc-950/80 p-4 font-mono text-sm leading-relaxed text-amber-200/90 whitespace-pre-wrap">{{ $samples->first()->payload['sql'] ?? $key }}</pre>
        @if ($samples->first()?->payload['location'] ?? null)
            <p class="mt-2 font-mono text-xs text-zinc-500">{{ $samples->first()->payload['location'] }}</p>
        @endif
    </x-panel>

    <div class="mb-4 grid grid-cols-2 gap-4 sm:max-w-sm">
        <div class="rounded-xl border border-zinc-800 bg-zinc-900/50 p-4">
            <p class="text-xs text-zinc-500">Occurrences ({{ $range }})</p>
            <p class="mt-1 text-xl font-semibold tabular-nums">{{ Number::abbreviate($rangeCount) }}</p>
        </div>
        <div class="rounded-xl border border-zinc-800 bg-zinc-900/50 p-4">
            <p class="text-xs text-zinc-500">Slowest ({{ $range }})</p>
            <p class="mt-1 text-xl font-semibold tabular-nums text-amber-400">{{ $rangeMax !== null ? round($rangeMax).' ms' : '—' }}</p>
        </div>
    </div>

    <x-panel title="Occurrences & duration" class="mb-4">
        <x-chart :chart="$chart" :height="200" />
    </x-panel>

    <x-panel title="Latest occurrences" subtitle="Captured by the agent — duration and location, never bindings">
        <table class="w-full text-sm">
            <thead>
                <tr class="text-left text-xs text-zinc-500">
                    <th class="pb-2 font-medium">When</th>
                    <th class="pb-2 font-medium">Location</th>
                    <th class="pb-2 pl-4 text-right font-medium">Duration</th>
                    <th class="pb-2 pl-4 text-right font-medium">Connection</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-800/70">
                @forelse ($samples as $sample)
                    <tr wire:key="sample-{{ $sample->id }}">
                        <td class="py-2 text-xs tabular-nums text-zinc-400">{{ $sample->occurred_at->format('d.m.Y H:i:s') }}</td>
                        <td class="py-2"><x-key-cell :value="$sample->payload['location'] ?? '—'" /></td>
                        <td class="py-2 pl-4 text-right tabular-nums text-amber-400">{{ round($sample->payload['duration_ms'] ?? 0) }} ms</td>
                        <td class="py-2 pl-4 text-right text-xs text-zinc-500">{{ $sample->payload['connection'] ?? '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="py-3 text-sm text-zinc-600">
                        No occurrence samples yet — they arrive with the next export after the query runs slow again.
                    </td></tr>
                @endforelse
            </tbody>
        </table>
    </x-panel>
</div>
