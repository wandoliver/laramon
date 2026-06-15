@props(['chart', 'height' => 230])

@if ($chart !== null)
    <div class="relative w-full" style="height: {{ $height }}px" wire:key="chart-{{ md5(json_encode($chart)) }}">
        <canvas data-chart="{{ json_encode($chart) }}"></canvas>
    </div>
@else
    <div class="flex items-center justify-center rounded-xl border border-dashed border-zinc-800 text-sm text-zinc-600" style="height: {{ $height }}px">
        No data in this range
    </div>
@endif
