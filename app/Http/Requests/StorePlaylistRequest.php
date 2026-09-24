<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePlaylistRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:1000'],
            'visibility' => ['sometimes', 'string', Rule::in(['private'])],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (! $this->has('visibility')) {
            $this->merge(['visibility' => 'private']);
        }
    }
}
