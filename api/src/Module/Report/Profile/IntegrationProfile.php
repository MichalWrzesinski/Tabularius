<?php

declare(strict_types=1);

namespace App\Module\Report\Profile;

use Symfony\Component\Yaml\Yaml;

/**
 * Read-only view of the versioned integration profile (config/integration/*.yaml). Validation is strict:
 * a malformed profile stops the application instead of running with guessed rules.
 */
final readonly class IntegrationProfile
{
    /**
     * @param array<string, Operation> $operations
     * @param list<string>             $categories
     */
    private function __construct(
        public string $id,
        public int $version,
        public string $currency,
        public string $timezone,
        public array $operations,
        public array $categories,
        public int $maxRows,
    ) {
    }

    public static function fromFile(string $path): self
    {
        $data = Yaml::parseFile($path);
        if (!\is_array($data)) {
            throw new \UnexpectedValueException('Integration profile must be a mapping');
        }

        return self::fromArray($data);
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $operations = [];
        foreach (self::map($data, 'operations') as $id => $operation) {
            if (!\is_string($id) || !\is_array($operation)) {
                throw new \UnexpectedValueException('Invalid operation definition');
            }
            $operations[$id] = new Operation(
                $id,
                self::str($operation, 'label'),
                self::str($operation, 'file_name'),
                self::strings($operation, 'columns'),
                \array_key_exists('permitted_fields', $operation) ? self::strings($operation, 'permitted_fields') : [],
                self::parameters(self::map($operation, 'parameters')),
            );
        }
        $limits = self::map($data, 'limits');

        return new self(
            self::str($data, 'id'),
            self::int($data, 'version'),
            self::str($data, 'currency'),
            self::str($data, 'timezone'),
            $operations,
            self::strings($data, 'categories'),
            self::int($limits, 'max_rows'),
        );
    }

    /**
     * @param array<array-key, mixed> $definitions
     *
     * @return array<string, bool> parameter name => required
     */
    private static function parameters(array $definitions): array
    {
        $parameters = [];
        foreach ($definitions as $name => $definition) {
            if (!\is_string($name) || !\is_array($definition)) {
                throw new \UnexpectedValueException('Profile: invalid parameter definition');
            }
            $parameters[$name] = true === ($definition['required'] ?? false);
        }

        return $parameters;
    }

    public function operation(string $id): Operation
    {
        return $this->operations[$id] ?? throw new \OutOfBoundsException('Operation outside the catalog: '.$id);
    }

    public function label(): string
    {
        return $this->id.' v'.$this->version;
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @return array<array-key, mixed>
     */
    private static function map(array $data, string $key): array
    {
        $value = $data[$key] ?? null;

        return \is_array($value) ? $value : throw new \UnexpectedValueException('Profile: missing mapping '.$key);
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private static function str(array $data, string $key): string
    {
        $value = $data[$key] ?? null;

        return \is_string($value) && '' !== $value ? $value : throw new \UnexpectedValueException('Profile: missing string '.$key);
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private static function int(array $data, string $key): int
    {
        $value = $data[$key] ?? null;

        return \is_int($value) ? $value : throw new \UnexpectedValueException('Profile: missing integer '.$key);
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @return list<string>
     */
    private static function strings(array $data, string $key): array
    {
        $values = $data[$key] ?? null;
        if (!\is_array($values) || !array_is_list($values) || [] === $values) {
            throw new \UnexpectedValueException('Profile: missing list '.$key);
        }
        foreach ($values as $value) {
            if (!\is_string($value)) {
                throw new \UnexpectedValueException('Profile: list '.$key.' must contain strings');
            }
        }

        /** @var list<string> $values */
        return $values;
    }
}
