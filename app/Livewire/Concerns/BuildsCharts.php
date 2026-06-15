<?php

namespace App\Livewire\Concerns;

use Illuminate\Support\Collection;

trait BuildsCharts
{
    /**
     * A single-series chart payload for the JS bootstrapper.
     *
     * @param  Collection<int, object>  $series
     * @return array<string, mixed>|null
     */
    protected function singleSeriesChart(Collection $series, int $from, int $to, int $step, string $label, string $color, string $metric = 'count', string $type = 'bar'): ?array
    {
        if ($series->isEmpty()) {
            return null;
        }

        return [
            'labels' => $this->chartLabels($from, $to, $step),
            'stacked' => false,
            'dualAxis' => false,
            'datasets' => [[
                'label' => $label,
                'type' => $type,
                'color' => $color,
                'fill' => $type === 'line',
                'stack' => null,
                'yAxisID' => 'y',
                'data' => $this->chartValues($series, $from, $to, $step, $metric, gapAware: $metric !== 'count'),
            ]],
        ];
    }

    /**
     * @return list<string>
     */
    protected function chartLabels(int $from, int $to, int $step): array
    {
        $format = ($to - $from) <= 86400 ? 'H:i' : 'd.m H:i';
        $labels = [];

        for ($t = $from - ($from % $step); $t <= $to; $t += $step) {
            $labels[] = date($format, $t);
        }

        return $labels;
    }

    /**
     * Align a series to fixed steps. Count metrics zero-fill; value metrics
     * gap-fill with null so charts show a hole instead of a fake zero.
     *
     * @param  Collection<int, object>  $series
     * @return list<float|null>
     */
    protected function chartValues(Collection $series, int $from, int $to, int $step, string $metric, bool $gapAware = false): array
    {
        $byTime = $series->keyBy('t');
        $values = [];

        for ($t = $from - ($from % $step); $t <= $to; $t += $step) {
            $value = $byTime[$t]->{$metric} ?? null;

            $values[] = $value !== null
                ? round((float) $value, 1)
                : ($gapAware ? null : 0.0);
        }

        return $values;
    }
}
