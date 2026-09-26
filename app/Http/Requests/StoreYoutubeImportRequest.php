<?php

namespace App\Http\Requests;

use App\Exceptions\ImportRejectedException;
use App\Models\MediaImport;
use Illuminate\Contracts\Validation\Validator;
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

    /**
     * Empty, missing or non-string urls answer like any other bad link:
     * 422 `{message, code: invalid_url}` (not Laravel's `errors` shape).
     */
    protected function failedValidation(Validator $validator): never
    {
        throw new ImportRejectedException(
            MediaImport::REASON_INVALID_URL,
            (string) $validator->errors()->first('url') ?: 'That doesn\'t look like a YouTube video link.',
            422,
        );
    }
}
