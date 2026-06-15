<?php

namespace App\Console\Commands;

use App\Models\Instance;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class MakeInstance extends Command
{
    protected $signature = 'monitor:make-instance
        {name : Display name, e.g. "Client X Production"}
        {--url= : Base URL of the instance}
        {--environment=production : Environment label}';

    protected $description = 'Register a monitored instance and print its API token (shown only once)';

    public function handle(): int
    {
        $name = $this->argument('name');
        $slug = Str::slug($name);

        if (Instance::query()->where('slug', $slug)->exists()) {
            $this->error("An instance with slug [{$slug}] already exists.");

            return self::FAILURE;
        }

        $instance = Instance::query()->create([
            'name' => $name,
            'slug' => $slug,
            'base_url' => $this->option('url'),
            'environment' => $this->option('environment'),
            'token_hash' => str_repeat('0', 64), // replaced immediately below
        ]);

        $token = $instance->rotateToken();
        $instance->forceFill(['previous_token_hash' => null, 'previous_token_expires_at' => null])->save();

        $this->info("Instance [{$instance->name}] registered with slug [{$instance->slug}].");
        $this->newLine();
        $this->line('API token (store it now — it will not be shown again):');
        $this->line("  <options=bold>{$token}</>");

        return self::SUCCESS;
    }
}
