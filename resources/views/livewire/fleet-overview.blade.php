<div wire:poll.30s>
    <div class="mb-6 flex items-end justify-between">
        <div>
            <h1 class="text-xl font-semibold tracking-tight">Fleet</h1>
            <p class="mt-1 text-sm text-zinc-500">{{ $cards->count() }} {{ Str::plural('instance', $cards->count()) }} · last 24 hours</p>
        </div>
        <a href="{{ route('instances.settings') }}" class="text-sm text-sky-400 transition hover:text-sky-300">Manage instances →</a>
    </div>

    @if ($cards->isEmpty())
        <div class="rounded-2xl border border-dashed border-zinc-800 p-12 text-center">
            <p class="text-lg font-medium text-zinc-300">No instances yet</p>
            <p class="mx-auto mt-2 max-w-md text-sm text-zinc-500">
                Register your first instance and point its agent here:
            </p>
            <code class="mt-4 inline-block rounded-lg bg-zinc-900 px-4 py-2 font-mono text-sm text-sky-300">php artisan monitor:make-instance "Client X Production"</code>
        </div>
    @else
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
            @foreach ($cards as $card)
                <a href="{{ route('instance', $card['instance']) }}" wire:key="instance-{{ $card['instance']->id }}"
                   class="group rounded-2xl border border-zinc-800 bg-zinc-900/50 p-5 transition hover:border-zinc-700 hover:bg-zinc-900">
                    <div class="flex items-center gap-2.5">
                        <x-health-dot :health="$card['health']" />
                        <span class="truncate font-medium text-zinc-100">{{ $card['instance']->name }}</span>
                        <span class="ml-auto shrink-0 rounded-md bg-zinc-800 px-2 py-0.5 text-xs text-zinc-400">{{ $card['instance']->environment }}</span>
                    </div>

                    <div class="mt-1.5 flex items-center gap-2 text-xs text-zinc-500">
                        <span>{{ $card['heartbeat_age'] ? 'Heartbeat '.$card['heartbeat_age'] : 'Never seen' }}</span>
                        @if ($card['app_version'])
                            <span>· v{{ $card['app_version'] }}</span>
                        @endif
                        @if ($card['online_users'] > 0)
                            <span class="flex items-center gap-1">· <span class="inline-block size-1.5 rounded-full bg-emerald-400"></span>{{ $card['online_users'] }} online</span>
                        @endif
                        @if ($card['has_gap'])
                            <span class="rounded bg-amber-500/15 px-1.5 py-0.5 font-medium text-amber-400">data gap</span>
                        @endif
                        @if ($card['open_alerts'] > 0)
                            <span class="rounded bg-rose-500/15 px-1.5 py-0.5 font-medium text-rose-400">🔔 {{ $card['open_alerts'] }} {{ Str::plural('alert', $card['open_alerts']) }}</span>
                        @endif
                    </div>

                    <div class="mt-4 grid grid-cols-3 gap-3 border-t border-zinc-800/80 pt-4 text-sm">
                        <div>
                            <p class="text-xs text-zinc-500">Requests</p>
                            <p class="mt-0.5 font-semibold tabular-nums">{{ Number::abbreviate($card['request_count']) }}</p>
                            <p class="text-xs tabular-nums text-zinc-500">
                                @if ($card['request_avg'] !== null) {{ round($card['request_avg']) }} ms avg @else — @endif
                            </p>
                        </div>
                        <div>
                            <p class="text-xs text-zinc-500">Errors</p>
                            <p class="mt-0.5 font-semibold tabular-nums {{ $card['exception_count'] > 0 ? 'text-rose-400' : '' }}">
                                {{ Number::abbreviate($card['exception_count']) }}
                            </p>
                            <div class="mt-1 text-rose-400/80">{!! \App\Support\Sparkline::render($card['exception_spark'], 'currentColor', 88, 20) !!}</div>
                        </div>
                        <div>
                            <p class="text-xs text-zinc-500">Queue</p>
                            <p class="mt-0.5 font-semibold tabular-nums">{{ $card['queue']['pending'] ?? '—' }}</p>
                            <p class="text-xs tabular-nums {{ ($card['queue']['failed'] ?? 0) > 0 || $card['failed_jobs'] > 0 ? 'text-rose-400' : 'text-zinc-500' }}">
                                {{ $card['queue']['failed'] ?? $card['failed_jobs'] }} failed
                            </p>
                        </div>
                    </div>
                </a>
            @endforeach
        </div>
    @endif
</div>
