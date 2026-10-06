<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Mattmy\ICalendar\Calendar;
use Mattmy\ICalendar\CalendarIssue;
use Mattmy\ICalendar\Exceptions\InvalidCalendar;
use Mattmy\ICalendar\Exceptions\RecurrenceLimitExceeded;
use Mattmy\ICalendar\Exceptions\UnsupportedRecurrence;
use Mattmy\ICalendar\Facades\ICalendar;

/** Read one event against caller-supplied calendar observances. */
function observanceCalendar(string $observances, string $target, string $eventProperties = ''): Calendar
{
    return ICalendar::read("BEGIN:VCALENDAR\nVERSION:2.0\nPRODID:-//Example//Observances//EN\n"
        . "BEGIN:VTIMEZONE\nTZID:Custom/Boundary\n{$observances}END:VTIMEZONE\n"
        . "BEGIN:VEVENT\nUID:boundary\nDTSTAMP:20000101T000000Z\nDTSTART;TZID=Custom/Boundary:{$target}\n"
        . $eventProperties . "END:VEVENT\nEND:VCALENDAR\n");
}

it('leaves dates unresolved when an unsupported observance rule may affect them', function (string $kind, string $rule) {
    $observances = "BEGIN:{$kind}\nDTSTART:20260101T000000\nRRULE:{$rule}\n"
        . "TZOFFSETFROM:+0800\nTZOFFSETTO:+0900\nEND:{$kind}\n"
        . "BEGIN:STANDARD\nDTSTART:20260102T000000\nTZOFFSETFROM:+0900\nTZOFFSETTO:+1000\nEND:STANDARD\n";
    $calendar = observanceCalendar($observances, '20260103T120000', "RRULE:FREQ=DAILY;COUNT=2\n");
    $event = $calendar->events()->sole();
    $before = [$calendar->toJson(), $calendar->rawComponent()->serialize()];

    expect($event->startsAt)->toBeNull()
        ->and($event->property('DTSTART')?->value)->toBe('20260103T120000')
        ->and($calendar->warnings()->where('code', 'mapping_warning')->where('property', 'DTSTART'))->not->toBeEmpty()
        ->and(ICalendar::tryRead($calendar->rawComponent()->serialize()))->not->toBeNull()
        ->and(fn () => $calendar->occurrencesBetween(CarbonImmutable::parse('2026-01-01 UTC'), CarbonImmutable::parse('2026-02-01 UTC')))
        ->toThrow(UnsupportedRecurrence::class)
        ->and([$calendar->toJson(), $calendar->rawComponent()->serialize()])->toBe($before);

    $earlier = observanceCalendar($observances, '20251231T120000');
    expect($earlier->events()->sole()->startsAt?->format('P'))->toBe('+08:00')
        ->and($earlier->warnings()->where('code', 'mapping_warning'))->toBeEmpty();
})->with(['STANDARD', 'DAYLIGHT'])->with([
    'daily monthday' => 'FREQ=DAILY;COUNT=3;BYMONTHDAY=1',
    'monthly hours' => 'FREQ=MONTHLY;COUNT=3;BYHOUR=0,12',
    'yearly weekday' => 'FREQ=YEARLY;COUNT=3;BYDAY=TH',
    'extension' => 'FREQ=DAILY;COUNT=3;RSCALE=GREGORIAN',
]);

it('preserves a supported start when an unsupported observance affects only the duration endpoint', function (string $duration, string $kind) {
    $observances = "BEGIN:STANDARD\nDTSTART:20000101T000000\nTZOFFSETFROM:+0800\nTZOFFSETTO:+0800\nEND:STANDARD\n"
        . "BEGIN:{$kind}\nDTSTART:20260102T000000\nRRULE:FREQ=DAILY;BYMONTHDAY=2\n"
        . "TZOFFSETFROM:+0800\nTZOFFSETTO:+0900\nEND:{$kind}\n";
    $calendar = observanceCalendar($observances, '20260101T120000', "DURATION:{$duration}\nRRULE:FREQ=DAILY;COUNT=2\n");

    expect($calendar->events()->sole()->startsAt?->format('P'))->toBe('+08:00')
        ->and($calendar->events()->sole()->endsAt)->toBeNull()
        ->and($calendar->warnings()->where('code', 'mapping_warning')->where('property', 'DURATION'))->toHaveCount(1)
        ->and(fn () => $calendar->occurrencesBetween(CarbonImmutable::parse('2026-01-01 UTC'), CarbonImmutable::parse('2026-01-04 UTC')))
        ->toThrow(UnsupportedRecurrence::class);
})->with(['P1D', 'PT24H'])->with(['STANDARD', 'DAYLIGHT']);

