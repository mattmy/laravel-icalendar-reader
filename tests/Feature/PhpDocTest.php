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

        expect(class_exists($class) || interface_exists($class))->toBeTrue();

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
