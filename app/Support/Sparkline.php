<?php

namespace App\Support;

use Illuminate\Support\HtmlString;

/**
 * Server-rendered SVG sparkline — no JS, morphs cleanly with Livewire.
 */
class Sparkline
{
    /**
     * @param  list<int|float>  $values
     */
    public static function render(array $values, string $stroke = 'currentColor', int $width = 120, int $height = 28): HtmlString
    {
        if (count($values) < 2) {
            $values = array_pad($values, 2, $values[0] ?? 0);
        }

        $max = max($values);
        $min = min($values);
        $span = $max - $min ?: 1;

        $stepX = $width / (count($values) - 1);
        $pad = 2;
        $innerHeight = $height - 2 * $pad;

        $points = [];

        foreach ($values as $i => $value) {
            $x = round($i * $stepX, 1);
            $y = round($pad + $innerHeight * (1 - ($value - $min) / $span), 1);
            $points[] = "{$x},{$y}";
        }

        $polyline = implode(' ', $points);
        $area = "0,{$height} ".$polyline." {$width},{$height}";

        return new HtmlString(<<<SVG
            <svg viewBox="0 0 {$width} {$height}" width="{$width}" height="{$height}" fill="none" preserveAspectRatio="none" aria-hidden="true">
                <polygon points="{$area}" fill="{$stroke}" opacity="0.1"/>
                <polyline points="{$polyline}" stroke="{$stroke}" stroke-width="1.5" stroke-linejoin="round" stroke-linecap="round"/>
            </svg>
            SVG);
    }
}
