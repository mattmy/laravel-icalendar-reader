<?php

declare(strict_types=1);

use Mattmy\ICalendar\Exceptions\InvalidCalendar;
use Mattmy\ICalendar\Facades\ICalendar;

it('rejects invalid original scalar values before parser coercion', function (string $type, string $raw, string $location) {
    $contents = scalarValidationCalendar("X-SCALAR;VALUE={$type}:{$raw}", $location);
    expect(fn () => ICalendar::read($contents))->toThrow(InvalidCalendar::class)
        ->and(ICalendar::tryRead($contents))->toBeNull();

    try {
        ICalendar::read($contents);
    } catch (InvalidCalendar $exception) {
        expect($exception->issues()->where('level', 3))->not->toBeEmpty();
    }
})->with([
    ['BOOLEAN', 'maybe'], ['BOOLEAN', '1'], ['BOOLEAN', 'yes'], ['BOOLEAN', ''], ['BOOLEAN', ' TRUE'],
    ['FLOAT', 'abc'], ['FLOAT', '1e2'], ['FLOAT', '.5'], ['FLOAT', '1.'], ['FLOAT', ''],
    ['FLOAT', ' 1'], ['FLOAT', '1 '], ['FLOAT', 'NaN'], ['FLOAT', 'INF'], ['FLOAT', '1;abc'],
    ['FLOAT', \str_repeat('9', 400)],
])->with(['root', 'VEVENT', 'VTODO', 'VJOURNAL', 'nested']);

it('preserves legal scalar values and text without guessing types', function (string $type, string $raw, bool|float|string $expected) {
    $contents = scalarValidationCalendar("X-SCALAR;VALUE={$type}:{$raw}", 'root');
    $calendar = ICalendar::read($contents);
    expect($calendar->property('X-SCALAR')?->value)->toBe($expected)
        ->and(ICalendar::tryRead($contents))->not->toBeNull();
})->with([
    ['BOOLEAN', 'TRUE', true], ['BOOLEAN', 'fAlSe', false], ['FLOAT', '12', 12.0],
    ['FLOAT', '-3.14', -3.14], ['FLOAT', '+0.5', 0.5], ['FLOAT', '0.1234567890123456', 0.1234567890123456],
    ['FLOAT', '0.' . \str_repeat('0', 400) . '1', 0.0],
    ['TEXT', 'maybe', 'maybe'], ['TEXT', '1e2', '1e2'], ['TEXT', 'abc', 'abc'],
]);

it('validates original scalars after quoted parameter colons', function (string $raw, bool $valid) {
    $contents = scalarValidationCalendar('X-SCALAR;X-LABEL="urn:example:scalar";VALUE=FLOAT:' . $raw, 'root');

    if ($valid) {
        expect(ICalendar::read($contents)->property('X-SCALAR')?->value)->toBe(1.5);
    } else {
        expect(fn () => ICalendar::read($contents))->toThrow(InvalidCalendar::class)
            ->and(ICalendar::tryRead($contents))->toBeNull();
    }
})->with([['1.5', true], ['1e2', false]]);

it('validates original GEO and multiple float parts without changing their shape', function (string $property, bool $valid) {
    $contents = scalarValidationCalendar($property, 'VEVENT');
    if ($valid) {
        $event = ICalendar::read($contents)->events()->sole();
        expect($event->properties()->last()?->values)->toBe([37.386013, -122.082932]);
    } else {
        expect(fn () => ICalendar::read($contents))->toThrow(InvalidCalendar::class)
            ->and(ICalendar::tryRead($contents))->toBeNull();
    }
})->with([
    ['GEO:37.386013;-122.082932', true], ['X-COORD;VALUE=FLOAT:37.386013;-122.082932', true],
    ['GEO:abc;-122.082932', false], ['GEO:37.386013;1e2', false], ['GEO:37.386013;', false],
]);

