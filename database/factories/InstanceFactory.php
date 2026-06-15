<?php

namespace Database\Factories;

use App\Models\Instance;
use App\Support\InstanceToken;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Instance>
 */
class InstanceFactory extends Factory
{
    protected $model = Instance::class;

    public function definition(): array
    {
        $name = $this->faker->unique()->company();

        return [
            'name' => $name,
            'slug' => Str::slug($name),
            'base_url' => 'https://'.Str::slug($name).'.example.com',
            'environment' => 'production',
            'token_hash' => InstanceToken::hash(Str::random(48)),
            'last_heartbeat_at' => now(),
        ];
    }

    /**
     * Set a known plaintext token so tests can authenticate.
     */
    public function withToken(string $token): static
    {
        return $this->state(fn () => ['token_hash' => InstanceToken::hash($token)]);
    }
}
