<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Mattmy\ICalendar\Calendar;
use Mattmy\ICalendar\Component;
use Mattmy\ICalendar\Event;
use Mattmy\ICalendar\Facades\ICalendar;
use Mattmy\ICalendar\Journal;
use Mattmy\ICalendar\Property;
use Mattmy\ICalendar\Todo;
use Sabre\VObject\Component as SabreComponent;

it('does not expose canonical raw ancestors through a subtree clone', function (string $route) {
    $calendar = isolationCalendar();
    $before = $calendar->components()->map(static fn (Component $component): string => $component->rawComponent()->serialize())->all();
    $raw = match ($route) {
        'event' => $calendar->events()->sole()->rawComponent(),
        'todo' => $calendar->todos()->sole()->rawComponent(),
        'journal' => $calendar->journals()->sole()->rawComponent(),
        'alarm' => $calendar->events()->sole()->alarms->sole()->rawComponent(),
        'generic' => $calendar->components('VEVENT')->sole()->rawComponent(),
        'nested generic' => $calendar->components('X-TREE')->sole()->components()->sole()->rawComponent(),
        default => throw new LogicException('Unknown raw route.'),
    };

    if ($raw->parent instanceof SabreComponent) {
        $raw->parent->__set('X-LEAK', 'Changed');

        foreach ($raw->parent->children() as $child) {
            if ($child instanceof SabreComponent) {
                $child->__set('X-LEAK', 'Changed');
            }
        }
    }

    expect($calendar->components()->map(static fn (Component $component): string => $component->rawComponent()->serialize())->all())->toBe($before);
})->with(['event', 'todo', 'journal', 'alarm', 'generic', 'nested generic']);

/** Read a small calendar with every mutable descendant used by the isolation checks. */
function isolationCalendar(): Calendar
{
    $alarm = "BEGIN:VALARM\nACTION:EMAIL\nTRIGGER:-PT5M\nDESCRIPTION:Body\nSUMMARY:Subject\n"
        . "ATTENDEE:mailto:alarm@example.test\nATTACH:https://example.test/alarm\nREPEAT:2\nDURATION:PT1M\nEND:VALARM\n";
    $shared = "DTSTAMP:20260801T000000Z\nDTSTART:20260803T010000Z\n"
        . "ATTENDEE;DELEGATED-FROM=\"mailto:from@example.test\";DELEGATED-TO=\"mailto:to@example.test\":mailto:user@example.test\n"
        . "CATEGORIES:Original\nCOMMENT:Comment\nCONTACT:Contact\nATTACH:https://example.test/file\n"
        . "RRULE:FREQ=DAILY;COUNT=2\nRDATE:20260805T010000Z\nEXDATE:20260804T010000Z\n"
        . "RELATED-TO:parent\nREQUEST-STATUS:2.0;Success\nX-SPAN;VALUE=DURATION:PT2H\n";

    return ICalendar::read("BEGIN:VCALENDAR\nVERSION:2.0\nPRODID:-//Example//Snapshots//EN\nX-SPAN;VALUE=DURATION:PT2H\nX-INSTANT;VALUE=DATE-TIME:20260803T010000Z\n"
        . "BEGIN:VEVENT\nUID:snapshot\n{$shared}DURATION:PT2H\nRESOURCES:Room\n{$alarm}END:VEVENT\n"
        . "BEGIN:VTODO\nUID:todo\n{$shared}DURATION:PT2H\nRESOURCES:Room\n{$alarm}END:VTODO\n"
        . "BEGIN:VJOURNAL\nUID:journal\n{$shared}DESCRIPTION:Journal\nEND:VJOURNAL\n"
        . "BEGIN:X-TREE\nX-SPAN;VALUE=DURATION:PT2H\nBEGIN:X-CHILD\nX-SPAN;VALUE=DURATION:PT2H\nEND:X-CHILD\nEND:X-TREE\nEND:VCALENDAR\n");
}

/** Capture public representations by value, including recurrence and low-level data. */
function isolationState(Calendar $calendar): string
{
    return \serialize([
        $calendar->toArray(), $calendar->jsonSerialize(), $calendar->toComponentArray(), $calendar->toJson(),
        $calendar->rawComponent()->serialize(),
        $calendar->events()->map(static fn (Event $event): array => $event->properties()->map->toArray()->all())->all(),
        $calendar->todos()->map(static fn (Todo $todo): array => $todo->properties()->map->toArray()->all())->all(),
        $calendar->journals()->map(static fn (Journal $journal): array => $journal->properties()->map->toArray()->all())->all(),
        $calendar->occurrencesBetween(CarbonImmutable::parse('2026-08-03 UTC'), CarbonImmutable::parse('2026-08-06 UTC'))
            ->map(static fn (Event $event): array => [$event->startsAt, $event->duration?->h, $event->categories->all()])->all(),
    ]);
}

