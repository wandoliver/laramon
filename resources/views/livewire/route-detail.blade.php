<div>
    <div class="mb-6 flex flex-wrap items-start gap-4">
        <div class="min-w-0">
            <a href="{{ route('instance', $instance) }}" class="text-sm text-zinc-500 transition hover:text-zinc-300">← {{ $instance->name }}</a>
            <h1 class="mt-1 text-lg font-semibold">Route</h1>
            <p class="mt-1 truncate font-mono text-sm text-sky-300">{{ $key }}</p>
        </div>
        <div class="ml-auto">
            <x-range-selector :range="$range" />
        </div>
    </div>

    <div class="mb-4 grid grid-cols-2 gap-4 sm:max-w-2xl sm:grid-cols-4">
        <div class="rounded-xl border border-zinc-800 bg-zinc-900/50 p-4">
            <p class="text-xs text-zinc-500">Requests ({{ $range }})</p>
            <p class="mt-1 text-xl font-semibold tabular-nums">{{ Number::abbreviate($rangeCount) }}</p>
        </div>
        <div class="rounded-xl border border-zinc-800 bg-zinc-900/50 p-4">
            <p class="text-xs text-zinc-500">Avg</p>
            <p class="mt-1 text-xl font-semibold tabular-nums">{{ $rangeAvg !== null ? round($rangeAvg).' ms' : '—' }}</p>
        </div>
        <div class="rounded-xl border border-zinc-800 bg-zinc-900/50 p-4">
            <p class="text-xs text-zinc-500">p95</p>
            <p class="mt-1 text-xl font-semibold tabular-nums text-emerald-400">{{ $rangeP95 !== null ? round($rangeP95).' ms' : '—' }}</p>
        </div>
        <div class="rounded-xl border border-zinc-800 bg-zinc-900/50 p-4">
            <p class="text-xs text-zinc-500">Slowest</p>
            <p class="mt-1 text-xl font-semibold tabular-nums text-amber-400">{{ $rangeMax !== null ? round($rangeMax).' ms' : '—' }}</p>
        </div>
    </div>

    <x-panel title="Throughput & latency" class="mb-4">
        <x-chart :chart="$chart" />
    </x-panel>

    <x-panel title="Slow occurrences of this route" subtitle="Requests over the slow threshold — click one for captured details">
        <table class="w-full text-sm">
            <thead>
                <tr class="text-left text-xs text-zinc-500">
                    <th class="pb-2 font-medium">Request</th>
                    <th class="pb-2 pl-4 text-right font-medium">Count</th>
                    <th class="pb-2 pl-4 text-right font-medium">Slowest</th>
                    <th class="pb-2"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-800/70">
                @forelse ($slowRequests as $request)
                    <tr class="group cursor-pointer transition hover:bg-zinc-800/30"
                        onclick="window.location='{{ route('instance.request', [$instance, md5($request->key)]) }}'">
                        <td class="py-2 pr-3">
                            <span class="underline decoration-zinc-700 decoration-dotted underline-offset-4 group-hover:text-sky-300 group-hover:decoration-sky-400/60">
                                <x-key-cell :value="$request->key" />
                            </span>
                        </td>
                        <td class="py-2 pl-4 text-right tabular-nums text-zinc-300">{{ $request->count }}</td>
                        <td class="py-2 pl-4 text-right tabular-nums text-amber-400">{{ round($request->max) }} ms</td>
                        <td class="w-6 py-2 text-right text-zinc-600 transition group-hover:text-sky-300">›</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="py-3 text-sm text-zinc-600">
                        No slow occurrences in this range — this route stayed under the slow-request threshold. 🎉
                    </td></tr>
                @endforelse
            </tbody>
        </table>
    </x-panel>
</div>
