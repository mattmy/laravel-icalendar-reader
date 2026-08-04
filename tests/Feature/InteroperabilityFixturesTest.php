<?php

declare(strict_types=1);

use Mattmy\ICalendar\Facades\ICalendar;

it('reads identifiable calendar client interoperability fixtures', function (string $client, string $productId, string $uid) {
    $calendar = ICalendar::fromPath(__DIR__ . "/../Fixtures/clients/{$client}/calendar.ics");

    expect($calendar->productId)->toBe($productId)
        ->and($calendar->events()->sole()->uid)->toBe($uid);
})->with([
    'Google Calendar' => ['google', '-//Google Inc//Google Calendar 70.9054//EN', 'google-client@example.test'],
    'Microsoft Outlook' => ['outlook', 'Microsoft Exchange Server 2010', 'outlook-client@example.test'],
    'Apple Calendar' => ['apple', '-//Apple Inc.//macOS 15.0//EN', 'apple-client@example.test'],
]);

it('preserves unicode, emoji, folding, LF, and case-insensitive names', function () {
    $path = __DIR__ . '/../Fixtures/text/unicode-folded-lf.ics';
    $contents = \file_get_contents($path);
    $calendar = ICalendar::fromPath($path);
    $event = $calendar->events()->sole();

    expect($contents)->not->toBeFalse()
        ->and($contents)->not->toContain("\r\n")
        ->and($event->summary)->toBe('繁體中文行事曆 📅')
        ->and($event->description)->toBe('這是一段需要折行的繁體中文與 emoji 📅 內容，第二行會接續在同一個 DESCRIPTION property。')
        ->and($event->property('x-mattmy-note')?->value)->toBe('保留未知屬性')
        ->and($event->property('X-MATTMY-NOTE')?->parameter('x-level'))->toBe('測試');
});

it('reads the same folded content with CRLF line endings', function () {
    $path = __DIR__ . '/../Fixtures/text/unicode-folded-crlf.ics';
    $contents = \file_get_contents($path);
    $event = ICalendar::fromPath($path)->events()->sole();

    expect($contents)->not->toBeFalse()
        ->and($contents)->toContain("\r\n")
        ->and($event->summary)->toBe('繁體中文行事曆 📅')
        ->and($event->description)->toContain('第二行會接續');
});
