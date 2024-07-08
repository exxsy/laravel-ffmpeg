<?php

namespace ProtoneMedia\LaravelFFMpeg\Tests;
use Illuminate\Support\Facades\Storage;
use ProtoneMedia\LaravelFFMpeg\Exceptions\NoFormatException;
use ProtoneMedia\LaravelFFMpeg\MediaOpener;

class DASHExportTest extends TestCase
{

    // /** @test */
    // public function it_throws_an_exception_when_no_format_has_been_added()
    // {
    //     $this->fakeLocalVideoFile();

    //     try {
    //         (new MediaOpener())
    //             ->open('video.mp4')
    //             ->exportForDASH()
    //             ->toDisk('local')
    //             ->save('adaptive.m3u8');
    //     } catch (NoFormatException $exception) {
    //         return $this->assertTrue(true);
    //     }

    //     $this->fail('Should have thrown NoFormatException.');
    // }

    /** @test */
    public function it_can_export_a_single_video_file_into_a_dash_export()
    {
        $this->fakeLocalVideoFile();

        $lowBitrate  = $this->x264()->setKiloBitrate(250);
        $midBitrate  = $this->x264()->setKiloBitrate(1000);
        $highBitrate = $this->x264()->setKiloBitrate(4000);

        (new MediaOpener())
            ->open('video.mp4')
            ->exportForDASH()
            ->addFormat($lowBitrate)
            ->addFormat($midBitrate)
            ->addFormat($highBitrate)
            ->toDisk('local')
            ->save('adaptive.mpd');

        // $this->assertTrue(Storage::disk('local')->has('adaptive.mpd'));
        $this->assertTrue(Storage::disk('local')->has('adaptive_0_250.mpd'));
        $this->assertTrue(Storage::disk('local')->has('adaptive_1_1000.mpd'));
        $this->assertTrue(Storage::disk('local')->has('adaptive_2_4000.mpd'));

        // $media = (new MediaOpener())->fromDisk('local')->open('adaptive_0_250_00000.m4s');
        // $this->assertEquals(1920, $media->getVideoStream()->get('width'));
        // $this->assertNotNull($media->getAudioStream());

        // $media = (new MediaOpener())->fromDisk('local')->open('adaptive_1_1000_00000.m4s');
        // $this->assertEquals(1920, $media->getVideoStream()->get('width'));
        // $this->assertNotNull($media->getAudioStream());

        // $media = (new MediaOpener())->fromDisk('local')->open('adaptive_2_4000_00000.m4s');
        // $this->assertEquals(1920, $media->getVideoStream()->get('width'));
        // $this->assertNotNull($media->getAudioStream());
    }
}
