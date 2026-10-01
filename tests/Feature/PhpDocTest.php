<?php

declare(strict_types=1);
use Mattmy\ICalendar\Alarm;
use Mattmy\ICalendar\Attendee;
use Mattmy\ICalendar\Event;
use Mattmy\ICalendar\Journal;
use Mattmy\ICalendar\Property;

it('documents every package class and declared method', function () {
    $source = \realpath(__DIR__ . '/../../src');

    expect($source)->not->toBeFalse();

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source));

    foreach ($files as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $relative = \substr($file->getPathname(), \strlen($source) + 1, -4);
        $class = 'Mattmy\\ICalendar\\' . \str_replace(\DIRECTORY_SEPARATOR, '\\', $relative);

        expect(\class_exists($class) || \interface_exists($class) || \trait_exists($class))->toBeTrue();

        $reflection = new ReflectionClass($class);

        expect($reflection->getDocComment())
            ->not->toBeFalse("{$class} must have a class-level PHPDoc comment.");

        foreach ($reflection->getMethods() as $method) {
            if ($method->getDeclaringClass()->getName() !== $class) {
                continue;
            }

            expect($method->getDocComment())
                ->not->toBeFalse("{$class}::{$method->getName()}() must have a PHPDoc comment.");
        }
    }
});

it('documents promoted public properties whose native types need refinement', function () {
    $properties = [
        [Property::class, 'value'],
        [Property::class, 'values'],
        [Event::class, 'endsAt'],
        [Event::class, 'allDay'],
        [Event::class, 'startIsFloating'],
        [Event::class, 'endIsFloating'],
        [Event::class, 'lastDay'],
        [Event::class, 'duration'],
        [Event::class, 'attendees'],
        [Event::class, 'alarms'],
        [Event::class, 'categories'],
        [Journal::class, 'startIsDate'],
        [Journal::class, 'startIsFloating'],
        [Journal::class, 'recurrenceIdIsDate'],
        [Journal::class, 'recurrenceIdIsFloating'],
        [Journal::class, 'attachments'],
        [Journal::class, 'attendees'],
        [Journal::class, 'categories'],
        [Journal::class, 'comments'],
        [Journal::class, 'contacts'],
        [Journal::class, 'descriptions'],
        [Attendee::class, 'delegatedFrom'],
        [Attendee::class, 'delegatedTo'],
        [Alarm::class, 'attendees'],
        [Alarm::class, 'duration'],
    ];

    foreach ($properties as [$class, $property]) {
        $reflection = new ReflectionProperty($class, $property);

        expect($reflection->getDocComment())
            ->not->toBeFalse("{$class}::\${$property} must document its refined type or semantics.");
    }
});
