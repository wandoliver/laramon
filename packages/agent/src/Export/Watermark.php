<?php

namespace LaraMon\Agent\Export;

use Illuminate\Support\Facades\DB;

/**
 * Tracks the unix timestamp up to which Pulse aggregates have been shipped.
 * Stored in a dedicated table (not the cache) so `cache:clear` can never
 * cause re-export floods or gaps.
 */
class Watermark
{
    protected const KEY = 'export_watermark';

    public function get(): ?int
    {
        $value = DB::table('monitor_agent_state')->where('key', self::KEY)->value('value');

        return $value !== null ? (int) $value : null;
    }

    public function set(int $timestamp): void
    {
        DB::table('monitor_agent_state')->upsert(
            [['key' => self::KEY, 'value' => (string) $timestamp]],
            ['key'],
            ['value'],
        );
    }
}
