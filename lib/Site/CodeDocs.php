<?php

declare(strict_types=1);

namespace Chiptune\Site;

use ReflectionClass;
use ReflectionClassConstant;
use ReflectionMethod;
use ReflectionParameter;
use ReflectionProperty;
use ReflectionType;

/**
 * Sammelt die Klassenbeschreibungen aus `lib/` zu einer Code-Übersicht.
 *
 * Gelesen wird per Reflection: Der Text kommt aus dem Docblock der Klasse, Felder, Typen, Konstanten
 * und Methoden aus dem Code selbst. Der Build macht daraus eine Seite, `bin/docs.php` eine Markdown-Datei.
 */
final class CodeDocs
{
    public const AREAS = [
        'Format' => ['Das Format (IN)', 'Liest Markdown und macht daraus unveränderliche Value Objects. Kennt weder Seiten noch Dateisystem.'],
        'Site' => ['Die Site (OUT)', 'Die Referenz-Implementierung: holt Quellen, baut daraus die Seite und rendert sie.'],
        'Common' => ['Gemeinsames', 'Was Format und Site beide brauchen.'],
    ];

    public function __construct(
        private readonly string $root,
    ) {}

    /**
     * @return array<string, list<DocEntry>>
     */
    public function areas(): array
    {
        $areas = [];

        foreach (array_keys(self::AREAS) as $area) {
            $areas[$area] = array_map($this->entry(...), $this->classes($area));
        }

        return $areas;
    }

    /**
     * @return list<string>
     */
    public function missing(): array
    {
        $missing = [];

        foreach ($this->areas() as $entries) {
            foreach ($entries as $entry) {
                if ($entry->text === '') {
                    $missing[] = $entry->name;
                }
            }
        }

        return $missing;
    }

    public function markdown(): string
    {
        $overview = [];
        $body = '';

        foreach ($this->areas() as $area => $entries) {
            [$title, $intro] = self::AREAS[$area];
            $body .= "## {$title}\n\n{$intro}\n\n";

            foreach ($entries as $entry) {
                $overview[] = "| [`{$entry->short}`](#" . strtolower($entry->short) . ") | {$area} | {$entry->summary()} |";
                $body .= "### {$entry->short}\n\n*{$entry->kind} in `{$entry->name}`*\n\n" . ($entry->text === '' ? "_Noch keine Beschreibung._\n\n" : $entry->text . "\n\n");

                if ($entry->fields !== []) {
                    $rows = array_map(static fn(array $field): string => "| `{$field['name']}` | `" . str_replace('|', '\|', $field['type']) . '` |', $entry->fields);
                    $body .= "**Felder**\n\n| Name | Typ |\n| --- | --- |\n" . implode("\n", $rows) . "\n\n";
                }

                if ($entry->cases !== []) {
                    $body .= '**Fälle:** `' . implode('`, `', $entry->cases) . "`\n\n";
                }

                if ($entry->constants !== []) {
                    $rows = array_map(static fn(array $constant): string => "| `{$constant['name']}` | `{$constant['value']}` |", $entry->constants);
                    $body .= "**Konstanten**\n\n| Name | Wert |\n| --- | --- |\n" . implode("\n", $rows) . "\n\n";
                }

                if ($entry->methods !== []) {
                    $body .= "**Methoden**\n\n- `" . implode("`\n- `", $entry->methods) . "`\n\n";
                }
            }
        }

        return (
            "# Code-Übersicht\n\nErzeugt aus den Klassenbeschreibungen in `lib/`. Nicht von Hand ändern.\n\n"
            . "| Klasse | Bereich | Kurz gesagt |\n| --- | --- | --- |\n"
            . implode("\n", $overview)
            . "\n\n"
            . rtrim($body)
            . "\n"
        );
    }

    /**
     * @return list<class-string>
     */
    private function classes(string $area): array
    {
        $found = [];

        foreach (glob("{$this->root}/lib/{$area}/*.php") ?: [] as $file) {
            $name = "Chiptune\\{$area}\\" . basename($file, '.php');

            if (class_exists($name) || interface_exists($name) || enum_exists($name)) {
                $found[] = $name;
            }
        }

        sort($found);

        return $found;
    }

    /**
     * @param class-string $name
     */
    private function entry(string $name): DocEntry
    {
        $class = new ReflectionClass($name);
        $types = $this->paramTypes($class);
        $fields = [];

        foreach ($class->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->getDeclaringClass()->getName() !== $name) {
                continue;
            }

            $declared = $types[$property->getName()] ?? null;
            $match = [];

            if ($declared === null && preg_match('/@var\s+([^\n*]+?)\s*(?:\*\/|\n|$)/', (string) $property->getDocComment(), $match) === 1) {
                $declared = $match[1];
            }

            $fields[] = ['name' => $property->getName(), 'type' => $declared ?? $this->typeName($property->getType())];
        }

        $constants = [];
        $cases = [];

        foreach ($class->getReflectionConstants(ReflectionClassConstant::IS_PUBLIC) as $constant) {
            if ($class->isEnum()) {
                $cases[] = $constant->getName();
            } elseif ($constant->getDeclaringClass()->getName() === $name) {
                $constants[] = ['name' => $constant->getName(), 'value' => (string) json_encode($constant->getValue())];
            }
        }

        $methods = [];

        foreach ($class->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($class->isEnum() || $method->getDeclaringClass()->getName() !== $name || $method->isConstructor()) {
                continue;
            }

            $params = implode(', ', array_map(fn(ReflectionParameter $p): string => $this->typeName($p->getType()) . ' $' . $p->getName(), $method->getParameters()));
            $methods[] = $method->getName() . "({$params}): " . $this->typeName($method->getReturnType());
        }

        return new DocEntry(
            name: $name,
            short: $class->getShortName(),
            kind: $class->isInterface() ? 'Interface' : ($class->isEnum() ? 'Enum' : 'Klasse'),
            text: $this->description($class->getDocComment()),
            fields: $fields,
            constants: $constants,
            cases: $cases,
            methods: $methods,
        );
    }

    private function description(string|false $doc): string
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
     * @param ReflectionClass<object> $class
     * @return array<string, string>
     */
    private function paramTypes(ReflectionClass $class): array
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

    private function typeName(?ReflectionType $type): string
    {
        if ($type === null) {
            return 'mixed';
        }

        $name = (string) preg_replace('/\b(Chiptune\\\\)?(Format|Site|Common)\\\\/', '', (string) $type);

        return str_starts_with($name, '?') ? substr($name, 1) . '|null' : $name;
    }
}
