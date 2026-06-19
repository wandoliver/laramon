<div>
    <div class="mb-6 flex flex-wrap items-start gap-4">
        <div class="min-w-0">
            <a href="{{ route('instance', $instance) }}" class="text-sm text-zinc-500 transition hover:text-zinc-300">← {{ $instance->name }}</a>
            <h1 class="mt-1 text-lg font-semibold">Slow request</h1>
            <p class="mt-1 truncate font-mono text-sm text-sky-300">{{ $key }}</p>
        </div>
        <div class="ml-auto">
            <x-range-selector :range="$range" />
        </div>
    </div>

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

    <x-panel title="Latest occurrences" subtitle="Captured by the agent — timings, resource usage, and safe context; never request payloads">
        <table class="w-full text-sm">
            <thead>
                <tr class="text-left text-xs text-zinc-500">
                    <th class="pb-2 font-medium">When</th>
                    <th class="pb-2 font-medium">Request</th>
                    <th class="pb-2 pl-4 text-right font-medium">Status</th>
                    <th class="pb-2 pl-4 text-right font-medium">Duration</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-800/70">
                @forelse ($samples as $sample)
                    @php($diagnostics = $sampleDiagnostics[$sample->id] ?? ['metrics' => [], 'context' => [], 'raw' => '{}'])
                    <tr wire:key="sample-{{ $sample->id }}-summary">
                        <td class="py-2 text-xs tabular-nums text-zinc-400">{{ $sample->occurred_at->format('d.m.Y H:i:s') }}</td>
                        <td class="py-2">
                            <span class="font-mono text-xs text-zinc-300">{{ $sample->payload['method'] ?? '' }} {{ $sample->payload['path'] ?? '—' }}</span>
                            @if ($sample->payload['via'] ?? null)
                                <span class="block truncate font-mono text-[11px] text-zinc-600">{{ $sample->payload['via'] }}</span>
                            @endif
                        </td>
                        <td class="py-2 pl-4 text-right tabular-nums {{ ($sample->payload['status'] ?? 200) >= 500 ? 'text-rose-400' : (($sample->payload['status'] ?? 200) >= 400 ? 'text-amber-400' : 'text-zinc-400') }}">
                            {{ $sample->payload['status'] ?? '—' }}
                        </td>
                        <td class="py-2 pl-4 text-right tabular-nums text-amber-400">{{ round($sample->payload['duration_ms'] ?? 0) }} ms</td>
                    </tr>
                    <tr wire:key="sample-{{ $sample->id }}-diagnostics">
                        <td colspan="4" class="pb-4 pt-1">
                            <div class="rounded-lg border border-zinc-800/80 bg-zinc-950/50 p-3">
                                @if ($diagnostics['metrics'] !== [])
                                    <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
                                        @foreach ($diagnostics['metrics'] as $metric)
                                            @php($tone = [
                                                'amber' => 'border-amber-500/20 bg-amber-500/10 text-amber-200',
                                                'sky' => 'border-sky-500/20 bg-sky-500/10 text-sky-200',
                                                'emerald' => 'border-emerald-500/20 bg-emerald-500/10 text-emerald-200',
                                            ][$metric['tone']] ?? 'border-zinc-700 bg-zinc-900 text-zinc-200')
                                            <div class="rounded-md border px-3 py-2 {{ $tone }}">
                                                <p class="text-[11px] font-medium uppercase tracking-wide opacity-70">{{ $metric['label'] }}</p>
                                                <p class="mt-0.5 text-sm font-semibold tabular-nums">{{ $metric['value'] }}</p>
                                            </div>
                                        @endforeach
                                    </div>
                                @endif

                                @if ($diagnostics['context'] !== [])
                                    <dl class="mt-3 grid gap-x-4 gap-y-2 text-xs sm:grid-cols-2">
                                        @foreach ($diagnostics['context'] as $item)
                                            <div class="min-w-0">
                                                <dt class="text-zinc-500">{{ $item['label'] }}</dt>
                                                <dd class="truncate font-mono text-zinc-300">{{ $item['value'] }}</dd>
                                            </div>
                                        @endforeach
                                    </dl>
                                @endif

                                <details class="mt-3">
                                    <summary class="cursor-pointer select-none text-xs font-medium text-zinc-500 transition hover:text-zinc-300">Raw sample payload</summary>
                                    <pre class="mt-2 max-h-64 overflow-auto rounded-md border border-zinc-800 bg-zinc-950 p-3 font-mono text-xs leading-relaxed text-zinc-300">{{ $diagnostics['raw'] }}</pre>
                                </details>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="py-3 text-sm text-zinc-600">
                        No occurrence samples yet — they arrive with the next export after the request is slow again.
                    </td></tr>
                @endforelse
            </tbody>
        </table>
    </x-panel>
</div>
