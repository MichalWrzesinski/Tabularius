<?php

declare(strict_types=1);

namespace App\Module\Identity\Http;

use App\Module\Identity\Account;

final class MeView
{
    /**
     * @return array{id: string, email: string, name: string, role: string, repCode: ?string, shared: bool}
     */
    public static function of(Account $account): array
    {
        return [
            'id' => $account->id()->toRfc4122(),
            'email' => $account->email(),
            'name' => $account->name(),
            'role' => $account->role()->value,
            'repCode' => $account->repCode(),
            'shared' => $account->isShared(),
        ];
    }
}
