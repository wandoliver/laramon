<?php

namespace LaraMon\Agent\Recorders;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Laravel\Pulse\Concerns\ConfiguresAfterResolving;
use Laravel\Pulse\Pulse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Records which authenticated users are active (requests per user id).
 * User ids are translated to display labels at export time by the host
 * app's resolver — raw identifiers never need to leave the instance.
 */
class ActiveUsers
{
    use ConfiguresAfterResolving;

    public function __construct(
        protected Pulse $pulse,
    ) {}

    public function register(callable $record, Application $app): void
    {
        $this->afterResolving(
            $app,
            Kernel::class,
            fn (Kernel $kernel) => $kernel->whenRequestLifecycleIsLongerThan(-1, $record),
        );
    }

    public function record(Carbon $startedAt, Request $request, Response $response): void
    {
        $userId = $this->pulse->resolveAuthenticatedUserId();

        if ($userId === null) {
            return;
        }

        $this->pulse->record(
            type: 'active_user',
            key: (string) $userId,
            timestamp: $startedAt,
        )->count()->onlyBuckets();
    }
}
