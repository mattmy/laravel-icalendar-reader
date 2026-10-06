<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Mattmy\ICalendar\Event;
use Mattmy\ICalendar\Exceptions\RecurrenceLimitExceeded;
use Mattmy\ICalendar\Exceptions\UnsupportedRecurrence;
use Mattmy\ICalendar\Facades\ICalendar;

it('expands equivalent padded numeric recurrence parts without changing raw rules', function (string $rule, string $until, array $expected) {
    $calendar = ICalendar::read("BEGIN:VCALENDAR\nVERSION:2.0\nPRODID:-//Example//Padded Recurrence//EN\n"
        . "BEGIN:VEVENT\nUID:padded\nDTSTAMP:20260101T000000Z\nDTSTART:20260101T090000Z\n"
        . "RRULE:{$rule}\nEND:VEVENT\nEND:VCALENDAR\n");
    $before = $calendar->rawComponent()->serialize();
    $dates = $calendar->occurrencesBetween(
        CarbonImmutable::parse('2026-01-01', 'UTC'),
        CarbonImmutable::parse($until, 'UTC'),
    )->map(static fn (Event $event): ?string => $event->startsAt?->format('Y-m-d H:i'))->all();

    expect($dates)->toBe($expected);
    expect($calendar->rawComponent()->serialize())->toBe($before);
})->with([
    ['FREQ=DAILY;COUNT=3;BYHOUR=09,10', '2026-01-03', ['2026-01-01 09:00', '2026-01-01 10:00', '2026-01-02 09:00']],
    ['FREQ=YEARLY;COUNT=3;BYMONTH=01,2', '2027-03-01', ['2026-01-01 09:00', '2026-02-01 09:00', '2027-01-01 09:00']],
]);

it('ignores BYHOUR for DATE recurrence without consuming the daily count', function () {
    $calendar = ICalendar::read("BEGIN:VCALENDAR\nVERSION:2.0\nPRODID:-//Example//DATE Recurrence//EN\n"
        . "BEGIN:VEVENT\nUID:date-hours\nDTSTAMP:20260101T000000Z\nDTSTART;VALUE=DATE:20260101\n"
        . "RRULE:FREQ=DAILY;COUNT=3;BYHOUR=9,12\nEND:VEVENT\nEND:VCALENDAR\n");
    $before = $calendar->rawComponent()->serialize();
    $dates = $calendar->occurrencesBetween(
        CarbonImmutable::parse('2026-01-01', 'Asia/Taipei'),
        CarbonImmutable::parse('2026-01-05', 'Asia/Taipei'),
    )->map(static fn (Event $event): ?string => $event->startsAt?->toDateString())->all();

    expect($dates)->toBe(['2026-01-01', '2026-01-02', '2026-01-03']);
    expect($calendar->rawComponent()->serialize())->toBe($before);
});

it('skips missing month days without counting them as valid occurrences', function () {
    $calendar = ICalendar::read("BEGIN:VCALENDAR\nVERSION:2.0\nPRODID:-//Example//Month Days//EN\n"
        . "BEGIN:VEVENT\nUID:monthday\nDTSTAMP:20260101T000000Z\nDTSTART:20260131T090000Z\n"
        . "RRULE:FREQ=MONTHLY;BYMONTHDAY=31;COUNT=3\nEND:VEVENT\nEND:VCALENDAR\n");
    $dates = $calendar->occurrencesBetween(
        CarbonImmutable::parse('2026-01-01', 'UTC'),
        CarbonImmutable::parse('2026-07-01', 'UTC'),
    )->map(static fn (Event $event): ?string => $event->startsAt?->format('Y-m-d'));

    expect($dates->all())->toBe(['2026-01-31', '2026-03-31', '2026-05-31']);
});

/** Build a recurrence fixture with calendar transitions deliberately unlike the host zone. */
function durationRecurrenceContents(string $properties, string $override = '', ?string $observances = null): string
{
    $observances ??= "BEGIN:STANDARD\nDTSTART:20000101T000000\nTZOFFSETFROM:-0500\nTZOFFSETTO:-0500\nEND:STANDARD\n"
        . "BEGIN:DAYLIGHT\nDTSTART:20260309T020000\nTZOFFSETFROM:-0500\nTZOFFSETTO:-0400\nEND:DAYLIGHT\n";

    return "BEGIN:VCALENDAR\nVERSION:2.0\nPRODID:-//Example//Duration Recurrence//EN\n"
        . "BEGIN:VTIMEZONE\nTZID:America/New_York\n{$observances}END:VTIMEZONE\n"
        . "BEGIN:VEVENT\nUID:duration\nDTSTAMP:20260101T000000Z\nDTSTART;TZID=America/New_York:20260307T090000\n"
        . $properties . "END:VEVENT\n{$override}END:VCALENDAR\n";
}

