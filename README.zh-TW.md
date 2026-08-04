# Laravel iCalendar Reader

[English](README.md)

透過簡潔、具型別的 Laravel API 讀取、驗證及查詢 `.ics`。底層由 Sabre/VObject 處理 RFC parsing，套件則提供唯讀物件、Collection、結構化錯誤與安全輸入邊界。英文文件是權威版本。

> 套件目前仍是 0.x 開發版本，公開 API 在 1.0 前可能調整。

## 安裝

```bash
composer require mattmy/laravel-icalendar-reader
```

Laravel 會自動發現 Service Provider 與 `ICalendar` Facade。需要調整設定時可發布設定檔：

```bash
php artisan vendor:publish --tag=icalendar-reader-config
```

## 快速開始

```php
use Mattmy\ICalendar\Facades\ICalendar;

$calendar = ICalendar::read($contents);

foreach ($calendar->events() as $event) {
    $event->summary;
    $event->startsAt;
    $event->endsAt;
    $event->isAllDay();
    $event->organizer;
    $event->attendees;
    $event->alarms;
}
```

支援 iCalendar 字串、本機路徑、由呼叫端管理的 stream 與 Laravel `UploadedFile`：

```php
$calendar = ICalendar::read($contents);
$calendar = ICalendar::fromPath($path);
$calendar = ICalendar::fromStream($stream);
$calendar = ICalendar::fromUploadedFile($request->file('calendar'));
```

所有來源都使用相同的嚴格解析與驗證管線。Throwing methods 遇到不合法內容會拋出 `InvalidCalendar`；對應的 `try*()` 只在內容不合法時回傳 `null`。檔案、stream、upload、大小及設定錯誤仍會拋出例外。

## 查詢與完整資料

```php
$event = $calendar->event($uid);
$events = $calendar->eventsBetween($from, $until);
$freeBusy = $calendar->component('VFREEBUSY');
$hasTodos = $calendar->hasComponent('VTODO');
$periods = $freeBusy?->properties('FREEBUSY');
$fbType = $periods?->first()?->parameter('FBTYPE');

$calendar->toArray();
$calendar->toJson();
$calendar->toComponentArray();
```

重複 properties、parameters、多值、recurrence properties、`VTODO`、`VJOURNAL`、`VFREEBUSY`、`VTIMEZONE`、廠商欄位及未知 components，都會保留在 `Property` 與 generic `Component` API。

## 時間語意

- UTC 與帶 `TZID` 的日期時間會保留時區。
- Floating time 依序使用 `icalendar_reader.floating_timezone` 與 `app.timezone`。
- 無效時區設定會 fallback 至 UTC，並在 Calendar 產生 warning。
- `isAllDay()` 依 `DTSTART` value type 判斷，不以午夜或 duration 猜測。
- 全天 `DTEND` 保持 exclusive；`lastDay` 提供 inclusive convenience date。

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

## 限制與安全

套件不產生 calendar、不下載遠端 URL、不實作 CalDAV、不儲存資料，也不展開 recurrence occurrences。`eventsBetween()` 只查詢文件中實際存在的 `VEVENT`。

所有輸入在解析前都受 `icalendar_reader.max_bytes` 限制。`fromPath()` 只接受可讀取的本機一般檔案，stream 仍由呼叫端管理，錯誤訊息不包含完整 calendar。Calendar 可能包含個人資料，預設不應記錄原始輸入或完整解析輸出。

## 授權

本套件使用 MIT License，詳見 [LICENSE](LICENSE)。

完整 API、例外、時區及版本契約請參考[繁體中文指南](docs/zh-TW/guide/README.md)。
