<?php

namespace Tests\Feature;

use App\Models\Song;
use App\Services\YouTubeService;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class YouTubeTest extends TestCase
{
    private MockInterface $youTubeService;

    public function setUp(): void
    {
        parent::setUp();

        $this->youTubeService = $this->mock(YouTubeService::class);
    }

    #[Test]
    public function searchYouTubeVideos(): void
    {
        /** @var Song $song */
        $song = Song::factory()->create();

        $this->youTubeService
            ->expects('searchVideosRelatedToSong')
            ->with(Mockery::on(static fn (Song $retrievedSong) => $song->is($retrievedSong)), 'foo');

        $this->getAs("/api/youtube/search/song/{$song->id}?pageToken=foo")
            ->assertOk();
    }
}
