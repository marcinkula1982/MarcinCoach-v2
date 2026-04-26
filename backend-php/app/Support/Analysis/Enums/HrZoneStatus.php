<?php

namespace App\Support\Analysis\Enums;

enum HrZoneStatus: string
{
    case Known = 'known';
    case Derived = 'derived';
    case Estimated = 'estimated';
    case Missing = 'missing';
}
