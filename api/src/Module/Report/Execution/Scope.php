<?php

declare(strict_types=1);

namespace App\Module\Report\Execution;

/**
 * Trusted execution scope: the ticket owner's representative identity (ACC-SCOPE-1, ACC-ADM-1). Never built from
 * interpreted parameters.
 */
final readonly class Scope
{
    public function __construct(public string $salesRep)
    {
        if (1 !== preg_match('/^PH-\d{2}$/', $salesRep)) {
            throw new \InvalidArgumentException('Invalid representative code');
        }
    }
}
