<?php

declare(strict_types=1);

namespace Chiptune\Site;

use Chiptune\Format\Link;
use Chiptune\Format\Session;
use Chiptune\Format\Set;
use Chiptune\Format\Song;

/**
 * Die Art eines Eintrags: Song, Set, Session, Link oder Album.
 */
enum EntryKind: string
{
    case Song = 'song';
    case Set = 'set';
    case Session = 'session';
    case Link = 'link';
    case Album = 'album';
}
