<?php

declare(strict_types=1);

namespace Chiptune\Format;

use Chiptune\Common\BuildError;
use Chiptune\Common\HtmlSanitizer;
use Tempest\Markdown\Markdown;

/**
 * Liest Links aus Markdown.
 *
 * Der Kopf braucht `release` (die https-Adresse der Aufnahme) und kann mit `from` nennen, aus
 * welchen Songs sie entstanden ist. Dabei wird geprüft, ob deren Autoren Remixe erlauben. Das
 * Ergebnis ist ein `Link`.
 */
final class LinkParser
{
    public const FORMAT = 1;
    public const MAX_BYTES = 16_384;
    public const MAX_FROM = 6;

    public function __construct(
        private readonly SongParser $songs,
        private readonly Markdown $markdown = new Markdown(null),
        private readonly HtmlSanitizer $sanitizer = new HtmlSanitizer(),
    ) {}

    /**
     * @param \Closure(string): array{prefix: string, slug: string, song: Song} $resolve
     */
    public function parse(string $source, string $fallbackTitle, \Closure $resolve, string $owner): Link
    {
        if (strlen($source) > self::MAX_BYTES) {
            throw new BuildError(sprintf('Die Datei ist größer als %d KB.', self::MAX_BYTES / 1024));
        }

        $parsed = $this->markdown->parse($source);
        /** @var array<string, mixed> $meta */
        $meta = $parsed->frontmatter;

        if (($meta['format'] ?? self::FORMAT) !== self::FORMAT) {
            throw new BuildError('Nicht unterstütztes format: ' . (string) json_encode($meta['format']) . '. Unterstützt wird format: ' . self::FORMAT . '.');
        }

        $unknown = array_diff(array_keys($meta), ['kind', 'format', 'title', 'artist', 'group', 'tags', 'release', 'from']);

        if ($unknown !== []) {
            throw new BuildError(
                'Link: unbekannter Schlüssel „' . implode('“, „', array_map('strval', $unknown)) . '“ (erlaubt: kind, format, title, artist, group, tags, release, from).',
            );
        }

        if (!isset($meta['release'])) {
            throw new BuildError('Link: „release“ fehlt. Es braucht mindestens die https-Adresse der Aufnahme.');
        }

        $media = $this->songs->media(['release' => $meta['release']]);

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

            if ($resolved['prefix'] !== $owner && $resolved['song']->remix !== true) {
                throw new BuildError("Link: „{$ref}“ erlaubt kein Remixen. Der Autor müsste „remix: true“ in seinen Song schreiben.");
            }

            $credits[$ref] = ['prefix' => $resolved['prefix'], 'slug' => $resolved['slug'], 'title' => $resolved['song']->title];
        }

        $head = $this->songs->describe($meta, $fallbackTitle);

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
