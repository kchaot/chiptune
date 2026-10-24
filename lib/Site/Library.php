<?php

declare(strict_types=1);

namespace Chiptune\Site;

use Chiptune\Common\BuildError;
use Chiptune\Format\Described;
use Chiptune\Format\Link\LinkParser;
use Chiptune\Format\Session\SessionParser;
use Chiptune\Format\Set\SetParser;
use Chiptune\Format\Song;
use Chiptune\Format\Song\LayerParser;
use Chiptune\Format\Song\SongParser;

/**
 * Lädt Dateien nach Schlüssel (`prefix/slug`) und wählt den passenden Parser.
 *
 * Ergebnisse werden gemerkt. Verweise zwischen Dateien löst `song()` auf, Zyklen werden erkannt,
 * und eine kaputte Datei wird nur einmal gemeldet.
 */
final class Library
{
    /** @var array<string, array{source: string, prefix: string, slug: string, album: string|null, file: string, strict: bool, media: list<string>}> */
    private array $files = [];

    /** @var array<string, array{kind: string, prefix: string, slug: string, data: Described}> */
    private array $loaded = [];

    /** @var array<string, string> */
    private array $failed = [];

    /** @var list<string> */
    private array $stack = [];

    /** @param list<array{source: string, prefix: string, slug: string, album: string|null, file: string, strict: bool, media: list<string>}> $files */
    public function __construct(
        array $files,
        private readonly SongParser $songs,
        private readonly SetParser $sets,
        private readonly ?SessionParser $sessions = null,
        private readonly ?LinkParser $links = null,
        private readonly ?LayerParser $layers = null,
    ) {
        foreach ($files as $file) {
            $this->files["{$file['prefix']}/{$file['slug']}"] = $file;
        }
    }

    /** @return array{kind: string, prefix: string, slug: string, data: Described} */
    public function load(string $key): array
    {
        if (isset($this->loaded[$key])) {
            return $this->loaded[$key];
        }

        if (isset($this->failed[$key])) {
            throw new BuildError($this->failed[$key]);
        }

        if (in_array($key, $this->stack, true)) {
            throw new BuildError('Zyklus: ' . implode(' → ', [...$this->stack, $key]));
        }

        $file = $this->files[$key] ?? throw new BuildError("„{$key}“ gibt es nicht in den Quellen.");

        $this->stack[] = $key;

        try {
            $source = (string) file_get_contents($file['file']);
            $resolve = $this->song(...);

            $fileKind = $this->songs->kind($source);

            if ($fileKind === 'session') {
                $kind = 'session';
                $data = ($this->sessions ?? new SessionParser())->parse($source, basename($file['slug']), $resolve);
            } elseif ($fileKind === 'link') {
                $kind = 'link';
                $data = ($this->links ?? new LinkParser())->parse($source, basename($file['slug']), $resolve, $file['prefix']);
            } elseif ($fileKind === 'set') {
                $kind = 'set';
                $data = $this->sets->parse($source, basename($file['slug']), $resolve);
            } elseif ($fileKind === 'layer') {
                $kind = 'song';
                $data = ($this->layers ?? new LayerParser())->parse($source, basename($file['slug']), $resolve, $file['prefix']);
            } else {
                $kind = 'song';
                $data = $this->songs->parse($source, basename($file['slug']), $resolve, $file['prefix']);
            }
        } catch (BuildError $error) {
            $this->failed[$key] = $error->getMessage();

            throw $error;
        } finally {
            array_pop($this->stack);
        }

        return $this->loaded[$key] = ['kind' => $kind, 'prefix' => $file['prefix'], 'slug' => $file['slug'], 'data' => $data];
    }

    /**
     * @return array{prefix: string, slug: string, song: Song}
     */
    public function song(string $ref): array
    {
        try {
            $entry = $this->load($ref);
        } catch (BuildError $error) {
            if (str_starts_with($error->getMessage(), 'Zyklus')) {
                throw $error;
            }

            throw new BuildError("Verweis auf „{$ref}“ nicht möglich: {$error->getMessage()}");
        }

        $song = $entry['data'];

        if (!$song instanceof Song) {
            throw new BuildError(
                "„{$ref}“ ist ein " . match ($entry['kind']) {
                    'session' => 'Session',
                    'link' => 'Link',
                    default => 'Set',
                }
                . '. Verweisen kann man nur auf Songs.',
            );
        }

        return ['prefix' => $entry['prefix'], 'slug' => $entry['slug'], 'song' => $song];
    }
}
