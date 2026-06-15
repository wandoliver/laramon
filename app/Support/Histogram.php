<?php

namespace App\Support;

/**
 * Percentile estimation over the agent's fixed-boundary latency histogram.
 * Bins are non-cumulative counts keyed "le_<upper-ms>" (le_25 … le_12800)
 * plus "le_inf" for everything beyond the ladder.
 */
class Histogram
{
    /**
     * Upper bin boundaries in milliseconds — must match the agent's ladder.
     */
    public const BOUNDARIES = [25, 50, 100, 200, 400, 800, 1600, 3200, 6400, 12800];

    /**
     * @param  array<string, int>  $binCounts  e.g. ['le_100' => 10, 'le_200' => 10]
     */
    public static function percentile(array $binCounts, float $quantile): ?float
    {
        $total = array_sum($binCounts);

        if ($total === 0) {
            return null;
        }

        $rank = $quantile * $total;
        $cumulative = 0;
        $lower = 0;

        foreach (self::BOUNDARIES as $boundary) {
            $count = (int) ($binCounts['le_'.$boundary] ?? 0);

            if ($count > 0 && $cumulative + $count >= $rank) {
                // Linear interpolation inside the winning bin.
                $position = ($rank - $cumulative) / $count;

                return round($lower + ($boundary - $lower) * $position, 1);
            }

            $cumulative += $count;
            $lower = $boundary;
        }

        // The rank falls into le_inf: report the ladder's ceiling — better a
        // clamped value than a fabricated one.
        return (float) self::BOUNDARIES[array_key_last(self::BOUNDARIES)];
    }

    /**
     * Collapse raw histogram keys into bin counts. Keys are
     * "{route}|le_{boundary}" (agent >= 0.3) or bare "le_{boundary}"
     * (older agents — counted in the instance-wide aggregate only).
     *
     * @param  array<string, int>  $keyCounts
     * @return array<string, int>  bin => count
     */
    public static function bins(array $keyCounts, ?string $route = null): array
    {
        $bins = [];

        foreach ($keyCounts as $key => $count) {
            $separator = strrpos($key, '|');
            $bin = $separator === false ? $key : substr($key, $separator + 1);
            $keyRoute = $separator === false ? null : substr($key, 0, $separator);

            if ($route !== null && $keyRoute !== $route) {
                continue;
            }

            $bins[$bin] = ($bins[$bin] ?? 0) + $count;
        }

        return $bins;
    }

    /**
     * Group routed histogram keys into per-route bin maps (legacy keys
     * without a route are skipped — they carry no route dimension).
     *
     * @param  array<string, int>  $keyCounts
     * @return array<string, array<string, int>>  route => (bin => count)
     */
    public static function binsByRoute(array $keyCounts): array
    {
        $routes = [];

        foreach ($keyCounts as $key => $count) {
            $separator = strrpos($key, '|');

            if ($separator === false) {
                continue;
            }

            $route = substr($key, 0, $separator);
            $bin = substr($key, $separator + 1);

            $routes[$route][$bin] = ($routes[$route][$bin] ?? 0) + $count;
        }

        return $routes;
    }

    /**
     * The bin key a duration falls into (shared vocabulary with the agent).
     */
    public static function binFor(float $milliseconds): string
    {
        foreach (self::BOUNDARIES as $boundary) {
            if ($milliseconds <= $boundary) {
                return 'le_'.$boundary;
            }
        }

        return 'le_inf';
    }
}
