<?php

namespace App\Http\Requests;

use App\Services\YoutubeDownloadService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreYoutubeImportRequest extends FormRequest
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
            'url' => ['required', 'string', 'max:2048', 'url'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $url = (string) $this->input('url', '');
            $youtube = app(YoutubeDownloadService::class);

            if ($url !== '' && ! $youtube->isSupportedUrl($url)) {
                $validator->errors()->add('url', 'Only YouTube links are supported.');
            }
        });
    }
}
