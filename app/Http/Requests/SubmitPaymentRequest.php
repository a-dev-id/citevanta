<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SubmitPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('pay', $this->route('invoice'));
    }

    /**
     * Proof of payment: optional image or PDF, at most 5 MB.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'proof' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:'.(int) config('citevanta.billing.proof_max_kb', 5120)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'proof.mimes' => 'Upload an image (JPG, PNG, WebP) or a PDF.',
            'proof.max' => 'The file can be at most 5 MB.',
        ];
    }
}