/** Select one typed snapshot through each public query route. */
function isolationModel(Calendar $calendar, string $route): Event|Todo|Journal
{
    return match ($route) {
        'event' => $calendar->event('snapshot') ?? throw new LogicException('Missing event.'),
        'events' => $calendar->events()->sole(),
        'filtered events' => $calendar->events('snapshot')->sole(),
        'range' => $calendar->eventsBetween(CarbonImmutable::parse('2026-08-03 UTC'), CarbonImmutable::parse('2026-08-04 UTC'))->sole(),
        'occurrences' => $calendar->occurrencesBetween(CarbonImmutable::parse('2026-08-03 UTC'), CarbonImmutable::parse('2026-08-04 UTC'))->sole(),
        'todo' => $calendar->todo('todo') ?? throw new LogicException('Missing todo.'),
        'todos' => $calendar->todos()->sole(),
        'filtered todos' => $calendar->todos('todo')->sole(),
        'journal' => $calendar->journal('journal') ?? throw new LogicException('Missing journal.'),
        'journals' => $calendar->journals()->sole(),
        'filtered journals' => $calendar->journals('journal')->sole(),
        default => throw new LogicException('Unknown route.'),
    };
}

it('detaches typed mutable descendants across every query route', function (string $route) {
    $calendar = isolationCalendar();
    $baseline = isolationState($calendar);
    $first = isolationModel($calendar, $route);
    $second = isolationModel($calendar, $route);
    $original = \serialize([$second->categories->all(), $second->attendees->sole()->delegatedFrom->all(), $second->properties()->map->toArray()->all()]);
    $originalDate = \json_encode($second->startsAt, \JSON_THROW_ON_ERROR);
    $first->startsAt?->settings(['toJsonFormat' => 'Y']);

    $first->categories->push('Changed');
    $first->comments->pop();
    $first->contacts->pop();
    $first->attendees->sole()->delegatedFrom->pop();
    $first->attendees->sole()->delegatedTo->push('Changed');
    $first->attachments->pop();
    $first->exceptionDates->pop();
    $first->recurrenceDates->pop();
    $first->relatedTo->pop();
    $first->requestStatuses->pop();
    $interval = $first->property('X-SPAN')?->value;

    if (! $interval instanceof DateInterval) {
        throw new LogicException('Expected a duration property.');
    }

    $interval->h = 9;

    if ($first instanceof Journal) {
        $first->descriptions->pop();
    } else {
        $duration = $first->duration ?? throw new LogicException('Missing duration.');
        $duration->h = 9;
        $first->resources->pop();
        $alarm = $first->alarms->sole();
        $alarm->attendees->pop();
        $alarm->attachments->pop();
        $alarmDuration = $alarm->duration ?? throw new LogicException('Missing alarm duration.');
        $alarmDuration->i = 9;
        $first->alarms->pop();
    }

    expect(\serialize([$second->categories->all(), $second->attendees->sole()->delegatedFrom->all(), $second->properties()->map->toArray()->all()]))->toBe($original);
    expect(\json_encode($second->startsAt, \JSON_THROW_ON_ERROR))->toBe($originalDate);
    expect(isolationState($calendar))->toBe($baseline);
})->with(['event', 'events', 'filtered events', 'range', 'occurrences', 'todo', 'todos', 'filtered todos', 'journal', 'journals', 'filtered journals']);

it('detaches property objects and values through direct generic and export routes', function (string $route) {
    $calendar = isolationCalendar();
    $baseline = isolationState($calendar);
    $select = static function () use ($calendar, $route): Property {
        return match ($route) {
            'property' => $calendar->property('X-SPAN') ?? throw new LogicException('Missing property.'),
            'properties' => $calendar->properties()->firstWhere('name', 'X-SPAN') ?? throw new LogicException('Missing property.'),
            'filtered properties' => $calendar->properties('x-span')->sole(),
            'component' => $calendar->component('X-TREE')?->property('X-SPAN') ?? throw new LogicException('Missing component.'),
            'components' => $calendar->components()->last()?->property('X-SPAN') ?? throw new LogicException('Missing component.'),
            'filtered components' => $calendar->components('x-tree')->sole()->property('X-SPAN') ?? throw new LogicException('Missing component.'),
            'descendant' => $calendar->components('X-TREE')->sole()->components()->sole()->property('X-SPAN') ?? throw new LogicException('Missing descendant.'),
            default => throw new LogicException('Unknown route.'),
        };
    };
    $first = $select();
    $second = $select();
    $original = \serialize($second->toArray());

    foreach ([$first->value, ...$first->values, ...$first->toArray()['values']] as $value) {
        if (! $value instanceof DateInterval) {
            throw new LogicException('Expected a mutable interval.');
        }

        $value->h = 9;
    }

    expect(\serialize($second->toArray()))->toBe($original);
    expect(isolationState($calendar))->toBe($baseline);
})->with(['property', 'properties', 'filtered properties', 'component', 'components', 'filtered components', 'descendant']);

