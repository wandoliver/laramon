<div>
    <div class="mb-6 flex flex-wrap items-start gap-4">
        <div class="min-w-0">
            <a href="{{ route('instance', $instance) }}" class="text-sm text-zinc-500 transition hover:text-zinc-300">← {{ $instance->name }}</a>
            <h1 class="mt-1 truncate font-mono text-lg font-semibold text-rose-300">{{ $group->class }}</h1>
            <p class="mt-1 flex flex-wrap items-center gap-x-2 text-sm text-zinc-500">
                <span class="font-mono text-xs">{{ $group->location ?? 'unknown location' }}</span>
                <span>·</span>
                <span>first seen {{ $group->first_seen_at->diffForHumans() }}</span>
                <span>·</span>
                <span>last seen {{ $group->last_seen_at->diffForHumans() }}</span>
            </p>
        </div>
        <div class="ml-auto">
            <x-range-selector :range="$range" />
        </div>
    </div>

    <div class="mb-4 grid grid-cols-2 gap-4 sm:max-w-sm">
        <div class="rounded-xl border border-zinc-800 bg-zinc-900/50 p-4">
            <p class="text-xs text-zinc-500">In range ({{ $range }})</p>
            <p class="mt-1 text-xl font-semibold tabular-nums text-rose-400">{{ Number::abbreviate($rangeCount) }}</p>
        </div>
        <div class="rounded-xl border border-zinc-800 bg-zinc-900/50 p-4">
            <p class="text-xs text-zinc-500">All time</p>
            <p class="mt-1 text-xl font-semibold tabular-nums">{{ Number::abbreviate($group->total_count) }}</p>
        </div>
    </div>

    <x-panel title="Occurrences" class="mb-4">
        <x-chart :chart="$chart" :height="180" />
    </x-panel>

    <x-panel title="Latest occurrences" subtitle="Captured by the agent — message and stack trace, no request payloads">
        <div class="divide-y divide-zinc-800/70">
            @forelse ($samples as $sample)
                <details class="group py-3" wire:key="sample-{{ $sample->id }}">
                    <summary class="flex cursor-pointer items-baseline gap-3 list-none">
                        <span class="shrink-0 text-xs tabular-nums text-zinc-500">{{ $sample->occurred_at->format('d.m.Y H:i:s') }}</span>
                        <span class="min-w-0 flex-1 truncate text-sm text-zinc-300 group-open:whitespace-normal">{{ $sample->payload['message'] ?? '(no message)' }}</span>
                        <span class="shrink-0 text-xs text-zinc-600 transition group-open:rotate-180">▾</span>
                    </summary>
                    <div class="mt-3 space-y-2">
                        @if ($sample->payload['url'] ?? null)
                            <p class="font-mono text-xs text-zinc-500">{{ $sample->payload['method'] ?? '' }} {{ $sample->payload['url'] }}</p>
                        @endif
                        @if ($sample->payload['trace'] ?? null)
                            <pre class="overflow-x-auto rounded-xl border border-zinc-800 bg-zinc-950/80 p-4 text-xs leading-relaxed text-zinc-400">{{ $sample->payload['trace'] }}</pre>
                        @endif
                    </div>
                </details>
            @empty
                <p class="py-3 text-sm text-zinc-600">
                    No occurrence samples yet — they arrive with the next export after the exception fires again.
                </p>
            @endforelse
        </div>
    </x-panel>
</div>