/** Place a property in a complete calendar without changing its original text. */
function scalarValidationCalendar(string $property, string $location): string
{
    $root = $location === 'root' ? "{$property}\n" : '';
    $kind = \in_array($location, ['VTODO', 'VJOURNAL'], true) ? $location : 'VEVENT';
    $nested = match ($location) {
        'root' => '',
        'nested' => "BEGIN:X-CHILD\n{$property}\nEND:X-CHILD\n",
        default => "{$property}\n",
    };

    return "BEGIN:VCALENDAR\nVERSION:2.0\nPRODID:-//Example//Original Values//EN\n{$root}"
        . "BEGIN:{$kind}\nUID:original\nDTSTAMP:20260101T000000Z\nDTSTART:20260101T090000Z\n"
        . "{$nested}END:{$kind}\nEND:VCALENDAR\n";
}

it('rejects illegal original recurrence structures values and combinations', function (string $rule) {
    $contents = scalarValidationCalendar("RRULE:{$rule}", 'VEVENT');
    expect(fn () => ICalendar::read($contents))->toThrow(InvalidCalendar::class)
        ->and(ICalendar::tryRead($contents))->toBeNull();

    try {
        ICalendar::read($contents);
    } catch (InvalidCalendar $exception) {
        expect($exception->issues()->where('level', 3))->not->toBeEmpty();
    }
})->with([
    'duplicate count' => 'FREQ=DAILY;COUNT=1;COUNT=2',
    'same duplicate' => 'FREQ=DAILY;COUNT=1;count=1',
    'duplicate frequency' => 'FREQ=DAILY;FREQ=DAILY',
    'missing frequency' => 'COUNT=1', 'bad frequency' => 'FREQ=OTHER',
    'bad week start' => 'FREQ=DAILY;WKST=1MO',
    'empty part' => 'FREQ=DAILY;;COUNT=1', 'empty value' => 'FREQ=DAILY;BYDAY=',
    'empty list item' => 'FREQ=DAILY;BYDAY=MO,,TU', 'trailing item' => 'FREQ=DAILY;BYMONTH=1,',
    'extra equals' => 'FREQ=DAILY;COUNT=1=2', 'whitespace' => 'FREQ=DAILY; BYDAY=MO',
    'count sign' => 'FREQ=DAILY;COUNT=+1', 'count exponent' => 'FREQ=DAILY;COUNT=1e2',
    'seconds sign' => 'FREQ=DAILY;BYSECOND=+1', 'seconds too wide' => 'FREQ=DAILY;BYSECOND=001',
    'seconds high' => 'FREQ=DAILY;BYSECOND=61', 'minutes high' => 'FREQ=DAILY;BYMINUTE=60',
    'hours high' => 'FREQ=DAILY;BYHOUR=24', 'hours negative' => 'FREQ=DAILY;BYHOUR=-1',
    'weekday token' => 'FREQ=DAILY;BYDAY=XX', 'weekday zero' => 'FREQ=MONTHLY;BYDAY=0MO',
    'weekday high' => 'FREQ=YEARLY;BYDAY=54MO', 'weekday missing' => 'FREQ=MONTHLY;BYDAY=1',
    'monthday high' => 'FREQ=MONTHLY;BYMONTHDAY=99', 'monthday zero' => 'FREQ=MONTHLY;BYMONTHDAY=0',
    'monthday low' => 'FREQ=MONTHLY;BYMONTHDAY=-32', 'middle item' => 'FREQ=MONTHLY;BYMONTHDAY=1,abc,2',
    'yearday high' => 'FREQ=YEARLY;BYYEARDAY=367', 'yearday zero' => 'FREQ=YEARLY;BYYEARDAY=0',
    'week high' => 'FREQ=YEARLY;BYWEEKNO=54', 'week zero' => 'FREQ=YEARLY;BYWEEKNO=0',
    'month high' => 'FREQ=YEARLY;BYMONTH=13', 'month zero' => 'FREQ=YEARLY;BYMONTH=0',
    'month sign' => 'FREQ=YEARLY;BYMONTH=+1', 'setpos high' => 'FREQ=MONTHLY;BYDAY=MO;BYSETPOS=367',
    'setpos zero' => 'FREQ=MONTHLY;BYDAY=MO;BYSETPOS=0',
    'weekly monthday' => 'FREQ=WEEKLY;BYMONTHDAY=1',
    'daily yearday' => 'FREQ=DAILY;BYYEARDAY=1', 'weekly yearday' => 'FREQ=WEEKLY;BYYEARDAY=1',
    'monthly yearday' => 'FREQ=MONTHLY;BYYEARDAY=1', 'monthly week' => 'FREQ=MONTHLY;BYWEEKNO=1',
    'daily numbered day' => 'FREQ=DAILY;BYDAY=1MO',
    'yearly numbered week day' => 'FREQ=YEARLY;BYWEEKNO=1;BYDAY=1MO',
    'setpos alone' => 'FREQ=MONTHLY;BYSETPOS=1',
    'bad until' => 'FREQ=DAILY;UNTIL=20260230T000000Z',
]);