it('uses proven observance frequency combinations without replacing calendar rules', function (string $rule, string $target, string $kind) {
    $observances = "BEGIN:{$kind}\nDTSTART:20260101T000000\nRRULE:{$rule}\n"
        . "TZOFFSETFROM:+0800\nTZOFFSETTO:+0900\nEND:{$kind}\n"
        . "BEGIN:STANDARD\nDTSTART:20260102T010000\nTZOFFSETFROM:+0900\nTZOFFSETTO:+1000\nEND:STANDARD\n";
    $calendar = observanceCalendar($observances, $target);

    expect($calendar->events()->sole()->startsAt?->format('P'))->toBe('+09:00')
        ->and($calendar->warnings()->where('code', 'mapping_warning'))->toBeEmpty()
        ->and($calendar->component('VTIMEZONE')?->components($kind)->first()?->property('RRULE')?->rawValue())->toBe($rule);
})->with([
    ['FREQ=HOURLY;INTERVAL=24;COUNT=3', '20260103T120000'],
    ['FREQ=DAILY;BYDAY=TH,SA;BYMONTH=1;BYHOUR=0,12;COUNT=4', '20260103T130000'],
    ['FREQ=WEEKLY;WKST=SU;BYDAY=TH;BYHOUR=0,12;COUNT=4', '20260108T130000'],
    ['FREQ=MONTHLY;BYDAY=TH;BYMONTHDAY=1,2,3,4,5,6,7;BYSETPOS=1;COUNT=3', '20260206T120000'],
    ['FREQ=YEARLY;BYMONTH=1;BYDAY=TH;BYMONTHDAY=1,2,3,4,5,6,7;BYSETPOS=1;COUNT=3', '20270108T120000'],
])->with(['STANDARD', 'DAYLIGHT']);

it('maps padded observance month tokens without changing the original rule', function () {
    $calendar = observanceCalendar("BEGIN:STANDARD\nDTSTART:20260101T000000\n"
        . "RRULE:FREQ=YEARLY;BYMONTH=01,2\nTZOFFSETFROM:+1000\nTZOFFSETTO:+0900\nEND:STANDARD\n"
        . "BEGIN:DAYLIGHT\nDTSTART:20261231T120000\nTZOFFSETFROM:+0900\nTZOFFSETTO:+1000\nEND:DAYLIGHT\n", '20270101T120000');

    expect($calendar->events()->sole()->startsAt?->format('P'))->toBe('+09:00');
    expect($calendar->warnings()->where('code', 'mapping_warning'))->toBeEmpty();
    expect(\str_contains($calendar->rawComponent()->serialize(), 'BYMONTH=01,2'))->toBeTrue();
});

it('compares observance UNTIL inclusively in UTC using TZOFFSETFROM', function (string $from, string $cutoff, int $delta, string $expected) {
    $until = CarbonImmutable::parse($cutoff, 'UTC')->addSeconds($delta)->format('Ymd\THis\Z');
    $observances = "BEGIN:STANDARD\nDTSTART:20260101T000000\nRRULE:FREQ=DAILY;UNTIL={$until}\n"
        . "TZOFFSETFROM:{$from}\nTZOFFSETTO:+0900\nEND:STANDARD\n"
        . "BEGIN:DAYLIGHT\nDTSTART:20260101T120000\nTZOFFSETFROM:+0900\nTZOFFSETTO:+1000\nEND:DAYLIGHT\n";
    $calendar = observanceCalendar($observances, '20260103T120000', "RRULE:FREQ=DAILY;COUNT=2\n");

    expect($calendar->events()->sole()->startsAt?->format('P'))->toBe($expected)
        ->and($calendar->warnings()->where('code', 'mapping_warning'))->toBeEmpty()
        ->and(\str_contains($calendar->rawComponent()->serialize(), "UNTIL={$until}"))->toBeTrue();
    foreach ($calendar->occurrencesBetween(CarbonImmutable::parse('2026-01-03 UTC'), CarbonImmutable::parse('2026-01-05 UTC')) as $event) {
        expect($event->startsAt?->format('P'))->toBe($expected);
    }
})->with([
    ['+0800', '2026-01-01 16:00:00', -1, '+10:00'],
    ['+0800', '2026-01-01 16:00:00', 0, '+09:00'],
    ['+0800', '2026-01-01 16:00:00', 1, '+09:00'],
    ['-0800', '2026-01-02 08:00:00', -1, '+10:00'],
    ['-0800', '2026-01-02 08:00:00', 0, '+09:00'],
    ['-0800', '2026-01-02 08:00:00', 1, '+09:00'],
]);

