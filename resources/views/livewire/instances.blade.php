<div>
    <div class="mb-6">
        <h1 class="text-xl font-semibold tracking-tight">Instances</h1>
        <p class="mt-1 text-sm text-zinc-500">Register the applications that report to this hub.</p>
    </div>

    @if ($freshToken)
        <div class="mb-6 rounded-2xl border border-emerald-500/30 bg-emerald-500/10 p-5">
            <p class="text-sm font-medium text-emerald-300">
                Token for {{ $instances->firstWhere('id', $freshTokenInstanceId)?->name }} — copy it now, it will not be shown again.
            </p>
            <div class="mt-3 flex items-center gap-3">
                <code class="block flex-1 overflow-x-auto rounded-lg bg-zinc-950/80 px-4 py-2.5 font-mono text-sm text-emerald-200">{{ $freshToken }}</code>
                <button type="button"
                        onclick="navigator.clipboard.writeText(this.previousElementSibling.textContent.trim()); this.textContent = 'Copied!'"
                        class="shrink-0 rounded-lg border border-emerald-500/40 px-3 py-2 text-sm text-emerald-300 transition hover:bg-emerald-500/10">
                    Copy
                </button>
            </div>
            <p class="mt-2 text-xs text-emerald-400/70">
                On the instance: set <code class="font-mono">MONITOR_HUB_URL={{ url('/') }}</code> and <code class="font-mono">MONITOR_HUB_TOKEN=…</code>, then run <code class="font-mono">php artisan monitor-agent:test</code>.
            </p>
        </div>
    @endif

    <div class="grid gap-4 lg:grid-cols-3">
        <x-panel title="Register instance" class="self-start">
            <form wire:submit="create" class="space-y-4">
                <div>
                    <label for="name" class="mb-1.5 block text-sm text-zinc-400">Name</label>
                    <input id="name" type="text" wire:model="name" placeholder="Client X Production"
                           class="w-full rounded-lg border border-zinc-700 bg-zinc-950 px-3 py-2 text-sm outline-none transition focus:border-sky-500 focus:ring-2 focus:ring-sky-500/30">
                    @error('name') <p class="mt-1.5 text-sm text-rose-400">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="base_url" class="mb-1.5 block text-sm text-zinc-400">Base URL <span class="text-zinc-600">(optional)</span></label>
                    <input id="base_url" type="url" wire:model="base_url" placeholder="https://client-x.example.com"
                           class="w-full rounded-lg border border-zinc-700 bg-zinc-950 px-3 py-2 text-sm outline-none transition focus:border-sky-500 focus:ring-2 focus:ring-sky-500/30">
                    @error('base_url') <p class="mt-1.5 text-sm text-rose-400">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="environment" class="mb-1.5 block text-sm text-zinc-400">Environment</label>
                    <input id="environment" type="text" wire:model="environment"
                           class="w-full rounded-lg border border-zinc-700 bg-zinc-950 px-3 py-2 text-sm outline-none transition focus:border-sky-500 focus:ring-2 focus:ring-sky-500/30">
                    @error('environment') <p class="mt-1.5 text-sm text-rose-400">{{ $message }}</p> @enderror
                </div>
                <button type="submit"
                        class="w-full rounded-lg bg-sky-500 px-4 py-2 text-sm font-semibold text-white transition hover:bg-sky-400">
                    Register & issue token
                </button>
            </form>
        </x-panel>

        <x-panel title="Registered instances" class="lg:col-span-2">
            <div class="divide-y divide-zinc-800/70">
                @forelse ($instances as $instance)
                    <div class="flex flex-wrap items-center gap-3 py-3" wire:key="row-{{ $instance->id }}">
                        <x-health-dot :health="$instance->health()" />
                        <div class="min-w-0 flex-1">
                            <a href="{{ route('instance', $instance) }}" class="font-medium text-zinc-100 hover:text-sky-300">{{ $instance->name }}</a>
                            <p class="mt-0.5 text-xs text-zinc-500">
                                {{ $instance->slug }} · {{ $instance->environment }}
                                · {{ $instance->last_heartbeat_at ? 'heartbeat '.$instance->last_heartbeat_at->diffForHumans(short: true) : 'never seen' }}
                                @if ($instance->previous_token_expires_at?->isFuture())
                                    · <span class="text-amber-400">old token valid until {{ $instance->previous_token_expires_at->format('d.m. H:i') }}</span>
                                @endif
                            </p>
                        </div>
                        <div class="flex shrink-0 items-center gap-2">
                            <button type="button" wire:click="rotate({{ $instance->id }})"
                                    wire:confirm="Rotate the token for {{ $instance->name }}? The current token keeps working for 7 days."
                                    class="rounded-lg border border-zinc-700 px-3 py-1.5 text-xs text-zinc-300 transition hover:border-zinc-500 hover:text-zinc-100">
                                Rotate token
                            </button>
                            <button type="button" wire:click="delete({{ $instance->id }})"
                                    wire:confirm="Delete {{ $instance->name }} and ALL of its collected metrics? This cannot be undone."
                                    class="rounded-lg border border-rose-500/30 px-3 py-1.5 text-xs text-rose-400 transition hover:bg-rose-500/10">
                                Delete
                            </button>
                        </div>
                    </div>
                @empty
                    <p class="py-3 text-sm text-zinc-600">Nothing registered yet.</p>
                @endforelse
            </div>
        </x-panel>
    </div>
</div>