it('validates recurrence originals across every recurring component', function (string $kind) {
    $rule = 'FREQ=YEARLY;COUNT=1;COUNT=2';
    $contents = \in_array($kind, ['STANDARD', 'DAYLIGHT'], true)
        ? observanceValidationCalendar($kind, $rule)
        : scalarValidationCalendar("RRULE:{$rule}", $kind);
    expect(fn () => ICalendar::read($contents))->toThrow(InvalidCalendar::class)
        ->and(ICalendar::tryRead($contents))->toBeNull();
})->with(['VEVENT', 'VTODO', 'VJOURNAL', 'STANDARD', 'DAYLIGHT']);

it('accepts legal original recurrence boundaries order case and extension parts', function (string $rule) {
    expect(ICalendar::tryRead(scalarValidationCalendar("RRULE:{$rule}", 'VEVENT')))->not->toBeNull();
})->with([
    'reordered' => 'COUNT=01;freq=daily;INTERVAL=01',
    'seconds' => 'FREQ=DAILY;BYSECOND=0,60', 'minutes' => 'FREQ=DAILY;BYMINUTE=0,59',
    'hours' => 'FREQ=DAILY;BYHOUR=0,23', 'weekday' => 'FREQ=YEARLY;BYDAY=-53MO,+53TU,MO',
    'monthday' => 'FREQ=MONTHLY;BYMONTHDAY=-31,31', 'yearday' => 'FREQ=YEARLY;BYYEARDAY=-366,366',
    'week' => 'FREQ=YEARLY;BYWEEKNO=-53,53;BYDAY=MO', 'month' => 'FREQ=YEARLY;BYMONTH=1,12',
    'setpos' => 'FREQ=YEARLY;BYDAY=MO;BYSETPOS=-366,366', 'duplicates in list' => 'FREQ=DAILY;BYDAY=MO,MO',
    'week start' => 'FREQ=WEEKLY;WKST=su;BYDAY=mo', 'scale' => 'RSCALE=GREGORIAN;FREQ=DAILY;COUNT=3',
    'unknown extension' => 'FREQ=DAILY;X-CUSTOM=VALUE',
    'unknown name grammar' => 'FREQ=DAILY;1EXT=VALUE',
]);

it('retains DATE receiving exceptions and observance UTC UNTIL validation', function () {
    $date = \str_replace(
        'DTSTART:20260101T090000Z',
        'DTSTART;VALUE=DATE:20260101',
        scalarValidationCalendar('RRULE:FREQ=DAILY;COUNT=2;BYHOUR=12;BYMINUTE=0;BYSECOND=60', 'VEVENT'),
    );
    expect(ICalendar::tryRead($date))->not->toBeNull();
    foreach (['STANDARD', 'DAYLIGHT'] as $kind) {
        expect(ICalendar::tryRead(observanceValidationCalendar($kind, 'FREQ=YEARLY;UNTIL=20260101T000000Z')))->not->toBeNull()
            ->and(ICalendar::tryRead(observanceValidationCalendar($kind, 'FREQ=YEARLY;UNTIL=20260101T000000')))->toBeNull();
    }
});