it('does not spend an observance budget on candidates beyond UTC UNTIL or restrict independent dates', function () {
    $last = CarbonImmutable::parse('2000-01-01 UTC')->addDays(3499)->subHours(9)->format('Ymd\THis\Z');
    $target = CarbonImmutable::parse('2000-01-01 UTC')->addDays(3501)->format('Ymd\THis');
    $calendar = observanceCalendar("BEGIN:STANDARD\nDTSTART:20000101T000000\nRRULE:FREQ=DAILY;UNTIL={$last}\n"
        . "RDATE:{$target}\nTZOFFSETFROM:+0900\nTZOFFSETTO:+0900\nEND:STANDARD\n", $target);
    expect($calendar->events()->sole()->startsAt?->format('P'))->toBe('+09:00')
        ->and($calendar->warnings()->where('code', 'mapping_warning'))->toBeEmpty();
});

it('resolves only the applicable supported UTC offset without initial fallback', function (string $from, string $to, string $target, ?string $offset) {
    $observance = "BEGIN:STANDARD\nDTSTART:20000101T000000\nTZOFFSETFROM:{$from}\nTZOFFSETTO:{$to}\nEND:STANDARD\n";
    $calendar = observanceCalendar($observance, $target);
    $event = $calendar->events()->sole();

    expect($event->startsAt?->format('P'))->toBe($offset);
    expect($calendar->warnings()->where('code', 'mapping_warning')->isNotEmpty())->toBe($offset === null);
    expect($event->property('DTSTART')?->rawValue())->toBe($target);
    expect($calendar->component('VEVENT')?->property('DTSTART')?->rawValue())->toBe($target);
    expect($calendar->toArray()['events'][0]['starts_at'])->toBe($offset === null ? null : $event->startsAt?->toIso8601String());
    expect(\str_contains($calendar->rawComponent()->serialize(), 'TZOFFSETTO:' . $to))->toBeTrue();

    if ($offset === null) {
        expect($event->property('DTSTART')?->value)->toBe($target);
    }
})->with([
    'positive effective seconds' => ['+0800', '+090030', '20260803T120000', null],
    'negative effective seconds' => ['-0800', '-090030', '20260803T120000', null],
    'positive initial seconds' => ['+080030', '+0900', '19991231T120000', null],
    'negative initial seconds' => ['-080030', '-0900', '19991231T120000', null],
    'positive minutes' => ['+0800', '+0900', '20260803T120000', '+09:00'],
    'negative minutes' => ['-0800', '-0900', '20260803T120000', '-09:00'],
    'positive zero seconds' => ['+080000', '+090000', '20260803T120000', '+09:00'],
    'negative zero seconds' => ['-080000', '-090000', '20260803T120000', '-09:00'],
    'supported initial minutes' => ['+0800', '+090030', '19991231T120000', '+08:00'],
    'supported initial zero seconds' => ['-080000', '-090030', '19991231T120000', '-08:00'],
]);

it('ignores unsupported observances that do not determine the target offset', function () {
    $observances = "BEGIN:STANDARD\nDTSTART:19700101T000000\nTZOFFSETFROM:+080030\nTZOFFSETTO:+090030\nEND:STANDARD\n"
        . "BEGIN:STANDARD\nDTSTART:20000101T000000\nTZOFFSETFROM:+090030\nTZOFFSETTO:+1000\nEND:STANDARD\n"
        . "BEGIN:DAYLIGHT\nDTSTART:20300101T000000\nTZOFFSETFROM:+1000\nTZOFFSETTO:+110030\nEND:DAYLIGHT\n";
    $observances .= "BEGIN:DAYLIGHT\nDTSTART:20400101T000000\nRRULE:FREQ=YEARLY;UNTIL=20500101T000000Z\n"
        . "TZOFFSETFROM:+100030\nTZOFFSETTO:+110030\nEND:DAYLIGHT\n";
    $calendar = observanceCalendar($observances, '20260803T120000');

    expect($calendar->events()->sole()->startsAt?->format('P'))->toBe('+10:00');
    expect($calendar->warnings()->where('code', 'mapping_warning'))->toBeEmpty();
});

