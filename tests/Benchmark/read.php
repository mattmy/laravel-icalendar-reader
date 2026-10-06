<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Mattmy\ICalendar\Reader;
use Mattmy\ICalendar\Support\BoundedInputReader;
use Mattmy\ICalendar\Support\CalendarHydrator;
use Mattmy\ICalendar\Support\CalendarValidator;
use Mattmy\ICalendar\Support\DateTimeMapper;
use Mattmy\ICalendar\Support\PropertyHydrator;
use Mattmy\ICalendar\Support\TimezoneResolver;

require __DIR__ . '/../../vendor/autoload.php';

$config = new Repository([
    'app' => ['timezone' => 'UTC'],
    'icalendar_reader' => [
        'max_bytes' => 10 * 1024 * 1024,
        'floating_timezone' => null,
    ],
]);
$dateTimeMapper = new DateTimeMapper();
$reader = new Reader(
    $config,
    new BoundedInputReader(),
    new CalendarValidator(),
    new TimezoneResolver($config),
    new CalendarHydrator(new PropertyHydrator($dateTimeMapper), $dateTimeMapper),
);

foreach ([1, 100, 1000] as $eventCount) {
    $events = '';

    for ($index = 1; $index <= $eventCount; $index++) {
        $events .= "BEGIN:VEVENT\r\nUID:benchmark-{$index}@example.test\r\n"
            . "DTSTAMP:20260803T000000Z\r\nDTSTART:20260803T010000Z\r\n"
            . "DURATION:PT30M\r\nSUMMARY:Benchmark {$index}\r\nEND:VEVENT\r\n";
    }

    $contents = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Mattmy//Benchmark//EN\r\n"
        . $events . "END:VCALENDAR\r\n";
    $startedAt = \hrtime(true);
    $calendar = $reader->read($contents);
    $elapsedMilliseconds = (\hrtime(true) - $startedAt) / 1_000_000;

    \printf(
        "%d events, %d bytes: %.2f ms, %.2f MiB peak memory\n",
        $calendar->events()->count(),
        \strlen($contents),
        $elapsedMilliseconds,
        \memory_get_peak_usage(true) / 1024 / 1024,
    );

    foreach (['single', 'filtered', 'all'] as $query) {
        \memory_reset_peak_usage();
        $before = \memory_get_usage();
        $startedAt = \hrtime(true);
        $result = match ($query) {
            'single' => $calendar->event('benchmark-1@example.test'),
            'filtered' => $calendar->events('benchmark-1@example.test'),
            'all' => $calendar->events(),
        };

        \printf(
            "  %s query: %.2f ms, %.2f MiB additional peak memory\n",
            $query,
            (\hrtime(true) - $startedAt) / 1_000_000,
            (\memory_get_peak_usage() - $before) / 1024 / 1024,
        );
        unset($result);
    }
}

foreach ([50, 100, 200] as $depth) {
    $calendar = $reader->read("BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Mattmy//Benchmark//EN\r\n"
        . \str_repeat("BEGIN:X-NEST\r\nX-SPAN;VALUE=DURATION:PT1H\r\n", $depth)
        . \str_repeat("END:X-NEST\r\n", $depth) . "END:VCALENDAR\r\n");
    \memory_reset_peak_usage();
    $before = \memory_get_usage();
    $startedAt = \hrtime(true);
    $result = $calendar->component('X-NEST');

    \printf(
        "nested query depth %d: %.2f ms, %.2f MiB additional peak memory\n",
        $depth,
        (\hrtime(true) - $startedAt) / 1_000_000,
        (\memory_get_peak_usage() - $before) / 1024 / 1024,
    );
    unset($result);
}
