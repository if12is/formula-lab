<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Throwable;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Views render without the Vite manifest, so tests do not depend on a frontend build.
        $this->withoutVite();
    }

    /**
     * Asserts that the callback throws an instance of the class (optionally with the text in its message).
     *
     * @param  class-string<Throwable>  $class
     */
    protected function assertThrowsException(string $class, callable $callback, ?string $messageContains = null): void
    {
        try {
            $callback();
        } catch (Throwable $exception) {
            $this->assertInstanceOf($class, $exception);

            if ($messageContains !== null) {
                $this->assertStringContainsString($messageContains, $exception->getMessage());
            }

            return;
        }

        $this->fail("Failed asserting that {$class} was thrown.");
    }
}
