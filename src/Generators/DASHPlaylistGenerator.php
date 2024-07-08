<?php

namespace ProtoneMedia\LaravelFFMpeg\Generators;

use Illuminate\Support\Collection;
use ProtoneMedia\LaravelFFMpeg\Drivers\PHPFFMpeg;
use ProtoneMedia\LaravelFFMpeg\Exporters\DASHExporter;
use ProtoneMedia\LaravelFFMpeg\Exporters\HLSExporter;
use ProtoneMedia\LaravelFFMpeg\Filesystem\Media;
use ProtoneMedia\LaravelFFMpeg\Http\DynamicHLSPlaylist;
use ProtoneMedia\LaravelFFMpeg\MediaOpener;
use ProtoneMedia\LaravelFFMpeg\Support\StreamParser;

class DASHPlaylistGenerator implements PlaylistGenerator
{
    /**
     * Loops through all segment playlists and generates a main playlist. It finds
     * the relative paths to the segment playlists and adds the framerate when
     * to each playlist.
     *
     * @param array $segmentPlaylists
     * @param \ProtoneMedia\LaravelFFMpeg\Drivers\PHPFFMpeg $driver
     * @return string
     */
    public function get(array $segmentPlaylists, PHPFFMpeg $driver): string
    {
        $mpd = new \SimpleXMLElement("");
        $node = $mpd->addChild("MPD");
        $node->addAttribute("xmlns:xsi", "http://www.w3.org/2001/XMLSchema-instance");
        $node->addAttribute("xmlns", "urn:mpeg:dash:schema:mpd:2011");
        $node->addAttribute("xmlns:xlink", "http://www.w3.org/1999/xlink");
        $node->addAttribute("xsi:schemaLocation", "urn:mpeg:DASH:schema:MPD:2011 http://standards.iso.org/ittf/PubliclyAvailableStandards/MPEG-DASH_schema_files/DASH-MPD.xsd");
        $node->addAttribute("profiles", "urn:mpeg:dash:profile:isoff-live:2011");
        $node->addAttribute("type", "static");
        $node->addAttribute("", "");
        $node->addAttribute("", "");
        $node->addAttribute("", "");
        $node->addAttribute("", "");
        $node->addAttribute("", "");
        $node->addAttribute("", "");

        Collection::make($segmentPlaylists)->map(function (Media $playlist, $key) use ($driver, $mpd) {
            $manifest = simplexml_load_file($playlist->getLocalPath());
        });

        return $mpd->asXML();
        // return Collection::make($segmentPlaylists)->map(function (Media $segmentPlaylist, $key) use ($driver) {
        //     // $streamInfoLine = $this->getStreamInfoLine($segmentPlaylist, $key);

        //     // $media = (new MediaOpener($segmentPlaylist->getDisk(), $driver))
        //     //     ->openWithInputOptions($segmentPlaylist->getPath(), ['-allowed_extensions', 'ALL']);

        //     // if ($media->getVideoStream()) {
        //     //     if ($frameRate = StreamParser::new($media->getVideoStream())->getFrameRate()) {
        //     //         $streamInfoLine .= ",FRAME-RATE={$frameRate}";
        //     //     }
        //     // }

        //     return [$segmentPlaylist->getFilename()];
        // })->collapse()->implode(PHP_EOL);
    }
}
