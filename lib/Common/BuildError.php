<?php

declare(strict_types=1);

namespace Chiptune\Common;

/**
 * Ein Fehler beim Einlesen oder Bauen, der dem Autor gemeldet werden soll.
 *
 * Das kann eine kaputte Datei oder ein ungültiger Wert sein. Das Format wirft ihn, die Site fängt ihn.
 */
final class BuildError extends \RuntimeException {}
