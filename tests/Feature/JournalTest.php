<?php

declare(strict_types=1);

use Mattmy\ICalendar\Facades\ICalendar;
use Sabre\VObject\Component\VJournal;

it('queries typed journals in document order and prefers a recurrence master', function () {
    $calendar = ICalendar::read(<<<'ICS'
BEGIN:VCALENDAR
VERSION:2.0
PRODID:-//Example//Journal Tests//EN
BEGIN:VTIMEZONE
TZID:Asia/Taipei
BEGIN:STANDARD
DTSTART:19700101T000000
TZOFFSETFROM:+0800
TZOFFSETTO:+0800
END:STANDARD
END:VTIMEZONE
BEGIN:VJOURNAL
UID:journal@example.test
DTSTAMP:20260803T000000Z
RECURRENCE-ID;VALUE=DATE:20260810
SUMMARY:Override
END:VJOURNAL
BEGIN:VJOURNAL
UID:journal@example.test
DTSTAMP:20260803T000000Z
DTSTART;VALUE=DATE:20260803
SUMMARY:Master
END:VJOURNAL
END:VCALENDAR
ICS);

    expect($calendar->journals())->toHaveCount(2)
        ->and($calendar->journals('journal@example.test')->pluck('summary')->all())->toBe(['Override', 'Master'])
        ->and($calendar->journal('journal@example.test')?->summary)->toBe('Master')
        ->and($calendar->hasJournals())->toBeTrue()
        ->and($calendar->hasJournals('journal@example.test'))->toBeTrue()
        ->and($calendar->journals('JOURNAL@example.test'))->toBeEmpty()
        ->and($calendar->journal('missing'))->toBeNull()
        ->and($calendar->hasJournals(''))->toBeFalse();
});

it('maps journal properties without collapsing repeated data', function () {
    $calendar = ICalendar::read(<<<'ICS'
BEGIN:VCALENDAR
VERSION:2.0
PRODID:-//Example//Journal Tests//EN
BEGIN:VTIMEZONE
TZID:Asia/Taipei
BEGIN:STANDARD
DTSTART:19700101T000000
TZOFFSETFROM:+0800
TZOFFSETTO:+0800
END:STANDARD
END:VTIMEZONE
BEGIN:VJOURNAL
UID:all-properties@example.test
DTSTAMP:20260803T000000Z
CLASS:confidential
CREATED:20260801T010203Z
DTSTART;TZID=Asia/Taipei:20260803T090000
LAST-MODIFIED:20260802T010203Z
ORGANIZER;CN=Owner:mailto:owner@example.test
RECURRENCE-ID;TZID=Asia/Taipei:20260810T090000
SEQUENCE:3
STATUS:final
SUMMARY:Journal summary
URL:https://example.test/journal
RRULE:FREQ=DAILY;COUNT=2
ATTACH;FMTTYPE=text/plain:https://example.test/note.txt
ATTENDEE;CN=Guest;PARTSTAT=ACCEPTED:mailto:guest@example.test
CATEGORIES:NOTES,WORK
COMMENT:First comment
COMMENT:Second comment
CONTACT:First contact
CONTACT:Second contact
DESCRIPTION:First description
DESCRIPTION:Second description
EXDATE;TZID=Asia/Taipei:20260804T090000
RELATED-TO;RELTYPE=PARENT:parent@example.test
RDATE;TZID=Asia/Taipei:20260805T090000
REQUEST-STATUS:2.0;Success
X-TEAM:platform
END:VJOURNAL
END:VCALENDAR
ICS);

    $journal = $calendar->journals()->sole();
    $array = $calendar->toArray()['journals'][0];

    expect($journal->timestamp?->toIso8601String())->toBe('2026-08-03T00:00:00+00:00')
        ->and($journal->classification)->toBe('CONFIDENTIAL')
        ->and($journal->startsAt?->timezoneName)->toBe('Asia/Taipei')
        ->and($journal->startIsDate)->toBeFalse()
        ->and($journal->startIsFloating)->toBeFalse()
        ->and($journal->recurrenceId?->format('Y-m-d H:i'))->toBe('2026-08-10 09:00')
        ->and($journal->sequence)->toBe(3)
        ->and($journal->status)->toBe('FINAL')
        ->and($journal->organizer?->email)->toBe('owner@example.test')
        ->and($journal->attendees->sole()->status)->toBe('ACCEPTED')
        ->and($journal->categories->all())->toBe(['NOTES', 'WORK'])
        ->and($journal->comments->all())->toBe(['First comment', 'Second comment'])
        ->and($journal->contacts->all())->toBe(['First contact', 'Second contact'])
        ->and($journal->descriptions->all())->toBe(['First description', 'Second description'])
        ->and($journal->property('X-TEAM')?->value)->toBe('platform')
        ->and($array)->toHaveKeys([
            'uid', 'timestamp', 'classification', 'created_at', 'starts_at', 'start_is_date',
            'start_is_floating', 'last_modified_at', 'organizer', 'recurrence_id',
            'recurrence_id_is_date', 'recurrence_id_is_floating', 'sequence', 'status', 'summary',
            'url', 'recurrence_rule', 'attachments', 'attendees', 'categories', 'comments', 'contacts',
            'descriptions', 'exception_dates', 'related_to', 'recurrence_dates', 'request_statuses',
        ])
        ->and($array['descriptions'])->toBe(['First description', 'Second description'])
        ->and(json_decode($calendar->toJson(), true, flags: JSON_THROW_ON_ERROR)['journals'][0]['descriptions'])
        ->toBe(['First description', 'Second description']);
});

