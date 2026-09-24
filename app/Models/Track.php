<?php

namespace App\Models;

use Database\Factories\TrackFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'user_id',
    'title',
    'artist_name',
    'album_name',
    'duration_ms',
    'mime',
    'size',
    'storage_path',
    'cover_path',
    'visibility',
    'source',
    'external_id',
    'license_url',
    'import_mode',
    'imported_by',
    'external_stream_url',
    'external_cover_url',
])]
class Track extends Model
{
    /** @use HasFactory<TrackFactory> */
    use HasFactory, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'duration_ms' => 'integer',
            'size' => 'integer',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function importer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'imported_by');
    }

    public function playlists(): BelongsToMany
    {
        return $this->belongsToMany(Playlist::class, 'playlist_tracks')
            ->withPivot('position')
            ->withTimestamps();
    }

    public function likes(): HasMany
    {
        return $this->hasMany(Like::class);
    }
}
