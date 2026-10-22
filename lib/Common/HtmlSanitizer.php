<?php

declare(strict_types=1);

namespace Chiptune\Common;

use Dom\Element;
use Dom\HTMLDocument;

/**
 * Säubert HTML, das aus Markdown entstanden ist.
 *
 * Erlaubt sind nur bekannte Elemente und bei Links ein sicheres `href`. Alles andere fällt weg,
 * Skripte und Ähnliches samt Inhalt, ebenso Kommentare.
 */
final class HtmlSanitizer
{
    private const ALLOWED = [
        'a',
        'b',
        'blockquote',
        'br',
        'code',
        'del',
        'div',
        'em',
        'h1',
        'h2',
        'h3',
        'h4',
        'h5',
        'h6',
        'hr',
        'i',
        'li',
        'ol',
        'p',
        'pre',
        'span',
        'strong',
        'table',
        'tbody',
        'td',
        'th',
        'thead',
        'tr',
        'ul',
    ];

    private const DROP_WITH_CONTENT = [
        'applet',
        'audio',
        'base',
        'button',
        'canvas',
        'embed',
        'form',
        'frame',
        'frameset',
        'head',
        'iframe',
        'img',
        'input',
        'link',
        'math',
        'meta',
        'noscript',
        'object',
        'script',
        'select',
        'source',
        'style',
        'svg',
        'template',
        'textarea',
        'title',
        'video',
    ];

    public function clean(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }

        $document = HTMLDocument::createFromString(
            '<!DOCTYPE html><html><body>' . $html . '</body></html>',
            LIBXML_NOERROR,
            'UTF-8',
        );

        $body = $document->body ?? throw new \LogicException('HTML-Dokument ohne body.');
        /** @var list<\Dom\Element> $elements */
        $elements = iterator_to_array($body->querySelectorAll('*'), false);

        foreach (array_reverse($elements) as $element) {
            $this->cleanElement($element);
        }

        return trim((string) preg_replace('/<!--.*?-->/s', '', $body->innerHTML));
    }

    private function cleanElement(Element $element): void
    {
        $tag = strtolower($element->localName);

        if (in_array($tag, self::DROP_WITH_CONTENT, true)) {
            $element->remove();

            return;
        }

        if (!in_array($tag, self::ALLOWED, true)) {
            $this->unwrap($element);

            return;
        }

        $names = [];

        foreach ($element->attributes as $attribute) {
            $names[] = $attribute->name;
        }

        foreach ($names as $name) {
            if ($tag === 'a' && $name === 'href' && $this->isSafeHref($element->getAttribute('href'))) {
                continue;
            }

            $element->removeAttribute($name);
        }

        if ($tag === 'a' && $element->hasAttribute('href')) {
            $element->setAttribute('rel', 'noopener noreferrer');
        }
    }

    private function unwrap(Element $element): void
    {
        $parent = $element->parentNode;

        if ($parent === null) {
            return;
        }

        while ($element->firstChild !== null) {
            $parent->insertBefore($element->firstChild, $element);
        }

        $element->remove();
    }

    private function isSafeHref(?string $href): bool
    {
        $href = trim((string) $href);

        if ($href === '' || preg_match('/[\x00-\x20\x7F]/', $href) === 1) {
            return false;
        }

        return preg_match('~^(https?://|mailto:|/(?!/)|#|[a-z0-9._+-]+(/|$))~i', $href) === 1;
    }
}
