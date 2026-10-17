<?php

declare(strict_types=1);

namespace Chiptune;

final class Library
{
    /** @var array<string, array{prefix: string, slug: string, file: string}> */
    private array $files = [];

    /** @var array<string, array{kind: string, prefix: string, slug: string, data: array<string, mixed>}> */
    private array $loaded = [];

    /** @var array<string, string> */
    private array $failed = [];

    /** @var list<string> */
    private array $stack = [];

    /** @param list<array{prefix: string, slug: string, file: string}> $files */
    public function __construct(
        array $files,
        private readonly ProjectParser $projects,
        private readonly SetParser $sets,
        private readonly ?SessionParser $sessions = null,
        private readonly ?ReleaseParser $releases = null,
    ) {
        foreach ($files as $file) {
            $this->files["{$file['prefix']}/{$file['slug']}"] = $file;
        }
    }

    /** @return array{kind: string, prefix: string, slug: string, data: array<string, mixed>} */
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
            $resolve = fn (string $ref): array => $this->project($ref);

            $fileKind = $this->projects->kind($source);

            if ($fileKind === 'session') {
                $kind = 'session';
                $data = ($this->sessions ?? new SessionParser($this->projects))->parse($source, basename($file['slug']), $resolve);
            } elseif ($fileKind === 'release') {
                $kind = 'release';
                $data = ($this->releases ?? new ReleaseParser($this->projects))->parse($source, basename($file['slug']), $resolve, $file['prefix']);
            } elseif ($fileKind === 'set') {
                $kind = 'set';
                $data = $this->sets->parse($source, basename($file['slug']), $resolve);
            } elseif ($fileKind === 'layer') {
                $kind = 'project';
                $data = $this->projects->parseLayer($source, basename($file['slug']), $resolve, $file['prefix']);
            } else {
                $kind = 'project';
                $data = $this->projects->parse($source, basename($file['slug']), $resolve, $file['prefix']);
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
     * @return array{prefix: string, slug: string, project: array<string, mixed>}
     */
    public function project(string $ref): array
    {
        try {
            $entry = $this->load($ref);
        } catch (BuildError $error) {
            if (str_starts_with($error->getMessage(), 'Zyklus')) {
                throw $error;
            }

            throw new BuildError("Verweis auf „{$ref}“ nicht möglich: {$error->getMessage()}");
        }

        if ($entry['kind'] !== 'project') {
            throw new BuildError("„{$ref}“ ist ein " . match ($entry['kind']) { 'session' => 'Session', 'release' => 'Release', default => 'Set' } . '. Verweisen kann man nur auf Projects.');
        }

        return ['prefix' => $entry['prefix'], 'slug' => $entry['slug'], 'project' => $entry['data']];
    }
}
