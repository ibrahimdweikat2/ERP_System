<?php

namespace App\Domains\Accounting\Enums;

enum JournalStatus: string
{
    case Draft = 'draft';
    case Posted = 'posted';
}
