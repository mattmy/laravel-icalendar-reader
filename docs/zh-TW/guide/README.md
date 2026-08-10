# Laravel iCalendar Reader 使用指南

[English](../../guide/README.md)

英文文件是本套件的權威版本。

## 安裝與讀取

```bash
composer require mattmy/laravel-icalendar-reader
```

```php
use Mattmy\ICalendar\Facades\ICalendar;

$calendar = ICalendar::read($contents);
$calendar = ICalendar::fromPath($path);
$calendar = ICalendar::fromStream($stream);
$calendar = ICalendar::fromUploadedFile($uploadedFile);
```

每種來源都有對應的 `try*()`。只有 iCalendar 內容不合法時才回傳 `null`；來源、
大小及設定錯誤仍會拋出例外。

## Calendar 與 Event

`Calendar` 提供 metadata、`events()`、`event($uid)`、`hasEvents()`、
`eventsBetween()`、properties、components 與 warnings。`Event` 提供 UID、標題、
描述、地點、開始與結束時間、effective duration、timestamps、狀態、分類、優先度、
序號、URL、organizer、attendees、alarms 與 categories。Readonly `allDay` property
與 `isAllDay()` 回傳相同結果；可使用任一方式判斷全天事件，不要以午夜或持續時間猜測。

傳入 UID 至 `events($uid)`，會依文件順序回傳所有完全相符且區分大小寫的事件，
包括 recurrence overrides。傳入 `null` 會回傳全部事件；沒有相符項目則回傳空
Collection。`hasEvents($uid)` 採用相同規則；`event($uid)` 則依文件所述的
recurrence 選擇規則回傳單一事件。

## 完整資料存取

`properties()` 依文件順序保留直接 properties，包括重複項目、parameters、多值、
recurrence 與未知 `X-*` 資料。`components()` 保留 `VTODO`、`VJOURNAL`、
`VFREEBUSY`、`VTIMEZONE` 與未知 components，而且不會遞迴攤平結構。
使用 `component($name)` 取得第一個直接匹配項目，使用 `hasComponent($name)`
檢查是否存在；兩者皆不區分名稱大小寫，也不會遞迴搜尋。

`toArray()`／`toJson()` 輸出 domain read model；`toComponentArray()` 輸出完整
normalized component tree。這些方法不會產生 `.ics`，也不保證 byte round-trip。

## 日期與時區

- UTC 與可解析的 `TZID` 會保留原時區。
- Floating time 在 `icalendar_reader.floating_timezone` 是有效 IANA 時區時使用該值；
  未設定時才使用有效的 `app.timezone`，否則 fallback 至 UTC。若 package override
  已設定但無效，會直接 fallback 至 UTC，即使 `app.timezone` 有效也不會改用它。
- 文件中的 `TZID` 無法解析時不會偷換 UTC；typed value 為 `null`、Property 保留
  原值，Calendar 並產生 `mapping_warning`。
- 全天 `DTEND` 保持 exclusive，`lastDay` 是 inclusive convenience date。
- `duration` 表示 effective duration；同時有 `DTEND` 與 `DURATION` 時以
  `DTEND` 為準。
- `eventsBetween()` 使用 half-open interval，且不展開 recurrence。

## 驗證與例外

每次輸入都使用嚴格 Sabre options 解析並完整 validation。Level 2 issue 由
`warnings()` 回傳；level 3 代表文件不合法。

`read*()` 對不合法內容拋出 `InvalidCalendar`；`try*()` 只把該例外轉成 `null`。
其他公開例外包括 `CalendarFileNotFound`、`CalendarFileUnreadable`、
`CalendarTooLarge`、`InvalidCalendarSource` 與 `InvalidConfiguration`。

## 設定、安全與版本

`max_bytes` 預設為 10 MiB；`floating_timezone` 預設使用 `app.timezone`。套件不下載
URL、不信任 upload MIME type，也不關閉呼叫端建立的 stream。應用程式仍須驗證
upload 並授權本機路徑，而且不應記錄可能包含個資的完整 calendar。

套件遵循 Semantic Versioning；公開 API、例外、warning code、日期解讀與固定輸出
shape 都屬於相容性契約，版本差異記錄於 `CHANGELOG.md`。