/** Supply a valid local observance so failures identify its original RRULE. */
function observanceValidationCalendar(string $kind, string $rule): string
{
    return "BEGIN:VCALENDAR\nVERSION:2.0\nPRODID:-//Example//Rule Validation//EN\nBEGIN:VTIMEZONE\nTZID:Rule\n"
        . "BEGIN:{$kind}\nDTSTART:20000101T000000\nTZOFFSETFROM:+0800\nTZOFFSETTO:+0800\n"
        . "RRULE:{$rule}\nEND:{$kind}\nEND:VTIMEZONE\nEND:VCALENDAR\n";
}

it('normalizes malformed recurrence parser failures through the invalid calendar boundary', function (string $rule) {
    $contents = "BEGIN:VCALENDAR\nVERSION:2.0\nPRODID:-//Example//Parser Failure//EN\n"
        . "BEGIN:VEVENT\nUID:parser\nDTSTAMP:20260101T000000Z\nDTSTART:20260101T090000Z\n"
        . "RRULE:{$rule}\nEND:VEVENT\nEND:VCALENDAR\n";

    expect(fn () => ICalendar::read($contents))->toThrow(InvalidCalendar::class);
    expect(ICalendar::tryRead($contents))->toBeNull();
})->with(['FREQ=DAILY;BAD', 'FREQ=DAILY;COUNT=2=3']);

it('rejects equal or reversed Todo endpoints even when host gap normalization suggests otherwise', function (string $start) {
    $contents = "BEGIN:VCALENDAR\nVERSION:2.0\nPRODID:-//Example//Todo Gap//EN\n"
        . "BEGIN:VTIMEZONE\nTZID:America/New_York\nBEGIN:STANDARD\nDTSTART:20000101T000000\n"
        . "TZOFFSETFROM:-0400\nTZOFFSETTO:-0400\nEND:STANDARD\nEND:VTIMEZONE\n"
        . "BEGIN:VTODO\nUID:todo\nDTSTAMP:20260101T000000Z\nDTSTART;TZID=America/New_York:{$start}\n"
        . "DUE;TZID=America/New_York:20260308T023000\nEND:VTODO\nEND:VCALENDAR\n";

    expect(fn () => ICalendar::read($contents))->toThrow(InvalidCalendar::class);
    expect(ICalendar::tryRead($contents))->toBeNull();
})->with(['20260308T023000', '20260308T031500']);

it('requires UTC UNTIL for timezone observances with local DTSTART', function (string $kind, string $until, bool $valid) {
    $contents = "BEGIN:VCALENDAR\nVERSION:2.0\nPRODID:-//Example//Until//EN\n"
        . "BEGIN:VTIMEZONE\nTZID:Custom\nBEGIN:{$kind}\nDTSTART:20000101T000000\n"
        . "RRULE:FREQ=YEARLY;UNTIL={$until}\nTZOFFSETFROM:+0800\nTZOFFSETTO:+0900\nEND:{$kind}\nEND:VTIMEZONE\nEND:VCALENDAR\n";

    if ($valid) {
        expect(ICalendar::read($contents)->component('VTIMEZONE'))->not->toBeNull()
            ->and(ICalendar::tryRead($contents))->not->toBeNull();
    } else {
        expect(fn () => ICalendar::read($contents))->toThrow(InvalidCalendar::class)
            ->and(ICalendar::tryRead($contents))->toBeNull();
    }
})->with(['STANDARD', 'DAYLIGHT'])->with([
    ['20260101T000000Z', true], ['20260101T000000', false], ['20260101', false], ['20260230T000000Z', false],
]);