it('applies source durations independently to generated and RDATE occurrences', function (string $properties, int $firstHours, int $secondHours, int $thirdHours) {
    $hours = [$firstHours, $secondHours, $thirdHours];
    $calendar = ICalendar::read(durationRecurrenceContents($properties));
    $before = [$calendar->toJson(), $calendar->rawComponent()->serialize()];
    $events = $calendar->occurrencesBetween(CarbonImmutable::parse('2026-03-07 UTC'), CarbonImmutable::parse('2026-03-11 UTC'));

    expect($events)->toHaveCount(3);
    foreach ($events as $index => $event) {
        expect(($event->endsAt?->getTimestamp() ?? 0) - ($event->startsAt?->getTimestamp() ?? 0))->toBe($hours[$index] * 3600);
    }
    expect([$calendar->toJson(), $calendar->rawComponent()->serialize()])->toBe($before)
        ->and($calendar->occurrencesBetween(CarbonImmutable::parse('2026-03-09 13:30 UTC'), CarbonImmutable::parse('2026-03-09 14:00 UTC')))->not->toBeEmpty();
})->with([
    'nominal RRULE' => ["DURATION:P1D\nRRULE:FREQ=DAILY;COUNT=3\n", 24, 23, 24],
    'accurate RRULE' => ["DURATION:PT24H\nRRULE:FREQ=DAILY;COUNT=3\n", 24, 24, 24],
    'explicit DTEND' => ["DTEND;TZID=America/New_York:20260308T090000\nRRULE:FREQ=DAILY;COUNT=3\n", 24, 24, 24],
    'inherited RDATE duration' => ["DURATION:P1D\nRDATE;TZID=America/New_York:20260308T090000,20260309T090000\n", 24, 23, 24],
    'period durations' => ["DURATION:P1D\nRDATE;VALUE=PERIOD;TZID=America/New_York:20260308T090000/P1D,20260309T090000/PT24H\n", 24, 23, 24],
    'explicit period endpoints' => ["DURATION:P1D\nRDATE;VALUE=PERIOD;TZID=America/New_York:20260308T090000/20260309T100000,20260309T090000/20260310T100000\n", 24, 24, 25],
]);

it('maps recurrence inclusions and exclusions with calendar wall clocks across a host gap', function (string $properties) {
    $calendar = ICalendar::read(durationRecurrenceContents(
        $properties,
        observances: "BEGIN:STANDARD\nDTSTART:20000101T000000\nTZOFFSETFROM:-0400\nTZOFFSETTO:-0400\nEND:STANDARD\n",
    ));
    $events = $calendar->occurrencesBetween(CarbonImmutable::parse('2026-03-07 UTC'), CarbonImmutable::parse('2026-03-10 UTC'));

    expect($events->map(fn (Event $event): ?string => $event->startsAt?->toIso8601String())->all())
        ->toBe(['2026-03-07T09:00:00-04:00', '2026-03-08T02:30:00-04:00']);
    expect($events->last()?->endsAt?->toIso8601String())->toBe('2026-03-08T03:00:00-04:00');
})->with([
    'date-time inclusion' => "DURATION:PT30M\nRDATE;TZID=America/New_York:20260308T023000,20260308T033000\nEXDATE;TZID=America/New_York:20260308T033000\n",
    'period inclusion' => "DURATION:PT30M\nRDATE;VALUE=PERIOD;TZID=America/New_York:20260308T023000/20260308T030000\n",
]);

