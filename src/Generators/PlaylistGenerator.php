<?php

namespace ProtoneMedia\LaravelFFMpeg\Generators;

use ProtoneMedia\LaravelFFMpeg\Drivers\PHPFFMpeg;

interface PlaylistGenerator
{
    public function get(array $playlistMedia, PHPFFMpeg $driver): string;
}
