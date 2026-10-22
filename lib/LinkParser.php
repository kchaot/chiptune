<?php

declare(strict_types=1);

namespace Chiptune;

use Tempest\Markdown\Markdown;

final class LinkParser
{
    public const FORMAT = 1;
    public const MAX_BYTES = 16_384;
    public const MAX_FROM = 6;

    public function __construct(
        private readonly ProjectParser $projects,
        private readonly Markdown $markdown = new Markdown(null),
        private readonly HtmlSanitizer $sanitizer = new HtmlSanitizer(),
    ) {}

    /**
     * @param \Closure(string): array{prefix: string, slug: string, project: array<string, mixed>} $resolve
     */
    public function parse(string $source, string $fallbackTitle, \Closure $resolve, string $owner): Link
    {
        if (strlen($source) > self::MAX_BYTES) {
            throw new BuildError(sprintf('Die Datei ist größer als %d KB.', self::MAX_BYTES / 1024));
        }

        $parsed = $this->markdown->parse($source);
        $meta = $parsed->frontmatter;

        if (($meta['format'] ?? self::FORMAT) !== self::FORMAT) {
            throw new BuildError('Nicht unterstütztes format: ' . json_encode($meta['format']) . '. Unterstützt wird format: ' . self::FORMAT . '.');
        }

        $unknown = array_diff(array_keys($meta), ['kind', 'format', 'title', 'artist', 'group', 'tags', 'release', 'from']);

        if ($unknown !== []) {
            throw new BuildError('Link: unbekannter Schlüssel „' . implode('“, „', array_map('strval', $unknown)) . '“ (erlaubt: title, artist, group, tags, release, from).');
        }

        if (!isset($meta['release'])) {
            throw new BuildError('Link: „release“ fehlt. Es braucht mindestens die https-Adresse der Aufnahme.');
        }

        $media = $this->projects->media(['release' => $meta['release']]);

        $from = $meta['from'] ?? [];

        if (!is_array($from) || !array_is_list($from) || count($from) > self::MAX_FROM) {
            throw new BuildError(sprintf('Link: „from“ muss eine Liste mit höchstens %d Songs in der Form prefix/slug sein.', self::MAX_FROM));
        }

        $credits = [];

        foreach ($from as $ref) {
            if (!is_string($ref) || preg_match('/^([a-z0-9][a-z0-9-]*)\/([a-z0-9][a-z0-9-]*(?:\/[a-z0-9][a-z0-9-]*)?)$/', trim($ref)) !== 1) {
                throw new BuildError('Link: Jeder Eintrag in „from“ muss ein Song in der Form prefix/slug sein.');
            }

            $ref = trim($ref);
            $resolved = $resolve($ref);

            if ($resolved['prefix'] !== $owner && ($resolved['project']['remix'] ?? null) !== true) {
                throw new BuildError("Link: „{$ref}“ erlaubt kein Remixen. Der Autor müsste „remix: true“ in sein Project schreiben.");
            }

            $credits[$ref] = ['prefix' => $resolved['prefix'], 'slug' => $resolved['slug'], 'title' => $resolved['project']['title']];
        }

        $head = $this->projects->describe($meta, $fallbackTitle);

        return new Link(
            title: $head['title'],
            artist: $head['artist'],
            group: $head['group'],
            tags: $head['tags'],
            media: $media,
            notesHtml: $this->sanitizer->clean($parsed->html),
            credits: array_values($credits),
        );
    }
}
