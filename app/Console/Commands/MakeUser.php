<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class MakeUser extends Command
{
    protected $signature = 'monitor:make-user {name} {email}';

    protected $description = 'Create a dashboard user (prompts for the password)';

    public function handle(): int
    {
        $email = $this->argument('email');

        if (User::query()->where('email', $email)->exists()) {
            $this->error("A user with email [{$email}] already exists.");

            return self::FAILURE;
        }

        $password = $this->secret('Password');

        if ($password === null || strlen($password) < 8) {
            $this->error('Password must be at least 8 characters.');

            return self::FAILURE;
        }

        User::query()->create([
            'name' => $this->argument('name'),
            'email' => $email,
            'password' => Hash::make($password),
        ]);

        $this->info("User [{$email}] created.");

        return self::SUCCESS;
    }
}
