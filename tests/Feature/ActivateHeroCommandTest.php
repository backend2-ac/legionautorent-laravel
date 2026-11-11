<?php

namespace Tests\Feature;

use App\Models\SiteSettings;
use Illuminate\Support\Facades\File;
use Tests\CatalogueTestCase;

class ActivateHeroCommandTest extends CatalogueTestCase
{
    private string $folder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->folder = 'hero-test-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists(public_path('media/'.$this->folder));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(public_path('media/'.$this->folder));
        parent::tearDown();
    }

    public function test_an_uploaded_sequence_becomes_the_banner(): void
    {
        $master = $this->writeSequence();

        $this->artisan('hero:activate', ['master' => $master])->assertSuccessful();

        $site = SiteSettings::findOrFail(1);
        $this->assertSame($master, $site->hero_video_path);
        $this->assertTrue($site->enable_hero_video);
        $this->assertSame(24, $site->hero_video_fps);
    }

    public function test_an_incomplete_upload_is_refused_and_the_banner_is_kept(): void
    {
        $before = SiteSettings::findOrFail(1)->hero_video_path;
        $master = $this->writeSequence();
        unlink(public_path('media/'.$this->folder.'/desktop-00.pack'));

        $this->artisan('hero:activate', ['master' => $master])->assertFailed();
        $this->artisan('hero:activate', ['master' => '/media/../.env'])->assertFailed();

        $this->assertSame($before, SiteSettings::findOrFail(1)->hero_video_path);
    }

    private function writeSequence(): string
    {
        $directory = public_path('media/'.$this->folder);
        $master = '/media/'.$this->folder.'/master.mp4';
        file_put_contents($directory.'/master.mp4', 'video');
        foreach (['desktop', 'mobile'] as $variant) {
            file_put_contents($directory.'/'.$variant.'-00.pack', 'pack');
        }
        file_put_contents($directory.'/sequence.json', json_encode([
            'master' => $master, 'fps' => 24, 'count' => 8, 'duration' => 1, 'pack_size' => 8,
            'desktop' => ['packs' => [['url' => '/media/'.$this->folder.'/desktop-00.pack']]],
            'mobile' => ['packs' => [['url' => '/media/'.$this->folder.'/mobile-00.pack']]],
        ]));

        return $master;
    }
}
