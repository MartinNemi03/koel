<?php

namespace Tests\Feature;

use App\Models\Song;
use App\Models\User;
use App\Services\LastfmService;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

use function Tests\create_user;

class ScrobbleTest extends TestCase
{
    #[Test]
    public function lastfmScrobble(): void
    {
        $user = create_user();

        /** @var Song $song */
        $song = Song::factory()->create();

        $this->mock(LastfmService::class)
            ->shouldReceive('scrobble')
            ->with(
                Mockery::on(static fn (Song $s) => $s->is($song)),
                Mockery::on(static fn (User $u) => $u->is($user)),
                100
            )
            ->once();

        $this->postAs("/api/songs/{$song->id}/scrobble", ['timestamp' => 100], $user)
            ->assertNoContent();
    }
}
