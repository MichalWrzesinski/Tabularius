<?php

declare(strict_types=1);

namespace App\Module\Report\Business;

final readonly class DiscountPolicy
{
    /**
     * @param list<DiscountTier> $tiers ascending by `from`
     */
    public function __construct(public string $code, public string $version, public string $validFrom, public array $tiers, public bool $fictional)
    {
    }
}
