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
    public const MAX_BYTES = 16_384;
    public const MAX_FROM = 6;

    public function __construct(
        private readonly Markdown $markdown = new Markdown(null),
        private readonly HtmlSanitizer $sanitizer = new HtmlSanitizer(),
        private readonly MediaParser $media = new MediaParser(),
    ) {}

    /**
     * @param \Closure(string): array{prefix: string, slug: string, song: Song} $resolve
     */
    public function parse(string $source, string $fallbackTitle, \Closure $resolve, string $owner): Link
    {
        Fields::size($source, self::MAX_BYTES);

        $parsed = $this->markdown->parse($source);
        /** @var array<string, mixed> $meta */
        $meta = $parsed->frontmatter;

        Fields::version($meta);

        Fields::keys($meta, ['kind', 'format', 'title', 'artist', 'group', 'tags', 'release', 'from'], 'Link');

        if (!isset($meta['release'])) {
            throw new BuildError('Link: „release“ fehlt. Es braucht mindestens die https-Adresse der Aufnahme.');
        }

        $media = $this->media->parse(['release' => $meta['release']]);

        $from = $meta['from'] ?? [];

        if (!is_array($from) || !array_is_list($from) || count($from) > self::MAX_FROM) {
            throw new BuildError(sprintf('Link: „from“ muss eine Liste mit höchstens %d Songs in der Form prefix/slug sein.', self::MAX_FROM));
        }

        $credits = [];

        foreach ($from as $ref) {
            if (!is_string($ref) || preg_match(Fields::REF, trim($ref)) !== 1) {
                throw new BuildError('Link: Jeder Eintrag in „from“ muss ein Song in der Form prefix/slug sein.');
            }

            $ref = trim($ref);
            $resolved = $resolve($ref);

            Fields::mayRemix('Link', $ref, $resolved['prefix'], $owner, $resolved['song']->remix);

            $credits[$ref] = new Credit($resolved['prefix'], $resolved['slug'], $resolved['song']->title);
        }

        $head = Fields::describe($meta, $fallbackTitle);

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
