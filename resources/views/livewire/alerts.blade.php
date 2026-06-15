<div>
    <div class="mb-6">
        <h1 class="text-xl font-semibold tracking-tight">Alerts</h1>
        <p class="mt-1 text-sm text-zinc-500">Threshold rules evaluated every minute — breaches and recoveries go to Microsoft Teams.</p>
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
                            <option value="{{ $type }}">{{ $type === 'heartbeat' ? 'heartbeat (instance down)' : $type }}</option>
                        @endforeach
                    </select>
                </div>

                @if ($metric_type === 'heartbeat')
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

                <div>
                    <label class="mb-1 block text-sm text-zinc-400">Cooldown (minutes)</label>
                    <input type="number" wire:model="cooldown_minutes"
                           class="w-full rounded-lg border border-zinc-700 bg-zinc-950 px-3 py-2 text-sm outline-none focus:border-sky-500">
                </div>

                <div>
                    <label class="mb-1 block text-sm text-zinc-400">Teams webhook URL</label>
                    <input type="url" wire:model="webhook_url" placeholder="https://prod-…westeurope.logic.azure.com/…"
                           class="w-full rounded-lg border border-zinc-700 bg-zinc-950 px-3 py-2 text-sm outline-none focus:border-sky-500">
                    @error('webhook_url') <p class="mt-1 text-sm text-rose-400">{{ $message }}</p> @enderror
                </div>

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
                        <div class="flex items-center gap-3 py-2.5 text-sm" wire:key="event-{{ $event->id }}">
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
                            @unless ($event->notified)
                                <span class="shrink-0 rounded bg-amber-500/15 px-1.5 py-0.5 text-xs text-amber-400" title="Teams delivery failed">not delivered</span>
                            @endunless
                        </div>
                    @empty
                        <p class="py-3 text-sm text-zinc-600">No alert events yet. 🎉</p>
                    @endforelse
                </div>
            </x-panel>
        </div>
    </div>
</div>
