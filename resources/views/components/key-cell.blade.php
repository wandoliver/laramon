@props(['value', 'max' => 90])

<span class="block max-w-md truncate font-mono text-xs text-zinc-300" title="{{ $value }}">
    {{ \Illuminate\Support\Str::limit($value, $max) }}
</span>
