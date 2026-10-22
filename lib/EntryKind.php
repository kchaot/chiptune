<?php

declare(strict_types=1);

namespace Chiptune;

enum EntryKind: string
{
    case Project = 'project';
    case Set = 'set';
    case Session = 'session';
    case Link = 'link';
    case Album = 'album';
}
