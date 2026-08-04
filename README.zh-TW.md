# Laravel iCalendar Reader

[English](README.md)

透過符合 Laravel 使用習慣的 API 讀取 `.ics` 行事曆。你可以直接取得事件、日期、
全天狀態、主辦人、參與者、提醒、properties 與 components，不必自己處理原始
iCalendar 文字。

## 可以做什麼

- 從字串、本機檔案、stream 或 Laravel 上傳檔案讀取 `.ics`。
- 取得全部事件，或依 UID、日期範圍查詢事件。
- 取得事件日期、持續時間、地點、狀態、分類及其他常用欄位。
- 使用 `$event->allDay` 或 `$event->isAllDay()` 確認是否為全天事件。
- 取得主辦人、參與者、委派資料、提醒及提醒時間。
- 讀取 `VTODO`、`VJOURNAL`、`VFREEBUSY`、`VTIMEZONE`、自訂欄位及未知 components。
- 自己選擇不合法內容要拋出例外或回傳 `null`。
- 取得行事曆中需要留意的警告。
- 將行事曆轉成 array、JSON 或完整的 component 階層。

本套件只負責讀取，不會產生 `.ics`，也不會把重複事件展開成每一次發生的事件。

## 安裝

```bash
composer require mattmy/laravel-icalendar-reader
```

## 快速開始

```php
use Mattmy\ICalendar\Facades\ICalendar;

$calendar = ICalendar::fromUploadedFile($request->file('calendar'));

foreach ($calendar->events() as $event) {
    echo $event->summary;
    echo $event->startsAt?->toDateTimeString();
    echo $event->endsAt?->toDateTimeString();

    if ($event->allDay) {
        echo '全天';
    }
}
```

## 讀取 `.ics`

```php
$calendar = ICalendar::read($contents);
$calendar = ICalendar::fromPath($path);
$calendar = ICalendar::fromStream($stream);
$calendar = ICalendar::fromUploadedFile($request->file('calendar'));
```

不合法內容希望回傳 `null` 時，使用對應的 `try*()` 方法：

```php
$calendar = ICalendar::tryRead($contents);
$calendar = ICalendar::tryFromPath($path);
$calendar = ICalendar::tryFromStream($stream);
$calendar = ICalendar::tryFromUploadedFile($request->file('calendar'));
```

檔案、stream、upload、大小及設定錯誤仍會拋出對應例外。

## 使用事件資料

```php
$events = $calendar->events();
$eventsWithUid = $calendar->events('event@example.com');
$event = $calendar->event('event@example.com');
$hasEvents = $calendar->hasEvents();
$hasEvent = $calendar->hasEvents('event@example.com');
$eventsInRange = $calendar->eventsBetween($from, $until);
```

`Event` 可以取得：

- `uid`、`summary`、`description`、`location`、`url`
- `startsAt`、`endsAt`、`lastDay`、`duration`
- `allDay`、`startIsFloating`、`endIsFloating`
- `status`、`classification`、`priority`、`sequence`
- `timestamp`、`createdAt`、`lastModifiedAt`
- `organizer`、`attendees`、`alarms`、`categories`

日期使用 `CarbonImmutable`，持續時間使用 `DateInterval`，多筆資料使用 Laravel
Collection。`eventsBetween()` 只回傳 `.ics` 內實際存在的事件，不會額外展開重複規則。

## 主辦人、參與者與提醒

```php
$organizer = $event->organizer;
$organizer?->email;
$organizer?->name;

foreach ($event->attendees as $attendee) {
    $attendee->email;
    $attendee->name;
    $attendee->role;
    $attendee->status;
    $attendee->rsvp;
}

foreach ($event->alarms as $alarm) {
    $alarm->action;
    $alarm->description;
    $alarm->trigger?->duration();
    $alarm->trigger?->dateTime();
}
```

## Properties 與 components

需要的資料沒有專用 Event 欄位時，可以從 properties 與 components 取得。

```php
$summaryProperty = $event->property('SUMMARY');
$rules = $event->properties('RRULE');
$hasLocation = $event->hasProperty('LOCATION');

$freeBusy = $calendar->component('VFREEBUSY');
$todos = $calendar->components('VTODO');
$hasTimezones = $calendar->hasComponent('VTIMEZONE');

$periods = $freeBusy?->properties('FREEBUSY');
$busyType = $periods?->first()?->parameter('FBTYPE');
```

Property 與 component 名稱不區分大小寫。`property()`、`component()` 取得第一筆；
`properties()`、`components()` 取得所有符合的資料。

## 驗證與警告

```php
use Mattmy\ICalendar\Exceptions\InvalidCalendar;

try {
    $calendar = ICalendar::read($contents);
} catch (InvalidCalendar $exception) {
    $issues = $exception->issues();
}

$warnings = $calendar->warnings();
```

內容不合法而被拒絕時，可由 `issues()` 取得原因。成功讀取後，可由 `warnings()` 取得
仍需留意的內容或設定問題。

## Array 與 JSON

```php
$data = $calendar->toArray();
$json = $calendar->toJson(JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
$tree = $calendar->toComponentArray();
```

`toArray()` 與 `toJson()` 包含常用 Calendar 與 Event 資料；`toComponentArray()` 包含
完整的 property 與 component 階層，也包含非事件及自訂資料。

## 限制與安全

輸入大小受 `icalendar_reader.max_bytes` 限制。行事曆欄位可能包含個人資料、使用者提供
的文字與 URL，因此顯示前應做好輸出轉義、使用連結前先檢查，也應避免記錄完整內容。

所有方法、欄位、參數、例外、時區及效能注意事項請參考
[完整文件](https://mattmy.github.io/laravel-icalendar-reader-doc/zh-TW/)。

## 授權

本套件使用 MIT License，詳見 [LICENSE](LICENSE)。
