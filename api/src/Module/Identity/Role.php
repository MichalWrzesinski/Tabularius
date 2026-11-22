<?php

declare(strict_types=1);

namespace App\Module\Identity;

enum Role: string
{
    case Admin = 'admin';
    case Rep = 'rep';

    public function securityRole(): string
    {
        return match ($this) {
            self::Admin => 'ROLE_ADMIN',
            self::Rep => 'ROLE_REP',
        };
    }
}
