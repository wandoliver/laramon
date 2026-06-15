<?php

namespace Tests\Unit;

use App\Support\Histogram;
use PHPUnit\Framework\TestCase;

class HistogramTest extends TestCase
{
    public function test_percentile_interpolates_within_the_winning_bin(): void
    {
        // 10 samples ≤100ms, 10 samples in (100, 200]: p95 → rank 19 of 20,
        // 90% through the second bin: 100 + (200-100) * 0.9 = 190.
        $bins = ['le_100' => 10, 'le_200' => 10];

        $this->assertSame(190.0, Histogram::percentile($bins, 0.95));
    }

    public function test_median_interpolates_from_the_bin_lower_edge(): void
    {
        // le_50 is empty, so all samples sit in (50, 100]; the median is the
        // midpoint of that bin — standard cumulative-histogram semantics.
        $this->assertSame(75.0, Histogram::percentile(['le_100' => 10], 0.5));
    }

    public function test_overflow_bin_clamps_to_ladder_ceiling(): void
    {
        $this->assertSame(12800.0, Histogram::percentile(['le_inf' => 5], 0.95));
    }

    public function test_empty_bins_yield_null(): void
    {
        $this->assertNull(Histogram::percentile([], 0.95));
        $this->assertNull(Histogram::percentile(['le_100' => 0], 0.95));
    }

    public function test_bins_aggregates_routed_and_legacy_keys(): void
    {
        $keyCounts = [
            '/users|le_100' => 5,
            '/users/roles|le_100' => 3,
            'le_100' => 2,          // legacy, no route dimension
            '/users|le_400' => 1,
        ];

        // Instance-wide: everything counts.
        $this->assertSame(['le_100' => 10, 'le_400' => 1], Histogram::bins($keyCounts));

        // Per-route: exact route match only (no prefix bleed between
        // /users and /users/roles), legacy keys excluded.
        $this->assertSame(['le_100' => 5, 'le_400' => 1], Histogram::bins($keyCounts, '/users'));
        $this->assertSame(['le_100' => 3], Histogram::bins($keyCounts, '/users/roles'));
    }

    public function test_bins_by_route_groups_and_skips_legacy(): void
    {
        $grouped = Histogram::binsByRoute([
            '/a|le_100' => 4,
            '/a|le_200' => 2,
            '/b|le_50' => 7,
            'le_100' => 9,
        ]);

        $this->assertSame(['/a' => ['le_100' => 4, 'le_200' => 2], '/b' => ['le_50' => 7]], $grouped);
    }

    public function test_bin_assignment_matches_boundaries(): void
    {
        $this->assertSame('le_25', Histogram::binFor(10));
        $this->assertSame('le_25', Histogram::binFor(25));
        $this->assertSame('le_50', Histogram::binFor(26));
        $this->assertSame('le_12800', Histogram::binFor(12800));
        $this->assertSame('le_inf', Histogram::binFor(99999));
    }
}
