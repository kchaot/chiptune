<?php

declare(strict_types=1);

namespace Chiptune\Common;

use Uri\Rfc3986\Uri;

/**
 * Prüft Adressen mit der URI-Erweiterung von PHP.
 *
 * Sie liest den Host aus und stellt fest, ob eine Adresse eine http(s)-Adresse mit Host ist.
 */
final class Url
{
    public static function host(string $url): string
    {
        return Uri::parse($url)?->getHost() ?? '';
    }

    public static function isHttp(string $url): bool
    {
        $uri = Uri::parse($url);

        return $uri !== null && in_array($uri->getScheme(), ['http', 'https'], true) && ($uri->getHost() ?? '') !== '';
    }
}