it('does not return partial recurrence when a generated duration endpoint cannot resolve', function (string $properties, string $override) {
    $observances = "BEGIN:STANDARD\nDTSTART:20000101T000000\nTZOFFSETFROM:-0500\nTZOFFSETTO:-0500\nEND:STANDARD\n"
        . "BEGIN:DAYLIGHT\nDTSTART:20260309T020000\nTZOFFSETFROM:-0500\nTZOFFSETTO:-040030\nEND:DAYLIGHT\n";
    $calendar = ICalendar::read(durationRecurrenceContents($properties, $override, $observances));
    $before = [$calendar->toJson(), $calendar->rawComponent()->serialize()];
    expect(fn () => $calendar->occurrencesBetween(CarbonImmutable::parse('2026-03-07 UTC'), CarbonImmutable::parse('2026-03-10 UTC')))->toThrow(UnsupportedRecurrence::class)
        ->and([$calendar->toJson(), $calendar->rawComponent()->serialize()])->toBe($before);
})->with([
    'generated' => ["DURATION:P1D\nRRULE:FREQ=DAILY;COUNT=3\n", ''],
    'period' => ["DURATION:PT1H\nRDATE;VALUE=PERIOD;TZID=America/New_York:20260308T090000/P1D\n", ''],
    'inherited' => ["DURATION:P1D\nRDATE;TZID=America/New_York:20260308T090000\n", ''],
    'override' => ["DURATION:PT1H\nRRULE:FREQ=DAILY;COUNT=2\n", "BEGIN:VEVENT\nUID:duration\nDTSTAMP:20260101T000000Z\nRECURRENCE-ID;TZID=America/New_York:20260308T090000\nDTSTART;TZID=America/New_York:20260308T090000\nDURATION:P1D\nEND:VEVENT\n"],
]);

it('rejects unresolved dates needed by recurrence instead of returning a partial set', function (string $start, string $properties, string $override) {
    $contents = "BEGIN:VCALENDAR\nVERSION:2.0\nPRODID:-//Example//Offset Recurrence//EN\n"
        . "BEGIN:VTIMEZONE\nTZID:Custom/Seconds\nBEGIN:STANDARD\nDTSTART:20000101T000000\n"
        . "TZOFFSETFROM:+0700\nTZOFFSETTO:+0800\nEND:STANDARD\n"
        . "BEGIN:STANDARD\nDTSTART:20260804T000000\nTZOFFSETFROM:+0800\nTZOFFSETTO:+090030\nEND:STANDARD\nEND:VTIMEZONE\n"
        . "BEGIN:VEVENT\nUID:seconds\nDTSTAMP:20260801T000000Z\n{$start}\nRRULE:FREQ=DAILY;COUNT=2\n"
        . $properties . "END:VEVENT\n" . $override . "END:VCALENDAR\n";
    $calendar = ICalendar::read($contents);

    expect($calendar->events())->not->toBeEmpty();
    expect(fn () => $calendar->occurrencesBetween(
        CarbonImmutable::parse('2026-08-02 UTC'),
        CarbonImmutable::parse('2026-08-07 UTC'),
    ))->toThrow(UnsupportedRecurrence::class);
})->with([
    'master start' => ['DTSTART;TZID=Custom/Seconds:20260804T120000', '', ''],
    'generated start' => ['DTSTART;TZID=Custom/Seconds:20260803T120000', '', ''],
    'master end' => ['DTSTART:20260803T040000Z', "DTEND;TZID=Custom/Seconds:20260804T120000\n", ''],
    'exclusion' => ['DTSTART:20260803T040000Z', "EXDATE;TZID=Custom/Seconds:20260804T120000\n", ''],
    'inclusion' => ['DTSTART:20260803T040000Z', "RDATE;TZID=Custom/Seconds:20260804T120000\n", ''],
    'override identity' => ['DTSTART:20260803T040000Z', '', "BEGIN:VEVENT\nUID:seconds\nDTSTAMP:20260801T000000Z\nRECURRENCE-ID;TZID=Custom/Seconds:20260804T120000\nDTSTART;TZID=Custom/Seconds:20260803T120000\nEND:VEVENT\n"],
    'cancelled override identity' => ['DTSTART:20260803T040000Z', '', "BEGIN:VEVENT\nUID:seconds\nDTSTAMP:20260801T000000Z\nRECURRENCE-ID;TZID=Custom/Seconds:20260804T120000\nDTSTART;TZID=Custom/Seconds:20260803T120000\nSTATUS:CANCELLED\nEND:VEVENT\n"],
    'override start' => ['DTSTART:20260803T040000Z', '', "BEGIN:VEVENT\nUID:seconds\nDTSTAMP:20260801T000000Z\nRECURRENCE-ID;TZID=Custom/Seconds:20260803T120000\nDTSTART;TZID=Custom/Seconds:20260804T120000\nEND:VEVENT\n"],
    'override end' => ['DTSTART:20260803T040000Z', '', "BEGIN:VEVENT\nUID:seconds\nDTSTAMP:20260801T000000Z\nRECURRENCE-ID:20260804T040000Z\nDTSTART:20260804T040000Z\nDTEND;TZID=Custom/Seconds:20260804T120000\nEND:VEVENT\n"],
]);