it('preserves the start when only a derived endpoint exceeds the observance budget', function (string $duration) {
    $start = CarbonImmutable::parse('2000-01-01 02:00 UTC')->addDays(3499)->format('Ymd\THis');
    $calendar = observanceCalendar("BEGIN:STANDARD\nDTSTART:20000101T000000\nRRULE:FREQ=DAILY;COUNT=3501\n"
        . "TZOFFSETFROM:+0900\nTZOFFSETTO:+0900\nEND:STANDARD\n", $start, "DURATION:{$duration}\nRRULE:FREQ=DAILY;COUNT=2\n");
    expect($calendar->events()->sole()->startsAt)->not->toBeNull()
        ->and($calendar->events()->sole()->endsAt)->toBeNull()
        ->and($calendar->events()->sole()->property('DURATION')?->rawValue())->toBe($duration)
        ->and($calendar->warnings()->where('code', 'mapping_warning')->where('property', 'DURATION'))->toHaveCount(1)
        ->and(ICalendar::tryRead($calendar->rawComponent()->serialize()))->not->toBeNull()
        ->and(fn () => $calendar->occurrencesBetween(CarbonImmutable::parse($start, 'UTC')->subDay(), CarbonImmutable::parse($start, 'UTC')->addDays(2)))->toThrow(UnsupportedRecurrence::class);
})->with(['P1D', 'PT24H']);

it('bounds each observance RRULE while resolving the exact final permitted transition', function (string $rule, int $day, bool $resolved) {
    $target = CarbonImmutable::parse('2000-01-01 UTC')->addDays($day)->addHours(2)->format('Ymd\THis');
    $calendar = observanceCalendar("BEGIN:STANDARD\nDTSTART:20000101T000000\nRRULE:{$rule}\nTZOFFSETFROM:+0800\nTZOFFSETTO:+0900\nEND:STANDARD\n", $target);
    $event = $calendar->events()->sole();

    expect($event->startsAt?->format('P'))->toBe($resolved ? '+09:00' : null);
    expect($calendar->warnings()->contains(static fn (CalendarIssue $issue): bool => $issue->code === 'mapping_warning' && $issue->property === 'DTSTART'))->toBe(! $resolved);
    expect($event->property('DTSTART')?->rawValue())->toBe($target);
    expect($calendar->component('VEVENT')?->property('DTSTART')?->rawValue())->toBe($target);

    if (! $resolved) {
        expect($event->property('DTSTART')?->value)->toBe($target);
    }
})->with([
    'exhausted at 3500' => ['FREQ=DAILY;COUNT=3500', 3499, true],
    'next transition beyond target' => ['FREQ=DAILY;COUNT=3501', 3499, true],
    'needs transition 3501' => ['FREQ=DAILY;COUNT=3501', 3500, false],
    'unbounded needs transition 3501' => ['FREQ=DAILY', 3500, false],
]);

it('keeps observance budgets independent and excludes standalone DTSTART and RDATE', function () {
    $target = CarbonImmutable::parse('2000-01-01 UTC')->addDays(3500)->format('Ymd\THis');
    $observances = "BEGIN:STANDARD\nDTSTART:20000101T000000\nRRULE:FREQ=DAILY;COUNT=3500\nRDATE:{$target}\n"
        . "TZOFFSETFROM:+0900\nTZOFFSETTO:+0900\nEND:STANDARD\n"
        . "BEGIN:DAYLIGHT\nDTSTART:20000101T010000\nRRULE:FREQ=DAILY;COUNT=3500\n"
        . "TZOFFSETFROM:+0900\nTZOFFSETTO:+1000\nEND:DAYLIGHT\n";
    $calendar = observanceCalendar($observances, $target, "RRULE:FREQ=DAILY;COUNT=1\n");

    expect($calendar->events()->sole()->startsAt?->format('P'))->toBe('+09:00');
    expect($calendar->warnings()->where('code', 'mapping_warning'))->toBeEmpty();
    expect($calendar->occurrencesBetween(
        CarbonImmutable::parse($target, 'UTC')->subDay(),
        CarbonImmutable::parse($target, 'UTC')->addDay(),
    ))->toHaveCount(1);
});

