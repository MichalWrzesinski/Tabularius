<?php

declare(strict_types=1);

namespace App\Module\Report\Profile;

final readonly class Operation
{
    /**
     * @param list<string>        $columns
     * @param list<string>        $permittedFields
     * @param array<string, bool> $parameters      name => required
     */
    public function __construct(
        public string $id,
        public string $label,
        public string $fileName,
        public array $columns,
        public array $permittedFields,
        public array $parameters,
    ) {
    }
}
