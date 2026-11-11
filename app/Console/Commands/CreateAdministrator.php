<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class CreateAdministrator extends Command
{
    protected $signature = 'intelligence:admin {email} {--generate-password : Save a generated password in private local storage}';

    protected $description = 'Create the single administrator using a hidden password prompt';

    public function handle(): int
    {
        if (User::where('is_admin', true)->exists()) {
            $this->error('An administrator already exists.');

            return self::FAILURE;
        }
        if ($this->option('generate-password')) {
            $email = $this->argument('email');
            if (! filter_var($email, FILTER_VALIDATE_EMAIL) || User::where('email', $email)->exists()) {
                $this->error('A valid, unused email is required.');

                return self::FAILURE;
            }
            $password = Str::password(24);
            $path = storage_path('app/private/administrator-credentials.txt');
            if (! is_dir(dirname($path))) {
                mkdir(dirname($path), 0700, true);
            }
            if (file_exists($path)) {
                $this->error('A credentials file already exists.');

                return self::FAILURE;
            }
            file_put_contents($path, 'Email: '.$email.PHP_EOL.'Password: '.$password.PHP_EOL);
            chmod($path, 0600);
            $user = new User(['name' => 'Administrator', 'email' => $email, 'password' => $password]);
            $user->is_admin = true;
            $user->is_platform_admin = true;
            $user->save();
            $this->assignInitialOrganization($user);
            $this->info('Administrator created. Credentials saved in storage/app/private/administrator-credentials.txt.');

            return self::SUCCESS;
        }
        $data = ['email' => $this->argument('email'), 'password' => $this->secret('Administrator password (minimum 12 characters)')];
        $data['password_confirmation'] = $this->secret('Confirm password');
        $validator = Validator::make($data, ['email' => 'required|email|max:254|unique:users,email', 'password' => ['required', 'confirmed', Password::min(12)->letters()->numbers()]]);
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }
        $user = new User(['name' => 'Administrator', 'email' => $data['email'], 'password' => $data['password']]);
        $user->is_admin = true;
        $user->is_platform_admin = true;
        $user->save();
        $this->assignInitialOrganization($user);
        $this->info('Administrator created.');

        return self::SUCCESS;
    }

    private function assignInitialOrganization(User $user): void
    {
        $org = Organization::where('slug', 'nandini-jungle')->firstOrFail();
        if ($org->owner_id !== null) {
            return;
        }
        $org->forceFill(['owner_id' => $user->id])->save();
        $org->members()->syncWithoutDetaching([$user->id => ['role' => 'owner']]);
    }
}
