<?php

namespace Tests\Feature;

use App\Livewire\AlertEmailTemplates;
use App\Mail\AlertNotificationMail;
use App\Models\AlertEmailTemplate;
use App\Models\User;
use App\Services\AlertEmailRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AlertEmailTemplateTest extends TestCase
{
    use RefreshDatabase;

    public function test_settings_page_requires_authentication(): void
    {
        $this->get(route('alerts.email-templates'))->assertRedirect('/login');
        Livewire::test(AlertEmailTemplates::class)->assertForbidden();
    }

    public function test_authenticated_users_can_preview_defaults_and_edit_central_templates(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get(route('alerts.email-templates'))
            ->assertOk()->assertSee('Preview with example data');

        Livewire::actingAs($user)->test(AlertEmailTemplates::class)
            ->assertSet('triggered_subject', AlertEmailTemplate::defaults()['triggered_subject'])
            ->set('triggered_subject', 'Alarm: {{ rule_name }}')
            ->set('resolved_subject', 'Recovered: {{ instance_name }}')
            ->set('resolved_body', 'Recovered at {{ resolved_at }}')
            ->call('save')->assertHasNoErrors()->assertSet('saved', true)
            ->set('triggered_body', 'Unsaved edits')->assertSet('saved', false);

        $this->assertSame(1, AlertEmailTemplate::query()->count());
        $this->assertSame('Alarm: {{ rule_name }}', AlertEmailTemplate::current()->triggered_subject);

        Livewire::actingAs($user)->test(AlertEmailTemplates::class)
            ->assertSet('triggered_subject', 'Alarm: {{ rule_name }}')
            ->set('triggered_subject', 'Updated {{ rule_name }}')->call('save')->assertHasNoErrors();
        $this->assertSame(1, AlertEmailTemplate::query()->count());
    }

    public function test_unknown_and_executable_placeholders_are_rejected_without_losing_the_input(): void
    {
        $user = User::factory()->create();
        foreach (['{{ unknown }}', '{{ config("app.key") }}', '{{ rule_name', 'bad }}'] as $invalid) {
            Livewire::actingAs($user)->test(AlertEmailTemplates::class)
                ->set('triggered_body', $invalid)
                ->call('save')->assertHasErrors(['triggered_body'])
                ->assertSet('triggered_body', $invalid);
        }

        $this->assertSame(0, AlertEmailTemplate::query()->count());
    }

    public function test_settings_create_the_fixed_singleton_even_when_the_sequence_has_advanced(): void
    {
        AlertEmailTemplate::query()->create(['id' => 2] + AlertEmailTemplate::defaults());
        $user = User::factory()->create();

        Livewire::actingAs($user)->test(AlertEmailTemplates::class)
            ->set('triggered_subject', 'Singleton {{ rule_name }}')
            ->call('save')->assertHasNoErrors();

        $this->assertSame(1, AlertEmailTemplate::current()->id);
        $this->assertSame('Singleton {{ rule_name }}', AlertEmailTemplate::current()->triggered_subject);
        Livewire::actingAs($user)->test(AlertEmailTemplates::class)
            ->assertSet('triggered_subject', 'Singleton {{ rule_name }}')
            ->set('resolved_subject', 'Updated singleton')
            ->call('save')->assertHasNoErrors();

        $this->assertSame(2, AlertEmailTemplate::query()->count());
        $this->assertSame('Updated singleton', AlertEmailTemplate::query()->findOrFail(1)->resolved_subject);
    }

    public function test_subject_header_injection_and_template_size_are_rejected(): void
    {
        $user = User::factory()->create();
        Livewire::actingAs($user)->test(AlertEmailTemplates::class)
            ->set('triggered_subject', "Alert\r\nBcc: hidden@example.com")
            ->set('resolved_body', str_repeat('x', 10001))
            ->call('save')->assertHasErrors(['triggered_subject', 'resolved_body']);
        $this->assertSame(0, AlertEmailTemplate::query()->count());
    }

    public function test_variable_values_are_not_reinterpreted_and_subject_control_characters_are_removed(): void
    {
        $template = new AlertEmailTemplate(array_merge(AlertEmailTemplate::defaults(), [
            'triggered_subject' => '{{ rule_name }}',
            'triggered_body' => '{{ rule_name }} {{ instance_name }}',
        ]));
        $rendered = app(AlertEmailRenderer::class)->render($template, 'triggered', [
            'rule_name' => "Rule\r\nBcc: secret@example.com {{ instance_name }}",
            'instance_name' => '<script>alert(1)</script>',
        ]);

        $this->assertStringNotContainsString("\r", $rendered['subject']);
        $this->assertStringNotContainsString("\n", $rendered['subject']);
        $this->assertStringContainsString('{{ instance_name }}', $rendered['subject']);
        $this->assertStringContainsString('{{ instance_name }}', $rendered['body']);
        $this->assertStringContainsString('<script>alert(1)</script>', $rendered['body']);
    }

    public function test_email_and_preview_escape_html_while_text_alternative_preserves_plain_text(): void
    {
        $content = '<script>alert("x")</script> & {{ rule_name }} @php file_put_contents("bad", "x"); @endphp';
        $html = (new AlertNotificationMail('Subject <unsafe>', $content))->render();
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringContainsString('{{ rule_name }}', $html);
        $this->assertStringContainsString('@php file_put_contents', $html);
        $this->assertSame($content."\n", view('mail.alert-notification-text', ['bodyText' => $content])->render());

        Livewire::actingAs(User::factory()->create())->test(AlertEmailTemplates::class)
            ->set('triggered_body', '<script>alert("preview")</script>')
            ->assertSee('&lt;script&gt;', false)
            ->assertDontSee('<script>alert("preview")</script>', false);
    }

    public function test_recovery_uses_its_own_template_and_all_allowed_placeholders_work(): void
    {
        $template = new AlertEmailTemplate(array_merge(AlertEmailTemplate::defaults(), [
            'resolved_subject' => 'Recovery {{ status }}',
            'resolved_body' => implode(' / ', array_map(fn ($key) => '{{ '.$key.' }}', AlertEmailRenderer::PLACEHOLDERS)),
        ]));
        $variables = AlertEmailRenderer::previewVariables('resolved');
        $rendered = app(AlertEmailRenderer::class)->render($template, 'resolved', $variables);

        $this->assertSame('Recovery Resolved', $rendered['subject']);
        foreach ($variables as $value) {
            $this->assertStringContainsString($value, $rendered['body']);
        }
    }
}
