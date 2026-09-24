<?php

namespace App\Models;

use Database\Factories\PlaylistFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['user_id', 'title', 'description', 'visibility'])]
class Playlist extends Model
{
    /** @use HasFactory<PlaylistFactory> */
    use HasFactory;

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function tracks(): BelongsToMany
    {
        return $this->belongsToMany(Track::class, 'playlist_tracks')
            ->withPivot('position')
            ->withTimestamps()
            ->orderByPivot('position');
    }

    public function playlistTracks(): HasMany
    {
        return $this->hasMany(PlaylistTrack::class)->orderBy('position');
    }
}
