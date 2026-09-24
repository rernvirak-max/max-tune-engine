<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AttachPlaylistTrackRequest extends FormRequest
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
            'track_id' => ['required', 'integer', 'exists:tracks,id'],
        ];
    }
}
