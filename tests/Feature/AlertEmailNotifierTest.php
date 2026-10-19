<?php

namespace Tests\Feature;

use App\Models\AlertEmailTemplate;
use App\Services\AlertEmailNotifier;
use App\Services\AlertEmailRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Mockery;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Tests\TestCase;

class AlertEmailNotifierTest extends TestCase
{
    use RefreshDatabase;

    private function fakeDeliverableTransport(?TransportInterface $transport = null): TransportInterface
    {
        $transport ??= new ArrayTransport;
        config(['mail.default' => 'smtp', 'mail.from.address' => 'monitor@example.com']);
        Mail::extend('smtp', fn () => $transport);

        return $transport;
    }

    private function send(array $recipients = ['one@example.com'], string $phase = 'triggered'): bool
    {
        return app(AlertEmailNotifier::class)->send($recipients, $phase, AlertEmailRenderer::previewVariables($phase));
    }

    public function test_recipients_receive_separate_multipart_messages_with_no_other_addresses(): void
    {
        $transport = $this->fakeDeliverableTransport();
        $this->assertTrue($this->send(['One@example.com', 'two@example.com', 'one@example.com']));
        $this->assertCount(2, $transport->messages());

        foreach ($transport->messages() as $index => $sent) {
            $email = $sent->getOriginalMessage();
            $this->assertCount(1, $email->getTo());
            $this->assertSame($index === 0 ? 'One@example.com' : 'two@example.com', $email->getTo()[0]->getAddress());
            $this->assertSame([], $email->getCc());
            $this->assertSame([], $email->getBcc());
            $this->assertStringContainsString('Example error alert', $email->getSubject());
            $this->assertStringContainsString('Instance: Example production', $email->getTextBody());
            $this->assertStringContainsString('Instance: Example production', $email->getHtmlBody());
        }
    }

    public function test_central_changes_apply_to_alarm_recovery_and_test_notifications(): void
    {
        $transport = $this->fakeDeliverableTransport();
        AlertEmailTemplate::query()->create(array_merge(AlertEmailTemplate::defaults(), [
            'triggered_subject' => 'Custom {{ status }} {{ rule_name }}',
            'resolved_subject' => 'Recovery {{ status }} {{ instance_name }}',
        ]));

        $this->assertTrue($this->send(phase: 'triggered'));
        $this->assertTrue($this->send(phase: 'resolved'));
        $this->assertTrue($this->send(phase: 'test'));
        $subjects = $transport->messages()->map(fn ($sent) => $sent->getOriginalMessage()->getSubject())->all();
        $this->assertSame([
            'Custom Alert Example error alert',
            'Recovery Resolved Example production',
            'Custom Test Example error alert',
        ], $subjects);
    }

    public function test_log_array_and_unsafe_composite_mailers_do_not_claim_delivery(): void
    {
        Mail::fake();
        foreach (['log', 'array', 'failover', 'missing'] as $mailer) {
            config(['mail.default' => $mailer]);
            $this->assertFalse($this->send());
        }
        config(['mail.default' => 'roundrobin', 'mail.mailers.roundrobin.mailers' => ['smtp', 'array']]);
        $this->assertFalse($this->send());
        config(['mail.default' => 'loop', 'mail.mailers.loop' => ['transport' => 'failover', 'mailers' => ['loop']]]);
        $this->assertFalse($this->send());
        Mail::assertNothingSent();
    }

    public function test_invalid_or_excessive_recipients_are_rejected_before_any_send(): void
    {
        $transport = $this->fakeDeliverableTransport();
        foreach ([[], ['valid@example.com', 'not-an-email'], ["one@example.com\r\nBcc: hidden@example.com"], [new \stdClass], array_fill(0, 11, 'one@example.com')] as $recipients) {
            $this->assertFalse($this->send($recipients));
        }
        $this->assertCount(0, $transport->messages());
    }

    public function test_a_cancelled_message_does_not_claim_delivery(): void
    {
        $transport = $this->fakeDeliverableTransport();
        Event::listen(MessageSending::class, fn () => false);
        $this->assertFalse($this->send());
        $this->assertCount(0, $transport->messages());
    }

    public function test_transport_errors_are_sanitized_and_do_not_stop_the_other_recipients(): void
    {
        $transport = Mockery::mock(TransportInterface::class);
        $transport->shouldReceive('send')->twice()->andThrow(new \RuntimeException('smtp-password recipient@example.com raw message'));
        $this->fakeDeliverableTransport($transport);
        Log::spy();

        $this->assertFalse($this->send(['one@example.com', 'two@example.com']));
        Log::shouldHaveReceived('warning')->once()->with('Alert email delivery failed.');
    }

    public function test_corrupt_saved_templates_and_unknown_phases_fail_without_delivery(): void
    {
        $transport = $this->fakeDeliverableTransport();
        AlertEmailTemplate::query()->create(array_merge(AlertEmailTemplate::defaults(), ['triggered_subject' => '{{ secret }}']));
        $this->assertFalse($this->send());
        $this->assertFalse($this->send(phase: 'invalid'));
        $this->assertCount(0, $transport->messages());
    }
}
