<?php

namespace App\Actions\Fortify;

use App\Models\User;
use App\Rules\HumanVerified;
use App\Services\Audits\AuditPrefill;
use App\Services\Teams\TeamCreator;
use App\Services\Trials\TrialSignupGuard;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules;

    public function __construct(private TeamCreator $teams, private TrialSignupGuard $trials) {}

    /**
     * Validate and create a newly registered user with their own team (a trial).
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        // A bot filled the hidden field.
        if (filled($input['fax'] ?? null)) {
            throw ValidationException::withMessages(['email' => __('site.errors.honeypot')]);
        }

        Validator::make($input, HumanVerified::rules() + [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required', 'string', 'email', 'max:255', Rule::unique(User::class),
                function (string $attribute, mixed $value, Closure $fail): void {
                    if ($this->trials->isDisposable((string) $value)) {
                        $fail('Please use your work email address. Temporary email addresses cannot start a trial.');
                    }
                },
            ],
            'team_name' => ['nullable', 'string', 'max:255'],
            'password' => $this->passwordRules(),
        ])->validate();

        $ip = request()->ip();

        if ($this->trials->tooManyFromIp($ip)) {
            throw ValidationException::withMessages([
                'email' => 'Too many trials were started from your network recently. Contact us and we will set up your account.',
            ]);
        }

        return DB::transaction(function () use ($input, $ip): User {
            $user = User::create([
                'name' => $input['name'],
                'email' => $input['email'],
                'password' => $input['password'],
            ]);

            $teamName = trim((string) ($input['team_name'] ?? ''));
            $team = $this->teams->create($user, $teamName !== '' ? $teamName : $user->name."'s Team");
            $this->trials->record($team, $ip);

            // Registered from a free audit report: count the lead as converted.
            AuditPrefill::convert($team);

            return $user;
        });
    }
}
