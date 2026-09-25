<?php

namespace Tests\Unit;

use App\Services\AudioMetadataExtractor;
use getID3;
use getid3_writetags;
use PHPUnit\Framework\TestCase;

class AudioMetadataExtractorTest extends TestCase
{
    /** MPEG-1 Layer III, 128 kbps, 44.1 kHz frame header (frame length 417 bytes). */
    private const MP3_FRAME_HEADER = "\xFF\xFB\x90\x64";

    private const MP3_FRAME_LENGTH = 417;

    private const MP3_FRAME_COUNT = 40;

    private string $path;

    protected function setUp(): void
    {
        parent::setUp();

        $frame = str_pad(self::MP3_FRAME_HEADER, self::MP3_FRAME_LENGTH, "\0");
        $this->path = tempnam(sys_get_temp_dir(), 'mp3');
        file_put_contents($this->path, str_repeat($frame, self::MP3_FRAME_COUNT));
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        parent::tearDown();
    }

    public function test_it_reads_id3v2_text_tags_and_cover(): void
    {
        $this->writeId3v2([
            'title' => ['បទចម្រៀង'],
            'artist' => ['សិល្បករ'],
            'album' => ['Album'],
            'attached_picture' => [[
                'data' => 'cover-bytes',
                'picturetypeid' => 3,
                'description' => 'cover',
                'mime' => 'image/jpeg',
            ]],
        ]);

        $meta = (new AudioMetadataExtractor)->extract($this->path);

        $this->assertSame('បទចម្រៀង', $meta['title']);
        $this->assertSame('សិល្បករ', $meta['artist_name']);
        $this->assertSame('Album', $meta['album_name']);
        $this->assertSame('cover-bytes', $meta['cover_binary']);
        $this->assertGreaterThan(0, $meta['duration_ms']);
    }

    /**
     * @param  array<string, array<int, mixed>>  $tags
     */
    private function writeId3v2(array $tags): void
    {
        new getID3; // defines GETID3_INCLUDEPATH, required by the writer
        require_once GETID3_INCLUDEPATH.'write.php';

        $writer = new getid3_writetags;
        $writer->filename = $this->path;
        $writer->tagformats = ['id3v2.3'];
        $writer->tag_encoding = 'UTF-8';
        $writer->tag_data = $tags;

        $this->assertTrue($writer->WriteTags(), implode(' ', $writer->errors));
    }
}
