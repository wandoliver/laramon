# Alert notification channels

Alert rules always record incidents in **Alerts → Recent events**. External
notifications are optional and configured independently for each rule:

| Teams webhook | Email recipients | Behavior |
| --- | --- | --- |
| Empty | Empty | Internal incidents only |
| Configured | Empty | Internal incidents and Teams |
| Empty | Configured | Internal incidents and email |
| Configured | Configured | Internal incidents, Teams and email |

Existing rules retain their Teams webhook. No recipients or external notification
channels are enabled automatically. Notification delays do not change breach
thresholds or the requirement for confirmed data before automatic recovery.
Manual resolution keeps its existing behavior and sends no notification.

## Rule configuration

**Alarm delay (minutes)** defaults to **10**. A breach creates an internal
incident immediately, but Teams and email are sent only after the breach has
continued for the configured delay. A short incident remains in Recent events
without an alarm or recovery message. The alarm delay is additional to the rule
condition: a 10-minute heartbeat threshold plus a 10-minute alarm delay means
about 20 minutes without a heartbeat before notification. For metric rules, the
condition is evaluated over its configured window first; the alarm delay then
requires that condition to remain breached continuously.

**Recovery delay (minutes)** defaults to **5**. The incident remains open while
healthy data is being confirmed continuously. A new breach, missing data, stale
coverage or another unverified evaluation cancels that recovery timer. Missing
or stale data never confirms recovery. Recent events show **Recovery being
confirmed** while this timer runs. A recovery notification is sent only to a
channel that successfully received the alarm and whose destination is unchanged.

A bounded normal export with fresh prior confirmation is neutral while its final
confirmation is pending. It creates no new data-flow incident and preserves an
existing recovery candidate; it cannot complete recovery or confirm metric totals. The hub
allows at most five minutes from the first received chunk, without extending the
deadline for later chunks or retries. Missing prior confirmation, an overdue
transfer, stale data or invalid clocks still prevent recovery and remain actual
data-flow issues. See [Monitoring data flow](monitoring-data-flow.md) for the full
conditions.

Both delays are configurable per rule, apply independently to each instance,
and accept whole minutes from **0 to 1440**. Set **0** for immediate action.
Interrupted scheduler evaluation also resets the continuity timers; an elapsed
wall-clock delay without fresh checks does not prove a continuous state. Editing,
disabling or re-enabling a rule also restarts continuity checks for its open
incidents.

**Cooldown (minutes)** spaces external alarm notifications for the same rule and
instance. It does not postpone internal incident recording, replace either
delay, or suppress recovery checks. These controls do not send repeated reminders
for an already open incident.

Built-in metrics are always available in the **Metric** dropdown, including
`job:failed` and `scheduled_task:failed`, so rules can be configured before the
first recorded failure. Received custom metric types remain available too.
Selecting a metric does not create data or confirm an empty observation window.

The **Teams webhook URL** is optional. Enter an HTTP/HTTPS webhook only when
Teams notifications are wanted.

The **Email recipients** field accepts up to ten plain email addresses separated
by commas, semicolons or new lines. Display names and header syntax are rejected.
Each recipient gets a separate message so recipients do not see other addresses.
Choose your recipients with the monitoring information in mind: notifications
contain the rule, instance, condition, observed value and timestamps, plus a link
to the authenticated dashboard. They do not include exception samples, traces or
request contents.

**Test** checks the configured channels without creating an incident. For a rule
without external channels it explains that the rule is internal-only and sends
nothing. Testing an email rule sends a message using the alarm template with the
status **Test**. Tests send immediately, bypassing both delays and the cooldown.

Incidents are stored before external delivery is attempted. Recent events show
the trigger and recovery delivery results separately. Internal-only is distinct
from a failed send; failure of one channel does not prevent the other channel.
Editing a rule's channels does not rewrite an existing incident's delivery history.
Historical incidents retain their original Teams result. Waiting for a delay
or cooldown is distinct from failed delivery. Short incidents are marked
**not sent (closed before delivery)**; recoveries without a delivered alarm or with a
changed destination are marked **not sent (no matching delivered alarm)**.
An alarm that has not been sent is shown as **held during recovery** while
recovery is being confirmed; this is distinct from waiting for a new continuous
breach to reach its alarm delay.

