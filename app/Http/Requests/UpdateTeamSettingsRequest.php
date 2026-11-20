<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTeamSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->user()->currentTeam);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'timezone' => ['required', 'string', Rule::in(timezone_identifiers_list())],
        ];
    }
}
