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
channels are enabled automatically. Notification settings do not change breach
thresholds, cooldowns or the requirement for confirmed data before automatic
recovery. Manual resolution keeps its existing behavior and sends no notification.

## Rule configuration

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
status **Test**.

Incidents are stored before external delivery is attempted. Recent events show
the trigger and recovery delivery results separately. Internal-only is distinct
from a failed send; failure of one channel does not prevent the other channel.
Editing a rule's channels does not rewrite an existing incident's delivery history.
Historical incidents retain their original Teams result.

Sending is synchronous and requires no queue worker. This change does not add
automatic delivery retries or reminders for an already open incident. A failed
delivery remains visible in Recent events. A successful send means the configured
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
browser requests also load the new configuration. The two new migrations are
`2026_10_19_000001_add_alert_notification_channels` and
`2026_10_19_000002_create_alert_email_templates_table`. The update is hub-only;
the bundled agent remains at 0.4.0.

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
