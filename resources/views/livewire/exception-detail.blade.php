<div>
    <div class="mb-6 flex flex-wrap items-start gap-4">
        <div class="min-w-0">
            <a href="{{ route('instance', $instance) }}" class="text-sm text-zinc-500 transition hover:text-zinc-300">← {{ $instance->name }}</a>
            <div class="mt-1 flex flex-wrap items-center gap-2">
                <h1 class="min-w-0 truncate font-mono text-lg font-semibold {{ $group->resolved_at ? 'text-zinc-300' : 'text-rose-300' }}">{{ $group->class }}</h1>
                @if ($group->resolved_at)
                    <span class="shrink-0 rounded bg-emerald-500/15 px-1.5 py-0.5 text-xs font-medium text-emerald-400">resolved</span>
                @else
                    <span class="shrink-0 rounded bg-rose-500/15 px-1.5 py-0.5 text-xs font-medium text-rose-400">open</span>
                @endif
            </div>
            <p class="mt-1 flex flex-wrap items-center gap-x-2 text-sm text-zinc-500">
                <span class="font-mono text-xs">{{ $group->location ?? 'unknown location' }}</span>
                <span>·</span>
                <span>first seen {{ $group->first_seen_at->diffForHumans() }}</span>
                <span>·</span>
                <span>last seen {{ $group->last_seen_at->diffForHumans() }}</span>
            </p>
            @if ($group->resolved_at)
                <p class="mt-2 text-sm text-zinc-500">
                    Resolved {{ $group->resolved_at->diffForHumans() }}
                    @if ($group->resolvedBy)
                        by {{ $group->resolvedBy->name }}
                    @endif
                    @if ($group->resolved_comment)
                        <span class="text-zinc-600">·</span>
                        <span class="text-zinc-300">{{ $group->resolved_comment }}</span>
                    @endif
                </p>
            @endif
        </div>
        <div class="ml-auto flex flex-wrap items-center gap-2">
            <x-range-selector :range="$range" />
        </div>
    </div>

    @if (! $showResolutionForm)
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3 rounded-2xl border {{ $group->resolved_at ? 'border-emerald-500/30 bg-emerald-500/10' : 'border-rose-500/30 bg-rose-500/10' }} p-5">
            <div>
                <p class="text-sm font-semibold {{ $group->resolved_at ? 'text-emerald-300' : 'text-rose-300' }}">
                    {{ $group->resolved_at ? 'Resolved exception' : 'Open exception' }}
                </p>
                <p class="mt-1 text-xs text-zinc-500">
                    {{ $group->resolved_at ? 'Closed '.$group->resolved_at->diffForHumans() : 'Last seen '.$group->last_seen_at->diffForHumans() }}
                </p>
            </div>
            @if ($group->resolved_at)
                <button type="button" wire:click="reopen"
                        class="rounded-lg border border-zinc-700 px-4 py-2 text-sm font-semibold text-zinc-200 transition hover:border-zinc-500">
                    Reopen
                </button>
            @else
                <button type="button" wire:click="startResolving"
                        class="rounded-lg bg-sky-500 px-4 py-2 text-sm font-semibold text-white transition hover:bg-sky-400">
                    Mark as resolved
                </button>
            @endif
        </div>
    @endif

    @if ($showResolutionForm)
        <form wire:submit="resolve" class="mb-4 rounded-xl border border-zinc-800 bg-zinc-900/50 p-4">
            <label class="mb-1 block text-sm font-medium text-zinc-300">Resolution comment <span class="font-normal text-zinc-600">(optional)</span></label>
            <textarea wire:model="resolutionComment" rows="3"
                      class="w-full resize-y rounded-lg border border-zinc-700 bg-zinc-950 px-3 py-2 text-sm text-zinc-100 outline-none transition placeholder:text-zinc-700 focus:border-sky-500 focus:ring-2 focus:ring-sky-500/30"
                      placeholder="What changed, or why this exception can be closed?"></textarea>
            @error('resolutionComment') <p class="mt-1 text-sm text-rose-400">{{ $message }}</p> @enderror

            <div class="mt-3 flex justify-end gap-2">
                <button type="button" wire:click="cancelResolution"
                        class="rounded-lg border border-zinc-700 px-3 py-2 text-sm text-zinc-300 transition hover:border-zinc-500">
                    Cancel
                </button>
                <button type="submit"
                        class="rounded-lg bg-emerald-500 px-3 py-2 text-sm font-semibold text-white transition hover:bg-emerald-400">
                    Save resolution
                </button>
            </div>
        </form>
    @endif

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