it('bounds infinite recurrence expansion by the requested interval', function () {
    $calendar = ICalendar::read(<<<'ICS'
BEGIN:VCALENDAR
VERSION:2.0
PRODID:-//Example//RFC Hardening//EN
BEGIN:VEVENT
UID:infinite@example.test
DTSTAMP:20260801T000000Z
DTSTART:20260803T010000Z
DTEND:20260803T040000Z
RRULE:FREQ=DAILY
END:VEVENT
END:VCALENDAR
ICS);

    $occurrences = $calendar->occurrencesBetween(
        CarbonImmutable::parse('2026-08-03 03:00:00 UTC'),
        CarbonImmutable::parse('2026-08-05 00:00:00 UTC'),
    );

    expect($occurrences)->toHaveCount(2)
        ->and($occurrences->first()?->startsAt?->toIso8601String())->toBe('2026-08-03T01:00:00+00:00')
        ->and($occurrences->last()?->startsAt?->toIso8601String())->toBe('2026-08-04T01:00:00+00:00');
});

it('counts recurrence candidates before EXDATE filtering', function () {
    $excluded = [];

    for ($day = 0; $day < 3501; $day++) {
        $excluded[] = CarbonImmutable::parse('2020-01-01 09:00:00 UTC')
            ->addDays($day)
            ->format('Ymd\THis\Z');
    }

    $calendar = ICalendar::read("BEGIN:VCALENDAR\r\n"
        . "VERSION:2.0\r\n"
        . "PRODID:-//Example//RFC Hardening//EN\r\n"
        . "BEGIN:VEVENT\r\n"
        . "UID:excluded@example.test\r\n"
        . "DTSTAMP:20200101T000000Z\r\n"
        . "DTSTART:20200101T090000Z\r\n"
        . "RRULE:FREQ=DAILY;COUNT=3502\r\n"
        . 'EXDATE:' . \implode(',', $excluded) . "\r\n"
        . "END:VEVENT\r\n"
        . "END:VCALENDAR\r\n");

    expect(fn () => $calendar->occurrencesBetween(
        CarbonImmutable::parse('2020-01-01 00:00:00 UTC'),
        CarbonImmutable::parse('2030-01-01 00:00:00 UTC'),
    ))->toThrow(RecurrenceLimitExceeded::class);
});

it('expands RDATE periods with their explicit durations', function () {
    $calendar = ICalendar::read(<<<'ICS'
BEGIN:VCALENDAR
VERSION:2.0
PRODID:-//Example//RFC Hardening//EN
BEGIN:VEVENT
UID:period@example.test
DTSTAMP:20260801T000000Z
DTSTART:20260803T010000Z
DTEND:20260803T020000Z
RRULE:FREQ=DAILY;COUNT=2
RDATE;VALUE=PERIOD:20260804T010000Z/20260804T040000Z,20260805T010000Z/PT2H,20260805T050000Z/+PT30M
END:VEVENT
END:VCALENDAR
ICS);

    $occurrences = $calendar->occurrencesBetween(
        CarbonImmutable::parse('2026-08-03 00:00:00 UTC'),
        CarbonImmutable::parse('2026-08-06 00:00:00 UTC'),
    );

    expect($occurrences)->toHaveCount(4)
        ->and($occurrences->map(fn ($event): int => (int) ($event->endsAt?->diffInMinutes($event->startsAt, true) ?? 0))->all())
        ->toBe([60, 180, 120, 30]);
});

