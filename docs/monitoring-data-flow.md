# Monitoring data flow and scheduled tasks

LaraMon distinguishes received history from verified observation. The hub can show
historical metrics while their completeness is unknown. Empty charts and missing
records do not confirm that no errors occurred.

## Reading the data-flow panel

The instance page shows three separate signals:

| Signal | What it establishes |
| --- | --- |
| Heartbeat | The agent's scheduler recently reached the hub. It does not prove metrics arrived. |
| Accepted export | The hub recently accepted an export. A partially delivered export is still unverified. |
| Export coverage | The agent finished exporting a settled interval and the hub accepted its final confirmation. |

An instance is healthy only when all three signals are fresh and completeness
covers a full five-minute interval. Heartbeats must be less than two minutes old;
accepted exports and completeness proofs must remain within fifteen minutes.
Invalid clocks, excessive clock skew, partial exports, and missing confirmation
leave monitoring unverified. A stale heartbeat can also indicate a stopped
application scheduler.

The fleet's 24-hour history indicator shows how much of the totals window is
confirmed, for example **6h 40m of 24h confirmed** and **17h 20m still
unconfirmed**. **Confirmed** in the export panel refers to its displayed interval;
the full 24-hour history may still be incomplete. Stale, pending, or missing
confirmations and gaps inside that interval leave progress unverified. A fully
confirmed time window can still contain unverified metric types, which are
labelled separately.

The fleet's verified totals end at the displayed confirmation boundary. The open
tail is excluded. Historical charts and tables remain useful observations but may
contain gaps. Missing current queue statistics are unavailable, even if an older
heartbeat reported zero failed jobs.

## How completeness is established

The agent exports closed, settled five-minute Pulse intervals. It sends a separate
empty confirmation batch only after the hub acknowledges every data chunk. Quiet
intervals still require confirmation. Failed exports retain their local watermark
and pending occurrence samples for retry; duplicate or older confirmations cannot
make a stalled stream look fresh.

Completeness is limited to known, enabled, fully sampled Pulse recorders whose
type mappings remain intact. It requires Pulse's direct `storage` ingest driver
and `database` storage driver; the exporter reads the configured
`pulse.storage.database.connection`. Redis ingestion and custom ingest/storage
drivers cannot confirm completeness. Existing export behavior continues, but its
data remains unverified. Redis used for unrelated application caching is not this
restriction.

The agent starts capture at a future five-minute boundary when completeness
monitoring begins, the capture configuration changes, or capture observations have
been interrupted. The hub also establishes its own observation baseline. Both
must observe new, continuous intervals before completeness becomes healthy.
Returning from an unsupported Pulse pipeline starts a new baseline. This warm-up
can take several export cycles; metric alert recovery additionally needs its full
configured window. Previous history is never retroactively declared complete.

Pulse retains fine-grained aggregates for a limited time. Long export outages can
lose history; the agent reports an export gap and starts confirmation from
available data. This mechanism does not add a durable error-event spool.

## Alert behavior

Metric alert recovery requires fresh, complete data for the rule's entire window,
whose end must follow the incident start. Heartbeat and data-flow alert recovery
require healthy data flow with a confirmation received after the incident began;
they do not use a metric rule window. Missing data is treated as unknown. Only a
fully covered event stream can interpret an absent count as zero. Observed
breaches can still open incidents before completeness is established; older clean
windows do not override newer evidence of a breach.

Custom gauge and counter rules need an explicit key and a real, finite sample in
every five-minute bucket. A missing collector cannot imply zero. Rule windows
must be multiples of five minutes to be fully verified from five-minute data;
other window sizes cannot automatically confirm recovery. Manual resolution stays
an explicit operator action.

To be notified about the observation system itself:

1. In **Alerts**, create a rule and select **data flow (missing, stale or incomplete)**.
2. Choose one instance or all instances, and set a notification cooldown.
3. Save the rule for internal-only incidents, or enter email recipients, a Teams Workflows webhook URL, or both. Use **Test** to verify configured channels.

The data-flow condition is fixed to `> 0`: unavailable or unverified data is `1`,
verified healthy data is `0`. A newly upgraded instance can alert during warm-up.
No rule, email recipients, or webhook is created automatically. Configured
notifications send alert details through the selected mail transport or webhook;
metric storage remains on your hub. See [Alert notification channels](alert-notifications.md)
for editable email templates and per-channel delivery status.

## Scheduled task outcomes

The Scheduler table includes successful and failed tasks before pagination, so
tasks with only failures remain visible. Foreground success requires exit code
zero. A background launch is not success; the agent records its actual completion
status when Laravel emits the completion event. Background completions do not
invent a zero runtime, and averages use only runs with measured runtimes.

Historical timestamps identify aggregate bucket starts. When success and failure
occur in one bucket, their ordering is uncertain. Exact completion metrics are
used for current expected-run monitoring. If Laravel cannot emit an outcome event,
normal exception monitoring remains responsible for the exception.

## Opt in critical scheduled tasks

Give a critical task a unique, static Laravel schedule name:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('reports:refresh')
    ->name('reports-refresh')
    ->everyTenMinutes()
    ->timezone('UTC');
```

Then add the name to `config/monitor-agent.php` in the monitored application:

```php
'expected_scheduled_tasks' => [
    'reports-refresh' => 900,
],
```

The number is the allowed delay in seconds after the expected run, including
maximum runtime and export delay. Its permitted range is `900` to `604800`.
Names allow ASCII letters, digits, dots, underscores, colons, and hyphens, up to
120 characters. Only explicitly configured names registered in Laravel's schedule
are exported; duplicate names are excluded. Generic installations default to no
expectations.

The agent reads the cron expression and timezone without running the task or its
`when`/`skip` filters. Opt in a conditional task only when every due run is expected.
Schedule execution, callbacks, mutexes, and failure handlers are unchanged.

The current expectations panel is independent of the historical chart range.
Initial setup or changed schedules start a new baseline and do not create
retrospective overdue warnings. Overdue status requires a passed deadline and
complete export coverage through that deadline. Current success also requires
fresh, complete coverage of both scheduler outcome streams since the expected
run. Until those requirements are met, the panel shows **Unknown** or
**Awaiting data**. Missing data never implies a successful run.

The scheduler manifest contains only bounded task names, cron expressions,
timezones, allowed delays, and an export watermark. It excludes command arguments,
process output, and filter results. Completeness metadata adds only technical
timestamps and metric types; capture configuration is stored locally as a digest.

## Upgrade compatibility

Deploy the hub before updating agents. Older agents can continue sending history,
but cannot attest completeness or automatically resolve incidents. Updating the
hub does not install the updated agent in monitored applications.

The 0.4.0 scheduler/data-flow features need no new database migration on an
existing installation. The 0.4.1 notification update does require migrations;
see its [upgrade checklist](install.md#upgrading-to-041). If an
application published its agent configuration, merge the new defaults and retain
the `scheduled_task_last_success` and `scheduled_task_last_failure` Pulse type
mappings. Expectations are suppressed if Pulse, its scheduler recorder, or these
mappings are disabled. Keep both the hub scheduler and application scheduler
running, and reload cached configuration and long-running workers through your
normal deployment process.

See the [0.4.0 upgrade checklist](install.md#upgrading-to-040) for the deployment
steps.
