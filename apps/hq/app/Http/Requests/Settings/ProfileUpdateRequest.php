<?php

namespace App\Http\Requests\Settings;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProfileUpdateRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        // docs/90 A.6 (stream V2): the language switch in the top bar saves the person's preference through
        // this same request with `locale` alone, so name and email are required only when they are sent.
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],

            'locale' => ['sometimes', 'nullable', 'string', Rule::in(['en', 'bn'])],

            'email' => [
                'sometimes',
                'required',
                'string',
                'lowercase',
                'email',
                'max:255',
                Rule::unique(User::class)->ignore($this->user()->id),
            ],
        ];
    }
}