it('includes a recurring point event at the lower boundary', function () {
    $calendar = ICalendar::read("BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Tests//EN\r\nBEGIN:VEVENT\r\nUID:point\r\nDTSTAMP:20260801T000000Z\r\nDTSTART:20260803T090000Z\r\nRRULE:FREQ=DAILY;COUNT=2\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n");

    expect($calendar->occurrencesBetween(
        CarbonImmutable::parse('2026-08-03 09:00 UTC'),
        CarbonImmutable::parse('2026-08-05 UTC'),
    )->map(fn (Event $event): ?string => $event->startsAt?->format('Y-m-d H:i'))->all())
        ->toBe(['2026-08-03 09:00', '2026-08-04 09:00']);
});

it('expands unordered RDATE inclusions before applying the upper boundary', function () {
    $calendar = ICalendar::read("BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Tests//EN\r\nBEGIN:VEVENT\r\nUID:rdate\r\nDTSTAMP:20260801T000000Z\r\nDTSTART:20260803T090000Z\r\nRDATE:20260810T090000Z,20260804T090000Z\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n");

    expect($calendar->occurrencesBetween(
        CarbonImmutable::parse('2026-08-03 UTC'),
        CarbonImmutable::parse('2026-08-05 UTC'),
    )->map(fn (Event $event): ?string => $event->startsAt?->format('Y-m-d H:i'))->all())
        ->toBe(['2026-08-03 09:00', '2026-08-04 09:00']);
});

it('rejects recurrence parts that the underlying engine silently ignores', function (string $rule) {
    $calendar = ICalendar::read("BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Tests//EN\r\nBEGIN:VEVENT\r\nUID:unsupported\r\nDTSTAMP:20260801T000000Z\r\nDTSTART:20260803T090000Z\r\nRRULE:{$rule}\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n");

    expect(fn () => $calendar->occurrencesBetween(
        CarbonImmutable::parse('2026-08-03 UTC'),
        CarbonImmutable::parse('2026-08-05 UTC'),
    ))->toThrow(UnsupportedRecurrence::class);
})->with([
    'secondly' => 'FREQ=SECONDLY;COUNT=3',
    'minutely' => 'FREQ=MINUTELY;COUNT=3',
    'seconds' => 'FREQ=DAILY;COUNT=3;BYSECOND=30',
    'minutes' => 'FREQ=DAILY;COUNT=3;BYMINUTE=30',
    'calendar scale' => 'RSCALE=GREGORIAN;FREQ=DAILY;COUNT=3',
]);

it('uses calendar observances and UTC UNTIL while expanding local recurrence', function () {
    $calendar = ICalendar::read(<<<'ICS'
BEGIN:VCALENDAR
VERSION:2.0
PRODID:-//Tests//EN
BEGIN:VTIMEZONE
TZID:Asia/Taipei
BEGIN:STANDARD
DTSTART:19700101T000000
TZOFFSETFROM:+0500
TZOFFSETTO:+0500
END:STANDARD
END:VTIMEZONE
BEGIN:VEVENT
UID:local
DTSTAMP:20260801T000000Z
DTSTART;TZID=Asia/Taipei:20260803T120000
DTEND;TZID=Asia/Taipei:20260803T130000
RRULE:FREQ=DAILY;UNTIL=20260804T070000Z
RDATE;TZID=Asia/Taipei:20260805T120000
EXDATE;TZID=Asia/Taipei:20260804T120000
END:VEVENT
END:VCALENDAR
ICS);

    $occurrences = $calendar->occurrencesBetween(
        CarbonImmutable::parse('2026-08-03 06:30 UTC'),
        CarbonImmutable::parse('2026-08-06 UTC'),
    );

    expect($occurrences->map(fn (Event $event): ?string => $event->startsAt?->toIso8601String())->all())
        ->toBe(['2026-08-03T12:00:00+05:00', '2026-08-05T12:00:00+05:00']);
    expect($occurrences->map(fn ($event): int => ($event->endsAt ?? throw new LogicException('Missing end.'))->getTimestamp() - ($event->startsAt ?? throw new LogicException('Missing start.'))->getTimestamp())->all())
        ->toBe([3600, 3600]);
});