/** Mutate interval values anywhere inside a public array export. */
function mutateExportIntervals(mixed $value): void
{
    if ($value instanceof DateInterval) {
        $value->h = 9;
    } elseif ($value instanceof CarbonImmutable) {
        $value->settings(['toJsonFormat' => 'Y']);
    } elseif (\is_array($value)) {
        foreach ($value as $child) {
            mutateExportIntervals($child);
        }
    }
}

it('detaches date object settings in properties and array outputs', function (string $route) {
    $calendar = isolationCalendar();
    $baseline = isolationState($calendar);
    $date = match ($route) {
        'calendar property' => $calendar->property('X-INSTANT')?->value,
        'typed property' => $calendar->events()->sole()->property('DTSTART')?->value,
        'generic property' => $calendar->components('VEVENT')->sole()->property('DTSTART')?->value,
        'domain array' => $calendar->toArray()['events'][0]['recurrence_dates'][0]['value'],
        'component array' => $calendar->toComponentArray()['properties'][3]['value'],
        default => throw new LogicException('Unknown date route.'),
    };

    if (! $date instanceof CarbonImmutable) {
        throw new LogicException('Expected a date object.');
    }

    $date->settings(['toJsonFormat' => 'Y']);
    expect(isolationState($calendar))->toBe($baseline);
})->with(['calendar property', 'typed property', 'generic property', 'domain array', 'component array']);

it('isolates absolute alarm trigger date settings', function () {
    $calendar = ICalendar::read("BEGIN:VCALENDAR\nVERSION:2.0\nPRODID:-//Example//Absolute Trigger//EN\n"
        . "BEGIN:VEVENT\nUID:x\nDTSTAMP:20260801T000000Z\nDTSTART:20260803T010000Z\n"
        . "BEGIN:VALARM\nACTION:DISPLAY\nTRIGGER;VALUE=DATE-TIME:20260803T005500Z\nDESCRIPTION:Reminder\nEND:VALARM\nEND:VEVENT\nEND:VCALENDAR\n");
    $trigger = $calendar->events()->sole()->alarms->sole()->trigger ?? throw new LogicException('Missing trigger.');
    $date = $trigger->dateTime() ?? throw new LogicException('Missing trigger date.');
    $original = \json_encode($date, \JSON_THROW_ON_ERROR);
    $date->settings(['toJsonFormat' => 'Y']);

    expect(\json_encode($trigger->dateTime(), \JSON_THROW_ON_ERROR))->toBe($original);
    expect(\json_encode($calendar->events()->sole()->alarms->sole()->trigger->dateTime(), \JSON_THROW_ON_ERROR))->toBe($original);
});

it('detaches nested exported arrays and retains defensive raw and trigger copies', function (string $export) {
    $calendar = isolationCalendar();
    $baseline = isolationState($calendar);
    mutateExportIntervals($calendar->{$export}());
    $event = $calendar->events()->sole();
    $raw = $event->rawComponent();
    $raw->__set('SUMMARY', 'Changed');
    $trigger = $event->alarms->sole()->trigger?->duration() ?? throw new LogicException('Missing trigger.');
    $trigger->i = 9;

    $alarmTrigger = $event->alarms->sole()->trigger ?? throw new LogicException('Missing trigger.');
    $unchangedTrigger = $alarmTrigger->duration() ?? throw new LogicException('Missing trigger duration.');
    expect($unchangedTrigger->i)->toBe(5);
    expect(isolationState($calendar))->toBe($baseline);
})->with(['toArray', 'jsonSerialize', 'toComponentArray']);

it('detaches explicitly derived and all-day durations', function (string $end) {
    $calendar = ICalendar::read("BEGIN:VCALENDAR\nVERSION:2.0\nPRODID:-//Example//Duration//EN\nBEGIN:VEVENT\nUID:x\nDTSTAMP:20260801T000000Z\n{$end}\nEND:VEVENT\nEND:VCALENDAR\n");
    $first = $calendar->events()->sole()->duration ?? throw new LogicException('Missing duration.');
    $secondEvent = $calendar->event('x') ?? throw new LogicException('Missing event.');
    $second = $secondEvent->duration ?? throw new LogicException('Missing duration.');
    $original = \serialize($second);
    $first->d = 9;
    expect(\serialize($second))->toBe($original);
    expect(\serialize($calendar->events()->sole()->duration))->toBe($original);
})->with([
    'explicit end' => "DTSTART:20260803T010000Z\nDTEND:20260804T020000Z",
    'all-day end' => "DTSTART;VALUE=DATE:20260803\nDTEND;VALUE=DATE:20260805",
    'implicit all-day end' => 'DTSTART;VALUE=DATE:20260803',
]);
