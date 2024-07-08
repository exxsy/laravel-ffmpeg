<?php

namespace ProtoneMedia\LaravelFFMpeg\Exporters;

use Closure;
use FFMpeg\Format\Audio\DefaultAudio;
use FFMpeg\Format\AudioInterface;
use FFMpeg\Format\FormatInterface;
use FFMpeg\Format\Video\DefaultVideo;
use Illuminate\Support\Collection;
use ProtoneMedia\LaravelFFMpeg\Exceptions\NoFormatException;
use ProtoneMedia\LaravelFFMpeg\Filesystem\Disk;
use ProtoneMedia\LaravelFFMpeg\Filesystem\Media;
use ProtoneMedia\LaravelFFMpeg\Filters\DASHVideoFilters;
use ProtoneMedia\LaravelFFMpeg\Generators\DASHPlaylistGenerator;
use ProtoneMedia\LaravelFFMpeg\Generators\HLSPlaylistGenerator;
use ProtoneMedia\LaravelFFMpeg\Generators\PlaylistGenerator;
use ProtoneMedia\LaravelFFMpeg\MediaOpener;

class DASHExporter extends MediaExporter
{
    /**
     * @var string
     * Available usable variables in segment name are: $RepresentationID$, $Number$, $Bandwidth$, $Time$, $ext$
     */
    private $initializationSegmentName = null;

    /**
     * @var \Illuminate\Support\Collection
     */
    private $pendingFormats;

    /**
     * @var integer
     */
    private $segmentLength = 10;

    /**
     * @var string
     */
    private $segmentType = "auto";

    /**
     * @var integer
     */
    private $keyFrameInterval = 48;

    /**
     * @var \Closure
     */
    private $segmentFilenameGenerator = null;

    /**
     * @var \ProtoneMedia\LaravelFFMpeg\Generators\PlaylistGenerator
     */
    private $playlistGenerator = null;

    /**
     * Method to set a different playlist generator than
     * the default DASHPlaylistGenerator.
     *
     * @param \ProtoneMedia\LaravelFFMpeg\Generators\PlaylistGenerator $playlistGenerator
     * @return self
     */
    public function withPlaylistGenerator(PlaylistGenerator $playlistGenerator): self
    {
        $this->playlistGenerator = $playlistGenerator;

        return $this;
    }

    /**
     * Gets the playlist generator to generate.
     * @return \ProtoneMedia\LaravelFFMpeg\Generators\PlaylistGenerator
     */
    private function getPlaylistGenerator(): PlaylistGenerator
    {
        return $this->playlistGenerator ?: new DASHPlaylistGenerator();
    }

    /**
     * Gives the callback an HLSVideoFilters object that provides addFilter(),
     * addLegacyFilter(), addWatermark() and resize() helper methods. It
     * returns a mapping for the video and (optional) audio stream.
     *
     * @param callable $filtersCallback
     * @param integer $formatKey
     * @return array
     */
    private function applyFiltersCallback(callable $filtersCallback, int $formatKey): array
    {
        $filtersCallback($dashVideoFilters = new DASHVideoFilters($this->driver, $formatKey));
        $filterCount = $dashVideoFilters->count();
        $outs = [$filterCount ? DASHVideoFilters::glue($formatKey, $filterCount) : '0:v'];
        if ($this->getAudioStream()) {
            $outs[] = '0:a';
        }

        return $outs;
    }

    /**
     * Returns a default generator if none is set.
     *
     * @return callable
     */
    private function getSegmentFilenameGenerator(): callable
    {
        return $this->segmentFilenameGenerator ?: function ($name, $format, $key, $segments, $playlist) {
            $bitrate = $this->driver->getVideoStream() ? $format->getKiloBitrate()
                : $format->getAudioKiloBitrate();

            $segments("{$name}_{$key}_{$bitrate}.m4s");
            $playlist("{$name}_{$key}_{$bitrate}.mpd");
        };
    }

    /**
     * Calls the generator with the path (without extension), format and key.
     *
     * @param string $baseName
     * @param \FFMpeg\Format\AudioInterface $format
     * @param integer $key
     * @return array
     */
    private function getSegmentPatternAndFormatPlaylistPath(string $baseName, AudioInterface $format, int $key): array
    {
        $segmentsPattern = null;
        $formatPlaylistPath = null;

        call_user_func(
            $this->getSegmentFilenameGenerator(),
            $baseName,
            $format,
            $key,
            function ($path) use (&$segmentsPattern) {
                $segmentsPattern = $path;
            },
            function ($path) use (&$formatPlaylistPath) {
                $formatPlaylistPath = $path;
            }
        );

        return [$segmentsPattern, $formatPlaylistPath];
    }

    /**
     * Merges the DASH parameters to the given format.
     *
     * @param \FFMpeg\Format\Audio\DefaultAudio $format
     * @param string $segmentsPattern
     * @param \ProtoneMedia\LaravelFFMpeg\Filesystem\Disk $disk
     * @param integer $key
     * @return array
     */
    private function addDASHParametersToFormat(DefaultVideo $format, string $segmentsPattern): array
    {
        $params = array_merge(
            $format->getAdditionalParameters() ?: [],
            $dashParameters = [
                '-sc_threshold',
                '0',
                '-g',
                $this->keyFrameInterval,
                '-dash_segment_type',
                $this->segmentType,
                '-segment_time',
                $this->segmentLength,
                '-media_seg_name',
                $segmentsPattern
            ],
        );

        if ($this->initializationSegmentName != null) {
            $params = array_merge($params, $dashParameters[] = [
                '-init_seg_name',
                $this->initializationSegmentName
            ]);
        }

        $format->setAdditionalParameters($params);

        return $dashParameters;
    }

