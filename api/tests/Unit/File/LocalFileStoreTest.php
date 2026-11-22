<?php

declare(strict_types=1);

namespace App\Tests\Unit\File;

use App\Module\File\LocalFileStore;
use PHPUnit\Framework\TestCase;

final class LocalFileStoreTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/tabularius-store-'.bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/*/*') ?: [] as $file) {
            unlink($file);
        }
        foreach (glob($this->dir.'/*') ?: [] as $dir) {
            rmdir($dir);
        }
        if (is_dir($this->dir)) {
            rmdir($this->dir);
        }
    }

    public function testStoresPrivatelyReadsAndDeletes(): void
    {
        $store = new LocalFileStore($this->dir);
        $key = str_repeat('ab', 16);
        $store->put($key, "a;b\r\n");

        self::assertSame("a;b\r\n", $store->get($key));
        self::assertSame('0600', substr(\sprintf('%o', fileperms($this->dir.'/ab/'.$key.'.csv')), -4));
        self::assertSame([], glob($this->dir.'/ab/*.tmp-*'));

        $store->delete($key);
        $store->delete($key); // idempotent
        $this->expectException(\RuntimeException::class);
        $store->get($key);
    }

    public function testRejectsKeysThatAreNotApplicationIds(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new LocalFileStore($this->dir)->get('../../etc/passwd');
    }
}
