<?php

declare(strict_types=1);

use Mattmy\ICalendar\Tests\TestCase;
use Sabre\VObject\Component;
use Sabre\VObject\Property;

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

/** Read a raw property through Sabre's explicit selection API. */
function calendarRawProperty(Component $component, string $name): string
{
    $property = $component->select($name)[0] ?? null;
    if (! $property instanceof Property) {
        throw new RuntimeException('Expected raw property is missing.');
    }

    return (string) $property;
}
