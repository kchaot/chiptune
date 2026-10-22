#!/usr/bin/env php
<?php

declare(strict_types=1);

$root = dirname(__DIR__);

if (!is_file($root . '/opt/packagist/autoload.php')) {
    fwrite(STDERR, "opt/packagist/ fehlt. Bitte zuerst „composer install“ ausführen.\n");

    exit(2);
}

require $root . '/opt/packagist/autoload.php';

const AREAS = [
    'Format' => ['Das Format (IN)', 'Liest Markdown und macht daraus unveränderliche Value Objects. Kennt weder Seiten noch Dateisystem.'],
    'Site' => ['Die Site (OUT)', 'Die Referenz-Implementierung: holt Quellen, baut daraus die Seite und rendert sie.'],
    'Common' => ['Gemeinsames', 'Was Format und Site beide brauchen.'],
];

function description(string|false $doc): string
{
    if ($doc === false) {
        return '';
    }

    $lines = [];

    foreach (preg_split('/\R/', $doc) ?: [] as $line) {
        $text = trim((string) preg_replace('~^\s*(/\*\*|\*/|\*)\s?~', '', $line));

        if (str_starts_with($text, '@')) {
            break;
        }

        $lines[] = $text;
    }

    return trim(implode("\n", $lines));
}

/**
 * @return array<string, string>
 */
function paramTypes(ReflectionClass $class): array
{
    $doc = $class->getConstructor()?->getDocComment();
    $types = [];

    $matches = [];

    if ($doc !== false && $doc !== null && preg_match_all('/@param\s+(.+?)\s+\$(\w+)/', $doc, $matches, PREG_SET_ORDER) !== false) {
        foreach ($matches as $match) {
            $types[$match[2]] = $match[1];
        }
    }

    return $types;
}

function typeName(?ReflectionType $type): string
{
    if ($type === null) {
        return 'mixed';
    }

    $name = (string) preg_replace('/\b(Chiptune\\\\)?(Format|Site|Common)\\\\/', '', (string) $type);

    return str_starts_with($name, '?') ? substr($name, 1) . '|null' : $name;
}

function firstSentence(string $text): string
{
    $first = trim(explode("\n\n", $text)[0]);

    return trim((string) preg_replace('/\s+/', ' ', $first));
}

/**
 * @return list<class-string>
 */
function classesOf(string $root, string $area): array
{
    $found = [];

    foreach (glob("{$root}/lib/{$area}/*.php") ?: [] as $file) {
        $name = "Chiptune\\{$area}\\" . basename($file, '.php');

        if (class_exists($name) || interface_exists($name) || enum_exists($name)) {
            $found[] = $name;
        }
    }

    sort($found);

    return $found;
}

$check = in_array('--check', $argv, true);
$target = null;

foreach (array_slice($argv, 1) as $arg) {
    if (!str_starts_with($arg, '--')) {
        $target = $arg;
    }
}

$missing = [];
$overview = [];
$body = '';

foreach (AREAS as $area => [$title, $intro]) {
    $body .= "## {$title}\n\n{$intro}\n\n";

    foreach (classesOf($root, $area) as $name) {
        $class = new ReflectionClass($name);
        $short = $class->getShortName();
        $text = description($class->getDocComment());

        if ($text === '') {
            $missing[] = $name;
        }

        $overview[] = "| [`{$short}`](#" . strtolower($short) . ") | {$area} | " . firstSentence($text) . ' |';

        $kind = $class->isInterface() ? 'Interface' : ($class->isEnum() ? 'Enum' : 'Klasse');
        $body .= "### {$short}\n\n*{$kind} in `{$name}`*\n\n" . ($text === '' ? "_Noch keine Beschreibung._\n\n" : $text . "\n\n");

        $types = paramTypes($class);
        $rows = [];

        foreach ($class->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->getDeclaringClass()->getName() !== $name) {
                continue;
            }

            $declared = $types[$property->getName()] ?? null;

            $match = [];

            if ($declared === null && preg_match('/@var\s+(\S+)/', (string) $property->getDocComment(), $match) === 1) {
                $declared = $match[1];
            }

            $rows[] = "| `{$property->getName()}` | `" . str_replace('|', '\\|', $declared ?? typeName($property->getType())) . '` |';
        }

        if ($rows !== []) {
            $body .= "**Felder**\n\n| Name | Typ |\n| --- | --- |\n" . implode("\n", $rows) . "\n\n";
        }

        $cases = $class->isEnum() ? array_map(static fn(ReflectionClassConstant $case): string => '`' . $case->getName() . '`', $class->getReflectionConstants()) : [];

        if ($cases !== []) {
            $body .= '**Fälle:** ' . implode(', ', $cases) . "\n\n";
        }

        $limits = [];

        foreach ($class->getReflectionConstants(ReflectionClassConstant::IS_PUBLIC) as $constant) {
            if (!$class->isEnum() && $constant->getDeclaringClass()->getName() === $name) {
                $limits[] = "| `{$constant->getName()}` | `" . (string) json_encode($constant->getValue()) . '` |';
            }
        }

        if ($limits !== []) {
            $body .= "**Konstanten**\n\n| Name | Wert |\n| --- | --- |\n" . implode("\n", $limits) . "\n\n";
        }

        $methods = [];

        foreach ($class->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getDeclaringClass()->getName() !== $name || $method->isConstructor()) {
                continue;
            }

            $params = implode(', ', array_map(static fn(ReflectionParameter $p): string => typeName($p->getType()) . ' $' . $p->getName(), $method->getParameters()));
            $methods[] = '- `' . $method->getName() . "({$params}): " . typeName($method->getReturnType()) . '`';
        }

        if ($methods !== [] && !$class->isEnum()) {
            $body .= "**Methoden**\n\n" . implode("\n", $methods) . "\n\n";
        }
    }
}

if ($check) {
    foreach ($missing as $name) {
        fwrite(STDERR, "Keine Beschreibung: {$name}\n");
    }

    exit($missing === [] ? 0 : 1);
}

$markdown =
    "# Code-Übersicht\n\nErzeugt mit `php bin/docs.php` aus den Klassenbeschreibungen in `lib/`. Nicht von Hand ändern.\n\n"
    . "| Klasse | Bereich | Kurz gesagt |\n| --- | --- | --- |\n"
    . implode("\n", $overview)
    . "\n\n"
    . rtrim($body)
    . "\n";

if ($target === null) {
    echo $markdown;

    exit(0);
}

if (file_put_contents($target, $markdown) === false) {
    fwrite(STDERR, "Datei konnte nicht geschrieben werden: {$target}\n");

    exit(1);
}
