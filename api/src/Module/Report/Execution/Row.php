<?php

declare(strict_types=1);

namespace App\Module\Report\Execution;

final readonly class Row
{
    public const string META = 'meta';

    /**
     * @param list<string|NumberCell> $cells
     */
    public function __construct(public string $type, public array $cells)
    {
    }

    /**
     * Metadata row (RPT-OUT-3): label in the second column, value in the third.
     *
     * @param int $width total number of columns
     */
    public static function meta(string $label, string $value, int $width): self
    {
        return new self(self::META, array_pad([self::META, $label, $value], $width, ''));
    }
}
