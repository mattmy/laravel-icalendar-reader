<?php

declare(strict_types=1);

use Mattmy\ICalendar\Facades\ICalendar;

it('preserves and exposes every property from an untyped VFREEBUSY component', function () {
    $calendar = ICalendar::read(calendarFixture('freebusy'));
    $freeBusy = $calendar->components('vfreebusy')->sole();

    expect($calendar->events())->toBeEmpty()
        ->and($calendar->hasProperty('method'))->toBeTrue()
        ->and($calendar->property('METHOD')?->value)->toBe('REPLY')
        ->and($freeBusy->name)->toBe('VFREEBUSY')
        ->and($freeBusy->properties())->toHaveCount(11)
        ->and($freeBusy->property('UID')?->value)
        ->toBe('fc6516e7-913a-45b9-b190-35006900674e@example.test')
        ->and($freeBusy->property('ORGANIZER')?->value)
        ->toBe('mailto:scheduler@example.test')
        ->and($freeBusy->property('ATTENDEE')?->value)
        ->toBe('mailto:matt@example.test')
        ->and($freeBusy->property('COMMENT')?->value)
        ->toBe('自動產生的七日忙碌時間摘要')
        ->and($freeBusy->property('URL')?->value)
        ->toBe('https://example.test/freebusy/matt');

    $periods = $freeBusy->properties('freebusy');

    expect($periods)->toHaveCount(3)
        ->and($periods->pluck('name')->all())->toBe(['FREEBUSY', 'FREEBUSY', 'FREEBUSY'])
        ->and($periods->pluck('type')->all())->toBe(['period', 'period', 'period'])
        ->and($periods->map->rawValue()->all())->toBe([
            '20260803T010000Z/20260803T023000Z,20260804T060000Z/PT2H',
            '20260805T030000Z/20260805T040000Z',
            '20260807T000000Z/20260807T090000Z',
        ])
        ->and($periods->map->parameter('FBTYPE')->all())->toBe([
            'BUSY',
            'BUSY-TENTATIVE',
            'BUSY-UNAVAILABLE',
        ])
        ->and($periods->first()?->values)->toHaveCount(2);
});

it('validates property and component query names', function () {
    $calendar = ICalendar::read(calendarFixture('freebusy'));
    $component = $calendar->components()->sole();

    expect(fn () => $calendar->property('   '))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $calendar->components(''))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $calendar->hasComponent("\t"))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $calendar->component('   '))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $component->properties("\t"))->toThrow(InvalidArgumentException::class);
});

it('supports presence queries without recursing into child components', function () {
    $calendar = ICalendar::read(calendarFixture('freebusy'));

    expect($calendar->hasProperty())->toBeTrue()
        ->and($calendar->hasProperty('METHOD'))->toBeTrue()
        ->and($calendar->hasProperty('FREEBUSY'))->toBeFalse()
        ->and($calendar->hasComponent())->toBeTrue()
        ->and($calendar->hasComponent('vfreebusy'))->toBeTrue()
        ->and($calendar->hasComponent('VALARM'))->toBeFalse()
        ->and($calendar->component('vfreebusy'))->toBe($calendar->components()->first())
        ->and($calendar->component('VTODO'))->toBeNull()
        ->and($calendar->components('VFREEBUSY')->sole()->hasProperty())->toBeTrue();
});

it('returns the first matching direct component in document order', function () {
    $calendar = ICalendar::read(<<<'ICS'
BEGIN:VCALENDAR
VERSION:2.0
PRODID:-//Example//Component Queries//EN
BEGIN:VTODO
UID:first@example.test
DTSTAMP:20260804T000000Z
END:VTODO
BEGIN:VTODO
UID:second@example.test
DTSTAMP:20260804T000000Z
END:VTODO
END:VCALENDAR
ICS);

    expect($calendar->components('VTODO'))->toHaveCount(2)
        ->and($calendar->component('vtodo')?->property('UID')?->value)
        ->toBe('first@example.test');
});

it('exports a normalized component tree without collapsing repeated properties', function () {
    $tree = ICalendar::read(calendarFixture('freebusy'))->toComponentArray();
    $freeBusy = $tree['components'][0];

    expect($tree['name'])->toBe('VCALENDAR')
        ->and($freeBusy['name'])->toBe('VFREEBUSY')
        ->and(collect($freeBusy['properties'])->where('name', 'FREEBUSY'))->toHaveCount(3);
});
