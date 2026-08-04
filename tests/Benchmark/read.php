<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Mattmy\ICalendar\Reader;
use Mattmy\ICalendar\Support\BoundedInputReader;
use Mattmy\ICalendar\Support\CalendarValidator;
use Mattmy\ICalendar\Support\TimezoneResolver;

require __DIR__ . '/../../vendor/autoload.php';

$config = new Repository([
    'app' => ['timezone' => 'UTC'],
    'icalendar_reader' => [
        'max_bytes' => 10 * 1024 * 1024,
        'floating_timezone' => null,
    ],
]);
$reader = new Reader($config, new BoundedInputReader(), new CalendarValidator(), new TimezoneResolver($config));

foreach ([1, 100, 1000] as $eventCount) {
    $events = '';

    for ($index = 1; $index <= $eventCount; $index++) {
        $events .= "BEGIN:VEVENT\r\nUID:benchmark-{$index}@example.test\r\n"
            . "DTSTAMP:20260803T000000Z\r\nDTSTART:20260803T010000Z\r\n"
            . "DURATION:PT30M\r\nSUMMARY:Benchmark {$index}\r\nEND:VEVENT\r\n";
    }

    $contents = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Mattmy//Benchmark//EN\r\n"
        . $events . "END:VCALENDAR\r\n";
    $startedAt = hrtime(true);
    $calendar = $reader->read($contents);
    $elapsedMilliseconds = (hrtime(true) - $startedAt) / 1_000_000;

    printf(
        "%d events, %d bytes: %.2f ms, %.2f MiB peak memory\n",
        $calendar->events()->count(),
        strlen($contents),
        $elapsedMilliseconds,
        memory_get_peak_usage(true) / 1024 / 1024,
    );
}