it('rejects invalid VEVENT and VTODO temporal relationships', function (string $component) {
    expect(fn () => ICalendar::read("BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Example//RFC Hardening//EN\r\n{$component}\r\nEND:VCALENDAR\r\n"))
        ->toThrow(InvalidCalendar::class);
})->with([
    'event end and duration' => "BEGIN:VEVENT\r\nUID:x\r\nDTSTAMP:20260801T000000Z\r\nDTSTART:20260803T010000Z\r\nDTEND:20260803T020000Z\r\nDURATION:PT1H\r\nEND:VEVENT",
    'event mismatched value types' => "BEGIN:VEVENT\r\nUID:x\r\nDTSTAMP:20260801T000000Z\r\nDTSTART;VALUE=DATE:20260803\r\nDTEND:20260804T020000Z\r\nEND:VEVENT",
    'event end before start' => "BEGIN:VEVENT\r\nUID:x\r\nDTSTAMP:20260801T000000Z\r\nDTSTART:20260803T020000Z\r\nDTEND:20260803T010000Z\r\nEND:VEVENT",
    'event negative duration' => "BEGIN:VEVENT\r\nUID:x\r\nDTSTAMP:20260801T000000Z\r\nDTSTART:20260803T010000Z\r\nDURATION:-PT1H\r\nEND:VEVENT",
    'todo due and duration' => "BEGIN:VTODO\r\nUID:x\r\nDTSTAMP:20260801T000000Z\r\nDTSTART:20260803T010000Z\r\nDUE:20260803T020000Z\r\nDURATION:PT1H\r\nEND:VTODO",
    'todo duration without start' => "BEGIN:VTODO\r\nUID:x\r\nDTSTAMP:20260801T000000Z\r\nDURATION:PT1H\r\nEND:VTODO",
    'todo due equal to start' => "BEGIN:VTODO\r\nUID:x\r\nDTSTAMP:20260801T000000Z\r\nDTSTART:20260803T010000Z\r\nDUE:20260803T010000Z\r\nEND:VTODO",
    'todo rule without start' => "BEGIN:VTODO\r\nUID:x\r\nDTSTAMP:20260801T000000Z\r\nRRULE:FREQ=DAILY;COUNT=2\r\nEND:VTODO",
]);

it('validates VALARM action grammars and paired repeat fields', function (string $alarm) {
    $contents = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Example//RFC Hardening//EN\r\n"
        . "BEGIN:VEVENT\r\nUID:x\r\nDTSTAMP:20260801T000000Z\r\nDTSTART:20260803T010000Z\r\n"
        . $alarm
        . "\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";

    expect(fn () => ICalendar::read($contents))->toThrow(InvalidCalendar::class);
})->with([
    'display without description' => "BEGIN:VALARM\r\nACTION:DISPLAY\r\nTRIGGER:-PT5M\r\nEND:VALARM",
    'email without required fields' => "BEGIN:VALARM\r\nACTION:EMAIL\r\nTRIGGER:-PT5M\r\nEND:VALARM",
    'repeat without duration' => "BEGIN:VALARM\r\nACTION:DISPLAY\r\nTRIGGER:-PT5M\r\nDESCRIPTION:x\r\nREPEAT:2\r\nEND:VALARM",
    'duration without repeat' => "BEGIN:VALARM\r\nACTION:DISPLAY\r\nTRIGGER:-PT5M\r\nDESCRIPTION:x\r\nDURATION:PT1M\r\nEND:VALARM",
    'audio with multiple attachments' => "BEGIN:VALARM\r\nACTION:AUDIO\r\nTRIGGER:-PT5M\r\nATTACH:https://example.test/1.mp3\r\nATTACH:https://example.test/2.mp3\r\nEND:VALARM",
    'display with attachment' => "BEGIN:VALARM\r\nACTION:DISPLAY\r\nTRIGGER:-PT5M\r\nDESCRIPTION:x\r\nATTACH:https://example.test/file.txt\r\nEND:VALARM",
    'display with repeated description' => "BEGIN:VALARM\r\nACTION:DISPLAY\r\nTRIGGER:-PT5M\r\nDESCRIPTION:x\r\nDESCRIPTION:y\r\nEND:VALARM",
    'repeat must be positive' => "BEGIN:VALARM\r\nACTION:DISPLAY\r\nTRIGGER:-PT5M\r\nDESCRIPTION:x\r\nREPEAT:0\r\nDURATION:PT1M\r\nEND:VALARM",
]);

