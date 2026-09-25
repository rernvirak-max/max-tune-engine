<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreYoutubeImportRequest extends FormRequest
{
    private const MAX_URL_LENGTH = 2048;

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
            'url' => ['required', 'string', 'max:'.self::MAX_URL_LENGTH],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'url.required' => 'Paste a YouTube link.',
            'url.max' => 'That doesn\'t look like a YouTube video link.',
        ];
    }
}