it('cannot bypass the UTC offset value type with another otherwise valid parser value', function (string $type, string $value) {
    $contents = "BEGIN:VCALENDAR\nVERSION:2.0\nPRODID:-//Example//Offset Type//EN\n"
        . "BEGIN:VTIMEZONE\nTZID:Unused\nBEGIN:STANDARD\nDTSTART:20000101T000000\n"
        . "TZOFFSETFROM:+0800\nTZOFFSETTO;VALUE={$type}:{$value}\nEND:STANDARD\nEND:VTIMEZONE\nEND:VCALENDAR\n";
    expect(fn () => ICalendar::read($contents))->toThrow(InvalidCalendar::class)
        ->and(ICalendar::tryRead($contents))->toBeNull();
})->with([['TEXT', '+0800'], ['INTEGER', '800'], ['DURATION', 'PT8H'], ['DATE-TIME', '20260101T000000Z']]);

it('compares explicit PERIOD endpoints with the same calendar gap semantics as hydration', function () {
    $contents = "BEGIN:VCALENDAR\nVERSION:2.0\nPRODID:-//Example//Period Gap//EN\n"
        . "BEGIN:VTIMEZONE\nTZID:Gap\nBEGIN:STANDARD\nDTSTART:20000101T000000\nTZOFFSETFROM:-0500\nTZOFFSETTO:-0500\nEND:STANDARD\n"
        . "BEGIN:DAYLIGHT\nDTSTART:20260308T020000\nTZOFFSETFROM:-0500\nTZOFFSETTO:-0400\nEND:DAYLIGHT\nEND:VTIMEZONE\n"
        . "BEGIN:VEVENT\nUID:period\nDTSTAMP:20260101T000000Z\nDTSTART:20260307T090000Z\n"
        . "RDATE;VALUE=PERIOD;TZID=Gap:20260308T024500/20260308T031500\nEND:VEVENT\nEND:VCALENDAR\n";
    expect(fn () => ICalendar::read($contents))->toThrow(InvalidCalendar::class)
        ->and(ICalendar::tryRead($contents))->toBeNull();
});

it('rejects illegal UTC offsets before mapping including unused and extension properties', function (string $offset, string $location) {
    $property = "X-OFFSET;VALUE=UTC-OFFSET:{$offset}\n";
    $root = $location === 'root' ? $property : '';
    $nested = $location === 'nested' ? "BEGIN:X-CHILD\n{$property}END:X-CHILD\n" : '';
    $from = $location === 'from' ? $offset : '+0800';
    $to = $location === 'to' ? $offset : '+0800';
    $override = $location === 'override' ? ';VALUE=TEXT' : '';
    if ($location === 'override') {
        $to = $offset;
    }
    $contents = "BEGIN:VCALENDAR\nVERSION:2.0\nPRODID:-//Example//Offsets//EN\n{$root}"
        . "BEGIN:VTIMEZONE\nTZID:Unused\nBEGIN:STANDARD\nDTSTART:20300101T000000\n"
        . "TZOFFSETFROM:{$from}\nTZOFFSETTO{$override}:{$to}\nEND:STANDARD\nEND:VTIMEZONE\n"
        . "BEGIN:VEVENT\nUID:x\nDTSTAMP:20260101T000000Z\nDTSTART:20260101T120000Z\n{$nested}END:VEVENT\nEND:VCALENDAR\n";

    expect(fn () => ICalendar::read($contents))->toThrow(InvalidCalendar::class)
        ->and(ICalendar::tryRead($contents))->toBeNull();
})->with([
    '+0861', '+2400', '-240000', '+0060', '+000060', '+000061', '0800', '+800', '+08000',
    '+0800000', '+08AA', '+08:00', ' +0800', '+0800 ', '+0800Z', '+0800,+0900', '-0000', '-000000',
])->with(['from', 'to', 'override', 'root', 'nested']);

