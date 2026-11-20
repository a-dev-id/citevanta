<?php

namespace App\Http\Requests;

use App\Enums\BillingInterval;
use App\Enums\PaymentMethod;
use App\Services\Billing\BillingService;
use App\Services\Plans\PlanCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ChoosePlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manageBilling', $this->user()->currentTeam);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'plan' => ['required', Rule::in(array_map(fn ($plan) => $plan->value, PlanCatalog::PAID))],
            'interval' => ['required', Rule::enum(BillingInterval::class)],
            'hotels' => ['required', 'integer', 'min:1', 'max:500'],
            'currency' => ['required', Rule::in(app(BillingService::class)->currencies())],
            'payment_method' => ['required', Rule::enum(PaymentMethod::class)],
        ];
    }
}