it('accepts email alarm attachments and exposes the complete alarm properties', function () {
    $calendar = ICalendar::read(<<<'ICS'
BEGIN:VCALENDAR
VERSION:2.0
PRODID:-//Example//RFC Hardening//EN
BEGIN:VEVENT
UID:email-alarm@example.test
DTSTAMP:20260801T000000Z
DTSTART:20260803T010000Z
BEGIN:VALARM
ACTION:EMAIL
TRIGGER:-PT5M
DESCRIPTION:Body
SUMMARY:Subject
ATTENDEE:mailto:user@example.test
ATTACH:https://example.test/1.txt
ATTACH:https://example.test/2.txt
X-ALARM-ID:custom
END:VALARM
END:VEVENT
END:VCALENDAR
ICS);
    $alarm = $calendar->events()->sole()->alarms->sole();

    $raw = $alarm->rawComponent();
    $raw->__set('SUMMARY', 'Changed');

    expect($alarm->attachments)->toHaveCount(2)
        ->and($alarm->properties('ATTACH'))->toHaveCount(2)
        ->and($alarm->property('X-ALARM-ID')?->value)->toBe('custom');
    expect(calendarRawProperty($alarm->rawComponent(), 'SUMMARY'))->toBe('Subject')
        ->and($calendar->toArray()['events'][0]['alarms'][0]['attachments'])->toHaveCount(2);
});

it('rejects invalid date-time forms and integers before normalization', function (string $property) {
    $contents = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Example//RFC Hardening//EN\r\n"
        . "BEGIN:VEVENT\r\nUID:x\r\nDTSTAMP:20260801T000000Z\r\nDTSTART:20260803T010000Z\r\n"
        . $property
        . "\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";

    expect(fn () => ICalendar::read($contents))->toThrow(InvalidCalendar::class)
        ->and(ICalendar::tryRead($contents))->toBeNull();
})->with([
    'floating timestamp' => 'DTSTAMP:20260801T000000',
    'floating created' => 'CREATED:20260801T000000',
    'TZID on date' => 'RDATE;VALUE=DATE;TZID=Asia/Taipei:20260804',
    'priority lexical form' => 'PRIORITY:abc',
    'priority range' => 'PRIORITY:10',
    'negative sequence' => 'SEQUENCE:-1',
    'integer above 32-bit range' => 'SEQUENCE:2147483648',
]);

it('rejects UTC-only and recurrence form violations in their valid component contexts', function (string $component) {
    $contents = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Example//RFC Hardening//EN\r\n"
        . $component
        . "\r\nEND:VCALENDAR\r\n";

    expect(fn () => ICalendar::read($contents))->toThrow(InvalidCalendar::class)
        ->and(ICalendar::tryRead($contents))->toBeNull();
})->with([
    'floating last modified' => "BEGIN:VEVENT\r\nUID:x\r\nDTSTAMP:20260801T000000Z\r\nDTSTART:20260803T010000Z\r\nLAST-MODIFIED:20260801T000000\r\nEND:VEVENT",
    'floating completed' => "BEGIN:VTODO\r\nUID:x\r\nDTSTAMP:20260801T000000Z\r\nCOMPLETED:20260801T000000\r\nEND:VTODO",
    'percent complete range' => "BEGIN:VTODO\r\nUID:x\r\nDTSTAMP:20260801T000000Z\r\nPERCENT-COMPLETE:101\r\nEND:VTODO",
    'floating absolute trigger' => "BEGIN:VEVENT\r\nUID:x\r\nDTSTAMP:20260801T000000Z\r\nDTSTART:20260803T010000Z\r\nBEGIN:VALARM\r\nACTION:DISPLAY\r\nDESCRIPTION:x\r\nTRIGGER;VALUE=DATE-TIME:20260803T005500\r\nEND:VALARM\r\nEND:VEVENT",
    'numeric UTC offset' => "BEGIN:VEVENT\r\nUID:x\r\nDTSTAMP:20260801T000000Z\r\nDTSTART:20260803T010000+0800\r\nEND:VEVENT",
    'UNTIL form mismatch' => "BEGIN:VEVENT\r\nUID:x\r\nDTSTAMP:20260801T000000Z\r\nDTSTART:20260803T010000Z\r\nRRULE:FREQ=DAILY;UNTIL=20260805T010000\r\nEND:VEVENT",
]);

it('uses a matching VTIMEZONE definition before host tzdata', function () {
    $event = ICalendar::read(<<<'ICS'
BEGIN:VCALENDAR
VERSION:2.0
PRODID:-//Example//RFC Hardening//EN
BEGIN:VTIMEZONE
TZID:Asia/Taipei
BEGIN:STANDARD
DTSTART:19700101T000000
TZOFFSETFROM:+0900
TZOFFSETTO:+0900
TZNAME:CUSTOM
END:STANDARD
END:VTIMEZONE
BEGIN:VEVENT
UID:custom-zone@example.test
DTSTAMP:20260801T000000Z
DTSTART;TZID=Asia/Taipei:20260803T120000
END:VEVENT
END:VCALENDAR
ICS)->events()->sole();

    expect($event->startsAt?->format('Y-m-d H:i:s P'))->toBe('2026-08-03 12:00:00 +09:00');
});

