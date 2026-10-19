<?php

namespace App\Livewire;

use App\Models\AlertEmailTemplate;
use App\Services\AlertEmailRenderer;
use Closure;
use Livewire\Component;

class AlertEmailTemplates extends Component
{
    public string $triggered_subject = '';

    public string $triggered_body = '';

    public string $resolved_subject = '';

    public string $resolved_body = '';

    public bool $saved = false;

    public function mount(): void
    {
        abort_unless(auth()->check(), 403);
        $template = AlertEmailTemplate::current();
        foreach (array_keys(AlertEmailTemplate::defaults()) as $field) {
            $this->{$field} = (string) $template->{$field};
        }
    }

    public function save(): void
    {
        abort_unless(auth()->check(), 403);
        $this->saved = false;
        $rules = [];
        foreach (array_keys(AlertEmailTemplate::defaults()) as $field) {
            $subject = str_ends_with($field, '_subject');
            $rules[$field] = ['required', 'string', 'max:'.($subject ? 200 : 10000), function (string $attribute, mixed $value, Closure $fail) use ($subject): void {
                if (! is_string($value) || ! AlertEmailRenderer::isValidTemplate($value, $subject)) {
                    $fail('Use only the listed placeholders. Subjects must be a single line; control characters are not allowed.');
                }
            }];
        }

        $data = $this->validate($rules);
        AlertEmailTemplate::query()->updateOrCreate(['id' => 1], $data);
        $this->saved = true;
    }

    public function updated(string $property): void
    {
        if (array_key_exists($property, AlertEmailTemplate::defaults())) {
            $this->saved = false;
        }
    }

    public function render()
    {
        abort_unless(auth()->check(), 403);
        $previews = [];
        $template = new AlertEmailTemplate([
            'triggered_subject' => $this->triggered_subject,
            'triggered_body' => $this->triggered_body,
            'resolved_subject' => $this->resolved_subject,
            'resolved_body' => $this->resolved_body,
        ]);
        foreach (['triggered', 'resolved'] as $phase) {
            try {
                $previews[$phase] = app(AlertEmailRenderer::class)->render($template, $phase, AlertEmailRenderer::previewVariables($phase));
            } catch (\InvalidArgumentException) {
                $previews[$phase] = null;
            }
        }

        return view('livewire.alert-email-templates', [
            'placeholders' => AlertEmailRenderer::PLACEHOLDERS,
            'previews' => $previews,
        ])->title('Alert email templates — '.config('app.name'));
    }
}
