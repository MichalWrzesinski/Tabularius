<?php

declare(strict_types=1);

namespace App\Module\Report\Business;

final readonly class Manufacturer
{
    /**
     * @param list<string> $aliases
     */
    public function __construct(public string $code, public string $name, public array $aliases, public bool $active)
    {
    }
}
