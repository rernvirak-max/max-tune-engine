<?php

namespace Tests\Feature;

use App\Http\Resources\TrackResource;
use App\Models\Track;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SignedMediaUrlTest extends TestCase
{
    use RefreshDatabase;

    private const PUBLIC_ORIGIN = 'https://engine.example.test';

    private Track $track;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('media');
        Config::set('app.url', self::PUBLIC_ORIGIN);

        Storage::disk('media')->put('user/1/covers/cover.jpg', 'jpeg-bytes');
        $this->track = Track::factory()->create(['cover_path' => 'user/1/covers/cover.jpg']);
    }

    public function test_media_urls_use_the_public_app_url_origin(): void
    {
        $data = (new TrackResource($this->track))->resolve();

        $this->assertStringStartsWith(self::PUBLIC_ORIGIN."/api/tracks/{$this->track->id}/cover?", $data['cover_url']);
        $this->assertStringStartsWith(self::PUBLIC_ORIGIN."/api/tracks/{$this->track->id}/stream?", $data['stream_url']);
    }

    public function test_signed_cover_url_validates_regardless_of_request_scheme_and_host(): void
    {
        $path = $this->signedCoverPath();

        // Behind Cloudflare/Traefik the app sees plain http on an internal host.
        $this->get('http://internal:8000'.$path)->assertOk();
        $this->get(self::PUBLIC_ORIGIN.$path)->assertOk();
    }

    public function test_tampered_signed_cover_url_is_rejected(): void
    {
        $other = Track::factory()->create(['cover_path' => 'user/1/covers/cover.jpg']);
        $path = str_replace("/tracks/{$this->track->id}/", "/tracks/{$other->id}/", $this->signedCoverPath());

        $this->get($path)->assertForbidden();
    }

    private function signedCoverPath(): string
    {
        $url = (new TrackResource($this->track))->resolve()['cover_url'];

        return substr($url, strlen(self::PUBLIC_ORIGIN));
    }
}
