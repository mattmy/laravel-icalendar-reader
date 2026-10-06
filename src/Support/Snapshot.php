<?php

declare(strict_types=1);

namespace Mattmy\ICalendar\Support;

use Carbon\CarbonImmutable;
use DateInterval;
use LogicException;
use Mattmy\ICalendar\Alarm;
use Mattmy\ICalendar\Attendee;
use Mattmy\ICalendar\Component;
use Mattmy\ICalendar\Event;
use Mattmy\ICalendar\Journal;
use Mattmy\ICalendar\Property;
use Mattmy\ICalendar\Todo;
use Sabre\VObject\Component as SabreComponent;

/**
 * @internal Detach mutable descendants of selected public query results.
 *
 * @phpstan-import-type PropertyAtom from Property
 */
final class Snapshot
{
    /** Return a detached event with the existing public field types. */
    public static function event(Event $source): Event
    {
        return new Event(
            uid: $source->uid,
            summary: $source->summary,
            description: $source->description,
            location: $source->location,
            startsAt: self::date($source->startsAt),
            endsAt: self::date($source->endsAt),
            allDay: $source->allDay,
            startIsDate: $source->startIsDate,
            endIsDate: $source->endIsDate,
            startIsFloating: $source->startIsFloating,
            endIsFloating: $source->endIsFloating,
            lastDay: self::date($source->lastDay),
            duration: $source->duration === null ? null : clone $source->duration,
            timestamp: self::date($source->timestamp),
            createdAt: self::date($source->createdAt),
            lastModifiedAt: self::date($source->lastModifiedAt),
            status: $source->status,
            classification: $source->classification,
            priority: $source->priority,
            recurrenceId: self::date($source->recurrenceId),
            recurrenceIdIsDate: $source->recurrenceIdIsDate,
            recurrenceIdIsFloating: $source->recurrenceIdIsFloating,
            sequence: $source->sequence,
            url: $source->url,
            organizer: $source->organizer,
            attendees: $source->attendees->map(self::attendee(...)),
            alarms: $source->alarms->map(self::alarm(...)),
            categories: collect($source->categories->all()),
            geo: $source->geo,
            transparency: $source->transparency,
            comments: collect($source->comments->all()),
            contacts: collect($source->contacts->all()),
            resources: collect($source->resources->all()),
            recurrenceRule: $source->recurrenceRule === null ? null : self::property($source->recurrenceRule),
            attachments: $source->attachments->map(self::property(...)),
            exceptionDates: $source->exceptionDates->map(self::property(...)),
            requestStatuses: $source->requestStatuses->map(self::property(...)),
            relatedTo: $source->relatedTo->map(self::property(...)),
            recurrenceDates: $source->recurrenceDates->map(self::property(...)),
            propertyItems: \array_values($source->properties()->map(self::property(...))->all()),
            component: $source->rawComponent(),
        );
    }

    /** Return a detached todo with the existing public field types. */
    public static function todo(Todo $source): Todo
    {
        return new Todo(
            uid: $source->uid,
            timestamp: self::date($source->timestamp),
            classification: $source->classification,
            completedAt: self::date($source->completedAt),
            createdAt: self::date($source->createdAt),
            description: $source->description,
            startsAt: self::date($source->startsAt),
            startIsDate: $source->startIsDate,
            startIsFloating: $source->startIsFloating,
            dueAt: self::date($source->dueAt),
            dueIsDate: $source->dueIsDate,
            dueIsFloating: $source->dueIsFloating,
            duration: $source->duration === null ? null : clone $source->duration,
            lastModifiedAt: self::date($source->lastModifiedAt),
            location: $source->location,
            organizer: $source->organizer,
            percentComplete: $source->percentComplete,
            priority: $source->priority,
            recurrenceId: self::date($source->recurrenceId),
            recurrenceIdIsDate: $source->recurrenceIdIsDate,
            recurrenceIdIsFloating: $source->recurrenceIdIsFloating,
            sequence: $source->sequence,
            status: $source->status,
            summary: $source->summary,
            url: $source->url,
            attendees: $source->attendees->map(self::attendee(...)),
            categories: collect($source->categories->all()),
            alarms: $source->alarms->map(self::alarm(...)),
            geo: $source->geo,
            comments: collect($source->comments->all()),
            contacts: collect($source->contacts->all()),
            resources: collect($source->resources->all()),
            recurrenceRule: $source->recurrenceRule === null ? null : self::property($source->recurrenceRule),
            attachments: $source->attachments->map(self::property(...)),
            exceptionDates: $source->exceptionDates->map(self::property(...)),
            requestStatuses: $source->requestStatuses->map(self::property(...)),
            relatedTo: $source->relatedTo->map(self::property(...)),
            recurrenceDates: $source->recurrenceDates->map(self::property(...)),
            propertyItems: \array_values($source->properties()->map(self::property(...))->all()),
            component: $source->rawComponent(),
        );
    }

