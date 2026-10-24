<?php

declare(strict_types=1);

namespace Chiptune\Site;

use Chiptune\Common\BuildError;

/**
 * Räumt den Ausgabeordner auf und schreibt Stylesheet und Player mit Hash im Namen.
 *
 * Die Adressen und Prüfsummen (`integrity`) gibt sie an den `Renderer` weiter.
 */
final class Assets
{
    private const ASSET_DIR = '_cdn';

    public function __construct(
        private readonly string $root,
        private readonly string $public,
    ) {}

    public function prepare(): void
    {
        $dist = $this->public;

        if ($dist !== $this->root . '/tmp/public') {
            throw new BuildError('Der Ausgabeordner muss tmp/public im Projektordner sein.');
        }

        Filesystem::removeDirectory($dist);

        if (!mkdir($dist, 0o775, true) && !is_dir($dist)) {
            throw new BuildError("Ausgabeordner konnte nicht angelegt werden: {$dist}");
        }
    }

    /**
     * @return array{css: array{url: string, integrity: string}, js: array{url: string, integrity: string}}
     */
    public function write(string $base): array
    {
        $assets = [];
        $fonts = [];

        foreach (glob($this->root . '/var/resources/fonts/*.woff2') ?: [] as $font) {
            $content = (string) file_get_contents($font);
            $name = basename($font, '.woff2') . '.' . substr(sha1($content), 0, 10) . '.woff2';
            Filesystem::write("{$this->public}/" . self::ASSET_DIR . "/fonts/{$name}", $content);
            $fonts['fonts/' . basename($font)] = 'fonts/' . $name;
        }

        foreach (glob($this->root . '/var/resources/fonts/LICENSE-*.txt') ?: [] as $license) {
            Filesystem::write("{$this->public}/" . self::ASSET_DIR . '/fonts/' . basename($license), (string) file_get_contents($license));
        }

        foreach (['css' => 'style', 'js' => 'player'] as $extension => $name) {
            $source = "{$this->root}/var/resources/{$name}.{$extension}";

            if (!is_file($source)) {
                throw new BuildError("var/resources/{$name}.{$extension} fehlt.");
            }

            $content = (string) file_get_contents($source);

            if ($extension === 'css') {
                $content = new \MatthiasMullie\Minify\CSS(strtr($content, $fonts))->minify();
            } else {
                $content = new \MatthiasMullie\Minify\JS($content)->minify();
            }

            $file = "{$name}." . substr(sha1($content), 0, 10) . ".{$extension}";
            Filesystem::write("{$this->public}/" . self::ASSET_DIR . "/{$file}", $content);
            $assets[$extension] = [
                'url' => $base . $file,
                'integrity' => 'sha384-' . base64_encode(hash('sha384', $content, true)),
            ];
        }

        return $assets;
    }
}
