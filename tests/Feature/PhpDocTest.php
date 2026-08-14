<?php

declare(strict_types=1);

it('documents every package class and declared method', function () {
    $source = realpath(__DIR__ . '/../../src');

    expect($source)->not->toBeFalse();

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source));

    foreach ($files as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $relative = substr($file->getPathname(), strlen($source) + 1, -4);
        $class = 'Mattmy\\ICalendar\\' . str_replace(DIRECTORY_SEPARATOR, '\\', $relative);

        expect(class_exists($class) || interface_exists($class) || trait_exists($class))->toBeTrue();

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
        [Mattmy\ICalendar\Property::class, 'value'],
        [Mattmy\ICalendar\Property::class, 'values'],
        [Mattmy\ICalendar\Event::class, 'endsAt'],
        [Mattmy\ICalendar\Event::class, 'allDay'],
        [Mattmy\ICalendar\Event::class, 'startIsFloating'],
        [Mattmy\ICalendar\Event::class, 'endIsFloating'],
        [Mattmy\ICalendar\Event::class, 'lastDay'],
        [Mattmy\ICalendar\Event::class, 'duration'],
        [Mattmy\ICalendar\Event::class, 'attendees'],
        [Mattmy\ICalendar\Event::class, 'alarms'],
        [Mattmy\ICalendar\Event::class, 'categories'],
        [Mattmy\ICalendar\Journal::class, 'startIsDate'],
        [Mattmy\ICalendar\Journal::class, 'startIsFloating'],
        [Mattmy\ICalendar\Journal::class, 'recurrenceIdIsDate'],
        [Mattmy\ICalendar\Journal::class, 'recurrenceIdIsFloating'],
        [Mattmy\ICalendar\Journal::class, 'attachments'],
        [Mattmy\ICalendar\Journal::class, 'attendees'],
        [Mattmy\ICalendar\Journal::class, 'categories'],
        [Mattmy\ICalendar\Journal::class, 'comments'],
        [Mattmy\ICalendar\Journal::class, 'contacts'],
        [Mattmy\ICalendar\Journal::class, 'descriptions'],
        [Mattmy\ICalendar\Attendee::class, 'delegatedFrom'],
        [Mattmy\ICalendar\Attendee::class, 'delegatedTo'],
        [Mattmy\ICalendar\Alarm::class, 'attendees'],
        [Mattmy\ICalendar\Alarm::class, 'duration'],
    ];

    foreach ($properties as [$class, $property]) {
        $reflection = new ReflectionProperty($class, $property);

        expect($reflection->getDocComment())
            ->not->toBeFalse("{$class}::\${$property} must document its refined type or semantics.");
    }
});
