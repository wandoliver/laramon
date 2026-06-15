<div wire:poll.60s>
    {{-- Header --}}
    <div class="mb-6 flex flex-wrap items-center gap-4">
        <div class="flex items-center gap-3">
            <x-health-dot :health="$instance->health()" class="size-3" />
            <div>
                <h1 class="text-xl font-semibold tracking-tight">{{ $instance->name }}</h1>
                <p class="mt-0.5 flex flex-wrap items-center gap-x-2 text-sm text-zinc-500">
                    <span>{{ $instance->environment }}</span>
                    @if ($instance->base_url)
                        <span>·</span><span class="font-mono text-xs">{{ $instance->base_url }}</span>
                    @endif
                    <span>·</span>
                    <span>{{ $instance->last_heartbeat_at ? 'Heartbeat '.$instance->last_heartbeat_at->diffForHumans() : 'Never seen' }}</span>
                    @if (($instance->meta['laravel_version'] ?? null))
                        <span>·</span><span>Laravel {{ $instance->meta['laravel_version'] }}</span>
                    @endif
                    @if (($instance->meta['php_version'] ?? null))
                        <span>·</span><span>PHP {{ $instance->meta['php_version'] }}</span>
                    @endif
                    @if (abs($instance->meta['clock_skew_seconds'] ?? 0) > 30)
                        <span class="rounded bg-amber-500/15 px-1.5 py-0.5 text-xs font-medium text-amber-400">
                            clock skew {{ $instance->meta['clock_skew_seconds'] }}s
                        </span>
                    @endif
                </p>
            </div>
        </div>

        <div class="ml-auto">
            <x-range-selector :range="$range" />
        </div>
    </div>

    <div class="grid gap-4">
        {{-- Active users --}}
        @if ($activeUsers->isNotEmpty())
            <x-panel title="Active users" subtitle="Authenticated activity in this range — labels are chosen by the instance">
                <div class="flex flex-wrap gap-2">
                    @foreach ($activeUsers as $user)
                        <div class="flex items-center gap-2.5 rounded-full border border-zinc-800 bg-zinc-950/40 py-1.5 pl-2 pr-3.5"
                             wire:key="user-{{ md5($user->label) }}"
                             title="{{ $user->count }} {{ Str::plural('request', $user->count) }} · last active {{ $user->last_seen ? \Illuminate\Support\Carbon::createFromTimestamp($user->last_seen)->diffForHumans() : 'unknown' }}">
                            <span class="relative flex size-7 items-center justify-center rounded-full bg-zinc-800 text-[11px] font-semibold text-zinc-300">
                                {{ mb_strtoupper(mb_substr($user->label, 0, 1)).mb_strtoupper(mb_substr(\Illuminate\Support\Str::afterLast($user->label, ' '), 0, 1)) }}
                                @if ($user->online)
                                    <span class="absolute -bottom-0.5 -right-0.5 size-2.5 rounded-full border-2 border-zinc-950 bg-emerald-400"></span>
                                @endif
                            </span>
                            <span class="text-sm text-zinc-200">{{ $user->label }}</span>
                            <span class="text-xs tabular-nums text-zinc-500">{{ Number::abbreviate($user->count) }}</span>
                        </div>
                    @endforeach
                </div>
            </x-panel>
        @endif

        {{-- Requests --}}
        <x-panel title="Requests" subtitle="Throughput and latency per route group">
            <div class="mb-4 flex flex-wrap gap-6 text-sm">
                <div>
                    <span class="text-zinc-500">Total</span>
                    <span class="ml-1.5 font-semibold tabular-nums">{{ Number::abbreviate($requestStats['count']) }}</span>
                </div>
                <div>
                    <span class="text-zinc-500">Avg</span>
                    <span class="ml-1.5 font-semibold tabular-nums">{{ $requestStats['avg'] !== null ? round($requestStats['avg']).' ms' : '—' }}</span>
                </div>
                <div>
                    <span class="text-zinc-500">p95</span>
                    <span class="ml-1.5 font-semibold tabular-nums text-emerald-400">{{ $requestStats['p95'] !== null ? round($requestStats['p95']).' ms' : '—' }}</span>
                </div>
            </div>

            <x-chart :chart="$requestsChart" />

            <div class="mt-5 grid gap-5 lg:grid-cols-2">
                <div>
                    <h3 class="mb-2 text-xs font-semibold uppercase tracking-wider text-zinc-500">Top routes</h3>
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-xs text-zinc-500">
                                <th class="pb-2 font-medium">Route</th>
                                @foreach (['count' => 'Count', 'avg' => 'Avg', 'p95' => 'p95', 'max' => 'Max'] as $column => $label)
                                    <th class="pb-2 pl-4 text-right font-medium">
                                        <button type="button" wire:click="$set('routesSort', '{{ $column }}')"
                                                class="transition hover:text-zinc-300 {{ $routesSort === $column ? 'text-sky-400' : '' }}">
                                            {{ $label }}{{ $routesSort === $column ? ' ↓' : '' }}
                                        </button>
                                    </th>
                                @endforeach
                                <th class="pb-2"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-800/70">
                            @forelse ($topRoutes as $route)
                                <tr class="group cursor-pointer transition hover:bg-zinc-800/30"
                                    onclick="window.location='{{ route('instance.route', [$instance, md5($route->key)]) }}'">
                                    <td class="py-2 pr-3">
                                        <span class="underline decoration-zinc-700 decoration-dotted underline-offset-4 group-hover:text-sky-300 group-hover:decoration-sky-400/60">
                                            <x-key-cell :value="$route->key" />
                                        </span>
                                    </td>
                                    <td class="py-2 pl-4 text-right tabular-nums text-zinc-300">{{ Number::abbreviate($route->count) }}</td>
                                    <td class="py-2 pl-4 text-right tabular-nums {{ $routesSort === 'avg' ? 'text-sky-300' : 'text-zinc-400' }}">{{ $route->avg !== null ? round($route->avg).' ms' : '—' }}</td>
                                    <td class="py-2 pl-4 text-right tabular-nums {{ $routesSort === 'p95' ? 'text-sky-300' : 'text-emerald-400/80' }}">{{ $route->p95 !== null ? round($route->p95).' ms' : '—' }}</td>
                                    <td class="py-2 pl-4 text-right tabular-nums {{ $routesSort === 'max' ? 'text-sky-300' : 'text-zinc-400' }}">{{ $route->max !== null ? round($route->max).' ms' : '—' }}</td>
                                    <td class="w-6 py-2 text-right text-zinc-600 transition group-hover:text-sky-300">›</td>
                                </tr>
                            @empty
                                <tr><td colspan="6" class="py-3 text-sm text-zinc-600">No requests in this range.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div>
                    <h3 class="mb-2 text-xs font-semibold uppercase tracking-wider text-zinc-500">Slow requests</h3>
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
                                <tr><td colspan="4" class="py-3 text-sm text-zinc-600">No slow requests. 🎉</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </x-panel>

        {{-- Database --}}
        <x-panel title="Database" subtitle="Slowest queries by maximum duration — click a query for full SQL, trend, and occurrences">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-xs text-zinc-500">
                        <th class="pb-2 font-medium">Query</th>
                        <th class="pb-2 pl-4 text-right font-medium">Count</th>
                        <th class="pb-2 pl-4 text-right font-medium">Avg</th>
                        <th class="pb-2 pl-4 text-right font-medium">Slowest</th>
                        <th class="pb-2"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-800/70">
                    @forelse ($slowQueries as $query)
                        <tr class="group cursor-pointer transition hover:bg-zinc-800/30"
                            onclick="window.location='{{ route('instance.query', [$instance, md5($query->key)]) }}'">
                            <td class="py-2 pr-3">
                                <span class="underline decoration-zinc-700 decoration-dotted underline-offset-4 group-hover:text-sky-300 group-hover:decoration-sky-400/60">
                                    <x-key-cell :value="$query->key" :max="130" />
                                </span>
                            </td>
                            <td class="py-2 pl-4 text-right tabular-nums text-zinc-300">{{ $query->count }}</td>
                            <td class="py-2 pl-4 text-right tabular-nums text-zinc-400">{{ $query->avg !== null ? round($query->avg).' ms' : '—' }}</td>
                            <td class="py-2 pl-4 text-right tabular-nums text-amber-400">{{ round($query->max) }} ms</td>
                            <td class="w-6 py-2 text-right text-zinc-600 transition group-hover:text-sky-300">›</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="py-3 text-sm text-zinc-600">No slow queries in this range. 🎉</td></tr>
                    @endforelse
                </tbody>
            </table>
        </x-panel>

        {{-- Jobs & Queue --}}
        <div class="grid gap-4 lg:grid-cols-2">
            <x-panel title="Jobs" subtitle="Processed, released, and failed">
                <x-chart :chart="$jobsChart" />
            </x-panel>
            <x-panel title="Queue backlog" subtitle="Pending jobs over time">
                <x-chart :chart="$queueChart" />
            </x-panel>
        </div>

        @if ($slowJobs->isNotEmpty())
            <x-panel title="Slow jobs">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-xs text-zinc-500">
                            <th class="pb-2 font-medium">Job</th>
                            <th class="pb-2 pl-4 text-right font-medium">Count</th>
                            <th class="pb-2 pl-4 text-right font-medium">Slowest</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-800/70">
                        @foreach ($slowJobs as $job)
                            <tr>
                                <td class="py-2 pr-3"><x-key-cell :value="$job->key" /></td>
                                <td class="py-2 pl-4 text-right tabular-nums text-zinc-300">{{ $job->count }}</td>
                                <td class="py-2 pl-4 text-right tabular-nums text-amber-400">{{ round($job->max) }} ms</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-panel>
        @endif

        {{-- Scheduler --}}
        <x-panel title="Scheduler" subtitle="Task runs, runtimes, and failures">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-xs text-zinc-500">
                        <th class="pb-2 font-medium">Task</th>
                        <th class="pb-2 pl-4 text-right font-medium">Runs</th>
                        <th class="pb-2 pl-4 text-right font-medium">Avg</th>
                        <th class="pb-2 pl-4 text-right font-medium">Max</th>
                        <th class="pb-2 pl-4 text-right font-medium">Failures</th>
                        <th class="pb-2 pl-4 text-right font-medium">Last run</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-800/70">
                    @forelse ($scheduledTasks as $task)
                        <tr>
                            <td class="py-2 pr-3"><x-key-cell :value="$task->key" /></td>
                            <td class="py-2 pl-4 text-right tabular-nums text-zinc-300">{{ $task->count }}</td>
                            <td class="py-2 pl-4 text-right tabular-nums text-zinc-400">{{ $task->avg !== null ? round($task->avg / 1000, 1).' s' : '—' }}</td>
                            <td class="py-2 pl-4 text-right tabular-nums text-zinc-400">{{ $task->max !== null ? round($task->max / 1000, 1).' s' : '—' }}</td>
                            <td class="py-2 pl-4 text-right tabular-nums {{ $task->failures > 0 ? 'font-semibold text-rose-400' : 'text-zinc-500' }}">{{ $task->failures }}</td>
                            <td class="py-2 pl-4 text-right text-xs text-zinc-500">{{ $task->last_seen ? \Illuminate\Support\Carbon::createFromTimestamp($task->last_seen)->diffForHumans(short: true) : '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="py-3 text-sm text-zinc-600">No scheduled task runs in this range.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </x-panel>

        {{-- Exceptions --}}
        <x-panel title="Exceptions" subtitle="Grouped by class and location — click a group for traces and occurrences">
            <x-chart :chart="$exceptionsChart" :height="160" />

            <div class="mt-5 divide-y divide-zinc-800/70">
                @forelse ($exceptionGroups as $entry)
                    <a href="{{ route('instance.exception', [$instance, $entry['group']->fingerprint]) }}"
                       class="flex items-center gap-4 py-2.5 transition hover:bg-zinc-800/30" wire:key="exc-{{ $entry['group']->id }}">
                        <div class="min-w-0 flex-1">
                            <p class="truncate font-mono text-sm text-rose-300">{{ $entry['group']->class }}</p>
                            <p class="mt-0.5 truncate text-xs text-zinc-500">
                                {{ $entry['group']->location ?? 'unknown location' }}
                                · first {{ $entry['group']->first_seen_at->diffForHumans(short: true) }}
                                · last {{ $entry['group']->last_seen_at->diffForHumans(short: true) }}
                            </p>
                        </div>
                        <div class="text-rose-400/70">{!! \App\Support\Sparkline::render($entry['spark'], 'currentColor', 110, 24) !!}</div>
                        <div class="w-16 text-right">
                            <p class="font-semibold tabular-nums {{ $entry['recent_count'] > 0 ? 'text-rose-400' : 'text-zinc-500' }}">{{ $entry['recent_count'] }}</p>
                            <p class="text-xs text-zinc-600">{{ Number::abbreviate($entry['group']->total_count) }} total</p>
                        </div>
                    </a>
                @empty
                    <p class="py-3 text-sm text-zinc-600">No exceptions recorded. 🎉</p>
                @endforelse
            </div>
        </x-panel>

        {{-- Business metrics --}}
        @if ($business !== [])
            <x-panel title="Business metrics" subtitle="Reported by this instance's collectors">
                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                    @foreach ($business as $metric)
                        <div class="rounded-xl border border-zinc-800/80 bg-zinc-950/40 p-4" wire:key="biz-{{ md5($metric['label']) }}">
                            <p class="truncate text-xs text-zinc-500" title="{{ $metric['label'] }}">{{ $metric['label'] }}</p>
                            <p class="mt-1 text-lg font-semibold tabular-nums">
                                {{ $metric['value'] !== null ? Number::format($metric['value'] == (int) $metric['value'] ? (int) $metric['value'] : round($metric['value'], 1)) : '—' }}
                                @if ($metric['kind'] === 'counter')
                                    <span class="text-xs font-normal text-zinc-500">in range</span>
                                @endif
                            </p>
                            <div class="mt-2 text-sky-400/70">{!! \App\Support\Sparkline::render(array_map(fn ($v) => $v ?? 0, $metric['spark']), 'currentColor', 150, 24) !!}</div>
                        </div>
                    @endforeach
                </div>
            </x-panel>
        @endif

        {{-- Cache --}}
        <x-panel title="Cache" subtitle="Hits vs. misses">
            <x-chart :chart="$cacheChart" :height="160" />
        </x-panel>
    </div>
</div>