it('accepts RFC INTEGER signs and leading zeroes without coercion loss', function () {
    $event = ICalendar::read(<<<'ICS'
BEGIN:VCALENDAR
VERSION:2.0
PRODID:-//Example//RFC Hardening//EN
BEGIN:VEVENT
UID:integer@example.test
DTSTAMP:20260801T000000Z
DTSTART:20260803T010000Z
PRIORITY:+01
SEQUENCE:0002
END:VEVENT
END:VCALENDAR
ICS)->events()->sole();

    expect($event->priority)->toBe(1)
        ->and($event->sequence)->toBe(2);
});

it('applies recurring VTIMEZONE observances from the calendar', function () {
    $events = ICalendar::read(<<<'ICS'
BEGIN:VCALENDAR
VERSION:2.0
PRODID:-//Example//RFC Hardening//EN
BEGIN:VTIMEZONE
TZID:Asia/Taipei
BEGIN:STANDARD
DTSTART:19701101T020000
RRULE:FREQ=YEARLY;BYMONTH=11;BYDAY=1SU
TZOFFSETFROM:+1000
TZOFFSETTO:+0900
END:STANDARD
BEGIN:DAYLIGHT
DTSTART:19700301T020000
RRULE:FREQ=YEARLY;BYMONTH=3;BYDAY=1SU
TZOFFSETFROM:+0900
TZOFFSETTO:+1000
END:DAYLIGHT
END:VTIMEZONE
BEGIN:VEVENT
UID:summer@example.test
DTSTAMP:20260801T000000Z
DTSTART;TZID=Asia/Taipei:20260803T120000
END:VEVENT
BEGIN:VEVENT
UID:winter@example.test
DTSTAMP:20260801T000000Z
DTSTART;TZID=Asia/Taipei:20261203T120000
END:VEVENT
END:VCALENDAR
ICS)->events();

    expect($events->first()?->startsAt?->format('P'))->toBe('+10:00')
        ->and($events->last()?->startsAt?->format('P'))->toBe('+09:00');
});

it('rejects invalid RDATE PERIOD values', function (string $period) {
    $contents = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Example//RFC Hardening//EN\r\n"
        . "BEGIN:VEVENT\r\nUID:x\r\nDTSTAMP:20260801T000000Z\r\nDTSTART:20260803T010000Z\r\n"
        . "RDATE;VALUE=PERIOD:{$period}\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";

    expect(fn () => ICalendar::read($contents))->toThrow(InvalidCalendar::class);
})->with([
    'equal endpoints' => '20260804T010000Z/20260804T010000Z',
    'end before start' => '20260804T020000Z/20260804T010000Z',
    'negative duration' => '20260804T010000Z/-PT1H',
    'zero duration' => '20260804T010000Z/PT0S',
]);

it('rejects additional calendar objects instead of silently truncating them', function () {
    $calendar = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Example//RFC Hardening//EN\r\n"
        . "BEGIN:VEVENT\r\nUID:x\r\nDTSTAMP:20260801T000000Z\r\nDTSTART:20260803T010000Z\r\nEND:VEVENT\r\n"
        . "END:VCALENDAR\r\n";

    expect(fn () => ICalendar::read($calendar . $calendar))->toThrow(InvalidCalendar::class);
});

it('hydrates deeply nested extension components without quadratic cloning', function () {
    $depth = 200;
    $contents = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Example//RFC Hardening//EN\r\n"
        . \str_repeat("BEGIN:X-NEST\r\nX-VALUE:1\r\n", $depth)
        . \str_repeat("END:X-NEST\r\n", $depth)
        . "END:VCALENDAR\r\n";

    \memory_reset_peak_usage();
    $before = \memory_get_usage(true);
    $calendar = ICalendar::read($contents);
    $growth = \memory_get_peak_usage(true) - $before;

    expect($growth)->toBeLessThan(10 * 1024 * 1024)
        ->and($calendar->components('X-NEST'))->toHaveCount(1)
        ->and($calendar->components('X-NEST')->sole()->components('X-NEST'))->toHaveCount(1);
});
