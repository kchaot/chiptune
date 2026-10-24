<?php

declare(strict_types=1);

namespace Chiptune\Site;

use Chiptune\Common\BuildError;
use Chiptune\Format\Link;
use Chiptune\Format\Media;
use Chiptune\Format\Song;

/**
 * Liest alle Dateien der Quellen über die `Library` ein und macht daraus die Einträge der Seite.
 *
 * Dabei werden die Medien gegen die freigegebenen Hosts der Quelle geprüft, und für Songs wird ein von Hand
 * geschriebener Strudel-Code aus der Quelle übernommen, falls es ihn gibt.
 */
final class EntryLoader
{
    /**
     * @param list<array{source: string, prefix: string, slug: string, album: string|null, file: string, strict: bool, media: list<string>}> $files
     * @return list<Entry>
     */
    public function load(array $files, Library $library, Problems $problems): array
    {
        $entries = [];

        foreach ($files as $file) {
            $label = "{$file['source']}/{$file['slug']}.md";

            try {
                $loaded = $library->load("{$file['prefix']}/{$file['slug']}");
            } catch (BuildError $error) {
                $problems->report($file['strict'], $label, $error);

                continue;
            }

            $data = $loaded['data'];

            foreach ($data->warnings as $warning) {
                fwrite(STDERR, "Warnung in {$label}: {$warning}\n");
            }

            if (($data instanceof Link || $data instanceof Song) && !$data->media->isEmpty()) {
                $data = $data->withMedia($this->checkedMedia($data->media, $file['media'], $file['strict'], $label, $problems));
            }

            if ($file['album'] !== null && $data->group !== '') {
                fwrite(STDERR, "Warnung in {$label}: „group“ gilt nur für Songs ohne Album und wird ignoriert.\n");
            }

            $entries[] = new Entry(
                prefix: $file['prefix'],
                slug: $file['slug'],
                album: $file['album'],
                source: $file['file'],
                kind: EntryKind::from($loaded['kind']),
                data: $data,
                strudel: $data instanceof Song ? $this->handStrudel($file['file'], $data, $label) : null,
            );
        }

        usort($entries, fn(Entry $a, Entry $b): int => strcasecmp($a->title(), $b->title()));

        return $entries;
    }

    /**
     * @param list<string> $allowed
     */
    private function checkedMedia(Media $media, array $allowed, bool $strict, string $label, Problems $problems): Media
    {
        foreach (['hq', 'cover'] as $key) {
            if ($media->{$key} === null) {
                continue;
            }

            $host = Url::host($media->{$key});

            if (in_array($host, $allowed, true)) {
                continue;
            }

            $message = "„{$key}“ verweist auf „{$host}“. Der Host ist für diese Quelle nicht unter „media“ in sources.json freigegeben.";

            if ($strict) {
                $problems->add("{$label}: {$message}");
            } else {
                fwrite(STDERR, "Warnung in {$label}: {$message} Der Link wird ignoriert.\n");
            }

            $media = $key === 'hq' ? $media->withoutHq() : $media->withoutCover();
        }

        if ($media->release !== null && in_array(Url::host($media->release), $allowed, true)) {
            $media = $media->asOwnRelease();
        }

        if (($media->cover !== null || !$media->info->isEmpty()) && !$media->releaseOwn) {
            $message = 'Die Angaben in „release“ (cover, title, date, label, catalog, license, credits) werden nur bei einer selbst veröffentlichten Audiodatei angezeigt (Host unter „media“ in sources.json freigegeben).';

            if ($strict) {
                $problems->add("{$label}: {$message}");
            } else {
                fwrite(STDERR, "Warnung in {$label}: {$message} Die Angaben werden ignoriert.\n");
            }

            $media = $media->withoutReleaseDetails();
        }

        return $media;
    }

    private function handStrudel(string $markdownFile, Song $song, string $label): ?string
    {
        $path = substr($markdownFile, 0, -3) . '.strudel';

        if (!is_file($path) || is_link($path)) {
            return null;
        }

        $name = basename($path);
        $text = str_replace("\r\n", "\n", (string) file_get_contents($path));
        $stand = Renderer::strudelStand($song);

        if (strlen($text) > 65_536) {
            fwrite(STDERR, "Warnung in {$label}: {$name} ist größer als 64 KB und wird ignoriert.\n");

            return null;
        }

        $lines = explode("\n", $text);
        $found = null;

        $match = [];
        while ($lines !== [] && preg_match('~^// (Quelle|Stand):[ \t]*(.*?)[ \t]*$~', $lines[0], $match) === 1) {
            if ($match[1] === 'Stand') {
                $found = $match[2];
            }

            array_shift($lines);
        }

        if ($found === null) {
            fwrite(STDERR, "Warnung in {$label}: {$name} braucht eine Zeile „// Stand: {$stand}“ am Anfang und wird ignoriert.\n");

            return null;
        }

        if ($found !== $stand) {
            fwrite(STDERR, "Warnung in {$label}: {$name} ist veraltet (Stand {$found}, Song jetzt {$stand}). Es gilt der generierte Code.\n");

            return null;
        }

        return rtrim(implode("\n", $lines), "\n") . "\n";
    }
}
