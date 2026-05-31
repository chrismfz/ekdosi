<?php

namespace Tests\Unit;

use App\Console\Commands\RefreshVatPicture;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * The 429 backoff maths: honour AADE's "try again in N seconds" (capped), else
 * exponential. Pure logic — no AADE, no sleeping.
 */
class RefreshVatPictureBackoffTest extends TestCase
{
    private function backoff(string $message, int $attempt): int
    {
        $m = new ReflectionMethod(RefreshVatPicture::class, 'backoffSeconds');
        $m->setAccessible(true);

        return $m->invoke(new RefreshVatPicture, $message, $attempt);
    }

    public function test_honours_the_aade_suggested_seconds_plus_one(): void
    {
        $this->assertSame(158, $this->backoff('Rate limit is exceeded. Try again in 157 seconds.', 0));
    }

    public function test_caps_an_excessive_suggestion(): void
    {
        $this->assertSame(180, $this->backoff('Try again in 600 seconds.', 0));
    }

    public function test_falls_back_to_exponential_when_no_hint(): void
    {
        $this->assertSame(5, $this->backoff('Too many requests', 0));
        $this->assertSame(10, $this->backoff('Too many requests', 1));
        $this->assertSame(20, $this->backoff('Too many requests', 2));
    }

    public function test_exponential_is_also_capped(): void
    {
        $this->assertSame(180, $this->backoff('Too many requests', 20));
    }
}
