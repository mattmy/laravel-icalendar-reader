# Laravel iCalendar Reader

[English](README.md)

在 Laravel 透過 `ICalendar` Facade 讀取並驗證 `.ics` 行事曆。

## 特色

- 依 UID 查詢事件、待辦和日誌，也可依日期範圍查詢並展開重複事件。
- 以 `CarbonImmutable` 取得日期、以 `DateInterval` 取得時長。主辦人、參與者、委派資料與提醒也有對應欄位。
- 透過 `Property` 和 `Component` 取得重複值、擴充屬性與 `VFREEBUSY` 等元件。
- 將常用欄位匯出為陣列或 JSON，或取得完整的屬性與元件階層。

## 系統需求

| 需求 | 宣告支援 | CI 持續實測 |
| --- | --- | --- |
| PHP | PHP 8.x 系列的 8.2 以上版本 | 8.2、8.3、8.4、8.5 |
| Laravel | 11、12、13 | 11、12、13 |
| PHP 擴充模組 | DOM、JSON、Multibyte String、XMLReader、XMLWriter | Composer 安裝時檢查 |
| libxml | 2.6.20 以上版本 | Composer 安裝時檢查 |

PHP 擴充模組 與 libxml 需求來自 Sabre/VObject 5 和 Sabre/XML。

## 安裝

```bash
composer require mattmy/laravel-icalendar-reader
```

## 設定

- `max_bytes` 預設限制每次輸入最多 10 MiB。
- `floating_timezone` 預設為 `null`。DATE 值和沒有 `Z` 或 `TZID` 的日期時間會使用有效的
  `app.timezone`。若 `app.timezone` 無效，則使用 UTC。

如需調整設定，可發布 `config/icalendar_reader.php`。

```bash
php artisan vendor:publish --tag=icalendar-reader-config
```

## 快速開始

```php
use Mattmy\ICalendar\Facades\ICalendar;

$calendar = ICalendar::read(<<<'ICS'
BEGIN:VCALENDAR
VERSION:2.0
PRODID:-//Example//Calendar//EN
BEGIN:VEVENT
UID:meeting@example.test
DTSTAMP:20260803T000000Z
DTSTART:20260810T090000Z
SUMMARY:Project meeting
END:VEVENT
END:VCALENDAR
ICS);

$event = $calendar->events()->sole();

echo $event->summary; // Project meeting
echo $event->startsAt?->toIso8601String(); // 2026-08-10T09:00:00+00:00
```

## 讀取行事曆資料

依輸入來源選一個方法。

```php
use Mattmy\ICalendar\Facades\ICalendar;

$calendar = ICalendar::read($contents);
$calendar = ICalendar::fromPath($path);
$calendar = ICalendar::fromStream($stream);
$calendar = ICalendar::fromUploadedFile($uploadedFile);
```

四個方法讀取成功後都會回傳 `Calendar`。若希望內容不合法時回傳 `null`，可改用對應的
`try*()` 方法，例如 `tryFromPath()`。來源、大小與設定錯誤仍會拋出各自的例外。

```php
$events = $calendar->events('event@example.test');
$event = $calendar->event('event@example.test');
$todos = $calendar->todos();
$journals = $calendar->journals();
$journal = $calendar->journal('entry@example.test');
$eventsInRange = $calendar->eventsBetween($from, $until);
$occurrences = $calendar->occurrencesBetween($from, $until);
$freeBusy = $calendar->component('VFREEBUSY');
$customProperty = $calendar->property('X-CUSTOM');
$json = $calendar->toJson(JSON_PRETTY_PRINT);
```

UID 比對區分大小寫。`events()`、`todos()` 和 `journals()` 依行事曆原始順序回傳結果。
`eventsBetween()` 查詢行事曆原有的事件。`occurrencesBetween()` 還會展開重複事件，包含
`RDATE;VALUE=PERIOD`，並依開始時間排序。查詢評估超過 3,500 個候選時，會拋出
`RecurrenceLimitExceeded`。

`eventsBetween()` 無法確認必要的結束時間時，會拋出 `UnresolvableEventRange`。
`occurrencesBetween()` 中的非重複事件也適用。若無法解析展開所需的日期，則會拋出
`UnsupportedRecurrence`。

`TZID` 日期會使用行事曆內相符的 `VTIMEZONE`。

## 驗證與警告

```php
use Mattmy\ICalendar\Exceptions\InvalidCalendar;
use Mattmy\ICalendar\Facades\ICalendar;

try {
    $calendar = ICalendar::read($contents);
    $warnings = $calendar->warnings();
} catch (InvalidCalendar $exception) {
    $issues = $exception->issues();
}
```

每次讀取接受一個 `VCALENDAR`。收到 `InvalidCalendar` 時，用 `issues()` 查看內容遭拒絕的原因。
讀取成功後，可用 `warnings()` 查看內容或設定警告。合法的重複規則可以讀取，若套件無法展開該規則，
`occurrencesBetween()` 會拋出 `UnsupportedRecurrence`。

## 效能與安全

套件會將完整行事曆載入記憶體。請依應用程式需求設定 `max_bytes`，並將可信任的本機路徑
傳給 `fromPath()`。自行開啟的串流要自行關閉。顯示行事曆文字前先跳脫，開啟連結前先驗證，
並避免記錄可能含有個人資料的完整行事曆。

## 文件

- [完整文件](https://mattmy.github.io/laravel-icalendar-reader-doc/zh-TW/)
- [Changelog](CHANGELOG.md)
- [安全政策](SECURITY.md)

## 授權

本套件使用 MIT License，詳見 [LICENSE](LICENSE)。
