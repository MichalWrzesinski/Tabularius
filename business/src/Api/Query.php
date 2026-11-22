<?php

declare(strict_types=1);

namespace App\Api;

use Symfony\Component\HttpFoundation\Request;

/**
 * Strict query-string reader: unknown parameters are rejected, values are validated before reaching SQL.
 */
final class Query
{
    public const int MAX_PER_PAGE = 1000;

    /**
     * @param list<string> $allowed
     */
    public function __construct(private readonly Request $request, array $allowed)
    {
        $unknown = array_diff(array_keys($request->query->all()), $allowed);
        if ([] !== $unknown) {
            throw new ApiException(400, 'unknown_parameter', 'Unknown query parameter: '.implode(', ', $unknown));
        }
    }

    public function string(string $name, string $pattern, bool $required = false): ?string
    {
        $value = $this->request->query->all()[$name] ?? null; // all(): arrays (?x[]=) must not throw, they are invalid
        if (null === $value || '' === $value) {
            if ($required) {
                throw new ApiException(400, 'missing_parameter', \sprintf('Query parameter "%s" is required', $name));
            }

            return null;
        }
        if (!\is_string($value) || 1 !== preg_match($pattern, $value)) {
            throw new ApiException(400, 'invalid_parameter', \sprintf('Query parameter "%s" is invalid', $name));
        }

        return $value;
    }

    public function date(string $name): \DateTimeImmutable
    {
        $value = (string) $this->string($name, '/^\d{4}-\d{2}-\d{2}$/', true);
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (false === $date || $date->format('Y-m-d') !== $value) {
            throw new ApiException(400, 'invalid_parameter', \sprintf('Query parameter "%s" is not a valid date', $name));
        }

        return $date;
    }

    /**
     * @return list<string>
     */
    public function list(string $name, string $itemPattern, int $max = 100): array
    {
        $value = $this->string($name, '/^[^,]+(,[^,]+)*$/');
        if (null === $value) {
            return [];
        }
        $items = array_values(array_unique(explode(',', $value)));
        if (\count($items) > $max) {
            throw new ApiException(400, 'invalid_parameter', \sprintf('Too many values in "%s"', $name));
        }
        foreach ($items as $item) {
            if (1 !== preg_match($itemPattern, $item)) {
                throw new ApiException(400, 'invalid_parameter', \sprintf('Query parameter "%s" is invalid', $name));
            }
        }

        return $items;
    }

    /**
     * @return array{int, int} [page, perPage]
     */
    public function pagination(int $defaultPerPage = 500): array
    {
        $page = (int) ($this->string('page', '/^[1-9]\d{0,5}$/') ?? 1);
        $perPage = (int) ($this->string('perPage', '/^[1-9]\d{0,3}$/') ?? $defaultPerPage);
        if ($perPage > self::MAX_PER_PAGE) {
            throw new ApiException(400, 'invalid_parameter', 'perPage must not exceed '.self::MAX_PER_PAGE);
        }

        return [$page, $perPage];
    }
}
