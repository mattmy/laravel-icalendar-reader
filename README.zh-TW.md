# Laravel iCalendar Reader

[English](README.md)

在 Laravel 讀取並驗證 `.ics` 行事曆，再透過 `ICalendar` 查詢事件、待辦、日期、參與者、
提醒、recurrence 資料、properties 與 components。

## 特色

- 從完整字串、本機檔案、stream 或 Laravel 上傳檔案讀取資料，並以可設定的 bytes 上限
  控制輸入大小。
- 依文件順序、完全符合的 UID 或日期範圍查詢事件與待辦。
- 以 `CarbonImmutable`、`DateInterval` 與 Laravel Collections 取得常用行事曆欄位。
- 取得主辦人、參與者、委派資料、提醒、recurrence 資料，以及全天與 floating time 行為。
- 透過 `Property` 與 `Component` 取得重複、自訂及非事件資料。
- 選擇讓不合法內容拋出例外或回傳 `null`，並在成功讀取後查看結構化 warnings。
- 將結果轉成 array、JSON 或完整的 property 與 component 階層。

## 系統需求

| 需求 | 宣告支援 | CI 持續實測 |
| --- | --- | --- |
| PHP | PHP 8.x 系列的 8.3 以上版本 | 8.3、8.4、8.5 |
| Laravel | 11、12、13 | 11、12、13 |
| PHP extensions | DOM、JSON、Multibyte String、XMLReader、XMLWriter | Composer 安裝時檢查 |
| libxml | 2.6.20 以上版本 | Composer 安裝時檢查 |

PHP extensions 與 libxml 需求來自 Sabre/VObject 5 和 Sabre/XML。

## 安裝

```bash
composer require mattmy/laravel-icalendar-reader
```

## 設定

套件安裝後可直接使用以下預設值：

- `max_bytes`：每次最多接受 10 MiB 輸入。
- `floating_timezone`：沒有 `Z` 或 `TZID` 的 date-time 使用 `app.timezone`。

需要調整時再發布 `config/icalendar_reader.php`：

```bash
php artisan vendor:publish --tag=icalendar-reader-config
```

## 快速開始

```php
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

依輸入來源選擇方法：

```php
$calendar = ICalendar::read($contents);
$calendar = ICalendar::fromPath($path);
$calendar = ICalendar::fromStream($stream);
$calendar = ICalendar::fromUploadedFile($uploadedFile);
```

每個方法都回傳相同的 `Calendar` 類型。對應的 `try*()` 方法會在 iCalendar 內容不合法時
回傳 `null`；來源、大小與設定錯誤仍會拋出各自的例外。

```php
$events = $calendar->events('event@example.test');
$event = $calendar->event('event@example.test');
$todos = $calendar->todos();
$eventsInRange = $calendar->eventsBetween($from, $until);
$freeBusy = $calendar->component('VFREEBUSY');
$customProperty = $calendar->property('X-CUSTOM');
$json = $calendar->toJson(JSON_PRETTY_PRINT);
```

UID 比對區分大小寫，Collections 會保留文件順序。事件範圍查詢使用 `.ics` 輸入中的事件
components，recurrence rules 與 dates 則保留在 properties 中供應用程式取得。

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

`issues()` 說明內容遭拒絕的原因；`warnings()` 回報成功讀取後仍需留意的內容或設定。

## 效能與安全

請依應用程式接受的最大行事曆調整 `max_bytes`。`fromPath()` 應使用可信任的本機路徑；
由呼叫端建立的 stream 應由呼叫端關閉。顯示行事曆文字前應先轉義、開啟行事曆中的 URL
前應先驗證，也應避免記錄可能包含個人資料的完整輸入或輸出。

## 文件

- [完整文件](https://mattmy.github.io/laravel-icalendar-reader-doc/zh-TW/)
- [Changelog](CHANGELOG.md)
- [安全政策](SECURITY.md)

## 授權

本套件使用 MIT License，詳見 [LICENSE](LICENSE)。