Data-flow incidents also show the recorded opening **Reason**, with a timestamped
**Latest issue** if the failing condition changes. Only fixed, server-defined
reason labels are displayed. Older incidents without recorded reasons explicitly
show **Reason was not recorded** rather than inferring a cause from current data.

Sending is synchronous and requires no queue worker. This change does not add
automatic delivery retries or reminders for an already open incident. A failed
delivery remains visible in Recent events. A notification cancelled before
delivery is marked **not sent (cancelled)** and is terminal; it is not retried
automatically. A successful send means the configured
transport accepted the message; it does not establish inbox delivery.

## Central email templates

Open **Alerts → Email templates** to edit the central subject and plain-text
message separately for alarms and recoveries. Saved templates apply to all rules
with email recipients. The live preview uses example data and sends no email.
Authenticated monitoring users have the same access to this editor as to rules.

Supported placeholders:

```text
{{ app_name }}       {{ status }}          {{ rule_name }}
{{ instance_name }}  {{ condition }}       {{ value }}
{{ previous_value }} {{ triggered_at }}    {{ resolved_at }}
{{ dashboard_url }}
```

`previous_value` and `resolved_at` are populated for recoveries. Timestamp values
use UTC. Subjects are limited to 200 characters and bodies to 10,000 characters.
Unknown placeholders and control characters in subjects are rejected.

Templates are plain text with a fixed HTML email layout and a plain-text
alternative. HTML, PHP and Blade are not executed. Values are substituted once,
and the HTML representation is escaped. Emails load no remote logos or fonts.

## Deployment

Deploy only the monitoring application and run the new migrations:

```sh
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
sudo service php8.4-fpm reload
```

Use the equivalent process reload for your hosting setup and PHP version so
browser requests also load the new configuration. Notification channels and
email templates use `2026_10_19_000001_add_alert_notification_channels` and
`2026_10_19_000002_create_alert_email_templates_table`.

The delay update adds
`2026_10_20_000001_add_alert_notification_delays`. Running it sets the **10-minute
alarm delay** and **5-minute recovery delay** for all existing rules as well as
new ones. It preserves incident and delivery history and marks all pre-update
incidents as already attempted, so deployment does not resend old alarm messages.
Open incidents still need newly confirmed recovery checks and the configured
recovery delay. Historical delivery results are retained, but recovery messages
for pre-update incidents are skipped because their original destinations cannot
be safely verified. Existing rule thresholds, destinations and cooldowns are retained.
The update is hub-only; no agent update or configuration change is required.

The normal-export and incident-reason update adds
`2026_10_20_000002_add_alert_event_reasons`. Run the same migration, cache rebuild
and PHP-FPM reload steps above. It adds nullable reason fields without rewriting
existing incident history or changing notification destinations and delays. No
agent update is required.

Keep the monitoring scheduler running. Existing Teams-only rules continue to
work. New internal-only rules need no mail configuration.

For email, configure a real transport through the existing `config/mail.php`
and environment settings. For SMTP these include `MAIL_MAILER=smtp`,
`MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`,
`MAIL_FROM_ADDRESS` and `MAIL_FROM_NAME`. Set `MAIL_SCHEME=smtp` for SMTP
with STARTTLS negotiation (commonly port 587) or `MAIL_SCHEME=smtps` for implicit
TLS (commonly port 465), as specified by your mail provider. `MAIL_ENCRYPTION`
is not read by the shipped configuration; `MAIL_SCHEME=tls` is not valid.
`MAIL_TIMEOUT` defaults to five seconds and is bounded between one and ten
seconds. Rebuild cached configuration and reload PHP-FPM after changing mail
settings, then use a rule's **Test** action with your own address.

The `log` and `array` transports do not deliver email. A failover or roundrobin
mailer containing a non-delivering transport is also rejected, so falling back
to a log cannot appear as successful delivery. SMTP credentials remain in server
configuration and are not editable in the template editor or written to delivery
error messages.
