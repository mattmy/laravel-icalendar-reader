<?php

declare(strict_types=1);

use Mattmy\ICalendar\Tests\TestCase;

pest()->extend(TestCase::class)->in('Feature');

/**
 * Load the complete bytes of a named package fixture.
 *
 * @throws RuntimeException
 */
function calendarFixture(string $name): string
{
    $contents = \file_get_contents(__DIR__ . "/Fixtures/{$name}.ics");

    if ($contents === false) {
        throw new RuntimeException("Unable to read the {$name} fixture.");
    }

    return $contents;
}