it('preserves legal UTC offset boundaries without restricting real world timezone ranges', function (string $offset) {
    $contents = "BEGIN:VCALENDAR\nVERSION:2.0\nPRODID:-//Example//Offsets//EN\nX-OFFSET;VALUE=UTC-OFFSET:{$offset}\n"
        . "BEGIN:VTIMEZONE\nTZID:Boundary\nBEGIN:STANDARD\nDTSTART:20000101T000000\n"
        . "TZOFFSETFROM:{$offset}\nTZOFFSETTO:{$offset}\nEND:STANDARD\nEND:VTIMEZONE\n"
        . "BEGIN:VEVENT\nUID:x\nDTSTAMP:20260101T000000Z\nDTSTART;TZID=Boundary:20260101T120000\nEND:VEVENT\nEND:VCALENDAR\n";
    $calendar = ICalendar::read($contents);

    expect(ICalendar::tryRead($contents))->not->toBeNull()
        ->and($calendar->property('X-OFFSET')?->rawValue())->toBe($offset);
    $unsupported = \strlen($offset) === 7 && \substr($offset, -2) !== '00';
    expect($calendar->events()->sole()->startsAt === null)->toBe($unsupported)
        ->and($calendar->warnings()->where('code', 'mapping_warning')->isNotEmpty())->toBe($unsupported);
})->with(['+0000', '+000000', '+2359', '-2359', '+235900', '-235900', '+000059', '-000030']);

it('rejects TZID on any UTC value in a mixed date-time list', function (string $name, string $values) {
    $contents = "BEGIN:VCALENDAR\nVERSION:2.0\nPRODID:-//Example//Mixed Date Forms//EN\n"
        . "BEGIN:VEVENT\nUID:mixed\nDTSTAMP:20260801T000000Z\nDTSTART:20260801T090000Z\n"
        . "{$name};TZID=Custom:{$values}\nEND:VEVENT\nEND:VCALENDAR\n";

    expect(fn () => ICalendar::read($contents))->toThrow(InvalidCalendar::class)
        ->and(ICalendar::tryRead($contents))->toBeNull();
})->with(['RDATE', 'EXDATE'])->with([
    'UTC first' => '20260802T090000Z,20260803T090000',
    'UTC last' => '20260802T090000,20260803T090000Z',
]);

it('rejects TZID on UTC endpoints in PERIOD values', function (string $period) {
    $contents = "BEGIN:VCALENDAR\nVERSION:2.0\nPRODID:-//Example//Period Date Forms//EN\n"
        . "BEGIN:VEVENT\nUID:period\nDTSTAMP:20260801T000000Z\nDTSTART:20260801T090000Z\n"
        . "RDATE;VALUE=PERIOD;TZID=Custom:{$period}\nEND:VEVENT\nEND:VCALENDAR\n";

    expect(fn () => ICalendar::read($contents))->toThrow(InvalidCalendar::class)
        ->and(ICalendar::tryRead($contents))->toBeNull();
})->with([
    'UTC endpoints' => '20260802T090000Z/20260802T100000Z',
    'UTC start with duration' => '20260802T090000Z/PT1H',
    'UTC start only' => '20260802T090000Z/20260802T100000',
    'UTC end only' => '20260802T090000/20260802T100000Z',
]);

it('accepts legal second-bearing UTC offsets while preserving unresolved mapped values', function (string $offset) {
    $contents = "BEGIN:VCALENDAR\nVERSION:2.0\nPRODID:-//Example//Offset Validation//EN\n"
        . "BEGIN:VTIMEZONE\nTZID:Custom/Seconds\nBEGIN:STANDARD\nDTSTART:20000101T000000\n"
        . "TZOFFSETFROM:{$offset}\nTZOFFSETTO:{$offset}\nEND:STANDARD\nEND:VTIMEZONE\n"
        . "BEGIN:VEVENT\nUID:seconds\nDTSTAMP:20260801T000000Z\nDTSTART;TZID=Custom/Seconds:20260803T120000\nEND:VEVENT\nEND:VCALENDAR\n";
    $calendar = ICalendar::read($contents);

    expect(ICalendar::tryRead($contents))->not->toBeNull();
    expect($calendar->events()->sole()->startsAt)->toBeNull();
    expect($calendar->warnings()->where('code', 'mapping_warning'))->toHaveCount(1);
    expect($calendar->component('VTIMEZONE')?->components('STANDARD')->sole()->property('TZOFFSETTO')?->rawValue())->toBe($offset);
})->with(['+090030', '-090030']);

