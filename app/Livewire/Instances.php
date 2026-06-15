<?php

namespace App\Livewire;

use App\Models\Instance;
use Illuminate\Support\Str;
use Livewire\Component;

class Instances extends Component
{
    public string $name = '';

    public string $base_url = '';

    public string $environment = 'production';

    public ?string $freshToken = null;

    public ?int $freshTokenInstanceId = null;

    public function create(): void
    {
        $this->validate([
            'name' => ['required', 'string', 'max:120'],
            'base_url' => ['nullable', 'url', 'max:255'],
            'environment' => ['required', 'string', 'max:40'],
        ]);

        $slug = Str::slug($this->name);

        if (Instance::query()->where('slug', $slug)->exists()) {
            $this->addError('name', 'An instance with this name already exists.');

            return;
        }

        $instance = Instance::query()->create([
            'name' => $this->name,
            'slug' => $slug,
            'base_url' => $this->base_url ?: null,
            'environment' => $this->environment,
            'token_hash' => str_repeat('0', 64),
        ]);

        $this->freshToken = $instance->rotateToken();
        $this->freshTokenInstanceId = $instance->id;

        $instance->forceFill(['previous_token_hash' => null, 'previous_token_expires_at' => null])->save();

        $this->reset('name', 'base_url');
        $this->environment = 'production';
    }

    public function rotate(int $instanceId): void
    {
        $instance = Instance::query()->findOrFail($instanceId);

        $this->freshToken = $instance->rotateToken();
        $this->freshTokenInstanceId = $instance->id;
    }

    public function delete(int $instanceId): void
    {
        Instance::query()->findOrFail($instanceId)->delete();

        if ($this->freshTokenInstanceId === $instanceId) {
            $this->reset('freshToken', 'freshTokenInstanceId');
        }
    }

    public function render()
    {
        return view('livewire.instances', [
            'instances' => Instance::query()->orderBy('name')->get(),
        ])->title('Instances — '.config('app.name'));
    }
}
