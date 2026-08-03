<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Mattmy\ICalendar\Facades\ICalendar;

beforeEach(function () {
    if (! is_dir(__DIR__ . '/../../../../public/ics')) {
        $this->markTestSkipped('Workspace public/ics fixtures are not available.');
    }
});

function publicFixturePath(string $name): string
{
    return __DIR__ . "/../../../../public/ics/{$name}.ics";
}

it('maps all-day event semantics and common fields from a real fixture', function () {
    $event = ICalendar::fromPath(publicFixturePath('01-all-day-event'))->events()->sole();

    expect($event->isAllDay())->toBeTrue()
        ->and($event->description)->toContain('Laravel 套件設計討論')
        ->and($event->location)->toBe('臺南市中西區')
        ->and($event->startsAt?->toDateString())->toBe('2026-08-15')
        ->and($event->endsAt?->toDateString())->toBe('2026-08-17')
        ->and($event->lastDay?->toDateString())->toBe('2026-08-16')
        ->and($event->duration?->days)->toBe(2)
        ->and($event->status)->toBe('CONFIRMED')
        ->and($event->url)->toBe('https://example.test/events/tainan-2026')
        ->and($event->categories->all())->toBe(['TRAVEL', 'DEVELOPMENT']);
});

it('maps timezone events organizers attendees and alarms from a real fixture', function () {
    $event = ICalendar::fromPath(publicFixturePath('02-timezone-meeting-with-alarm'))
        ->events()
        ->sole();

    expect($event->startsAt?->timezoneName)->toBe('Asia/Taipei')
        ->and($event->endsAt?->format('H:i'))->toBe('16:00')
        ->and($event->startIsFloating)->toBeFalse()
        ->and($event->classification)->toBe('PRIVATE')
        ->and($event->priority)->toBe(3)
        ->and($event->sequence)->toBe(2)
        ->and($event->organizer?->email)->toBe('matt@example.test')
        ->and($event->organizer?->name)->toBe('Matt Huang')
        ->and($event->attendees)->toHaveCount(2)
        ->and($event->attendees->first()?->status)->toBe('ACCEPTED')
        ->and($event->attendees->first()?->rsvp)->toBeTrue()
        ->and($event->alarms)->toHaveCount(1);

    $alarm = $event->alarms->sole();

    expect($alarm->action)->toBe('DISPLAY')
        ->and($alarm->trigger?->isRelative())->toBeTrue()
        ->and($alarm->trigger?->relatedTo())->toBe('START')
        ->and($alarm->trigger?->duration()?->invert)->toBe(1);
});

it('keeps recurrence data and selects the master while querying concrete events', function () {
    $calendar = ICalendar::fromPath(publicFixturePath('03-recurring-event-with-exceptions'));
    $uid = 'e913cbf4-8c75-455e-97ef-f81c70b4e7cb@example.test';

    expect($calendar->events())->toHaveCount(2)
        ->and($calendar->event($uid)?->hasProperty('RECURRENCE-ID'))->toBeFalse()
        ->and($calendar->event($uid)?->property('RRULE'))->not->toBeNull()
        ->and($calendar->events()->last()?->hasProperty('RECURRENCE-ID'))->toBeTrue()
        ->and($calendar->events()->first()?->endsAt?->format('H:i'))->toBe('01:45');

    $events = $calendar->eventsBetween(
        CarbonImmutable::parse('2026-08-20 01:30:00 UTC'),
        CarbonImmutable::parse('2026-08-20 03:30:00 UTC'),
    );

    expect($events)->toHaveCount(1)
        ->and($events->sole()->summary)->toContain('延後');
});

it('keeps non-event components and emits the fixed domain and normalized outputs', function () {
    $todoCalendar = ICalendar::fromPath(publicFixturePath('04-todo-task'));
    $todo = $todoCalendar->components('VTODO')->sole();

    expect($todoCalendar->events())->toBeEmpty()
        ->and($todo->property('PERCENT-COMPLETE')?->value)->toBe(45)
        ->and($todo->components('VALARM'))->toHaveCount(1);

    $calendar = ICalendar::fromPath(publicFixturePath('02-timezone-meeting-with-alarm'));
    $event = $calendar->toArray()['events'][0];

    expect($event)->toHaveKeys([
        'uid', 'summary', 'description', 'location', 'starts_at', 'ends_at',
        'start_is_floating', 'end_is_floating', 'is_all_day', 'last_day', 'duration',
        'timestamp', 'created_at', 'last_modified_at', 'status', 'classification',
        'priority', 'sequence', 'url', 'organizer', 'attendees', 'alarms', 'categories',
    ])->and(json_decode($calendar->toJson(), true, flags: JSON_THROW_ON_ERROR))
        ->toBe($calendar->toArray());
});

it('reads every valid supplied public fixture without hiding non-event data', function () {
    foreach (range(1, 6) as $number) {
        $path = (glob(publicFixturePath(sprintf('%02d-*', $number))) ?: [])[0] ?? null;

        expect($path)->not->toBeNull();

        $calendar = ICalendar::fromPath($path);

        expect($calendar->components())->not->toBeEmpty()
            ->and($calendar->toComponentArray()['components'])->not->toBeEmpty();
    }
});

it('rejects the supplied legacy fixture when strict validation finds invalid value types', function () {
    expect(ICalendar::tryFromPath(publicFixturePath('test')))->toBeNull();
});