it('rejects normalized or malformed temporal values before hydration', function (string $property) {
    $contents = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Example//Validation//EN\r\n"
        . "BEGIN:VEVENT\r\nUID:x\r\nDTSTAMP:20261001T000000Z\r\n"
        . $property . "\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";

    expect(fn () => ICalendar::read($contents))->toThrow(InvalidCalendar::class)
        ->and(ICalendar::tryRead($contents))->toBeNull();
})->with([
    'invalid DATE' => 'DTSTART;VALUE=DATE:20260230',
    'invalid month' => 'DTSTART:20260001T120000Z',
    'invalid day' => 'DTSTART:20261000T120000Z',
    'invalid DATE-TIME' => 'DTSTART:20260230T120000Z',
    'invalid hour' => 'DTSTART:20261001T240000Z',
    'invalid minute' => 'DTSTART:20261001T126000Z',
    'invalid UNTIL' => "DTSTART:20261001T120000Z\r\nRRULE:FREQ=DAILY;UNTIL=20260230T120000Z",
    'invalid duration unit' => "DTSTART:20261001T120000Z\r\nDURATION:PT1X",
    'mixed weeks and days' => "DTSTART:20261001T120000Z\r\nDURATION:P1W1D",
    'unfinished day duration' => "DTSTART:20261001T120000Z\r\nDURATION:P1DT",
    'empty trigger duration' => "DTSTART:20261001T120000Z\r\nBEGIN:VALARM\r\nACTION:DISPLAY\r\nTRIGGER:-P\r\nDESCRIPTION:x\r\nEND:VALARM",
    'invalid trigger duration' => "DTSTART:20261001T120000Z\r\nBEGIN:VALARM\r\nACTION:DISPLAY\r\nTRIGGER:-PT1X\r\nDESCRIPTION:x\r\nEND:VALARM",
    'invalid PERIOD date' => "DTSTART:20261001T120000Z\r\nRDATE;VALUE=PERIOD:20260230T120000Z/PT1H",
    'invalid PERIOD end' => "DTSTART:20261001T120000Z\r\nRDATE;VALUE=PERIOD:20261001T120000Z/20261001T240000Z",
    'invalid PERIOD duration' => "DTSTART:20261001T120000Z\r\nRDATE;VALUE=PERIOD:20261001T120000Z/P1W1D",
    'text DTSTART' => 'DTSTART;VALUE=TEXT:20261001T120000Z',
    'integer DTSTART' => 'DTSTART;VALUE=INTEGER:1',
    'text DURATION' => "DTSTART:20261001T120000Z\r\nDURATION;VALUE=TEXT:P1D",
    'multiple durations' => "DTSTART:20261001T120000Z\r\nDURATION:PT1H,PT2H",
    'multiple start values' => 'DTSTART:20261001T120000Z,20261002T120000Z',
    'invalid interval' => "DTSTART:20261001T120000Z\r\nRRULE:FREQ=DAILY;INTERVAL=abc",
    'zero interval' => "DTSTART:20261001T120000Z\r\nRRULE:FREQ=DAILY;INTERVAL=0",
    'fractional count' => "DTSTART:20261001T120000Z\r\nRRULE:FREQ=DAILY;COUNT=1.5",
    'zero count' => "DTSTART:20261001T120000Z\r\nRRULE:FREQ=DAILY;COUNT=0",
    'count with until' => "DTSTART:20261001T120000Z\r\nRRULE:FREQ=DAILY;COUNT=1;UNTIL=20261002T120000Z",
]);

