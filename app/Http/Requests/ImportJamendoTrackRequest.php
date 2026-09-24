<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ImportJamendoTrackRequest extends FormRequest
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
            'external_id' => ['required', 'string', 'max:64'],
        ];
    }
}
