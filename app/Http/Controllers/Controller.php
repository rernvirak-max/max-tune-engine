<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

abstract class Controller
{
    /**
     * Media endpoints (<img>/<audio>) accept a temporary relative signature or the owner's bearer token.
     */
    protected function authorizeSignedOrOwner(Request $request, int $ownerId): void
    {
        if ($request->hasValidSignature(absolute: false)) {
            return;
        }

        if ($request->user()?->id === $ownerId) {
            return;
        }

        abort(403);
    }
}
