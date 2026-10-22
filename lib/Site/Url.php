<?php

declare(strict_types=1);

namespace Chiptune\Site;

use Uri\Rfc3986\Uri;

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
