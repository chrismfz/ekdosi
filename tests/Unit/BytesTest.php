<?php

namespace Tests\Unit;

use App\Support\Bytes;
use PHPUnit\Framework\TestCase;

class BytesTest extends TestCase
{
    public function test_humanizes_sizes(): void
    {
        $this->assertSame('—', Bytes::forHumans(null));
        $this->assertSame('', Bytes::forHumans(null, ''));
        $this->assertSame('0 B', Bytes::forHumans(0));
        $this->assertSame('512 B', Bytes::forHumans(512));
        $this->assertSame('1 KB', Bytes::forHumans(1024));
        $this->assertSame('1.5 KB', Bytes::forHumans(1536));
        $this->assertSame('1 MB', Bytes::forHumans(1024 * 1024));
        $this->assertSame('2.5 MB', Bytes::forHumans((int) (2.5 * 1024 * 1024)));
        $this->assertSame('1 GB', Bytes::forHumans(1024 ** 3));
        $this->assertSame('1 TB', Bytes::forHumans(1024 ** 4));
    }
}
