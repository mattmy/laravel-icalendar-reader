<?php

declare(strict_types=1);

use Mattmy\ICalendar\Component;
use Mattmy\ICalendar\Facades\ICalendar;
use Mattmy\ICalendar\Property;
use Mattmy\ICalendar\Support\ParserValue;

it('preserves scalar parser values in property and component exports', function (string $type, string $raw, bool|int|float $expected) {
    $calendar = ICalendar::read("BEGIN:VCALENDAR\nVERSION:2.0\nPRODID:-//Example//Scalar Values//EN\n"
        . "X-SCALAR;VALUE={$type}:{$raw}\nBEGIN:VEVENT\nUID:scalar\nDTSTAMP:20260804T000000Z\n"
        . "DTSTART:20260804T010000Z\nX-SCALAR;VALUE={$type}:{$raw}\nEND:VEVENT\nEND:VCALENDAR\n");

    foreach ([$calendar, $calendar->events()->sole(), $calendar->components('VEVENT')->sole()] as $snapshot) {
        expect($snapshot->property('X-SCALAR')?->value)->toBe($expected)
            ->and($snapshot->property('X-SCALAR')?->values)->toBe([$expected]);
    }

    $tree = $calendar->toComponentArray();
    foreach ([$tree, $tree['components'][0]] as $component) {
        expect(collect(normalizedComponentProperties($component))->firstWhere('name', 'X-SCALAR')['value'] ?? null)->toBe($expected);
    }
})->with([
    'true' => ['BOOLEAN', 'TRUE', true],
    'false' => ['BOOLEAN', 'FALSE', false],
    'case-insensitive true' => ['BOOLEAN', 'true', true],
    'float precision' => ['FLOAT', '0.1234567890123456', 0.1234567890123456],
    'integer' => ['INTEGER', '2147483647', 2147483647],
]);

it('narrows parser text without coercing structured values', function () {
    expect(ParserValue::text(null))->toBe('');
    expect(ParserValue::text(12))->toBe('12');
    expect(ParserValue::text('raw'))->toBe('raw');
    expect(fn () => ParserValue::text([]))->toThrow(LogicException::class);
    expect(fn () => ParserValue::text(new stdClass()))->toThrow(LogicException::class);
});

it('preserves interleaved direct property order in typed generic and serialized views', function () {
    $calendar = ICalendar::read(<<<'ICS'
BEGIN:VCALENDAR
VERSION:2.0
PRODID:-//Example//Interleaved Properties//EN
X-A:first
X-B:middle
X-A:last
BEGIN:VEVENT
UID:ordered@example.test
DTSTAMP:20260804T000000Z
DTSTART:20260804T010000Z
X-A:first
X-B:middle
X-A:last
END:VEVENT
END:VCALENDAR
ICS);

    foreach ([$calendar, $calendar->events()->sole(), $calendar->components('VEVENT')->sole()] as $snapshot) {
        $properties = $snapshot->properties()->filter(static fn (Property $property): bool => \str_starts_with($property->name, 'X-'));

        expect($properties->pluck('name')->values()->all())->toBe(['X-A', 'X-B', 'X-A'])
            ->and($properties->pluck('value')->values()->all())->toBe(['first', 'middle', 'last']);
    }

    $tree = $calendar->toComponentArray();

    foreach ([$tree, $tree['components'][0]] as $component) {
        $properties = collect(normalizedComponentProperties($component))->filter(static fn (array $property): bool => \str_starts_with($property['name'], 'X-'));

        expect($properties->pluck('name')->values()->all())->toBe(['X-A', 'X-B', 'X-A'])
            ->and($properties->pluck('value')->values()->all())->toBe(['first', 'middle', 'last']);
    }
});

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

