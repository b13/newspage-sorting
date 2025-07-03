<?php

declare(strict_types=1);

namespace B13\NewspageSorting;

enum FolderType: string
{
    case YEAR = 'Y';
    case MONTH = 'm';
    case DAY = 'd';
}