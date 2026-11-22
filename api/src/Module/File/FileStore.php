<?php

declare(strict_types=1);

namespace App\Module\File;

/**
 * Storage of report contents by opaque key. Never served directly; access goes through FileAccess (ACC-FILE-1).
 */
interface FileStore
{
    public function put(string $key, string $content): void;

    /**
     * @throws \RuntimeException when the content is missing
     */
    public function get(string $key): string;

    public function delete(string $key): void;
}
