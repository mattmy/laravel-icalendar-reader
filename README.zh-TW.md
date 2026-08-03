# Laravel iCalendar Reader

[English](README.md)

Laravel 專用、API 簡潔且具型別的 iCalendar reader，底層使用 Sabre/VObject。
英文文件是套件的權威版本。

> 套件仍在開發中，公開 API 尚未穩定。

## 目前完成的開發切片

目前可嚴格解析及驗證字串、本機路徑、stream 與 Laravel UploadedFile，並提供
第一批 Calendar、Event typed fields。`isAllDay()` 只依 `DTSTART` 的 iCalendar
value type 判斷全天，不會以午夜或持續 24 小時猜測。

```php
use Mattmy\ICalendar\Facades\ICalendar;

$calendar = ICalendar::read($contents);
$event = $calendar->events()->first();

if ($event?->isAllDay()) {
    // DTSTART 是 iCalendar DATE value。
}
```

`read*()` 遇到不合法內容會拋出 `InvalidCalendar`。對應的 `try*()` 只在內容不是
合法 iCalendar 時回傳 `null`，來源、大小與設定錯誤仍會拋出。

產生 `.ics`、遠端 URL 下載、CalDAV 與 recurrence expansion 明確不在 1.0 範圍。

## 授權

本套件使用 MIT License，詳見 [LICENSE](LICENSE)。
