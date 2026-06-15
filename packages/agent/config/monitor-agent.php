<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Monitor Agent
    |--------------------------------------------------------------------------
    |
    | When enabled, the agent exports Laravel Pulse aggregates and registered
    | business metrics to the configured LaraMon hub every five
    | minutes, plus a tiny heartbeat every minute. A misconfigured or
    | unreachable hub never affects the host application.
    |
    */

    'enabled' => env('MONITOR_AGENT_ENABLED', false),

    'hub_url' => env('MONITOR_HUB_URL'),

    'token' => env('MONITOR_HUB_TOKEN'),

    'bucket_seconds' => 300,

    'timeout' => env('MONITOR_AGENT_TIMEOUT', 5),

    'heartbeat_timeout' => env('MONITOR_AGENT_HEARTBEAT_TIMEOUT', 2),

    'max_buckets_per_batch' => 2000,

    // Track which authenticated users are active. Labels are produced by the
    // resolver registered via MonitorAgent::resolveUsersUsing() — without
    // one, users appear as "User #id".
    'track_users' => env('MONITOR_AGENT_TRACK_USERS', true),

    // Reported in heartbeats so the hub can show what is deployed.
    'app_version' => env('MONITOR_APP_VERSION'),

    // Business metric collector classes (implementing BusinessMetricCollector).
    // Closures can also be registered at runtime via the MonitorAgent facade.
    'collectors' => [
        LaraMon\Agent\Collectors\QueueBacklogCollector::class,
    ],

    // Pulse aggregate types exported to the hub. Keys are Pulse types,
    // values are the hub-side type names.
    'pulse_types' => [
        'request' => 'request',
        'slow_request' => 'slow_request',
        'slow_query' => 'slow_query',
        'slow_job' => 'slow_job',
        'slow_outgoing_request' => 'slow_outgoing_request',
        'exception' => 'exception',
        'cache_hit' => 'cache:hit',
        'cache_miss' => 'cache:miss',
        'active_user' => 'active_user',
        'request_hist' => 'request_hist',
        'queued' => 'job:queued',
        'processed' => 'job:processed',
        'released' => 'job:released',
        'failed' => 'job:failed',
        'scheduled_task' => 'scheduled_task',
        'scheduled_task_failed' => 'scheduled_task:failed',
    ],

];