it('allows exactly the documented number of recurrence candidates', function (int $count) {
    $calendar = ICalendar::read("BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Tests//EN\r\nBEGIN:VEVENT\r\nUID:limit\r\nDTSTAMP:20200101T000000Z\r\nDTSTART:20200101T090000Z\r\nRRULE:FREQ=DAILY;COUNT={$count}\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n");
    $query = fn () => $calendar->occurrencesBetween(
        CarbonImmutable::parse('2020-01-01 UTC'),
        CarbonImmutable::parse('2030-01-01 UTC'),
    );

    if ($count === 3500) {
        expect($query())->toHaveCount(3500);
    } else {
        expect($query)->toThrow(RecurrenceLimitExceeded::class);
    }
})->with([[3500], [3501]]);

it('preserves the exact DTEND duration across a calendar observance transition', function () {
    $calendar = ICalendar::read(<<<'ICS'
BEGIN:VCALENDAR
VERSION:2.0
PRODID:-//Tests//EN
BEGIN:VTIMEZONE
TZID:Custom/Transition
BEGIN:STANDARD
DTSTART:19700101T000000
TZOFFSETFROM:+0500
TZOFFSETTO:+0500
END:STANDARD
BEGIN:DAYLIGHT
DTSTART:20260804T020000
TZOFFSETFROM:+0500
TZOFFSETTO:+0600
END:DAYLIGHT
END:VTIMEZONE
BEGIN:VEVENT
UID:transition
DTSTAMP:20260801T000000Z
DTSTART;TZID=Custom/Transition:20260803T010000
DTEND;TZID=Custom/Transition:20260803T030000
RRULE:FREQ=DAILY;COUNT=2
END:VEVENT
END:VCALENDAR
ICS);

    $occurrences = $calendar->occurrencesBetween(
        CarbonImmutable::parse('2026-08-02 UTC'),
        CarbonImmutable::parse('2026-08-05 UTC'),
    );

    expect($occurrences)->toHaveCount(2);
    expect($occurrences->map(fn ($event): int => ($event->endsAt ?? throw new LogicException('Missing end.'))->getTimestamp() - ($event->startsAt ?? throw new LogicException('Missing start.'))->getTimestamp())->all())
        ->toBe([7200, 7200]);
});

it('uses the effective duration and start of overrides when checking overlap', function (string $override, string $from, string $until, array $expected) {
    $calendar = ICalendar::read("BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Tests//EN\r\nBEGIN:VEVENT\r\nUID:override\r\nDTSTAMP:20260801T000000Z\r\nDTSTART:20260803T090000Z\r\nDURATION:PT1H\r\nRRULE:FREQ=DAILY;COUNT=2\r\nSUMMARY:Master\r\nEND:VEVENT\r\nBEGIN:VEVENT\r\nUID:override\r\nDTSTAMP:20260801T000000Z\r\nRECURRENCE-ID:20260804T090000Z\r\n{$override}\r\nSUMMARY:Override\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n");

    expect($calendar->occurrencesBetween(
        CarbonImmutable::parse($from),
        CarbonImmutable::parse($until),
    )->pluck('summary')->all())->toBe($expected);
})->with([
    'long duration crosses lower boundary' => [
        "DTSTART:20260804T090000Z\r\nDURATION:PT5H",
        '2026-08-04 12:00 UTC', '2026-08-04 13:00 UTC', ['Override'],
    ],
    'moved into range from future slot' => [
        "DTSTART:20260802T090000Z\r\nDURATION:PT1H",
        '2026-08-02 UTC', '2026-08-03 UTC', ['Override'],
    ],
    'moved out of original slot' => [
        "DTSTART:20260810T090000Z\r\nDURATION:PT1H",
        '2026-08-04 UTC', '2026-08-05 UTC', [],
    ],
]);