it('keeps journal raw components and query collections isolated', function () {
    $journal = ICalendar::fromPath(__DIR__ . '/../Fixtures/public/05-journal-entry.ics')->journals()->sole();
    $raw = $journal->rawComponent();
    $properties = $journal->properties();

    expect($raw)->toBeInstanceOf(VJournal::class)
        ->and($journal->startIsDate)->toBeTrue()
        ->and($journal->descriptions->all())->toBe(['今天確認了 jCal 的陣列結構。']);

    $raw->SUMMARY = 'Changed';
    $properties->pop();

    expect((string) $journal->rawComponent()->SUMMARY)->toBe('Sabre VObject 研究筆記')
        ->and($journal->properties())->toHaveCount(8);
});

it('returns empty journal queries when a calendar has no journals', function () {
    $calendar = ICalendar::read(calendarFixture('basic-event'));

    expect($calendar->journals())->toBeEmpty()
        ->and($calendar->journals(''))->toBeEmpty()
        ->and($calendar->journal(''))->toBeNull()
        ->and($calendar->hasJournals())->toBeFalse();
});

it('uses the shared DATE, floating, and unresolved timezone mapping semantics', function () {
    $calendar = ICalendar::read(<<<'ICS'
BEGIN:VCALENDAR
VERSION:2.0
PRODID:-//Example//Journal Tests//EN
BEGIN:VJOURNAL
UID:date@example.test
DTSTAMP:20260803T000000Z
DTSTART;VALUE=DATE:20260803
STATUS:DRAFT
END:VJOURNAL
BEGIN:VJOURNAL
UID:floating@example.test
DTSTAMP:20260803T000000Z
DTSTART:20260803T090000
STATUS:FINAL
END:VJOURNAL
BEGIN:VJOURNAL
UID:unresolved@example.test
DTSTAMP:20260803T000000Z
DTSTART;TZID=Invalid/Zone:20260803T090000
STATUS:CANCELLED
END:VJOURNAL
END:VCALENDAR
ICS);

    expect($calendar->journal('date@example.test')?->startsAt?->toDateString())->toBe('2026-08-03')
        ->and($calendar->journal('date@example.test')?->startIsDate)->toBeTrue()
        ->and($calendar->journal('date@example.test')?->startIsFloating)->toBeTrue()
        ->and($calendar->journal('date@example.test')?->status)->toBe('DRAFT')
        ->and($calendar->journal('floating@example.test')?->startIsFloating)->toBeTrue()
        ->and($calendar->journal('floating@example.test')?->status)->toBe('FINAL')
        ->and($calendar->journal('unresolved@example.test')?->startsAt)->toBeNull()
        ->and($calendar->journal('unresolved@example.test')?->status)->toBe('CANCELLED')
        ->and($calendar->warnings()->pluck('property')->all())->toContain('DTSTART');
});

it('uses the shared validation pipeline for invalid journal cardinality and recurrence data', function (string $properties) {
    $contents = "BEGIN:VCALENDAR\nVERSION:2.0\nPRODID:-//Example//Journal Tests//EN\nBEGIN:VJOURNAL\n{$properties}\nEND:VJOURNAL\nEND:VCALENDAR";

    expect(ICalendar::tryRead($contents))->toBeNull();
})->with([
    'missing DTSTAMP' => "UID:missing-stamp@example.test\nSUMMARY:Invalid",
    'missing UID' => "DTSTAMP:20260803T000000Z\nSUMMARY:Invalid",
    'duplicate UID' => "UID:first@example.test\nUID:second@example.test\nDTSTAMP:20260803T000000Z",
    'RRULE without DTSTART' => "UID:missing-start@example.test\nDTSTAMP:20260803T000000Z\nRRULE:FREQ=DAILY",
]);
