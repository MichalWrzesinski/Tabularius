<?php

declare(strict_types=1);

namespace App\Module\Report\Business;

final readonly class Customer
{
    public function __construct(public string $code, public string $name, public bool $active)
    {
    }
}
