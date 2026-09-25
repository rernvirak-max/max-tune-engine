<?php

namespace App\Http\Resources;

use App\Models\MediaImport;
use Illuminate\Http\Request;

/**
 * Admin view: adds the owner, attempts and the technical error_detail
 * that regular users never see.
 *
 * @mixin MediaImport
 */
class AdminMediaImportResource extends MediaImportResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            'attempts' => $this->attempts,
            'error_detail' => $this->error_detail,
            'owner' => [
                'id' => $this->owner?->id,
                'email' => $this->owner?->email,
                'name' => $this->owner?->name,
            ],
        ];
    }
}
