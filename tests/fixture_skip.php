<?php

declare(strict_types=1);

/** A required database job must not report an unexecuted test as coverage. */
function fixture_skip(string $reason): never
{
    if (getenv('OPNG_TEST_REQUIRE_DATABASE_FIXTURES') === '1') {
        fwrite(STDERR, "FAIL: {$reason}\n");
        exit(1);
    }
    echo "SKIP: {$reason}\n";
    exit(0);
}