    /**
     * Prepares the saves command but returns the command instead.
     *
     * @param string $path
     * @return mixed
     */
    public function getCommand(string $path = null)
    {
        $this->prepareSaving($path);

        return parent::getCommand(null);
    }

    /**
     * Initializes the $pendingFormats property when needed and adds the format
     * with the optional callback to add filters.
     *
     * @param \FFMpeg\Format\FormatInterface $format
     * @param callable $filtersCallback
     * @return self
     */
    public function addFormat(FormatInterface $format, callable $filtersCallback = null): self
    {
        if (!$this->pendingFormats)
            $this->pendingFormats = new Collection();

        if (!$format instanceof DefaultVideo && $format instanceof DefaultAudio) {
            $originalFormat = clone $format;

            $format = new class () extends DefaultVideo {
                private array $audioCodecs = [];

                public function setAvailableAudioCodecs(array $audioCodecs)
                {
                    $this->audioCodecs = $audioCodecs;
                }

                public function getAvailableAudioCodecs(): array
                {
                    return $this->audioCodecs;
                }

                public function supportBFrames()
                {
                    return false;
                }

                public function getAvailableVideoCodecs()
                {
                    return [];
                }
            };

            $format->setAvailableAudioCodecs($originalFormat->getAvailableAudioCodecs());
            $format->setAudioCodec($originalFormat->getAudioCodec());
            $format->setAudioKiloBitrate($originalFormat->getAudioKiloBitrate());
            if ($originalFormat->getAudioChannels())
                $format->setAudioChannels($originalFormat->getAudioChannels());
        }

        $this->pendingFormats->push([$format, $filtersCallback]);

        return $this;
    }

    /**
     * Adds a mapping for each added format and automatically handles the mapping
     * for filters returns a media collection of all segment playlists.
     *
     * @param string $path
     * @throws \ProtoneMedia\LaravelFFMpeg\Exceptions\NoFormatException
     * @return \Illuminate\Support\Collection
     */
    private function prepareSaving(string $path = null): Collection
    {
        $media = $this->getDisk()->makeMedia($path);
        $baseName = $media->getDirectory() . $media->getFilenameWithoutExtension();

        return $this->pendingFormats->map(function (array $formatAndCallback, $key) use ($baseName) {
            [$format, $filtersCallback] = $formatAndCallback;
            [$segmentsPattern, $formatPlaylistPath] = $this->getSegmentPatternAndFormatPlaylistPath($baseName, $format, $key);

            $this->addDASHParametersToFormat($format, $segmentsPattern);

            if ($filtersCallback)
                $outs = $this->applyFiltersCallback($filtersCallback, $key);

            $formatPlaylistOutput = $this->getDisk()->clone()->makeMedia($formatPlaylistPath);
            $this->addFormatOutputMapping($format, $formatPlaylistOutput, $outs ?? ['0']);

            return $formatPlaylistOutput;
        });
    }

    /**
     * Runs the export, generates the main playlist, and cleans up the
     * segment playlist guides
     *
     * @param string $path
     * @return \ProtoneMedia\LaravelFFMpeg\MediaOpener
     */
    public function save(string $mainPlaylistPath = null): MediaOpener
    {
        // $media = $this->getDisk()->makeMedia($mainPlaylistPath);
        // $baseName = $media->getDirectory() . $media->getFilenameWithoutExtension();
        // $playlists = $this->pendingFormats->map(function (array $formatData, $key) use ($baseName) {
        //     [$format, $callback] = $formatData;
        //     [$segmentsPattern, $formatPlaylistPath] = $this->getSegmentPatternAndFormatPlaylistPath($baseName, $format, $key);
        //     if ($callback) $outs = $this->applyFiltersCallback($callback, $key);
        //     $formatPlaylistOutput = $this->getDisk()->clone()->makeMedia($formatPlaylistPath);
        //     $this->addDASHParametersToFormat($format, $segmentsPattern);
        //     $this->addFormatOutputMapping($format, $formatPlaylistOutput, $outs ?? ['0']);

        //     return $formatPlaylistOutput;
        // });
        // $result = parent::save($mainPlaylistPath);
        // $playlist = $this->getPlaylistGenerator()->get(
        //     $this->prepareSaving($mainPlaylistPath)->all(),
        //     $this->driver->fresh()
        // );
        // $this->getDisk()->put($mainPlaylistPath, $playlist);
        return $this->prepareSaving($mainPlaylistPath)->pipe(function ($segmentPlaylists) use ($mainPlaylistPath) {
            $result = parent::save();

            $playlist = $this->getPlaylistGenerator()->get(
                $segmentPlaylists->all(),
                $this->driver->fresh()
            );

            $this->getDisk()->put($mainPlaylistPath, $playlist);
            return $result;
        });
    }
}