it('applies identical direct property query behavior to every property-bearing snapshot', function () {
    $calendar = ICalendar::read(<<<'ICS'
BEGIN:VCALENDAR
VERSION:2.0
PRODID:-//Example//Shared Property Queries//EN
X-REPEATED:one
X-REPEATED:two
BEGIN:VEVENT
UID:event@example.test
DTSTAMP:20260804T000000Z
DTSTART:20260804T010000Z
X-REPEATED:one
X-REPEATED:two
END:VEVENT
BEGIN:VTODO
UID:todo@example.test
DTSTAMP:20260804T000000Z
DTSTART:20260804T010000Z
X-REPEATED:one
X-REPEATED:two
END:VTODO
END:VCALENDAR
ICS);

    $snapshots = [
        $calendar,
        $calendar->events()->sole(),
        $calendar->todos()->sole(),
        $calendar->components('VTODO')->sole(),
    ];

    foreach ($snapshots as $snapshot) {
        expect($snapshot->hasProperty())->toBeTrue()
            ->and($snapshot->hasProperty(' x-repeated '))->toBeTrue()
            ->and($snapshot->properties('X-REPEATED')->pluck('value')->all())->toBe(['one', 'two'])
            ->and($snapshot->property('x-repeated')?->value)->toBe('one')
            ->and($snapshot->properties('missing'))->toBeEmpty()
            ->and($snapshot->hasProperty('missing'))->toBeFalse()
            ->and($snapshot->property('missing'))->toBeNull()
            ->and(fn () => $snapshot->properties("\t"))->toThrow(InvalidArgumentException::class);

        $properties = $snapshot->properties('X-REPEATED');
        $properties->pop();

        expect($snapshot->properties('X-REPEATED'))->toHaveCount(2);
    }
});

it('supports presence queries without recursing into child components', function () {
    $calendar = ICalendar::read(calendarFixture('freebusy'));

    expect($calendar->hasProperty())->toBeTrue()
        ->and($calendar->hasProperty('METHOD'))->toBeTrue()
        ->and($calendar->hasProperty('FREEBUSY'))->toBeFalse()
        ->and($calendar->hasComponent())->toBeTrue()
        ->and($calendar->hasComponent('vfreebusy'))->toBeTrue()
        ->and($calendar->hasComponent('VALARM'))->toBeFalse()
        ->and($calendar->component('vfreebusy'))->toBeInstanceOf(Component::class)
        ->and($calendar->component('vfreebusy')?->rawComponent()->serialize())->toBe($calendar->components()->first()?->rawComponent()->serialize())
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
        ->and($freeBusy['name'])->toBe('VFREEBUSY');
    expect(collect(normalizedComponentProperties($freeBusy))->where('name', 'FREEBUSY'))->toHaveCount(3);
});

it('exports a property using the normalized shape shared by component trees', function () {
    $empty = new Property('X-EMPTY', 'unknown', null, [], [], '');
    $single = new Property('X-SINGLE', 'text', 'one', ['one'], ['LANGUAGE' => 'en'], 'one');
    $multiple = new Property('X-MULTIPLE', 'text', ['one', 'two'], ['one', 'two'], [], 'one,two');

    expect($empty->toArray())->toBe([
        'name' => 'X-EMPTY',
        'type' => 'unknown',
        'value' => null,
        'values' => [],
        'parameters' => [],
        'raw_value' => '',
    ])
        ->and($single->toArray())->toMatchArray([
            'value' => 'one',
            'values' => ['one'],
            'parameters' => ['LANGUAGE' => 'en'],
        ])
        ->and($multiple->toArray())->toMatchArray([
            'value' => ['one', 'two'],
            'values' => ['one', 'two'],
        ]);

    $calendar = ICalendar::read(calendarFixture('freebusy'));
    $property = $calendar->components('VFREEBUSY')->sole()->property('FREEBUSY');
    $treeProperty = collect(normalizedComponentProperties($calendar->toComponentArray()['components'][0]))
        ->firstWhere('name', 'FREEBUSY');

    expect($property)->not->toBeNull()
        ->and($property?->toArray())->toBe($treeProperty);
});

/**
 * Narrow the normalized subtree used by the public export assertions.
 *
 * @param  array<string, mixed>  $component
 * @return list<array{name: string, value: mixed}>
 */
function normalizedComponentProperties(array $component): array
{
    $properties = $component['properties'] ?? null;
    if (! \is_array($properties) || ! \array_is_list($properties)) {
        throw new RuntimeException('Expected ordered component properties.');
    }
    $result = [];
    foreach ($properties as $property) {
        if (! \is_array($property) || ! \is_string($property['name'] ?? null)
            || ! \array_key_exists('value', $property)) {
            throw new RuntimeException('Expected a normalized property.');
        }
        $result[] = $property;
    }

    return $result;
}
