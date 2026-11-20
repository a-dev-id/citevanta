<?php

namespace App\Console\Commands;

use App\Enums\Plan;
use App\Models\User;
use App\Services\Teams\TeamCreator;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

#[Signature('citevanta:make-admin {email : Email of the admin} {--name= : Name for a new user (default: the part before @)}')]
#[Description('Create an internal admin user, or make an existing user an admin')]
class MakeAdminCommand extends Command
{
    public function handle(TeamCreator $teams): int
    {
        $email = Str::lower(trim((string) $this->argument('email')));

        if (Validator::make(['email' => $email], ['email' => ['required', 'email']])->fails()) {
            $this->error('That is not a valid email address.');

            return self::INVALID;
        }

        $user = User::where('email', $email)->first();

        if ($user !== null) {
            $user->forceFill(['is_admin' => true])->save();
            $this->info("{$email} is now an admin.");

            return self::SUCCESS;
        }

        $password = $this->input->isInteractive()
            ? (string) $this->secret('Password for the new admin (leave empty to generate one)')
            : '';
        $generated = $password === '';
        $password = $generated ? Str::password(20, symbols: false) : $password;

        if (strlen($password) < 12) {
            $this->error('Use a password of at least 12 characters.');

            return self::INVALID;
        }

        $user = User::create([
            'name' => $this->option('name') ?: Str::headline(Str::before($email, '@')),
            'email' => $email,
            'password' => $password,
        ]);
        $user->forceFill(['is_admin' => true, 'email_verified_at' => now()])->save();

        // Own team, so every page works for the admin.
        $teams->create($user, config('app.name').' Admin', Plan::Pro);

        $this->info("Admin {$email} created.");

        if ($generated) {
            $this->warn("Password (shown once, store it safely): {$password}");
        }

        return self::SUCCESS;
    }
}
