<?php

declare(strict_types=1);

namespace App\Module\Identity;

enum AccountStatus: string
{
    case Active = 'active';
    case Blocked = 'blocked';
    case Deleted = 'deleted';
}