it('requires one complete calendar without ignored trailing data', function (string $suffix, bool $removeEnd) {
    $contents = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Example//Validation//EN\r\n"
        . "BEGIN:VEVENT\r\nUID:x\r\nDTSTAMP:20261001T000000Z\r\nDTSTART:20261001T120000Z\r\nEND:VEVENT\r\n"
        . ($removeEnd ? '' : "END:VCALENDAR\r\n") . $suffix;

    expect(fn () => ICalendar::read($contents))->toThrow(InvalidCalendar::class)
        ->and(ICalendar::tryRead($contents))->toBeNull();
})->with([
    'missing calendar end' => ['', true],
    'trailing text' => ['ignored text', false],
    'trailing property' => ["X-DATA:ignored\r\n", false],
    'trailing component' => ["BEGIN:VCARD\r\nVERSION:3.0\r\nFN:x\r\nEND:VCARD\r\n", false],
    'duplicate calendar end' => ["END:VCALENDAR\r\n", false],
]);

it('validates typed extension properties on the calendar root', function () {
    $contents = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Example//Validation//EN\r\n"
        . "X-NUMBER;VALUE=INTEGER:abc\r\nBEGIN:X-COMPONENT\r\nEND:X-COMPONENT\r\nEND:VCALENDAR\r\n";

    expect(fn () => ICalendar::read($contents))->toThrow(InvalidCalendar::class)
        ->and(ICalendar::tryRead($contents))->toBeNull();
});

it('preserves valid folded extension data and RFC duration forms', function (string $duration) {
    $contents = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Example//Validation//EN\r\n"
        . "BEGIN:VEVENT\r\nUID:x\r\nDTSTAMP:20261001T000000Z\r\nDTSTART:20261001T120000Z\r\n"
        . "DURATION:{$duration}\r\nX-DATA:prefix\r\n BEGIN:VCALENDAR\r\n END:VCALENDAR\r\n"
        . "END:VEVENT\r\nEND:VCALENDAR\r\n\r\n";

    $event = ICalendar::read($contents)->event('x');

    expect($event?->duration)->not->toBeNull()
        ->and($event?->property('X-DATA')?->value)->toBe('prefixBEGIN:VCALENDAREND:VCALENDAR');
})->with(['P1W', 'P2D', 'P2DT3H4M5S', 'PT1H', 'PT30M', '+PT1S']);

it('compares calendar-defined and floating temporal values as they are hydrated', function (string $start, string $end, bool $isValid) {
    config()->set('icalendar_reader.floating_timezone', 'Asia/Taipei');
    $contents = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Example//Validation//EN\r\n"
        . "BEGIN:VTIMEZONE\r\nTZID:America/New_York\r\nBEGIN:STANDARD\r\nDTSTART:19700101T000000\r\n"
        . "TZOFFSETFROM:+0200\r\nTZOFFSETTO:+0200\r\nEND:STANDARD\r\nEND:VTIMEZONE\r\n"
        . "BEGIN:VEVENT\r\nUID:x\r\nDTSTAMP:20261001T000000Z\r\n{$start}\r\n{$end}\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";

    if (! $isValid) {
        expect(fn () => ICalendar::read($contents))->toThrow(InvalidCalendar::class)
            ->and(ICalendar::tryRead($contents))->toBeNull();

        return;
    }

    $event = ICalendar::read($contents)->event('x');

    if ($event === null || $event->startsAt === null || $event->endsAt === null) {
        throw new RuntimeException('Expected valid event dates.');
    }

    expect($event->endsAt->greaterThan($event->startsAt))->toBeTrue();
})->with([
    'calendar timezone allows valid end' => ['DTSTART;TZID=America/New_York:20261001T120000', 'DTEND:20261001T110000Z', true],
    'calendar timezone rejects earlier end' => ['DTSTART:20261001T110000Z', 'DTEND;TZID=America/New_York:20261001T120000', false],
    'floating timezone allows valid end' => ['DTSTART:20261001T120000', 'DTEND:20261001T050000Z', true],
    'floating timezone rejects earlier end' => ['DTSTART:20261001T050000Z', 'DTEND:20261001T120000', false],
]);