    /** Return a detached journal with the existing public field types. */
    public static function journal(Journal $source): Journal
    {
        return new Journal(
            uid: $source->uid,
            timestamp: self::date($source->timestamp),
            classification: $source->classification,
            createdAt: self::date($source->createdAt),
            startsAt: self::date($source->startsAt),
            startIsDate: $source->startIsDate,
            startIsFloating: $source->startIsFloating,
            lastModifiedAt: self::date($source->lastModifiedAt),
            organizer: $source->organizer,
            recurrenceId: self::date($source->recurrenceId),
            recurrenceIdIsDate: $source->recurrenceIdIsDate,
            recurrenceIdIsFloating: $source->recurrenceIdIsFloating,
            sequence: $source->sequence,
            status: $source->status,
            summary: $source->summary,
            url: $source->url,
            recurrenceRule: $source->recurrenceRule === null ? null : self::property($source->recurrenceRule),
            attachments: $source->attachments->map(self::property(...)),
            attendees: $source->attendees->map(self::attendee(...)),
            categories: collect($source->categories->all()),
            comments: collect($source->comments->all()),
            contacts: collect($source->contacts->all()),
            descriptions: collect($source->descriptions->all()),
            exceptionDates: $source->exceptionDates->map(self::property(...)),
            relatedTo: $source->relatedTo->map(self::property(...)),
            recurrenceDates: $source->recurrenceDates->map(self::property(...)),
            requestStatuses: $source->requestStatuses->map(self::property(...)),
            propertyItems: \array_values($source->properties()->map(self::property(...))->all()),
            component: $source->rawComponent(),
        );
    }

    /** Return a detached alarm with the existing public field types. */
    public static function alarm(Alarm $source): Alarm
    {
        return new Alarm(
            action: $source->action,
            trigger: $source->trigger,
            description: $source->description,
            summary: $source->summary,
            attendees: $source->attendees->map(self::attendee(...)),
            attachments: $source->attachments->map(self::property(...)),
            repeat: $source->repeat,
            duration: $source->duration === null ? null : clone $source->duration,
            propertyItems: \array_values($source->properties()->map(self::property(...))->all()),
            component: $source->rawComponent(),
        );
    }

    /** Return a detached attendee with the existing public field types. */
    public static function attendee(Attendee $source): Attendee
    {
        return new Attendee(
            address: $source->address,
            email: $source->email,
            name: $source->name,
            role: $source->role,
            status: $source->status,
            rsvp: $source->rsvp,
            type: $source->type,
            delegatedFrom: collect($source->delegatedFrom->all()),
            delegatedTo: collect($source->delegatedTo->all()),
            parameterItems: $source->parameters(),
        );
    }

    /** Copy one property, preserving its normalized and raw representations. */
    public static function property(Property $source): Property
    {
        $value = $source->value;

        if ($value instanceof DateInterval || $value instanceof CarbonImmutable) {
            $value = clone $value;
        } elseif (\is_array($value) && \array_is_list($value)) {
            $value = \array_map(self::atom(...), $value);
        }

        return new Property(
            name: $source->name,
            type: $source->type,
            value: $value,
            values: \array_map(self::atom(...), $source->values),
            parameterItems: $source->parameters(),
            rawValue: $source->rawValue(),
        );
    }

    /** Rebuild a generic tree around one cloned raw subtree, never one clone per level. */
    public static function component(Component $source, ?SabreComponent $raw = null): Component
    {
        $raw ??= $source->rawComponent();
        $positions = [];
        $nodes = [];
        $children = [];

        foreach ($raw->children() as $node) {
            if ($node instanceof SabreComponent) {
                $nodes[$node->name][] = $node;
            }
        }

        foreach ($source->components() as $child) {
            $position = $positions[$child->name] ?? 0;
            $node = $nodes[$child->name][$position] ?? null;

            if (! $node instanceof SabreComponent) {
                throw new LogicException('The generic snapshot and its raw tree disagree.');
            }

            $positions[$child->name] = $position + 1;
            $children[] = self::component($child, $node);
        }

        return new Component(
            name: $source->name,
            propertyItems: \array_values($source->properties()->map(self::property(...))->all()),
            componentItems: $children,
            component: $raw,
        );
    }

    /** Copy a date because CarbonImmutable instance settings remain mutable. */
    private static function date(?CarbonImmutable $value): ?CarbonImmutable
    {
        return $value === null ? null : clone $value;
    }

    /**
     * Copy interval and date settings; structured string arrays are copied by value.
     *
     * @param  PropertyAtom  $value
     * @return PropertyAtom
     */
    private static function atom(bool|int|float|string|CarbonImmutable|DateInterval|array $value): bool|int|float|string|CarbonImmutable|DateInterval|array
    {
        return $value instanceof DateInterval || $value instanceof CarbonImmutable ? clone $value : $value;
    }
}
