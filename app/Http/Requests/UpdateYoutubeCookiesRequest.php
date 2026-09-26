<?php

namespace App\Http\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;

class UpdateYoutubeCookiesRequest extends FormRequest
{
    /** Netscape cookies.txt rows: domain, flag, path, secure, expiry, name, value */
    private const NETSCAPE_FIELD_COUNT = 7;

    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $maxBytes = (int) config('max-tune.youtube.cookies_max_kb') * 1024;

        return [
            'cookies' => [
                'required',
                'string',
                'max:'.$maxBytes,
                function (string $attribute, mixed $value, Closure $fail) {
                    if (! $this->hasNetscapeCookieRow((string) $value)) {
                        $fail('Couldn\'t save cookies. Check the file and try again.');
                    }
                },
            ],
        ];
    }

    private function hasNetscapeCookieRow(string $contents): bool
    {
        foreach (preg_split('/\R/', $contents) ?: [] as $line) {
            if (! str_starts_with($line, '#') && count(explode("\t", $line)) === self::NETSCAPE_FIELD_COUNT) {
                return true;
            }
        }

        return false;
    }
}
