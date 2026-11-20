<?php

namespace App\Rules;

use App\Services\Site\HumanVerifier;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The Turnstile token of a public form (field "cf-turnstile-response").
 * Always passes when Turnstile is off.
 */
class HumanVerified implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! app(HumanVerifier::class)->verify(is_string($value) ? $value : null, request()->ip())) {
            $fail(__('site.errors.human'));
        }
    }

    /**
     * Rules for the token field: required only when Turnstile is on.
     *
     * @return array<string, list<mixed>>
     */
    public static function rules(): array
    {
        return app(HumanVerifier::class)->enabled()
            ? ['cf-turnstile-response' => ['bail', 'required', 'string', new self]]
            : [];
    }
}
