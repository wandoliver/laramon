<?php

namespace LaraMon\Agent\Tests;

use Illuminate\Support\Facades\Http;
use LaraMon\Agent\MonitorAgent;

class ActiveUsersTest extends TestCase
{
    private function seedActiveUser(string $userId, int $count = 3): int
    {
        $bucket = (int) (floor((now()->getTimestamp() - 900) / 300) * 300);

        $this->seedAggregate($bucket, 'active_user', $userId, 'count', $count);

        return $bucket;
    }

    public function test_user_ids_are_rewritten_with_resolved_labels(): void
    {
        Http::fake(['hub.test/*' => Http::response(['status' => 'ok'])]);

        MonitorAgent::resolveUsersUsing(fn (array $ids) => [42 => 'Maria Muster']);

        $this->seedActiveUser('42');
        $this->seedActiveUser('77');

        $this->artisan('monitor-agent:export')->assertExitCode(0);

        Http::assertSent(function ($request) {
            $keys = collect($request['buckets'])->where('type', 'active_user')->pluck('key');

            return $keys->contains('42|Maria Muster')
                && $keys->contains('77|User #77');
        });
    }

    public function test_failing_resolver_falls_back_to_generic_labels(): void
    {
        Http::fake(['hub.test/*' => Http::response(['status' => 'ok'])]);

        MonitorAgent::resolveUsersUsing(fn (array $ids) => throw new \RuntimeException('db gone'));

        $this->seedActiveUser('42');

        $this->artisan('monitor-agent:export')->assertExitCode(0);

        Http::assertSent(function ($request) {
            return collect($request['buckets'])->where('type', 'active_user')->pluck('key')->contains('42|User #42');
        });
    }
}
