<div>
    <div class="mb-6">
        <a href="{{ route('alerts') }}" class="text-sm text-sky-400 hover:text-sky-300">← Alerts</a>
        <h1 class="mt-3 text-xl font-semibold tracking-tight">Alert email templates</h1>
        <p class="mt-1 text-sm text-zinc-500">These central templates apply to all rules with email recipients. Write plain text; HTML and code are displayed as text.</p>
    </div>

    <x-panel title="Available placeholders" class="mb-6">
        <div class="flex flex-wrap gap-2">
            @foreach ($placeholders as $placeholder)
                <code class="rounded bg-zinc-900 px-2 py-1 text-xs text-zinc-300">&#123;&#123; {{ $placeholder }} &#125;&#125;</code>
            @endforeach
        </div>
        <p class="mt-3 text-xs text-zinc-500">Preview uses example data. Test notifications are sent from the individual alert rule. SMTP must be configured on the server; the log and array mailers do not deliver notifications.</p>
    </x-panel>

    <form wire:submit="save" class="space-y-6">
        @foreach (['triggered' => 'Alarm', 'resolved' => 'Recovery'] as $phase => $label)
            <x-panel :title="$label" wire:key="template-{{ $phase }}">
                <div class="grid gap-6 lg:grid-cols-2">
                    <div class="space-y-4">
                        <div>
                            <label for="{{ $phase }}_subject" class="mb-1.5 block text-sm text-zinc-400">Subject</label>
                            <input id="{{ $phase }}_subject" type="text" maxlength="200" wire:model.live.debounce.500ms="{{ $phase }}_subject"
                                   class="w-full rounded-lg border border-zinc-700 bg-zinc-950 px-3 py-2 text-sm outline-none focus:border-sky-500">
                            @error($phase.'_subject') <p class="mt-1.5 text-sm text-rose-400">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="{{ $phase }}_body" class="mb-1.5 block text-sm text-zinc-400">Message (plain text)</label>
                            <textarea id="{{ $phase }}_body" rows="12" maxlength="10000" wire:model.live.debounce.500ms="{{ $phase }}_body"
                                      class="w-full rounded-lg border border-zinc-700 bg-zinc-950 px-3 py-2 font-mono text-sm outline-none focus:border-sky-500"></textarea>
                            @error($phase.'_body') <p class="mt-1.5 text-sm text-rose-400">{{ $message }}</p> @enderror
                        </div>
                    </div>
                    <div>
                        <h2 class="mb-2 text-sm font-medium text-zinc-400">Preview with example data</h2>
                        @if ($previews[$phase])
                            <div class="rounded-lg border border-zinc-800 bg-zinc-900/50 p-4">
                                <p class="mb-4 break-words font-medium">{{ $previews[$phase]['subject'] }}</p>
                                <div class="whitespace-pre-wrap break-words text-sm text-zinc-300">{{ $previews[$phase]['body'] }}</div>
                            </div>
                        @else
                            <p class="text-sm text-amber-400">Correct this template to display a preview.</p>
                        @endif
                    </div>
                </div>
            </x-panel>
        @endforeach

        <div class="flex items-center gap-4">
            <button type="submit" class="rounded-lg bg-sky-500 px-4 py-2 text-sm font-semibold text-white hover:bg-sky-400">Save templates</button>
            @if ($saved) <p role="status" class="text-sm text-emerald-400">Email templates saved.</p> @endif
        </div>
    </form>
</div>
