<div>
    <div class="mb-6 flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold tracking-tight">Alerts</h1>
            <p class="mt-1 text-sm text-zinc-500">Rules are evaluated every minute. Alarms are recorded here; Teams and email notifications are optional.</p>
        </div>
        <a href="{{ route('alerts.email-templates') }}" wire:navigate
           class="rounded-lg border border-zinc-700 px-3 py-2 text-sm text-zinc-300 transition hover:border-zinc-500">Email templates</a>
    </div>

    @if ($testResult)
        <div class="mb-4 rounded-xl border border-sky-500/30 bg-sky-500/10 px-4 py-3 text-sm text-sky-300">
            {{ $testResult }}
        </div>
    @endif

    <div class="grid gap-4 lg:grid-cols-3">
        {{-- Rule form --}}
        <x-panel :title="$editingId ? 'Edit rule' : 'New rule'" class="self-start">
            <form wire:submit="save" class="space-y-3">
                <div>
                    <label class="mb-1 block text-sm text-zinc-400">Name</label>
                    <input type="text" wire:model="name" placeholder="High error rate"
                           class="w-full rounded-lg border border-zinc-700 bg-zinc-950 px-3 py-2 text-sm outline-none transition focus:border-sky-500 focus:ring-2 focus:ring-sky-500/30">
                    @error('name') <p class="mt-1 text-sm text-rose-400">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="mb-1 block text-sm text-zinc-400">Instance</label>
                    <select wire:model="instance_id"
                            class="w-full rounded-lg border border-zinc-700 bg-zinc-950 px-3 py-2 text-sm outline-none focus:border-sky-500">
                        <option value="">All instances</option>
                        @foreach ($instances as $instance)
                            <option value="{{ $instance->id }}">{{ $instance->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="mb-1 block text-sm text-zinc-400">Metric</label>
                    <select wire:model.live="metric_type"
                            class="w-full rounded-lg border border-zinc-700 bg-zinc-950 px-3 py-2 text-sm outline-none focus:border-sky-500">
                        @foreach ($metricTypes as $type)
                            <option value="{{ $type }}">{{ match ($type) { 'heartbeat' => 'heartbeat (instance down)', 'data_flow' => 'data flow (missing, stale or incomplete)', default => $type } }}</option>
                        @endforeach
                    </select>
                </div>

                @if ($metric_type === 'data_flow')
                    <p class="rounded-lg border border-amber-500/20 bg-amber-500/5 p-3 text-xs text-zinc-400">Alerts when heartbeat, accepted exports or confirmed coverage are missing, stale or incomplete. Normal exports with fresh prior confirmation have up to five minutes to finish. Recovery requires confirmed fresh data flow.</p>
                @elseif ($metric_type === 'heartbeat')
                    <div>
                        <label class="mb-1 block text-sm text-zinc-400">Alert after silent minutes</label>
                        <input type="number" wire:model="threshold" placeholder="10"
                               class="w-full rounded-lg border border-zinc-700 bg-zinc-950 px-3 py-2 text-sm outline-none focus:border-sky-500">
                        @error('threshold') <p class="mt-1 text-sm text-rose-400">{{ $message }}</p> @enderror
                    </div>
                @else
                    <div>
                        <label class="mb-1 block text-sm text-zinc-400">Key filter <span class="text-zinc-600">(optional, exact)</span></label>
                        <input type="text" wire:model="key" placeholder="e.g. a route or queue name"
                               class="w-full rounded-lg border border-zinc-700 bg-zinc-950 px-3 py-2 text-sm outline-none focus:border-sky-500">
                    </div>

                    <div class="grid grid-cols-3 gap-2">
                        <div>
                            <label class="mb-1 block text-sm text-zinc-400">Aggregate</label>
                            <select wire:model="aggregate" class="w-full rounded-lg border border-zinc-700 bg-zinc-950 px-2 py-2 text-sm outline-none focus:border-sky-500">
                                @foreach (\App\Models\AlertRule::AGGREGATES as $agg)
                                    <option value="{{ $agg }}">{{ $agg }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="mb-1 block text-sm text-zinc-400">Operator</label>
                            <select wire:model="operator" class="w-full rounded-lg border border-zinc-700 bg-zinc-950 px-2 py-2 text-sm outline-none focus:border-sky-500">
                                @foreach (\App\Models\AlertRule::OPERATORS as $op)
                                    <option value="{{ $op }}">{{ $op }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="mb-1 block text-sm text-zinc-400">Threshold</label>
                            <input type="number" step="any" wire:model="threshold"
                                   class="w-full rounded-lg border border-zinc-700 bg-zinc-950 px-2 py-2 text-sm outline-none focus:border-sky-500">
                        </div>
                    </div>
                    @error('threshold') <p class="text-sm text-rose-400">{{ $message }}</p> @enderror

                    <div>
                        <label class="mb-1 block text-sm text-zinc-400">Window (minutes)</label>
                        <input type="number" wire:model="window_minutes"
                               class="w-full rounded-lg border border-zinc-700 bg-zinc-950 px-3 py-2 text-sm outline-none focus:border-sky-500">
                        @error('window_minutes') <p class="mt-1 text-sm text-rose-400">{{ $message }}</p> @enderror
                    </div>
                @endif

                <div class="grid grid-cols-2 gap-2">
                    <div>
                        <label class="mb-1 block text-sm text-zinc-400">Alarm delay (minutes)</label>
                        <input type="number" min="0" max="1440" step="1" wire:model="trigger_delay_minutes"
                               class="w-full rounded-lg border border-zinc-700 bg-zinc-950 px-3 py-2 text-sm outline-none focus:border-sky-500">
                        @error('trigger_delay_minutes') <p class="mt-1 text-sm text-rose-400">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="mb-1 block text-sm text-zinc-400">Recovery delay (minutes)</label>
                        <input type="number" min="0" max="1440" step="1" wire:model="recovery_delay_minutes"
                               class="w-full rounded-lg border border-zinc-700 bg-zinc-950 px-3 py-2 text-sm outline-none focus:border-sky-500">
                        @error('recovery_delay_minutes') <p class="mt-1 text-sm text-rose-400">{{ $message }}</p> @enderror
                    </div>
                </div>
                <p class="text-xs text-zinc-500">Incidents appear here immediately. The alarm delay starts after the rule condition is breached (e.g. a 10-minute heartbeat threshold plus a 10-minute delay means about 20 minutes before notification). Teams and email wait for a continuous breach; recovery requires continuously confirmed healthy data. Set 0 for immediate action. Recovery messages only go to channels that received the alarm.</p>

                <div>
                    <label class="mb-1 block text-sm text-zinc-400">Cooldown (minutes)</label>
                    <input type="number" wire:model="cooldown_minutes"
                           class="w-full rounded-lg border border-zinc-700 bg-zinc-950 px-3 py-2 text-sm outline-none focus:border-sky-500">
                    <p class="mt-1 text-xs text-zinc-500">Minimum spacing between external alarm notifications for this rule and instance. Internal incidents are still recorded immediately.</p>
                    @error('cooldown_minutes') <p class="mt-1 text-sm text-rose-400">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="mb-1 block text-sm text-zinc-400">Teams webhook URL <span class="text-zinc-600">(optional)</span></label>
                    <input type="url" wire:model="webhook_url" placeholder="https://prod-…westeurope.logic.azure.com/…"
                           class="w-full rounded-lg border border-zinc-700 bg-zinc-950 px-3 py-2 text-sm outline-none focus:border-sky-500">
                    @error('webhook_url') <p class="mt-1 text-sm text-rose-400">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="mb-1 block text-sm text-zinc-400">Email recipients <span class="text-zinc-600">(optional)</span></label>
                    <textarea wire:model="email_recipients" rows="2" placeholder="ops@example.com, oncall@example.com"
                              class="w-full resize-y rounded-lg border border-zinc-700 bg-zinc-950 px-3 py-2 text-sm outline-none focus:border-sky-500"></textarea>
                    <p class="mt-1 text-xs text-zinc-500">Up to 10 addresses, separated by commas, semicolons or new lines. Email can be used on its own or alongside Teams.</p>
                    @error('email_recipients') <p class="mt-1 text-sm text-rose-400">{{ $message }}</p> @enderror
                </div>

                <p class="text-xs text-zinc-500">Leave both fields empty for internal alarms only. Outgoing email requires the monitoring server's mail configuration.</p>

                <div class="flex gap-2">
                    <button type="submit" class="flex-1 rounded-lg bg-sky-500 px-4 py-2 text-sm font-semibold text-white transition hover:bg-sky-400">
                        {{ $editingId ? 'Save changes' : 'Create rule' }}
                    </button>
                    @if ($editingId)
                        <button type="button" wire:click="resetForm" class="rounded-lg border border-zinc-700 px-3 py-2 text-sm text-zinc-300 hover:border-zinc-500">
                            Cancel
                        </button>
                    @endif
                </div>
            </form>
        </x-panel>

        <div class="space-y-4 lg:col-span-2">
            {{-- Rules --}}
            <x-panel title="Rules">
                <div class="divide-y divide-zinc-800/70">
                    @forelse ($rules as $rule)
                        <div class="flex flex-wrap items-center gap-3 py-3" wire:key="rule-{{ $rule->id }}">
                            <button type="button" wire:click="toggle({{ $rule->id }})"
                                    class="relative h-5 w-9 shrink-0 rounded-full transition {{ $rule->enabled ? 'bg-emerald-500/80' : 'bg-zinc-700' }}"
                                    title="{{ $rule->enabled ? 'Enabled — click to disable' : 'Disabled — click to enable' }}">
                                <span class="absolute top-0.5 size-4 rounded-full bg-white transition-all {{ $rule->enabled ? 'left-4.5' : 'left-0.5' }}"></span>
                            </button>
                            <div class="min-w-0 flex-1">
                                <p class="font-medium {{ $rule->enabled ? 'text-zinc-100' : 'text-zinc-500' }}">{{ $rule->name }}</p>
                                <p class="mt-0.5 truncate text-xs text-zinc-500">
                                    {{ $rule->instance?->name ?? 'All instances' }} · {{ $rule->describeCondition() }} · cooldown {{ $rule->cooldown_minutes }}m
                                </p>
                                <p class="mt-0.5 text-xs text-zinc-500">
                                    Notifications: {{ implode(' + ', array_filter([$rule->webhook_url ? 'Teams' : null, $rule->email_recipients ? 'Email' : null])) ?: 'Internal only' }}
                                    · alarm delay {{ $rule->trigger_delay_minutes }}m · recovery delay {{ $rule->recovery_delay_minutes }}m
                                </p>
                            </div>
                            <div class="flex shrink-0 items-center gap-2">
                                <button type="button" wire:click="sendTest({{ $rule->id }})"
                                        class="rounded-lg border border-zinc-700 px-2.5 py-1.5 text-xs text-zinc-300 transition hover:border-zinc-500">
                                    Test
                                </button>
                                <button type="button" wire:click="edit({{ $rule->id }})"
                                        class="rounded-lg border border-zinc-700 px-2.5 py-1.5 text-xs text-zinc-300 transition hover:border-zinc-500">
                                    Edit
                                </button>
                                <button type="button" wire:click="delete({{ $rule->id }})"
                                        wire:confirm="Delete rule {{ $rule->name }} and its event history?"
                                        class="rounded-lg border border-rose-500/30 px-2.5 py-1.5 text-xs text-rose-400 transition hover:bg-rose-500/10">
                                    Delete
                                </button>
                            </div>
                        </div>
                    @empty
                        <p class="py-3 text-sm text-zinc-600">No rules yet — create the first one on the left. A good start: heartbeat &gt; 10 minutes, all instances.</p>
                    @endforelse
                </div>
            </x-panel>

            {{-- Recent events --}}
            <x-panel title="Recent events" subtitle="Last 50 triggers and recoveries">
                <div class="divide-y divide-zinc-800/70">
                    @forelse ($events as $event)
                        <div class="py-2.5 text-sm" wire:key="event-{{ $event->id }}">
                            <div class="flex items-center gap-3">
                                @if ($event->resolved_at)
                                    <span class="shrink-0 rounded bg-emerald-500/15 px-1.5 py-0.5 text-xs font-medium text-emerald-400">resolved</span>
                                @else
                                    <span class="shrink-0 rounded bg-rose-500/15 px-1.5 py-0.5 text-xs font-medium text-rose-400">open</span>
                                @endif
                                <span class="min-w-0 flex-1 truncate text-zinc-300">
                                    {{ $event->rule?->name ?? '(deleted rule)' }} — {{ $event->instance?->name }}
                                </span>
                                <span class="shrink-0 tabular-nums text-xs text-zinc-500">
                                    {{ $event->triggered_at->format('d.m. H:i') }}{{ $event->resolved_at ? ' → '.$event->resolved_at->format('H:i') : '' }}
                                </span>
                                @if ($event->notification_status === null && ! $event->notified)
                                    <span class="shrink-0 rounded bg-amber-500/15 px-1.5 py-0.5 text-xs text-amber-400" title="Teams delivery failed">not delivered</span>
                                @endif
                                @unless ($event->resolved_at)
                                    <button type="button" wire:click="startResolving({{ $event->id }})"
                                            class="shrink-0 rounded-lg border border-zinc-700 px-2.5 py-1.5 text-xs text-zinc-300 transition hover:border-zinc-500">
                                        Resolve
                                    </button>
                                @endunless
                            </div>

                            @if ($event->reason_code !== null || $event->last_reason_code !== null || $event->rule?->metric_type === \App\Models\AlertRule::TYPE_DATA_FLOW)
                                <p class="mt-1.5 text-xs text-zinc-400">Reason: {{ \App\Services\DataFlowStatus::reasonText($event->reason_code) }}</p>
                                @if ($event->last_reason_code !== null && $event->last_reason_code !== $event->reason_code)
                                    <p class="mt-1 text-xs text-zinc-500">
                                        Latest issue: {{ \App\Services\DataFlowStatus::reasonText($event->last_reason_code) }}
                                        @if ($event->last_reason_at)
                                            · {{ $event->last_reason_at->format('d.m. H:i') }} UTC
                                        @endif
                                    </p>
                                @endif
                            @endif

                            @if (! $event->resolved_at && $event->recovery_started_at)
                                <p class="mt-1.5 text-xs text-amber-400">Recovery being confirmed · since {{ $event->recovery_started_at->format('d.m. H:i') }} UTC</p>
                            @endif

                            @if (is_array($event->notification_status))
                                <div class="mt-1.5 space-y-1 text-xs text-zinc-500">
                                    @foreach (['triggered' => 'Alarm', 'resolved' => 'Recovery'] as $phase => $label)
                                        @php($channels = $event->notification_status[$phase] ?? null)
                                        @if (is_array($channels) && count($channels))
                                            <div class="flex flex-wrap items-center gap-2">
                                                <span>{{ $label }} delivery:</span>
                                                @if (($channels['teams'] ?? 'disabled') === 'disabled' && ($channels['email'] ?? 'disabled') === 'disabled')
                                                    <span>Internal only</span>
                                                @else
                                                    @foreach (['teams' => 'Teams', 'email' => 'Email'] as $channel => $channelLabel)
                                                        @php($status = $channels[$channel] ?? 'disabled')
                                                        <span class="{{ match ($status) { 'sent' => 'text-emerald-400', 'failed' => 'text-amber-400', default => 'text-zinc-500' } }}">
                                                            {{ $channelLabel }}: {{ match ($status) { 'sent' => 'delivered', 'failed' => 'delivery failed', 'pending' => 'pending', 'waiting' => ! $event->resolved_at && $event->recovery_started_at !== null ? 'held during recovery' : 'waiting for delay', 'cooldown' => 'waiting for cooldown', 'suppressed' => 'not sent (closed before delivery)', 'skipped' => 'not sent (no matching delivered alarm)', 'cancelled' => 'not sent (cancelled)', default => 'not configured' } }}
                                                        </span>
                                                    @endforeach
                                                @endif
                                            </div>
                                        @endif
                                    @endforeach
                                </div>
                            @endif

                            @if ($event->resolved_at && ($event->resolvedBy || $event->resolved_comment))
                                <div class="mt-1.5 pl-16 text-xs text-zinc-500">
                                    @if ($event->resolvedBy)
                                        Resolved by {{ $event->resolvedBy->name }}
                                    @else
                                        Resolved automatically
                                    @endif
                                    @if ($event->resolved_comment)
                                        <span class="text-zinc-600">·</span>
                                        <span class="text-zinc-400">{{ $event->resolved_comment }}</span>
                                    @endif
                                </div>
                            @endif

                            @if ($resolvingEventId === $event->id)
                                <form wire:submit="resolveEvent" class="mt-3 rounded-lg border border-zinc-800 bg-zinc-950/60 p-3">
                                    <label class="mb-1 block text-xs font-medium text-zinc-400">Resolution comment <span class="font-normal text-zinc-600">(optional)</span></label>
                                    <textarea wire:model="resolutionComment" rows="3"
                                              class="w-full resize-y rounded-lg border border-zinc-700 bg-zinc-950 px-3 py-2 text-sm text-zinc-100 outline-none transition placeholder:text-zinc-700 focus:border-sky-500 focus:ring-2 focus:ring-sky-500/30"
                                              placeholder="What changed, or why this can be closed?"></textarea>
                                    @error('resolutionComment') <p class="mt-1 text-sm text-rose-400">{{ $message }}</p> @enderror

                                    <div class="mt-2 flex justify-end gap-2">
                                        <button type="button" wire:click="cancelResolution"
                                                class="rounded-lg border border-zinc-700 px-3 py-1.5 text-xs text-zinc-300 transition hover:border-zinc-500">
                                            Cancel
                                        </button>
                                        <button type="submit"
                                                class="rounded-lg bg-emerald-500 px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-emerald-400">
                                            Mark resolved
                                        </button>
                                    </div>
                                </form>
                            @endif
                        </div>
                    @empty
                        <p class="py-3 text-sm text-zinc-600">No alert events yet. 🎉</p>
                    @endforelse
                </div>
            </x-panel>
        </div>
    </div>
</div>
