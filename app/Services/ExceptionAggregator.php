<?php

namespace App\Services;

use App\Models\ExceptionGroup;
use App\Models\Instance;
use Illuminate\Support\Carbon;

class ExceptionAggregator
{
    /**
     * Upsert exception group metadata. Counts are additive — the caller is
     * responsible for filtering duplicate batches before this runs.
     *
     * @param  list<array{fingerprint: string, class: string, location: string|null, count: int, last_seen_at: string}>  $exceptions
     */
    public function record(Instance $instance, array $exceptions): void
    {
        foreach ($exceptions as $exception) {
            $lastSeenAt = Carbon::parse($exception['last_seen_at']);

            $group = ExceptionGroup::query()->firstOrCreate(
                [
                    'instance_id' => $instance->id,
                    'fingerprint' => $exception['fingerprint'],
                ],
                [
                    'class' => mb_substr($exception['class'], 0, 255),
                    'location' => $exception['location'] !== null
                        ? mb_substr($exception['location'], 0, 500)
                        : null,
                    'first_seen_at' => $lastSeenAt,
                    'last_seen_at' => $lastSeenAt,
                    'total_count' => 0,
                ],
            );

            $group->newQuery()
                ->whereKey($group->id)
                ->increment('total_count', (int) $exception['count']);

            $group->newQuery()
                ->whereKey($group->id)
                ->where('last_seen_at', '<', $lastSeenAt)
                ->update(['last_seen_at' => $lastSeenAt]);
        }
    }
}
