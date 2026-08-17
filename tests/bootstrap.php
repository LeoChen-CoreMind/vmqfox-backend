<?php

require dirname(__DIR__) . '/vendor/autoload.php';

$failures = 0;

function test(string $name, callable $callback): void
{
    global $failures;

    try {
        $callback();
        echo "PASS {$name}\n";
    } catch (Throwable $e) {
        $failures++;
        fwrite(STDERR, "FAIL {$name}: {$e->getMessage()}\n");
    }
}

function assertSameValue(mixed $expected, mixed $actual): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true)
        );
    }
}
