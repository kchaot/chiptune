<?php

declare(strict_types=1);

namespace Chiptune;

final class Filesystem
{
    public static function removeDirectory(string $directory): void
    {
        if (!is_dir($directory) || is_link($directory)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            $path = $item->getPathname();

            if ($item->isDir() && !$item->isLink()) {
                rmdir($path);
            } else {
                unlink($path);
            }
        }

        rmdir($directory);
    }

    public static function write(string $path, string $contents): void
    {
        $directory = dirname($path);

        if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw new BuildError("Ordner konnte nicht angelegt werden: {$directory}");
        }

        if (file_put_contents($path, $contents) === false) {
            throw new BuildError("Datei konnte nicht geschrieben werden: {$path}");
        }
    }
}
