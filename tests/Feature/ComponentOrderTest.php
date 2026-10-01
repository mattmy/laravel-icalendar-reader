<?php

declare(strict_types=1);

use Mattmy\ICalendar\Facades\ICalendar;

it('preserves interleaved component names and empty extension children in source order', function () {
    $calendar = ICalendar::read(<<<'ICS'
BEGIN:VCALENDAR
VERSION:2.0
PRODID:-//Example//Component Order//EN
BEGIN:X-FIRST
END:X-FIRST
BEGIN:X-SECOND
BEGIN:X-A
END:X-A
BEGIN:X-B
X-VALUE:middle
END:X-B
BEGIN:X-A
X-VALUE:last
END:X-A
END:X-SECOND
BEGIN:X-FIRST
X-VALUE:last root child
END:X-FIRST
END:VCALENDAR
ICS);

    expect($calendar->components()->pluck('name')->all())->toBe(['X-FIRST', 'X-SECOND', 'X-FIRST'])
        ->and($calendar->component('X-SECOND')?->components()->pluck('name')->all())->toBe(['X-A', 'X-B', 'X-A'])
        ->and(\array_column($calendar->toComponentArray()['components'], 'name'))->toBe(['X-FIRST', 'X-SECOND', 'X-FIRST']);
});

it('keeps structural-looking folded property text out of component ordering', function () {
    $calendar = ICalendar::read("BEGIN:VCALENDAR\nVERSION:2.0\nPRODID:-//Example//Component Order//EN\n"
        . "X-NOTE:folded\n BEGIN:X-NOT-A-COMPONENT\nBEGIN:X-REAL\nEND:X-REAL\nEND:VCALENDAR\n");

    expect($calendar->components()->pluck('name')->all())->toBe(['X-REAL'])
        ->and($calendar->property('X-NOTE')?->value)->toBe('foldedBEGIN:X-NOT-A-COMPONENT');
});
