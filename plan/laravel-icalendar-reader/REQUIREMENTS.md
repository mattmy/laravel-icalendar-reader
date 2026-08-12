# Laravel iCalendar Reader 產品需求與設計決策書

## 1. 文件資訊

| 項目 | 內容 |
| --- | --- |
| 套件名稱 | `laravel-icalendar-reader` |
| Composer package | `mattmy/laravel-icalendar-reader` |
| Namespace | `Mattmy\ICalendar` |
| 產品類型 | Laravel 專用 PHP 套件 |
| 核心用途 | 讀取與查詢 iCalendar（`.ics`）資料 |
| 底層解析引擎 | `sabre/vobject` |
| 文件狀態 | 需求基準，實作中 |

本文定義 `laravel-icalendar-reader` 第一個穩定版本的產品目標、公開
API、資料語意、錯誤處理、相容範圍與驗收條件。

除非後續決策紀錄明確修改，本文的「必須」代表穩定版發布前必須完成；
「可以」或「未來」代表不阻擋第一個穩定版。

### 1.1 完整性與開發版本標示

本文件描述的是完整 `1.0` 產品契約，不是可以任意挑選的功能清單。分階段開發
只決定實作順序，不降低任何標示為「必須」的驗收要求。

開發中的 commit、branch 或 private package 可以暫時只有部分功能，但必須遵守：

- 尚未實作某個產品 Phase 列出的公開 method 或資料語意時，不得宣稱該 Phase
  完成；尚未符合本文全部 `1.0` 要求時，不得宣稱完整 reader、release candidate
  或穩定版完成。
- 對外提供安裝或請使用者測試前，必須明確標示目前完成的產品 Phase、尚未實作
  的公開 API，以及該版本可能遺失哪些 typed 或 generic views。
- 「可由 Composer 安裝」、「測試通過」與「套件功能完整」是不同狀態；前兩者
  不得被用來暗示需求已全部實作。
- 若只完成技術 spike 或 vertical slice，README、release notes 與進度回報必須
  使用 `experimental`／`incomplete` 等清楚標示，不得只用「目前切片」等可能被
  解讀為可正常使用的模糊文字。
- 只有第 30 節全部符合（包含其引用的第 31 至 33 節補充契約）時，才能宣稱 `1.0`
  完成；只有第 27 節 Phase 1 的所有項目完成時，才能宣稱核心 MVP 完成。

Phase 1 與 Phase 2 的開發版本仍可具有尚未 freeze 的 array／JSON shape；第 18 節
列出的完整固定輸出契約最遲必須在 Phase 3 的 API freeze 前完成。任何 0.x 變更
都必須在 changelog 與測試版本說明中標示，不能讓使用者誤以為已是穩定契約。

其中 `Property` 與 generic `Component` 不是附加功能。它們是避免未知、重複或尚未
typed 的資料被公開 API 隱藏的基礎讀取能力；只有 Event convenience fields 而沒有
這兩個 escape hatches 的版本，不符合本產品「不遺失資料」的核心定位。

## 2. 產品背景

PHP 生態已有可解析 iCalendar 的底層套件，但常見 API 直接反映 RFC 5545
的 component、property、parameter 結構。Laravel 開發者為了取得事件標題、
開始時間、參與者或提醒，往往必須：

1. 理解 iCalendar component tree。
2. 熟悉 Sabre/VObject 的物件與型別轉換方式。
3. 自行處理重複 property。
4. 自行處理 `TZID`、全天日期與 floating date-time。
5. 將結果再次整理成 Collection、Carbon 或應用程式 DTO。

本套件不重新實作 RFC parser，而是在 `sabre/vobject` 之上提供穩定、
型別清楚、符合 Laravel 使用習慣的讀取介面。

## 3. 產品定位

一句話定位：

> A clean, typed iCalendar reader for Laravel.

套件的價值是「讓應用程式容易取得正確資料」，而不是讓使用者直接操作
iCalendar 規格樹。

主要使用者：

- 需要匯入 `.ics` 檔案的 Laravel 應用程式。
- 需要讀取行事曆事件、參與者與提醒的後端開發者。
- 不希望直接操作 Sabre/VObject component/property API 的開發者。

## 4. 產品目標

### 4.1 簡潔

最常見流程必須能在數行內完成：

```php
use Mattmy\ICalendar\Facades\ICalendar;

$calendar = ICalendar::read($contents);
$event = $calendar->events()->first();

$event->summary;
$event->startsAt;
$event->attendees;
```

### 4.2 Laravel 原生體驗

- 多筆資料使用 `Illuminate\Support\Collection`。
- 日期時間使用 `Carbon\CarbonImmutable`。
- 支援 `Illuminate\Http\UploadedFile`。
- 支援 package auto-discovery 與 Facade。
- 不使用 Facade 時仍能正常使用所有功能。

### 4.3 正確保留資料

- 不得因簡化 API 而覆蓋重複 property。
- 不得丟棄未知 property、parameter 或 `X-*` 擴充欄位。
- 必須區分 date、date-time、UTC、帶 `TZID` 與 floating date-time。
- 必須保留底層 parser 可提供的 component／property 順序，以及
  parameter、值與型別語意，供進階使用者存取。Parameter 的原始排列
  不屬於承諾範圍，依第 18.3 節的 normalized 輸出契約處理。
- 不宣稱保留原始大小寫、換行、folding 位置或 byte-for-byte 輸入；Sabre
  在 parsing 時可能正規化這些表示細節。

### 4.4 可預期

- 同一份輸入在相同套件設定與宣告支援的依賴版本下，必須產生相同的
  物件結構與值。
- 不得依內容猜測某個字串究竟是路徑、URL 或 iCalendar 內容。
- 每次讀取都必須執行完整 iCalendar validation，不能只依賴 parser 的基本
  語法檢查。
- `read*()` 遇到不合法 iCalendar 必須拋出套件例外。
- `try*()` 遇到不合法 iCalendar 必須回傳 `null`。
- 不得無聲修正輸入內容。
- 合法但可能有互通性問題的內容必須保留 validation warning。

### 4.5 IDE 與靜態分析友善

- 公開類別、方法與 properties 必須宣告原生型別。
- 套件自行宣告的每個 method 必須具有描述責任或結果的 PHPDoc；詳細範圍與
  驗收標準依第 24.6 節。
- Collection methods 與 properties 必須提供 PHPDoc generic；已知 list、map 與
  固定結構陣列必須提供精確 PHPDoc type 或 array shape。
- 公開 API 的正常失敗路徑必須使用 `@throws` 表達，且 throwing／nullable API
  的差異要能從 IDE 直接理解。
- 公開 domain data objects 必須使用 immutable／readonly 設計。
- 不要求使用者閱讀 Sabre/VObject 原始碼才能理解回傳型別。

## 5. 非目標

第一個穩定版明確不提供：

- iCalendar generator 或 builder。
- 修改既有 calendar 後重新輸出 `.ics`。
- `toIcs()` 或其他輸出 iCalendar／`.ics` 的 serialization API；第 18 節的
  domain array／JSON 與 normalized component array 不在此限。
- CalDAV client 或 server。
- Google Calendar、Microsoft 365 或 Apple Calendar API 整合。
- 透過 URL 自動下載遠端 `.ics`。
- Eloquent model、migration 或資料庫同步。
- Queue、scheduled job 或自動匯入流程。
- 郵件邀請寄送與 iTIP workflow。
- UI、Blade component 或前端日曆。
- vCard 讀取。
- 重新實作完整 RFC 5545 parser。

應用程式如需下載遠端檔案，應先透過 Laravel HTTP Client 完成網路請求，
再將回應內容交給本套件。此邊界可避免套件承擔 SSRF、redirect、timeout、
retry 與憑證等網路安全責任。

## 6. 設計原則

### 6.1 常見欄位提供直覺 API

常用事件資料應能直接存取：

```php
$event->uid;
$event->summary;
$event->description;
$event->location;
$event->startsAt;
$event->endsAt;
$event->organizer;
$event->attendees;
$event->alarms;
```

### 6.2 完整 property bag 作為 escape hatch

套件不可能替所有標準與廠商擴充欄位建立專用 accessor，因此每個 component
必須保留通用 property API：

```php
$event->property('SUMMARY');
$event->properties('ATTENDEE');
$event->hasProperty('X-MICROSOFT-CDO-BUSYSTATUS');
$event->properties();
```

`Calendar`、`Event`、`Todo` 與 generic `Component` 的 direct-property 查詢必須遵循
同一套名稱正規化、順序、重複值、Collection isolation 與錯誤契約。實作應集中在
一個標示 `@internal` 的共用行為中，不得讓四個公開類別各自維護可漂移的副本；此
內部共用不建立新的公開 type hierarchy。

### 6.3 重複 property 是合法資料

內部 property storage 必須是有順序且可重複的集合，不得以 property name
作為唯一 array key。

以下兩位參與者必須同時存在：

```ics
ATTENDEE:mailto:one@example.com
ATTENDEE:mailto:two@example.com
```

### 6.4 不暴露 Sabre/VObject 作為主要 API

公開 domain object 不得繼承 Sabre/VObject 類別。這可避免底層版本升級直接
改變套件 API。

進階除錯必須提供明確標示為低階 API 的方法：

```php
$calendar->rawComponent();
$event->rawComponent();
```

這些方法回傳 Sabre/VObject 型別，不承諾跨 major version 相容。
為維持 domain object 的 immutable snapshot 語意，`rawComponent()` 必須回傳
deep clone，而不是 mapper 內部持有的同一個 mutable Sabre instance。修改
clone 不得改變 Calendar、Event 或其他 wrappers 已 hydration 的值。

Domain objects 只由 Reader 建立；其 constructor 不屬於公開 API，必須使用
private constructor，或將 constructor／factory 明確標示 `@internal`，防止使用者
依賴內部建構參數。套件不提供手動建立或修改 calendar 的用途。

本套件的 immutable／readonly 契約是「hydrated snapshot 與來源隔離」，不是宣稱
PHP 8.3 能讓所有巢狀物件 deep-immutable：

- readonly property 不可被重新指派，套件也不提供 domain mutation methods。
- `rawComponent()` 的 clone、其他 domain object 或 mapper 內部狀態不得與公開
  Collection／DateInterval 共用可變 instance。
- `events()`、`properties()`、`components()`、`warnings()` 等方法每次回傳新的
  Collection container；呼叫端對該 Collection 使用 `pop()`、`shift()` 等操作，
  不得改變 domain object 下一次查詢的結果。
- 需求書指定為 readonly public property 的 Collection 或 DateInterval 仍受 PHP
  8.3 shallow readonly 限制；呼叫端可以修改該巢狀物件本身。文件必須明確說明，
  套件只保證此修改不回寫 Sabre tree、其他 domain objects 或重新取得的低階 clone。

### 6.5 不預先建立抽象

- 不為單一 parser 建立可替換 parser interface。
- 不為每一種 property 建立 class。
- 不建立 query builder 取代 Laravel Collection。
- 不為尚未支援的 VJOURNAL 或 VFREEBUSY 建立空殼 API。

只有資料語意、公開邊界或獨立測試需求明確存在時才抽取類別。

## 7. 相容範圍

第一個穩定版正式支援：

| 依賴 | 範圍 |
| --- | --- |
| PHP | `^8.3` |
| Laravel / Illuminate | Laravel 11、12、13 |
| `sabre/vobject` | `^5.0` |

正式發布前必須以 CI matrix 驗證每個宣告支援的 PHP 與 Laravel 組合。
未被 CI 覆蓋的版本不得列為正式支援。

Composer runtime dependencies 必須直接宣告實際出現在公開 API 或執行路徑的
套件，至少包含：

```json
{
    "require": {
        "php": "^8.3",
        "illuminate/contracts": "^11.0|^12.0|^13.0",
        "illuminate/http": "^11.0|^12.0|^13.0",
        "illuminate/support": "^11.0|^12.0|^13.0",
        "nesbot/carbon": "^3.0",
        "sabre/vobject": "^5.0"
    }
}
```

不得只因開發環境使用完整 Laravel 而 require `laravel/framework`。正式版本
範圍仍以 CI 實際可解出的 dependency matrix 為準；若某個宣告組合無法安裝
或通過測試，發布前必須縮小宣告範圍。

套件只正式支援 UTF-8 iCalendar 內容。其他字元編碼即使能被底層 parser
讀取，也不得宣稱為正式支援。

## 8. 安裝與 Laravel 整合

預期安裝方式：

```bash
composer require mattmy/laravel-icalendar-reader
```

套件必須透過 Composer package discovery 自動註冊 service provider 與
Facade alias。

Facade：

```php
use Mattmy\ICalendar\Facades\ICalendar;
```

Facade 只提供方便入口，不得包含無法透過容器注入取得的獨有功能。

可注入的 reader：

```php
use Mattmy\ICalendar\Reader;

final class ImportCalendar
{
    public function __construct(
        private readonly Reader $reader,
    ) {}

    public function handle(string $contents): void
    {
        $calendar = $this->reader->read($contents);
    }
}
```

## 9. 輸入 API

### 9.1 讀取字串內容

```php
$calendar = ICalendar::read($contents);
$calendar = ICalendar::tryRead($contents);
```

`read()` 的字串參數永遠代表 iCalendar 內容，不得自動判斷是否為檔案路徑
或 URL。

兩個方法都必須解析並驗證完整文件：

- `read()` 遇到不合法 iCalendar 時拋出 `InvalidCalendar`。
- `tryRead()` 遇到不合法 iCalendar 時回傳 `null`。
- `tryRead()` 只吸收 `InvalidCalendar`，不得把程式錯誤、大小限制或其他
  來源錯誤偽裝成 `null`。
- 大小使用輸入字串的實際 byte length 計算；超過 `max_bytes` 時拋出
  `CalendarTooLarge`，不得進入 parser。

### 9.2 讀取本機路徑

```php
$calendar = ICalendar::fromPath($path);
$calendar = ICalendar::tryFromPath($path);
```

需求：

- 路徑不存在、不是一般檔案或無法讀取時必須拋出明確例外。
- 不得接受 URL wrapper。
- 可以先用 metadata 快速拒絕過大檔案，但仍必須在實際讀取時累計 bytes；
  不得只信任可能過期的 `filesize()`。
- Symlink 解析後的目標必須是可讀取的一般本機檔案。
- 套件不建立 application path sandbox；呼叫端仍必須授權並限制可傳入的
  路徑。面向終端使用者的原始 request path 不應直接交給此方法。
- `tryFromPath()` 只有在檔案內容不是合法 iCalendar 時回傳 `null`；路徑與
  讀取錯誤仍必須拋出例外。

### 9.3 讀取 stream

```php
$calendar = ICalendar::fromStream($stream);
$calendar = ICalendar::tryFromStream($stream);
```

需求：

- 只接受可讀取 stream resource。
- 不得關閉由呼叫端建立的 stream。
- 從 stream 目前位置開始讀取，成功或失敗後都不承諾還原原始 position。
- 超過大小限制時必須立即停止讀取並拋出例外。
- `tryFromStream()` 只有在內容不是合法 iCalendar 時回傳 `null`。

### 9.4 讀取 Laravel UploadedFile

```php
$calendar = ICalendar::fromUploadedFile($request->file('calendar'));
$calendar = ICalendar::tryFromUploadedFile($request->file('calendar'));
```

需求：

- 接受 `Illuminate\Http\UploadedFile`。
- 必須檢查 upload 是否有效且可讀取。
- UploadedFile 的暫存 backing file 在檢查後、實際開啟前消失時，必須拋出
  `CalendarFileNotFound`；檔案仍存在但無法讀取時拋出
  `CalendarFileUnreadable`。
- 不得只信任 client MIME type 或副檔名。
- 套件負責解析安全；檔案是否為使用者允許上傳，仍由應用程式 validation
  決定。
- `tryFromUploadedFile()` 只有在內容不是合法 iCalendar 時回傳 `null`；
  upload 狀態、讀取與大小錯誤仍必須拋出例外。

### 9.5 不提供多型 `from()`

第一版不提供以下 API：

```php
ICalendar::from($unknown);
```

明確入口可避免 string、path、URL、resource 與 UploadedFile 之間的猜測行為。

## 10. 核心回傳物件

### 10.1 Calendar

`Calendar` 代表一個 `VCALENDAR`。

最低公開資料：

```php
$calendar->version;
$calendar->productId;
$calendar->method;
$calendar->calendarScale;
$calendar->floatingTimezone;
$calendar->events();
$calendar->warnings();
```

最低公開方法：

```php
/** @return Collection<int, Event> */
$calendar->events();
$calendar->events($uid);

$calendar->event($uid);
$calendar->hasEvents();
$calendar->hasEvents($uid);
$calendar->todos();
$calendar->todos($uid);
$calendar->todo($uid);
$calendar->hasTodos();
$calendar->hasTodos($uid);
$calendar->properties();
$calendar->property('X-WR-CALNAME');
$calendar->properties('X-WR-CALNAME');
$calendar->hasProperty();
$calendar->hasProperty('X-WR-CALNAME');
$calendar->components();
$calendar->components('VFREEBUSY');
$calendar->hasComponent();
$calendar->hasComponent('VFREEBUSY');
$calendar->component('VFREEBUSY');
$calendar->warnings();
$calendar->toArray();
$calendar->toComponentArray();
$calendar->toJson();
```

`events(?string $uid = null)` 與 `event(string $uid)`：

- `events(null)` 依文件中的 `VEVENT` component 順序回傳全部事件，包含具有
  `RECURRENCE-ID` 的 recurrence override；第一版不將它們合併或展開。
- `events($uid)` 以 Event 解碼後的 typed `UID` 作精確、大小寫敏感
  比對，並以文件順序回傳所有符合事件；找不到時回傳空 Collection。
- 非 `null` 的 `$uid` 只會與非 `null` 且完全相同的 Event UID 相符。一般讀取流程
  會先依第 16 節拒絕缺少必要 UID 的不合法文件；若 internal mapper-level 測試建立
  UID 為 `null` 的 Event，該 Event 仍不得與任何非 `null` 查詢值相符。
- 空字串是合法查詢值，不 trim、不拋出例外；合法文件沒有完全相同的空 UID 時
  回傳空 Collection。
- 每次呼叫均回傳新的 Collection container，篩選不得修改 Calendar snapshot。
- `event($uid)` 對解碼後 UID 作精確、大小寫敏感比對；空字串找不到事件並
  回傳 `null`。
- 若同一 UID 同時存在 recurrence master 與 overrides，`event($uid)` 優先
  回傳沒有 `RECURRENCE-ID` 的 master。
- 沒有 master 時，`event($uid)` 回傳文件順序中的第一個符合者。
- 所有同 UID components 必須保留，可用 `events($uid)` 取得完整集合。

`hasEvents(?string $uid = null)`：

- `$uid = null` 時，回傳 Calendar 是否至少包含一個 Event。
- 傳入 `$uid` 時，以 Event 解碼後的 typed `UID` 作精確、大小寫敏感
  比對。
- 非 `null` 的 `$uid` 只會與非 `null` 且完全相同的 Event UID 相符；一般讀取流程
  仍會先拒絕缺少必要 UID 的不合法文件。
- 此方法只回答是否存在，不回傳符合事件；其判斷必須與
  `events($uid)->isNotEmpty()` 相同並共用篩選邏輯，不得維護第二套比對規則。

`hasProperty(?string $name = null)`：

- `$name = null` 時，回傳 Calendar 是否至少包含一個直接 property。
- 傳入 `$name` 時，檢查 Calendar 是否包含該名稱的直接 property。
- property name 依 iCalendar 規則採大小寫不敏感比對。
- 不遞迴搜尋 Event 或其他 child component 的 properties。

`components(?string $name = null)`、`hasComponent(?string $name = null)` 與
`component(string $name)`：

- `components(null)` 回傳所有直接 child components；傳入名稱時回傳所有同名
  direct children，並保留文件順序。
- `hasComponent(null)` 檢查是否有任意直接 child component；傳入名稱時檢查
  是否有指定名稱的直接 child component。
- `component($name)` 回傳文件順序中的第一個同名直接 child component；找不到
  時回傳 `null`。
- 名稱以 trim 後的值進行大小寫不敏感比對；非 `null` 的空字串或全空白名稱
  拋出 `InvalidArgumentException`。
- 查詢不遞迴 descendants；呼叫端可透過 generic `Component::components()`
  逐層走訪。

### 10.2 Event

`Event` 代表一個 `VEVENT`。

第一版必須提供：

```php
$event->uid;             // ?string
$event->summary;         // ?string
$event->description;     // ?string
$event->location;        // ?string
$event->startsAt;        // ?CarbonImmutable
$event->endsAt;          // ?CarbonImmutable
$event->allDay;          // bool
$event->startIsDate;     // bool
$event->endIsDate;       // bool
$event->startIsFloating; // bool
$event->endIsFloating;   // bool
$event->lastDay;         // ?CarbonImmutable
$event->duration;        // ?DateInterval
$event->timestamp;       // ?CarbonImmutable (DTSTAMP)
$event->createdAt;       // ?CarbonImmutable (CREATED)
$event->lastModifiedAt;  // ?CarbonImmutable (LAST-MODIFIED)
$event->status;          // ?string
$event->classification;  // ?string
$event->priority;        // ?int
$event->recurrenceId;    // ?CarbonImmutable (RECURRENCE-ID)
$event->recurrenceIdIsDate;     // bool
$event->recurrenceIdIsFloating; // bool
$event->sequence;        // ?int
$event->url;             // ?string
$event->organizer;       // ?Organizer
$event->attendees;       // Collection<int, Attendee>
$event->alarms;          // Collection<int, Alarm>
$event->categories;      // Collection<int, string>
$event->geo;             // ?array{latitude: float, longitude: float}
$event->transparency;    // ?string (TRANSP，標準值 OPAQUE／TRANSPARENT)
$event->comments;        // Collection<int, string>
$event->contacts;        // Collection<int, string>
$event->resources;       // Collection<int, string>
$event->recurrenceRule;  // ?Property (RRULE)
$event->attachments;     // Collection<int, Property>
$event->exceptionDates;  // Collection<int, Property> (EXDATE)
$event->requestStatuses; // Collection<int, Property> (REQUEST-STATUS)
$event->relatedTo;       // Collection<int, Property> (RELATED-TO)
$event->recurrenceDates; // Collection<int, Property> (RDATE)
```

上述欄位補齊 RFC 5545 core VEVENT 中尚未覆蓋、又能以穩定 PHP 型別直接改善讀取
體驗的 convenience API：

- `geo` 將 `GEO` 的兩個 FLOAT 依 wire order 映射為 `latitude`、`longitude`；缺少時
  為 `null`。latitude 必須介於 -90 至 90，longitude 必須介於 -180 至 180；mapper
  不交換座標、不依 locale 改寫小數，也不 clamp 超界值。若 validation 只產生 warning
  而 Calendar 仍成功建立，不是恰好兩個有限且範圍合法的 float 時，typed `geo` 為
  `null`；原 parameters、raw value 與 warning 仍保留。
- `transparency` 只表示明確存在的 `TRANSP` 並正規化為大寫；缺少時為 `null`，但
  RFC 的 effective default 仍是 `OPAQUE`。不得把未指定與明確 `OPAQUE` 合併成同一
  個來源值；標準 token 僅為 `OPAQUE` 或 `TRANSPARENT`。若非標準 token 只造成
  validation warning，typed accessor 保留其 uppercase source token，generic Property
  保留原值，mapper 不自行改成 `OPAQUE`、`TRANSPARENT` 或 `null`。
- `comments`、`contacts` 依同名 property 的文件順序回傳每筆 TEXT；
  每筆 property 恰好對應一個 string，不以逗號再次拆分。`resources` 才依每筆
  property 內的 RFC TEXT-list 順序展平；跨 property 仍保持文件順序。缺少時皆為
  空 `Collection`，且回傳 defensive Collection。
- `recurrenceRule` 直接指向第一筆 `RRULE` 的 immutable `Property` snapshot；缺少時
  為 `null`。`attachments`、`exceptionDates`、`requestStatuses`、`relatedTo` 與
  `recurrenceDates` 則依文件順序回傳 defensive `Collection<int, Property>`。
  這些欄位使用既有 `Property`，是因其 value type、parameters、多值邊界或
  structured value 本身就是公開語意；不得另建一套會丟失資訊的平行 DTO。

#### 10.2.1 RFC 5545 core VEVENT property coverage

以下表格完整盤點 RFC 5545 §3.6.1 的 VEVENT grammar。複合欄位直接重用既有
`Property` snapshot；只有名稱開放且無法預先列舉的 IANA／`X-*` properties 維持
純 generic 查詢。

| RFC property | Cardinality | RFC value type | Event 契約 |
|---|---:|---|---|
| `DTSTAMP` | 必要，1 | DATE-TIME（UTC） | `timestamp: ?CarbonImmutable` |
| `UID` | 必要，1 | TEXT | `uid: ?string` |
| `DTSTART` | 無 `METHOD` 時必要，否則 optional；0..1 | DATE-TIME 或 DATE | `startsAt`、`startIsDate`、`startIsFloating`、`allDay` |
| `CLASS` | 0..1 | TEXT token | `classification: ?string` |
| `CREATED` | 0..1 | DATE-TIME（UTC） | `createdAt: ?CarbonImmutable` |
| `DESCRIPTION` | 0..1 | TEXT | `description: ?string` |
| `GEO` | 0..1 | 兩個 FLOAT（緯度；經度） | 新增 `geo: ?array{latitude: float, longitude: float}` |
| `LAST-MODIFIED` | 0..1 | DATE-TIME（UTC） | `lastModifiedAt: ?CarbonImmutable` |
| `LOCATION` | 0..1 | TEXT | `location: ?string` |
| `ORGANIZER` | 0..1 | CAL-ADDRESS | `organizer: ?Organizer` |
| `PRIORITY` | 0..1 | INTEGER | `priority: ?int` |
| `SEQUENCE` | 0..1 | INTEGER | `sequence: ?int` |
| `STATUS` | 0..1 | TEXT token | `status: ?string` |
| `SUMMARY` | 0..1 | TEXT | `summary: ?string` |
| `TRANSP` | 0..1 | TEXT token | 新增 `transparency: ?string`；缺少時 effective default 為 `OPAQUE` |
| `URL` | 0..1 | URI | `url: ?string` |
| `RECURRENCE-ID` | 0..1 | DATE-TIME 或 DATE | `recurrenceId` 與兩個 value-type flags |
| `RRULE` | 0..*，SHOULD NOT 超過一次 | RECUR | 新增 `recurrenceRule: ?Property`，保留 rule parts、順序與 extension parts |
| `DTEND`／`DURATION` | 各 0..1，彼此互斥 | DATE-TIME／DATE；DURATION | 既有 `endsAt`、end flags、`duration` 與 `lastDay` effective-value 契約 |
| `ATTACH` | 0..* | URI，或 BINARY | 新增 `attachments: Collection<int, Property>`，保留 URI／binary 與 parameters |
| `ATTENDEE` | 0..* | CAL-ADDRESS | `attendees: Collection<int, Attendee>` |
| `CATEGORIES` | 0..* | TEXT list | `categories: Collection<int, string>` |
| `COMMENT` | 0..* | TEXT | 新增 `comments: Collection<int, string>` |
| `CONTACT` | 0..* | TEXT | 新增 `contacts: Collection<int, string>`；ALTREP／LANGUAGE 等 parameters 留 generic |
| `EXDATE` | 0..* | DATE-TIME 或 DATE list | 新增 `exceptionDates: Collection<int, Property>`，保留 value type、TZID 與多值邊界 |
| `REQUEST-STATUS` | 0..* | structured TEXT | 新增 `requestStatuses: Collection<int, Property>`，保留 code、description 與 exception data |
| `RELATED-TO` | 0..* | TEXT | 新增 `relatedTo: Collection<int, Property>`，保留 UID 與 `RELTYPE` |
| `RESOURCES` | 0..* | TEXT list | 新增 `resources: Collection<int, string>` |
| `RDATE` | 0..* | DATE-TIME、DATE 或 PERIOD list | 新增 `recurrenceDates: Collection<int, Property>`，保留 value type、TZID、PERIOD 與多值邊界 |
| IANA／`X-*` | 0..* | 任意註冊／自訂型別 | generic；不得猜測固定 PHP 型別 |
| nested `VALARM` | 0..* child component | VALARM | `alarms: Collection<int, Alarm>`，完整 child 仍保留在 normalized tree |

Cardinality 與允許清單直接來自
[RFC 5545 §3.6.1](https://www.rfc-editor.org/rfc/rfc5545.html#section-3.6.1)。
`GEO` 的 FLOAT pair 與座標語意見
[§3.8.1.6](https://www.rfc-editor.org/rfc/rfc5545.html#section-3.8.1.6)，
`TRANSP` 的 token 與 `OPAQUE` default 見
[§3.8.2.7](https://www.rfc-editor.org/rfc/rfc5545.html#section-3.8.2.7)。
重複文字欄位的型別與用途分別見
[COMMENT §3.8.1.4](https://www.rfc-editor.org/rfc/rfc5545.html#section-3.8.1.4)、
[RESOURCES §3.8.1.10](https://www.rfc-editor.org/rfc/rfc5545.html#section-3.8.1.10) 與
[CONTACT §3.8.4.2](https://www.rfc-editor.org/rfc/rfc5545.html#section-3.8.4.2)。
複合欄位以 `Property` shortcut 保留 value type 與 parameters，詳見
[ATTACH §3.8.1.1](https://www.rfc-editor.org/rfc/rfc5545.html#section-3.8.1.1)、
[recurrence properties §3.8.5](https://www.rfc-editor.org/rfc/rfc5545.html#section-3.8.5)、
[RELATED-TO §3.8.4.5](https://www.rfc-editor.org/rfc/rfc5545.html#section-3.8.4.5) 與
[REQUEST-STATUS §3.8.8.3](https://www.rfc-editor.org/rfc/rfc5545.html#section-3.8.8.3)。

全天判斷同時透過 readonly boolean property 與具名 boolean method 提供：

```php
$event->allDay;     // bool
$event->isAllDay(); // bool
```

`allDay`、`isAllDay()` 與 `startIsDate` 必須永遠回傳相同結果；三者直接使用同一份
hydrated `DTSTART` value-type 判斷，不得維護第二套流程。`endIsDate` 反映明確
`DTEND`，或由 `DURATION`／全天隱含一日推導的 end 語意；無法取得或推導 end 時為
`false`。

Event 的 `recurrenceId`、`recurrenceIdIsDate` 與 `recurrenceIdIsFloating` 和 Todo
採相同轉換規則。沒有 `RECURRENCE-ID` 時 value 為 `null`、flags 為 `false`；若
property 存在但 TZID 無法可靠解析，typed value 為 `null`，generic Property 與
flags 仍保留來源語意。`event($uid)` 判斷 master 必須依 property 是否存在，不得以
nullable typed value 猜測。

缺少 optional property 時使用 `null` 或空 Collection，不得以空字串假裝有值。

若 `UID`、`DTSTART` 或其他 typed accessor 對應的 property 不存在：

- 以 Sabre/VObject validation level 判定文件是否合法。
- level 3 代表不合法，`read*()` 拋出 `InvalidCalendar`，`try*()` 回傳
  `null`。
- level 2 代表文件仍合法，缺少欄位以 `null` 表示，Calendar 必須包含對應
  warning。
- Sabre 沒有回報問題時不得由 mapper 自行假設該 property 在所有情境都必填。

### 10.3 Organizer

```php
$organizer->address;   // string，原始 cal-address
$organizer->email;     // ?string，移除 mailto: 後的 email
$organizer->name;      // ?string，CN parameter
$organizer->sentBy;    // ?string
$organizer->directory; // ?string
$organizer->parameters(); // array<string, string|list<string>>
```

不得假設所有 organizer address 都使用 `mailto:` scheme。

### 10.4 Attendee

```php
$attendee->address;       // string
$attendee->email;         // ?string
$attendee->name;          // ?string
$attendee->role;          // ?string
$attendee->status;        // ?string
$attendee->rsvp;          // ?bool
$attendee->type;          // ?string
$attendee->delegatedFrom; // Collection<int, string>
$attendee->delegatedTo;   // Collection<int, string>
$attendee->parameters();  // array<string, string|list<string>>
```

`role`、`status` 與 `type` 第一版使用保留原值的大寫字串，不使用 closed enum。
這可避免廠商擴充值因 enum 不認得而造成資料遺失。

### 10.5 Alarm

第一版必須支援 `VALARM` 的常見欄位：

```php
$alarm->action;      // ?string
$alarm->trigger;     // ?AlarmTrigger
$alarm->description; // ?string
$alarm->summary;     // ?string
$alarm->attendees;   // Collection<int, Attendee>
$alarm->repeat;      // ?int
$alarm->duration;    // ?DateInterval
```

`trigger` 必須保留「相對 duration」或「絕對 date-time」的差異，不得全部
壓成沒有語意的字串。

第一版必須使用單一小型 value object `AlarmTrigger` 表達：

```php
$alarm->trigger->isRelative();
$alarm->trigger->isAbsolute();
$alarm->trigger->duration();
$alarm->trigger->dateTime();
$alarm->trigger->relatedTo();
```

### 10.6 Property

`Property` 是未知欄位與進階使用情境的共同 API：

```php
$property->name;
$property->type;
$property->value;
$property->values;
$property->parameters();
$property->parameter('TZID');
$property->rawValue();
```

需求：

- `name` 與 parameter name 查詢不區分大小寫。
- 輸出名稱應正規化為大寫。
- `type` 使用小寫 canonical value type 名稱；Sabre 無法辨識時使用
  `unknown`，不得根據內容自行猜測。
- `values` 永遠是依原始順序排列的 list。
- 沒有值時 `value` 為 `null`；一個值時為該 typed value；多個值時為與
  `values` 相同的 list。
- `rawValue()` 回傳 Sabre parser 所提供、尚未轉成套件 typed value 的字串
  表示；不保證與輸入 bytes 完全相同。
- `parameters()` 回傳以大寫名稱為 key 的參數資料；多值 parameter 必須保留
  為 list，不得只留下第一個值。
- 不得將「同名 property 重複出現」與「一個 property 含多個值」混為一談。
- 未知 value type 必須保留原始值。
- `properties(null)` 回傳 component 的所有直接 properties；
  `properties($name)` 回傳所有同名直接 properties。
- `hasProperty(null)` 檢查是否有任意直接 property；`hasProperty($name)`
  檢查是否有指定名稱的直接 property。
- `property($name)` 回傳 component 中第一個同名 property；所有同名項目使用
  `properties($name)` 取得。
- `properties($name)`、`property($name)`、`hasProperty($name)` 與
  `parameter($name)` 的非 `null` 名稱都必須是 trim 後的非空字串；空字串或
  全空白輸入拋出 `InvalidArgumentException`。名稱比對使用 trim 後的值且
  不區分大小寫。

### 10.7 Generic Component

`Component` 是尚未提供 typed domain object 之 component 的唯讀通用 view：

```php
$component->name;
$component->properties();
$component->property('UID');
$component->components();
$component->rawComponent();
```

需求：

- `name` 正規化為大寫。
- Properties 與直接 child components 保留文件順序。
- `properties()`、`hasProperty()` 與 `property()` 遵循第 10.6 節相同語意。
- `components($name)` 的名稱查詢不區分大小寫；`null` 代表所有直接 children。
- 非 `null` 的 component name 必須是 trim 後的非空字串，否則拋出
  `InvalidArgumentException`。
- 不遞迴搜尋 descendants；呼叫端可逐層走訪。
- 不提供 mutation API。

## 11. 日期與時間語意

日期時間是第一版的核心品質要求。

### 11.1 UTC date-time

```ics
DTSTART:20260803T063000Z
```

必須轉成 timezone 為 UTC 的 `CarbonImmutable`。

### 11.2 帶 TZID 的 date-time

```ics
DTSTART;TZID=Asia/Taipei:20260803T143000
```

必須轉成 timezone 為 `Asia/Taipei` 的 `CarbonImmutable`。

### 11.3 Floating date-time

```ics
DTSTART:20260803T143000
```

此值沒有 UTC 或 `TZID`，不得預設為 UTC。

第一版使用解析後的 floating timezone 解讀 floating date-time，並保留以下資訊：

```php
$event->startsAt;
$event->startIsFloating; // true
$event->endIsFloating;   // true when DTEND is also floating
```

`startIsFloating` 與 `endIsFloating` 必須反映原始或推導來源的 property 語意：

- `DTSTART` 不存在時，`startIsFloating` 為 `false`；`VALUE=DATE` 與沒有 `Z`／
  `TZID` 的 DATE-TIME 為 `true`。
- `DTEND` 存在時，`endIsFloating` 反映該 property 的 DATE／DATE-TIME 語意。
- 以 `DURATION` 推算的 `endsAt`，或全天事件因缺少 `DTEND`／`DURATION` 而套用
  隱含一日 duration 時，`endIsFloating` 與 `startIsFloating` 相同。
- 沒有 `DTEND` 且無法推導結束時間時，`endIsFloating` 為 `false`。

Event 與 Todo 對共同時間 properties 使用同一套 flags 定義：

- `*IsDate` 只回答來源或推導值是否具有 `VALUE=DATE` 語意。
- `*IsFloating` 回答 DATE 或沒有 `Z`／`TZID` 的 DATE-TIME 是否依 floating
  timezone 解讀；因此 `*IsDate === true` 時，對應 `*IsFloating` 亦為 `true`。
- 明確 end／due property 存在時，flags 以該 property 為準；由 `DURATION` 推導時
  繼承 start flags；沒有 property 且無法推導時兩個 flags 都是 `false`。
- `RECURRENCE-ID` flags 只反映該 property 自身，不從 `DTSTART` 推導。

套件設定可以覆寫 floating timezone：

```php
'floating_timezone' => null, // null 代表 config('app.timezone')
```

時區解析順序：

1. 每次讀取都先驗證 `config('app.timezone')` 是否為合法 IANA timezone；
   不合法時記錄 `invalid_timezone_configuration` warning。
2. `icalendar_reader.floating_timezone` 為合法 IANA timezone 時使用該值。
3. 該值為 `null` 時，使用合法的 `config('app.timezone')`。
4. 該值為非 `null` 但不合法時，記錄 warning 並使用 `UTC`。
5. 該值為 `null` 且 `config('app.timezone')` 不合法時，使用 `UTC`。

合法的 package override 已決定 floating time 的解析結果後，無效的
`config('app.timezone')` 仍產生設定 warning，但不得覆蓋該合法 override。

設定時區的合法性必須以 PHP 支援的 IANA timezone identifiers 判斷，不接受
任意縮寫、固定 offset 或無法由 `DateTimeZone` 穩定辨識的字串。fallback
不得中止讀取，也不得因 Carbon 自行猜測而產生不同結果。

發現無效設定時，成功回傳的 Calendar 必須包含
`invalid_timezone_configuration` warning，並記錄最後採用的 effective
timezone；發生 fallback 時該值為 `UTC`。Warning 不得包含其他敏感設定。

每個無效設定來源各產生一筆 warning；若 package override 與
`config('app.timezone')` 都無效，兩筆 warning 必須能從 message 分辨設定 key。
最後採用的值必須公開於 `Calendar::$floatingTimezone`。

### 11.4 全天日期

```ics
DTSTART;VALUE=DATE:20260803
DTEND;VALUE=DATE:20260804
```

公開便利 API 使用 `CarbonImmutable` 表示日期，時間固定為該解讀時區的
`00:00:00`，並且：

```php
$event->allDay === true;
$event->isAllDay() === true;
$event->startIsDate === true;
```

`allDay`、`isAllDay()` 與 `startIsDate` 的共同判斷規則：

- `DTSTART` 的 value type 是 `DATE` 時回傳 `true`。
- `DTSTART` 不存在或 value type 是 `DATE-TIME` 時回傳 `false`。
- 不得因開始時間剛好是 `00:00:00`、事件持續 24 小時、`DTEND` 落在隔天，
  或使用者所在時區顯示為整天，就自行推測為全天事件。
- 判斷只依解析後的 iCalendar value type，不要求使用者自行讀取
  `VALUE=DATE` parameter。

完整 `VALUE=DATE` 語意仍必須由底層 `Property` 保留。應用程式不得把全天
日期便利值當成 UTC instant。

### 11.5 DTEND 語意

iCalendar 的全天 `DTEND` 是 exclusive。套件不得將其私自減去一天。

```ics
DTSTART;VALUE=DATE:20260803
DTEND;VALUE=DATE:20260804
```

代表 8 月 3 日一整天。`endsAt` 仍回傳 8 月 4 日 00:00，另提供：

```php
$event->lastDay; // 2026-08-03
```

`lastDay` 只適用於具有可計算結束日期的全天事件，其他事件回傳 `null`。
若全天事件沒有 `DTEND` 或 `DURATION`，依 iCalendar 隱含一個日曆日的語意，
`endsAt` 為 `DTSTART` 所在時區的下一日 `00:00:00`，`lastDay` 等於 `DTSTART`
日期。此加日必須使用 calendar-day arithmetic，不得固定加 86,400 秒。

### 11.6 Duration

若事件只有 `DTSTART` 與 `DURATION`，`endsAt` 必須根據兩者計算。原始
`DURATION` 仍必須保留在 property bag。

若存在 `DTEND`，`endsAt` 一律由 `DTEND` 決定。若全天事件只有 DATE 型別的
`DTSTART`，沒有 `DTEND` 或 `DURATION`，`endsAt` 依第 11.5 節使用隱含的一日
exclusive end。

`Event::$duration` 表示 effective duration：

- 有合法 `DURATION` 且沒有 `DTEND` 時使用該值。
- 沒有 `DURATION`，但有可比較的 `DTSTART` 與 `DTEND` 時，以兩者差值計算。
- 由 `DTSTART` 與 `DTEND` 推導差值時，必須使用 PHP 原生
  `DateTimeImmutable::diff()` 語意，不直接依賴 Carbon 覆寫的 `diff()`；不同
  支援版本都必須讓 `DateInterval::$days` 保持可用的整數總天數。
- `DTEND` 與 `DURATION` 同時存在且結果一致時，使用兩者共同表示的 duration。
- 全天事件只有 DATE 型別 `DTSTART` 時，使用一個日曆日的 effective duration。
- 無法可靠計算時回傳 `null`。
- 全天事件的 duration 以完整日曆日語意計算，不以 DST 當日的秒數假設一天
  永遠是 86,400 秒。

若 `DTEND` 與 `DURATION` 同時存在而互相矛盾：

- Sabre/VObject 判定為 level 3 時視為不合法文件。
- 若只產生 level 2 warning，`endsAt` 與 `Event::$duration` 都以 `DTEND` 為準，
  並保留 warning；矛盾的原始 `DURATION` 仍保留在 property bag。

## 12. 查詢 API

### 12.1 第一版使用 Collection

```php
$calendar->events()
    ->where('status', 'CONFIRMED')
    ->sortBy('startsAt');
```

不建立自訂 query builder。

### 12.2 便利查詢

第一版只提供能明顯降低重複程式碼的查詢：

```php
$calendar->events($uid);
$calendar->event($uid);
$calendar->todos($uid);
$calendar->todo($uid);
$calendar->eventsBetween($from, $until);
```

`events($uid)` 用於取得同一 UID 的全部事件（包括 recurrence master 與
overrides）；`event($uid)` 則依本需求書的選擇規則取得單一事件。更複雜的條件
仍使用 Laravel Collection，不新增 query builder。`todos($uid)` 與 `todo($uid)`
對 VTODO 採用相同的 UID 與 master／override 選擇規則；第一版不提供
`todosBetween()`，時間篩選直接使用 `todos()` 回傳的 Collection。

`eventsBetween()`：

- 接受 `DateTimeInterface`。
- 回傳 `Collection<int, Event>`。
- 使用 half-open interval `[from, until)`；`from` 必須早於 `until`，否則拋出
  `InvalidArgumentException`。
- 包含與範圍有重疊的事件，而非只包含開始時間落在範圍內的事件；事件在
  `until` 才開始或在 `from` 已結束時不算重疊。
- 全天事件使用 exclusive `DTEND` 判斷重疊。
- 沒有可判定開始時間的 Event 不包含在結果中。
- DATE-TIME 事件只有 `DTSTART`、沒有 `DTEND` 或 `DURATION` 時視為零長度事件；
  當其開始時間位於 `[from, until)` 時包含在結果中。
- DATE 全天事件只有 `DTSTART` 時，使用第 11.5 節隱含的一日 exclusive end
  判斷 overlap，不視為零長度事件。
- 第一版不展開 recurring event。
- recurrence master 與 override 以各自的 typed 時間獨立判斷；RRULE 產生但
  文件中不存在的 occurrences 不會出現在結果中。此限制必須在文件中明確
  說明。

以下 API 不在第一版：

```php
$calendar->query()->confirmed()->upcoming()->withAttendee(...);
```

## 13. Recurrence

### 13.1 第一版

第一版必須：

- 保留 `RRULE`、`RDATE`、`EXDATE` 與 `RECURRENCE-ID`。
- 允許透過通用 Property API 取得上述資料，並由 Event／Todo 的
  `recurrenceRule`、`recurrenceDates`、`exceptionDates` 提供具名 Property
  shortcuts。
- 不提供 occurrence expansion。
- 不得讓 `eventsBetween()` 看起來像已完整處理 recurrence。
- Todo 的 UID 查詢只選擇文件中已有的 master／override，不推導 recurrence
  occurrences 或完成狀態。

### 13.2 後續版本候選

待真實使用案例與測試樣本足夠後，可加入：

```php
$calendar->occurrencesBetween($from, $until);
$event->occurrencesBetween($from, $until);
```

加入前必須正確處理：

- `RRULE`
- `RDATE`
- `EXDATE`
- `RECURRENCE-ID`
- overridden occurrence
- cancelled occurrence
- DST 與 `VTIMEZONE`
- 無限 recurrence 的時間範圍與安全上限

不得自行寫簡化版 recurrence engine。應優先包裝並驗證 Sabre/VObject
既有能力。

## 14. VTIMEZONE

第一版不把 `VTIMEZONE` 暴露成主要 domain object，但解析日期時間時必須
尊重 calendar 內提供的時區資料。

使用者仍可透過 component API 取得原始 `VTIMEZONE`：

```php
$calendar->components('VTIMEZONE');
```

若 `TZID` 無法解析：

- Sabre/VObject 判定為 level 3 時視為不合法文件。
- 若只產生 level 2 warning，保留原始 property，typed date-time accessor
  回傳 `null`，不得偷偷改用 UTC。

此處的 iCalendar `TZID` 與 Laravel `config('app.timezone')` 是不同來源。
只有應用程式的 floating timezone 設定可以 fallback 至 UTC；文件內無法解析
的 `TZID` 不得套用此 fallback。

## 15. 其他 Components

第一版正式支援：

- `VCALENDAR`
- `VEVENT`
- `VTODO`
- `VALARM`
- `VTIMEZONE`，供時間解析與低階存取

第一版遇到下列未提供 typed read model 的 components 時不得丟棄：

- `VJOURNAL`
- `VFREEBUSY`
- 未知 `X-*` component

它們可透過第 10.7 節的 generic Component API 取得：

```php
$freeBusy = $calendar->component('VFREEBUSY');
$hasTodos = $calendar->hasComponent('VTODO');
$calendar->components('VTODO');
$calendar->components();
```

`VTODO` 另提供第 31 節的 typed `Todo` read model；VJOURNAL、VFREEBUSY 與未知
components 不承諾專用 typed domain object。這些功能應依真實需求逐一加入，
而非同時建立完整 RFC object model。

## 16. 解析、驗證與 Warning

### 16.1 每次讀取都必須驗證

依 [Sabre/VObject 官方 iCalendar 文件](https://sabre.io/vobject/icalendar/)，
parser 只執行基本語法檢查。成功 parse 後，Reader 必須再呼叫：

```php
$issues = $vcalendar->validate();
```

第一版不得傳入 `Sabre\VObject\Node::REPAIR`，因為 reader 不應修改使用者
輸入。也不得預設使用 `PROFILE_CALDAV`，因為一般 `.ics` 與 CalDAV object
具有不同限制。

Parser 必須使用 Sabre Reader 預設 options `0`；不得啟用
`Reader::OPTION_FORGIVING` 或 `Reader::OPTION_IGNORE_INVALID_LINES`。後者會
忽略無法解析的輸入行，與「不合法內容必須失敗」的產品契約衝突。

validation level 依 Sabre/VObject 語意處理：

| Level | 語意 | Reader 行為 |
| --- | --- | --- |
| 1 | 問題已被 repair | 第一版不使用 `REPAIR`，正常情況不應出現 |
| 2 | 文件合法，但可能有互通性問題 | 回傳 Calendar 並加入 warning |
| 3 | 文件不合法 | 依 `read*()` 或 `try*()` 契約處理 |

以下任一情況都屬於不合法 iCalendar：

- parser 因語法錯誤無法產生 `VCALENDAR`。
- root component 不是 `VCALENDAR`。
- 完整 validation 至少包含一筆 level 3 issue。

不得只以副檔名、MIME type、是否含有 `BEGIN:VCALENDAR` 字串或 parser 沒有
拋出例外作為合法性判斷。

### 16.2 由呼叫端選擇失敗行為

需要明確知道錯誤原因時使用 throwing API：

```php
try {
    $calendar = ICalendar::read($contents);
} catch (InvalidCalendar $exception) {
    $issues = $exception->issues();
}
```

只關心能否讀取時使用 nullable API：

```php
$calendar = ICalendar::tryRead($contents);

if ($calendar === null) {
    // The contents are not a valid iCalendar document.
}
```

不提供以下 boolean 或 config 切換：

```php
ICalendar::read($contents, throw: false);
config(['icalendar_reader.throw_on_invalid' => false]);
```

失敗策略是每次呼叫的控制流程，不能是影響整個應用程式的全域設定。

`try*()` 只能將 `InvalidCalendar` 轉成 `null`。`CalendarTooLarge`、
`CalendarFileNotFound`、`CalendarFileUnreadable`、`InvalidCalendarSource` 與
其他非內容合法性錯誤仍必須拋出。

### 16.3 CalendarIssue 與 Warning

`InvalidCalendar::issues()` 與 `$calendar->warnings()` 使用相同的 readonly
`CalendarIssue` model，讓 parser、validator、設定與 mapping 問題具有一致
結構：

```php
$issue->level;
$issue->code;
$issue->message;
$issue->source;
$issue->line;
$issue->component;
$issue->property;
```

`line`、`component` 或 `property` 無法取得時可以為 `null`。

欄位語意：

- `level` 使用 `CalendarIssue::LEVEL_WARNING`（值為 2）表示 warning，使用
  `CalendarIssue::LEVEL_ERROR`（值為 3）表示 invalid/error；第一版不產生 level 1。
  這兩個 Sabre severity mapping 是固定技術值，必須宣告為 typed public class
  constants，不得在 validator、configuration、mapping 或測試程式碼散落裸數字。
- `$level` 的公開型別與 array／JSON 輸出維持 `int`，不改為 enum；這可保留既有
  穩定輸出契約，也避免為兩個上游固定數值新增轉換層。這些值不是使用者可調整的
  策略，因此不得放入 config。
- `source` 是 `parser`、`validator`、`configuration` 或 `mapping`。
- `code` 是套件定義的穩定分類，不能直接把 Sabre message 當成 code。
- `message` 是供人閱讀的訊息，不承諾跨 major version 完全相同。

Sabre validation result 只有 level、message 與 node，沒有穩定 machine code。
第一版不得解析 human-readable message 來猜測細分類；先提供少量可靠 code：

```text
invalid_timezone_configuration
invalid_root_component
parser_error
validation_error
validation_warning
mapping_warning
```

未來只有在能以 node type、property name 或其他結構化資料穩定判定，且具有
regression tests 時，才新增更細的 code。

最低 mapping：

- Sabre level 2 → `CalendarIssue::LEVEL_WARNING`、source `validator`、code
  `validation_warning`。
- Sabre level 3 → `CalendarIssue::LEVEL_ERROR`、source `validator`、code
  `validation_error`。
- Parser exception → `CalendarIssue::LEVEL_ERROR`、source `parser`、code
  `parser_error`。
- 非 VCALENDAR root → `CalendarIssue::LEVEL_ERROR`、source `parser`、code
  `invalid_root_component`。
- 無效 floating timezone 設定 → `CalendarIssue::LEVEL_WARNING`、source
  `configuration`、code
  `invalid_timezone_configuration`。

`InvalidCalendar` 必須保留 Sabre parser exception 作為 `$previous`；但
exception 與 issue 不得預設包含整份 calendar 內容。

## 17. 例外語意

所有套件例外必須實作：

```php
Mattmy\ICalendar\Exceptions\ICalendarException
```

第一版必要例外集合：

| 例外 | 語意 |
| --- | --- |
| `InvalidCalendar` | parser 無法解析、root 不是 `VCALENDAR`，或 validation 含 level 3 issue |
| `CalendarFileNotFound` | 傳入路徑不存在，或 UploadedFile 的暫存 backing file 已不存在 |
| `CalendarFileUnreadable` | 檔案或 stream 無法讀取 |
| `CalendarTooLarge` | 輸入超過限制 |
| `InvalidCalendarSource` | stream 或 UploadedFile 類型／狀態不合法 |
| `InvalidConfiguration` | `max_bytes` 等無法安全 fallback 的設定不合法 |

套件不得要求使用者捕捉 Sabre/VObject 例外才能處理一般失敗。原始例外應
作為 `$previous` 保留。

`InvalidCalendar` 必須提供：

```php
/** @return Collection<int, CalendarIssue> */
public function issues(): Collection;
```

Parser 在失敗前無法提供 node 或 line 時，仍至少包含一筆 code 為
`parser_error`、source 為 `parser`、level 為 3 的 CalendarIssue。

## 18. Array 與 JSON 輸出

### 18.1 目的

`toArray()` 與 JSON 輸出適用於：

- API response。
- 除錯。
- 記錄解析結果。
- 傳遞給前端或其他服務。

### 18.2 Domain-oriented 輸出

本節的 `jsonSerialize()` 與 `toJson()` 專指 `Calendar` 的公開方法；
`Calendar::toArray()` 提供 domain-oriented 輸出，`Property::toArray()` 則提供第
18.3 節的 normalized property shape。`Event`、`Todo`、`Organizer`、`Attendee`、`Alarm`
等其他巢狀資料仍由 `Calendar` 統一轉換，不因此要求它們額外提供公開
serialization methods。
`Calendar::toArray()` 與 `Calendar::jsonSerialize()` 應輸出相同、容易使用的
domain 結構。以下為縮短篇幅的示意；Event 未列出的 keys 不代表可以省略，完整
輸出仍必須涵蓋第 10.2 節全部正式 typed fields：

```php
[
    'version' => '2.0',
    'product_id' => '-//Example//Calendar//EN',
    'method' => 'REQUEST',
    'calendar_scale' => 'GREGORIAN',
    'floating_timezone' => 'Asia/Taipei',
    'events' => [
        [
            'uid' => 'event@example.test',
            'summary' => 'Architecture review',
            'starts_at' => '2026-08-03T14:30:00+08:00',
            'ends_at' => '2026-08-03T16:00:00+08:00',
            'start_is_date' => false,
            'end_is_date' => false,
            'start_is_floating' => false,
            'end_is_floating' => false,
            'recurrence_id' => null,
            'recurrence_id_is_date' => false,
            'recurrence_id_is_floating' => false,
            'is_all_day' => false,
            'last_day' => null,
            'attendees' => [],
            'alarms' => [],
        ],
    ],
    'todos' => [],
    'warnings' => [],
]
```

日期時間使用 ISO 8601 字串；date-only convenience value 使用 `YYYY-MM-DD`；
DateInterval 使用 iCalendar／ISO 8601 duration 字串。

輸出契約：

- Domain keys 固定存在；缺少的 singular value 使用 `null`，多值資料使用空
  list，不因值為空而省略 key。
- Event 輸出必須涵蓋第 10.2 節所有正式 typed fields，PHP camelCase property
  原則上轉成 JSON snake_case key。
- Event 固定輸出 `start_is_date`、`end_is_date`、`recurrence_id`、
  `recurrence_id_is_date`、`recurrence_id_is_floating`；DATE value 使用
  `YYYY-MM-DD`，DATE-TIME 使用 ISO 8601。
- Event 與 Todo 的 `geo` 輸出 `null` 或固定 `array{latitude: float, longitude: float}`
  shape；`comments`、`contacts`、`resources` 輸出 list of strings。`recurrence_rule`
  輸出 `null` 或 `Property::toArray()` shape；其他複合 shortcuts 輸出依文件順序的
  normalized Property lists，不另造第二套 serialization shape。
- Event 固定新增 `geo`、`transparency`、`comments`、`contacts`、`resources`、
  `recurrence_rule`、`attachments`、`exception_dates`、`request_statuses`、
  `related_to`、`recurrence_dates` keys。Todo 固定新增相同 keys，但不包含
  VEVENT-only 的 `transparency`。Property list 即使為空也輸出 `[]`，並使用從 0
  開始的 JSON array。
- Todo 輸出必須涵蓋第 31.3 節所有正式 typed fields，並採用相同的 snake_case、
  日期時間、duration 與 nested domain shape 規則。
- `allDay` 是命名例外：只輸出既有的 `is_all_day` key，不另增
  `all_day`。其值固定來自 `Event::$allDay`，且必須與 `Event::isAllDay()`、
  `start_is_date` 相同。
- Collection 輸出為從 0 開始的 JSON arrays。
- `warnings` 輸出 `CalendarIssue::toArray()` 結果。
- `jsonSerialize()` 等同 `toArray()`。
- `toJson()` 必須以 `JSON_THROW_ON_ERROR` 編碼並回傳字串；呼叫端傳入的
  `$options` 與 `JSON_THROW_ON_ERROR` 以 bitwise OR 合併，編碼失敗時拋出
  `JsonException`，不得回傳 `false` 或空字串。

### 18.3 完整 normalized 輸出

如需輸出完整 component tree，另提供明確名稱：

```php
$calendar->toComponentArray();
```

結構必須保留：

- component 順序。
- property 順序。
- 重複 properties。
- property parameters。
- value type。
- 單值與多值。
- 未知 properties 與 components。

「完整」代表不遺失 Sabre parser 已解析出的資料語意，不代表 byte-lossless。
輸出不保證保留原始大小寫、換行風格、content-line folding 位置、parameter
排列或 escaping 表示。第一版沒有從此陣列重建 `.ics` 的 round-trip 契約。

Properties 必須使用 list，而不是以名稱作唯一 key：

```php
[
    'name' => 'VEVENT',
    'properties' => [
        [
            'name' => 'ATTENDEE',
            'type' => 'cal-address',
            'value' => 'mailto:one@example.test',
            'values' => ['mailto:one@example.test'],
            'parameters' => ['CN' => 'One'],
            'raw_value' => 'mailto:one@example.test',
        ],
        [
            'name' => 'ATTENDEE',
            'type' => 'cal-address',
            'value' => 'mailto:two@example.test',
            'values' => ['mailto:two@example.test'],
            'parameters' => ['CN' => 'Two'],
            'raw_value' => 'mailto:two@example.test',
        ],
    ],
    'components' => [],
]
```

單一 `Property` 可直接輸出相同的 normalized property shape：

```php
$property->toArray();

[
    'name' => 'ATTENDEE',
    'type' => 'cal-address',
    'value' => 'mailto:one@example.test',
    'values' => ['mailto:one@example.test'],
    'parameters' => ['CN' => 'One'],
    'raw_value' => 'mailto:one@example.test',
]
```

`Property::toArray()` 的契約：

- 固定輸出 `name`、`type`、`value`、`values`、`parameters`、`raw_value` 六個
  keys，順序如上，不因 `null`、空 list 或空 parameter map 省略 key。
- `value` 與 `values` 使用 hydration 後的 typed values；零值時 `value` 為
  `null`，單值時為唯一值，多值時為完整 list。
- `parameters` 等同 `parameters()`，`raw_value` 等同 `rawValue()`。
- 輸出 shape 與 `Calendar::toComponentArray()` 中每個 property 完全相同；後者
  必須委派 `Property::toArray()`，不得維護第二套轉換規則。
- 此方法不表示 `Property` 實作 `JsonSerializable`，第一版也不新增
  `Property::toJson()`；需要完整 JSON 時仍由 `Calendar::toJson()` 負責。

## 19. 設定

第一版只提供確實影響安全或日期語意的設定：

```php
return [
    'max_bytes' => 10 * 1024 * 1024,
    'floating_timezone' => null,
];
```

可發布設定檔名稱為 `config/icalendar_reader.php`，所有 key 透過
`config('icalendar_reader.*')` 讀取。

規則：

- `max_bytes` 必須是正整數；型別錯誤、小於 1 或超出 PHP integer 範圍時
  拋出 `InvalidConfiguration`，不得以無限制讀取或不安全值繼續。
- `floating_timezone = null` 代表使用 `config('app.timezone')`。
- 非 `null` 的 `floating_timezone` 不是合法 IANA timezone，或其為 `null`
  且 `config('app.timezone')` 不合法時，不得拋出例外，一律使用 `UTC` 並
  加入 `invalid_timezone_configuration` warning。
- 合法 `floating_timezone` 存在時不使用 `config('app.timezone')` 作為解析
  依據；後者若無效仍產生 warning，但不得把解析結果改成 UTC。
- `UTC` 是固定且不可設定的最終 fallback，避免 fallback 本身再次無效。
- 不提供無實際用途的 publishable options。

Facade、domain object 命名與回傳格式不得做成 config。

iCalendar 合法性失敗要 throw 或回傳 `null` 也不得做成 config；呼叫端應以
`read*()` 或 `try*()` 明確選擇。

## 20. 安全需求

- 所有輸入來源必須以實際讀取 bytes 受 `max_bytes` 限制；metadata 只能用於
  提前拒絕，不能取代讀取時計數。
- 不得自動存取遠端 URL。
- `fromPath()` 不得開啟 PHP URL wrapper。
- 不得執行或反序列化輸入中的任意內容。
- 不得信任 UploadedFile 的 client MIME type。
- 錯誤訊息不得洩漏非必要的完整檔案內容。
- log 與 exception context 不得預設包含整份 calendar；內容可能含姓名、
  email、地點及會議資訊。
- CalendarIssue 只保留說明問題需要的最小資訊。

## 21. 效能與資源需求

第一版採整份 calendar 載入記憶體，與 Sabre/VObject 的 object model 一致。
套件不宣稱支援無限大小或 streaming event iteration。

最低要求：

- 讀取限制必須在建立完整 object tree 前盡早執行。
- 單次 property 查詢不應重複轉換整個 calendar。
- typed Event、Todo、Attendee 與 Alarm 必須在讀取時一次 hydration 建立，
  不得延遲到首次存取，也不得讓 accessor 重新遍歷 Sabre tree。
- 不為未測量的效能問題建立額外 cache layer。

正式發布前應建立至少三種 benchmark fixture：

- 小型：1 個事件。
- 一般：100 個事件。
- 大型：1,000 個事件或接近 `max_bytes`。

Benchmark 用於發現明顯 regression，不作為第一版硬性毫秒 SLA。

## 22. 公開 API 草案

### 22.1 Reader

```php
namespace Mattmy\ICalendar;

use Illuminate\Http\UploadedFile;

final class Reader
{
    public function read(string $contents): Calendar;

    public function tryRead(string $contents): ?Calendar;

    public function fromPath(string $path): Calendar;

    public function tryFromPath(string $path): ?Calendar;

    public function fromStream(mixed $stream): Calendar;

    public function tryFromStream(mixed $stream): ?Calendar;

    public function fromUploadedFile(UploadedFile $file): Calendar;

    public function tryFromUploadedFile(UploadedFile $file): ?Calendar;
}
```

所有方法都執行相同的 parse 與 validation pipeline。`try*()` 應呼叫相對應
的 throwing implementation 並且只捕捉 `InvalidCalendar`，不得維護第二套
解析流程。

### 22.2 Calendar

```php
final readonly class Calendar implements JsonSerializable
{
    public ?string $version;

    public ?string $productId;

    public ?string $method;

    public ?string $calendarScale;

    public string $floatingTimezone;

    /** @return Collection<int, Event> */
    public function events(?string $uid = null): Collection;

    public function hasEvents(?string $uid = null): bool;

    public function event(string $uid): ?Event;

    /** @return Collection<int, Todo> */
    public function todos(?string $uid = null): Collection;

    public function hasTodos(?string $uid = null): bool;

    public function todo(string $uid): ?Todo;

    /** @return Collection<int, Event> */
    public function eventsBetween(
        DateTimeInterface $from,
        DateTimeInterface $until,
    ): Collection;

    /** @return Collection<int, Property> */
    public function properties(?string $name = null): Collection;

    public function hasProperty(?string $name = null): bool;

    public function property(string $name): ?Property;

    /** @return Collection<int, Component> */
    public function components(?string $name = null): Collection;

    public function hasComponent(?string $name = null): bool;

    public function component(string $name): ?Component;

    /** @return Collection<int, CalendarIssue> */
    public function warnings(): Collection;

    public function toArray(): array;

    public function toComponentArray(): array;

    public function jsonSerialize(): array;

    public function toJson(int $options = 0): string;

    public function rawComponent(): Sabre\VObject\Component\VCalendar;
}
```

此處為精簡簽名草案；正式 PHP 檔案仍須依第 24.6 節為每個方法加入有意義的
PHPDoc、generic、array shape、value union 與 `@throws`。`events($uid)`、
`hasEvents($uid)` 與 `event($uid)` 都以 Event `UID` 作精確、大小寫敏感比對；
前兩者分別回傳全部符合事件與存在性，後者依 recurrence 選擇規則回傳單一事件。
`component($name)` 僅搜尋 Calendar 的直接 child components，以 trim 後的名稱
進行不區分大小寫比對，回傳文件順序中的第一筆；找不到時回傳 `null`，空字串或
全空白名稱拋出 `InvalidArgumentException`。需要全部同名 components 時使用
`components($name)`，不遞迴搜尋 descendants。
`hasComponent()` 無參數或傳入 `null` 時表示是否存在任意直接 child component；
傳入名稱時遵循相同的 trim、大小寫不敏感與非遞迴規則。非 `null` 的空字串或
全空白名稱同樣拋出 `InvalidArgumentException`。

`todos($uid)`、`hasTodos($uid)` 與 `todo($uid)` 對 Todo 採用第 31.2 節相同的
UID 與 recurrence master 選擇契約。

### 22.3 Event

Event 以 readonly public properties 提供最常用值，通用 property 方法遵循
第 10.6 節的共同查詢語意，但不繼承 generic `Component`。最低公開方法：

```php
final readonly class Event
{
    public bool $allDay;

    public function isAllDay(): bool;

    /** @return Collection<int, Property> */
    public function properties(?string $name = null): Collection;

    public function hasProperty(?string $name = null): bool;

    public function property(string $name): ?Property;

    public function rawComponent(): Sabre\VObject\Component\VEvent;
}
```

`isAllDay()` 必須直接回傳 `$allDay`，兩者是同一份 hydrated 全天語意的兩種
存取方式。正式類別還必須宣告第 10.2 節列出的其他 readonly public properties。
目前 `Calendar`、`Event`、`Todo` 與 generic `Component` 已有四個正式使用者共享
direct-property 查詢 invariant，因此依第 32 節抽取最小 `@internal` trait；不得為此
建立公開 base class、interface 或空泛 component hierarchy。

### 22.4 Todo

Todo 以 readonly public properties 提供第 31.3 節的 typed values，並保留完整
Property escape hatch：

```php
final readonly class Todo
{
    /** @return Collection<int, Property> */
    public function properties(?string $name = null): Collection;

    public function hasProperty(?string $name = null): bool;

    public function property(string $name): ?Property;

    public function rawComponent(): Sabre\VObject\Component\VTodo;
}
```

Todo 不繼承 Event 或 generic `Component`。其 direct-property 查詢依第 32 節重用
共同 internal implementation，但不擴張 public type hierarchy。

### 22.5 Generic Component

`components()` 回傳不依賴專用 domain class 的 generic `Component` wrapper，
使 typed view 之外的 VTODO 完整資料，以及 VJOURNAL、VFREEBUSY、VTIMEZONE 與
未知 components 仍可查詢：

```php
final readonly class Component
{
    public string $name;

    /** @return Collection<int, Property> */
    public function properties(?string $name = null): Collection;

    public function hasProperty(?string $name = null): bool;

    public function property(string $name): ?Property;

    /** @return Collection<int, Component> */
    public function components(?string $name = null): Collection;

    public function rawComponent(): Sabre\VObject\Component;
}
```

這是單一通用 wrapper，不建立每種 RFC component 的 speculative class
hierarchy。`Calendar::events()` 仍回傳 typed Event；`Calendar::components()`
則以文件順序回傳所有直接 child components 的 generic views。

### 22.6 Property

```php
/**
 * @phpstan-type StructuredValue array<array-key, string|list<string>>
 * @phpstan-type PropertyAtom bool|int|float|string|CarbonImmutable|DateInterval|StructuredValue
 * @phpstan-type PropertyValue PropertyAtom|list<PropertyAtom>|null
 * @phpstan-type PropertyArray array{name: string, type: string, value: PropertyValue, values: list<PropertyAtom>, parameters: array<string, string|list<string>>, raw_value: string}
 */
final readonly class Property
{
    public string $name;

    public string $type;

    /** @var PropertyValue */
    public mixed $value;

    /** @var list<PropertyAtom> */
    public array $values;

    /** @return array<string, string|list<string>> */
    public function parameters(): array;

    /** @return string|list<string>|null */
    public function parameter(string $name): string|array|null;

    public function rawValue(): string;

    /** @return PropertyArray */
    public function toArray(): array;
}
```

`PropertyArray`、`PropertyAtom` 與 `PropertyValue` 應由 `Property` 宣告為 PHPStan
local type aliases；`Calendar` 匯入並重用該 shape，不複製第二份可能分歧的型別。

原生 `mixed` 僅存在於 RFC value type 的公開邊界，並由 property-level PHPDoc
限制為目前正式支援的 value union；mapper 內部必須盡早轉成已知 scalar、
CarbonImmutable、DateInterval 或其他明確 value object，不得繼續傳遞無資訊的
`mixed`。

### 22.7 CalendarIssue

```php
final readonly class CalendarIssue implements JsonSerializable
{
    public const int LEVEL_WARNING = 2;

    public const int LEVEL_ERROR = 3;

    public int $level;

    public string $code;

    public string $message;

    public string $source;

    public ?int $line;

    public ?string $component;

    public ?string $property;

    public function toArray(): array;

    public function jsonSerialize(): array;
}
```

`level`、`code` 與 `source` 的允許值遵循第 16.3 節。`LEVEL_WARNING` 與
`LEVEL_ERROR` 是穩定公開 API；內部建立、比較或正規化 issue level 時必須引用
這兩個 constants。`source` 繼續使用第 16.3 節定義的穩定字串；不只為四個
固定值新增 backed enum 與額外轉換層。

### 22.8 Organizer、Attendee、Alarm 與 AlarmTrigger

第 10.3 至 10.5 節列出的欄位全部是 readonly public properties。相關方法
至少包含：

```php
final readonly class Organizer
{
    /** @return array<string, string|list<string>> */
    public function parameters(): array;
}

final readonly class Attendee
{
    /** @return array<string, string|list<string>> */
    public function parameters(): array;
}

final readonly class AlarmTrigger
{
    public function isRelative(): bool;

    public function isAbsolute(): bool;

    public function duration(): ?DateInterval;

    public function dateTime(): ?CarbonImmutable;

    public function relatedTo(): ?string;
}
```

`AlarmTrigger` 必須恰好是 relative 或 absolute 其中之一。Relative trigger 的
`relatedTo()` 只回傳 `START` 或 `END`，未指定 `RELATED` 時為 `START`；absolute
trigger 的 `relatedTo()` 回傳 `null`。錯誤或
同時存在兩種值的 trigger 交由 validation 契約處理，不建立矛盾 value object。

## 23. 使用範例

### 23.1 上傳並讀取

```php
use Illuminate\Http\Request;
use Mattmy\ICalendar\Facades\ICalendar;

Route::post('/calendars/read', function (Request $request) {
    $request->validate([
        'calendar' => ['required', 'file', 'max:10240'],
    ]);

    $calendar = ICalendar::fromUploadedFile(
        $request->file('calendar'),
    );

    return $calendar->toArray();
});
```

套件文件應建議使用 Form Request，但範例可以為了展示 reader 而保持精簡。

### 23.2 取得事件

```php
$calendar = ICalendar::read($contents);
$matchingEvents = $calendar->events($uid);

foreach ($calendar->events() as $event) {
    echo $event->summary;

    if ($event->allDay) {
        echo $event->startsAt?->toDateString();
    } else {
        echo $event->startsAt?->toIso8601String();
    }
}
```

### 23.3 取得參與者

```php
$attendees = $calendar
    ->events()
    ->flatMap->attendees;

foreach ($attendees as $attendee) {
    echo $attendee->name ?? $attendee->email ?? $attendee->address;
}
```

### 23.4 取得未知擴充欄位

```php
$busyStatus = $event
    ->property('X-MICROSOFT-CDO-BUSYSTATUS')
    ?->value;
```

### 23.5 檢查解析警告

```php
$calendar = ICalendar::read($contents);

if ($calendar->warnings()->isNotEmpty()) {
    report(new CalendarImportedWithWarnings(
        $calendar->warnings(),
    ));
}
```

### 23.6 選擇不合法內容的處理方式

需要錯誤細節時：

```php
use Mattmy\ICalendar\Exceptions\InvalidCalendar;

try {
    $calendar = ICalendar::read($contents);
} catch (InvalidCalendar $exception) {
    return response()->json([
        'message' => 'The uploaded file is not a valid iCalendar.',
        'issues' => $exception->issues(),
    ], 422);
}
```

只需要判斷成功或失敗時：

```php
$calendar = ICalendar::tryRead($contents);

if ($calendar === null) {
    return response()->json([
        'message' => 'The uploaded file is not a valid iCalendar.',
    ], 422);
}
```

## 24. 測試與品質需求

套件使用 Pest。

### 24.1 必要測試類別

- 字串、path、stream 與 UploadedFile 輸入。
- 無效、空白與超過大小限制的輸入。
- `max_bytes` 無效設定與四種來源的實際 byte limit。
- Stream current position、超限停止讀取與不關閉 caller-owned resource。
- parser syntax failure、非 `VCALENDAR` root 與 validation level 3。
- validation level 2 仍回傳 Calendar 並保留 warning。
- 所有 `read*()` 遇到不合法內容拋出 `InvalidCalendar`。
- 所有 `try*()` 只將 `InvalidCalendar` 轉成 `null`。
- `try*()` 不得吸收檔案、stream、upload 或大小限制例外。
- validation 不得使用 `REPAIR` 修改輸入。
- parser 不得使用 forgiving 或 ignore-invalid-lines options。
- UTC、TZID、floating 與全天日期。
- `allDay`、`isAllDay()` 與 `startIsDate` 對 `VALUE=DATE`、`DATE-TIME`、缺少
  `DTSTART`、午夜開始與 24 小時事件的判斷，且三者結果永遠一致。
- 合法及不合法的 `config('app.timezone')`。
- 合法、`null` 及不合法的 `floating_timezone`。
- 合法 `floating_timezone` override 搭配無效 `app.timezone` 時仍採 override，
  並產生設定 warning，但不錯誤 fallback 至 UTC。
- 沒有合法 package override 可用時，無效設定固定 fallback 至 UTC 並產生
  warning。
- exclusive all-day DTEND。
- `DTEND` 與 `DURATION`。
- 由 `DTSTART`／`DTEND` 推導 duration 時，在 lowest 與 stable dependencies 下
  `DateInterval::$days` 都是正確整數，不得為 `false`。
- Event 的 `startIsDate`／`endIsDate`、`startIsFloating`／`endIsFloating`，涵蓋
  explicit DTEND、duration-derived end、隱含全天結束與沒有結束值。
- 全天 Event 的 `lastDay` 有結束、無結束與非全天情境。
- 多個 events。
- `events()` 無參數回傳全部事件；`events($uid)` 以 `UID` 篩選所有
  符合事件，涵蓋多筆、找不到、空字串與大小寫行為。
- 缺少必要 UID 的文件由 validation 測試確認會在建立 Calendar 前失敗；如有
  mapper-level Event fixture，另確認 `uid === null` 不符合任何非 `null` UID 查詢。
- Event 的 typed `recurrenceId`、`recurrenceIdIsDate`、
  `recurrenceIdIsFloating`，以及 master／override 共用 UID 時的 `event($uid)`
  選擇規則；master 判斷必須依 property 是否存在，不得依 nullable typed value。
- `hasEvents()` 無參數、以 `UID` 比對、空字串與大小寫行為，
  並與 `events($uid)->isNotEmpty()` 結果一致。
- 一個及多個 todos、Todo UID master／override 選擇，以及 `todos()`、`todo()`、
  `hasTodos()` 的空值、大小寫、順序與 defensive Collection 行為。
- Todo 的 DATE／DATE-TIME／UTC／TZID／floating flags、effective due／duration、
  UTC-only timestamps、percent／priority／sequence／status 邊界與 nested alarms。
- VTODO 的 DUE／DURATION 互斥、DURATION／RRULE 對 DTSTART 的依賴，以及 DTSTART、
  DUE、RECURRENCE-ID value type 一致性。
- 多個 attendees。
- 多個 alarms。
- Event 與 Todo 對共同 properties 使用同一組 DATE／floating、attendee 與 alarm
  mapping 測試矩陣，並驗證共同 UTC timestamps；effective end／due／duration
  則按 component 分開驗證，避免兩條 hydration 路徑產生不同標準或錯誤共用規則。
- Event 的 `geo` 覆蓋正負與邊界座標、缺少值及 malformed pair；合法值依
  `latitude;longitude` 順序輸出 float array，原 parameters／raw value 仍可由
  `property('GEO')` 取得；成功讀取但 pair 非法時 typed value 為 `null`。
- Event 的 `transparency` 覆蓋 `OPAQUE`、`TRANSPARENT`、缺少 property 與非法
  token；缺少時 typed source value 為 `null`，effective RFC default 仍為 `OPAQUE`，
  非法 token 依 validation level warning 或 failure；warning 情境保留 uppercase
  typed source token，不偽造 default。
- Event 的 `comments`、`contacts`、`resources` 覆蓋缺少、單筆、重複 property、
  `RESOURCES` 單行多值、跨行順序、escaping／Unicode 與 defensive Collection；
  同時確認 parameters 與 property boundaries 在 generic API 未遺失。
- Event 的 `recurrenceRule`、`attachments`、`exceptionDates`、`requestStatuses`、
  `relatedTo`、`recurrenceDates` 覆蓋缺少、單筆、重複與 defensive Collection；
  shortcut 必須與 `property()`／`properties()` 指向相同 hydrated `Property` 語意，
  並保留 URI／binary、TZID、PERIOD、RELTYPE 與 structured value。
- 逐項 fixture 覆蓋第 10.2.1 節全部 RFC 5545 core VEVENT properties；有 typed
  accessor 的欄位驗證型別、nullable／空 Collection 與 cardinality，Property
  shortcuts 驗證 value type、parameters、raw value、重複順序與多值邊界；IANA／
  `X-*` 驗證只能由 generic API 無損取得。
- 重複 properties。
- `hasProperty()` 無參數、指定名稱、大小寫不敏感與不遞迴行為。
- 多值 property。
- 未知 `X-*` property、parameter 與 component。
- `Calendar::component()` 對名稱採大小寫不敏感比對、回傳第一個同名直接 child、
  找不到時回傳 `null`、拒絕空白名稱，且不遞迴搜尋 descendants。
- `Calendar::hasComponent()` 無參數、指定名稱、大小寫不敏感、空白名稱與
  非遞迴行為。
- Generic Component 的 property、child component、順序與非遞迴查詢。
- `rawComponent()` 回傳 deep clone，修改 clone 不影響 hydrated domain data。
- `RRULE`、`RDATE`、`EXDATE` 與 `RECURRENCE-ID` 的保留。
- `eventsBetween()` 的 half-open boundary、overlap、全天、零長度、缺少開始
  時間與未展開 recurrence 行為。
- `Calendar::toArray()` 固定 keys（包括 `todos`）、`jsonSerialize()` 等價性、`toJson()` 錯誤、
  `Property::toArray()` 六個固定 keys，以及 `toComponentArray()` 委派相同
  normalized property 契約。
- CalendarIssue、warning 與 exception mapping。
- Laravel service provider、container binding 與 Facade。
- 以 reflection 掃描 `src` 中套件自行宣告的方法，確認全部具有 doc comment；
  inherited framework／第三方 methods 不列入。

### 24.2 相容性 fixtures

測試 fixtures 至少涵蓋：

- Google Calendar 匯出的 `.ics`。
- Microsoft Outlook 匯出的 `.ics`。
- Apple Calendar 匯出的 `.ics`。
- 不包含 `VEVENT`、只包含 `VFREEBUSY` 的 calendar，且必須涵蓋重複
  `FREEBUSY`、`FBTYPE` parameter、folded 多值 PERIOD、Unicode COMMENT 與 URL。
- 含中文與 emoji 的內容。
- CRLF、LF 與 folded content lines。
- 大小寫不同的 property 與 parameter。

Fixtures 若來自真實資料，必須先移除個人資料並確認可存入 repository。
Google、Outlook 與 Apple fixture 必須能從檔名、目錄、fixture metadata 或緊鄰的
測試說明辨識來源類型；全部使用自行產生的通用 `PRODID` 範例，不能視為完成三種
client compatibility 驗收。中文、emoji、大小寫差異與換行／folding 也必須有
明確 assertion，不能只因 fixture 內可能出現相關內容就宣稱已覆蓋。

### 24.3 測試原則

- domain mapping 必須以公開 API 斷言，不依賴私有實作。
- 不 mock Sabre/VObject parser；解析整合測試必須使用真實輸入。
- 例外測試必須驗證套件例外與必要 context。
- 每個修復過的真實世界 `.ics` 問題都應留下最小 regression fixture。

### 24.4 靜態品質

正式發布前必須：

- 通過 Pint。
- 通過專案採用的靜態分析工具。
- 公開 API 無缺漏型別。
- PHPStan 能驗證 Collection generics、array shapes 與 Property value union
  不會和實際回傳值衝突。
- PHPDoc completeness architecture test 通過。
- Composer validation 通過。
- 測試不得依賴網路。

### 24.5 程式碼風格

實作必須遵循
[`LARAVEL_PACKAGE_CODE_STYLE_PHP_83.md`](../code-style/LARAVEL_PACKAGE_CODE_STYLE_PHP_83.md)。
該文件是本套件的程式碼風格與品質基準；本需求書仍是領域行為與公開 API
的依據。兩者如有衝突，必須先釐清並更新文件，不得由實作者自行選擇忽略。

本套件特別要求：

- 每個 PHP 檔案使用 `declare(strict_types=1);`、UTF-8、LF 且不加結尾 `?>`。
- 參數、回傳值、properties 與 PHP 8.3 class constants 必須宣告明確型別。
- 固定技術值不得以 magic number 散落；CalendarIssue severity 必須由
  `CalendarIssue::LEVEL_WARNING` 與 `CalendarIssue::LEVEL_ERROR` 統一表達。
- 使用 `===`、`!==`，且 `\in_array()` 必須開啟 strict mode。
- PHP 全域函式與內建 class 使用根命名空間；Laravel helpers 不加反斜線。
- boolean 方法使用 `is`、`has`、`can` 或 `should` 等可辨識前綴。
- 避免以 boolean 參數切換不同失敗策略；本套件使用 `read*()` 與 `try*()`
  分開表達。
- `mixed` 只允許存在於 stream、設定或第三方套件等真實邊界，進入內部流程
  前必須驗證並轉成明確型別。
- 每個方法必須有有意義的 PHPDoc；原生型別足夠時不重複無資訊價值的
  `@param` 或 `@return`，Collection 則必須標示泛型。
- 對外 API 的正常失敗路徑必須以 `@throws` 說明。
- 一個檔案原則上只宣告一個主要 class、interface、trait 或 enum。
- imports 依字母排序，移除未使用 imports；多行結構保留 trailing comma。
- Stream 等外部資源必須在成功及失敗路徑正確釋放，不依賴 destructor 清理。
- 專案根目錄必須提供參考規範所定義的 `pint.json`，並以 Laravel preset
  作為基準。
- 提交前必須通過 Pint、PHPStan、Pest、Composer validation 與 audit。

### 24.6 PHPDoc 契約

#### 24.6.1 適用範圍

PHPDoc 規範適用於 `packages/laravel-icalendar-reader/src/**/*.php` 中套件自行
宣告的所有類別與 methods，包括：

- Public、protected 與 private instance methods。
- Constructors、static factories 及其他 static methods。
- Domain objects、Reader、Support、Exceptions、Service Provider 與 Facade。
- Constructor-promoted public properties 中原生型別無法表達的 generic 或
  非直覺語意。
- Facade class-level `@method` declarations。

不要求為繼承自 Laravel、Sabre/VObject 或 PHP 的 methods 重寫 PHPDoc，也不要求
為 Pest closures 增加 docblock。第三方 stub 只有在 PHPStan 確實無法理解既有
dependency type 時才可補充，不得把第三方文件整份複製到套件。

#### 24.6.2 Method docblock

每個 method docblock 必須符合：

- 第一段以一句話描述 method 的責任、結果或重要副作用。
- 不得只重複 method 名稱、原生 signature，或使用沒有額外資訊的「Get／Set」
  文字充數。
- 原生型別已完整表達時，不重複無資訊價值的 `@param`／`@return`。
- 需要補充 trim、大小寫、順序、half-open interval、exclusive end、stream
  ownership、deep clone、nullable failure 等契約時，必須寫在 summary 或補充段落。
- Hydration-only constructor／factory 必須標示 `@internal`，避免 IDE 將其呈現為
  建議由使用者手動建立 domain object 的 API。

只有 docblock 存在但內容與實作不符，仍視為未完成。PHPDoc 必須以 method body、
callee、測試與本需求書的實際契約為準，不得文件化尚未實作的預期行為。

#### 24.6.3 Generics、array shapes 與 value union

- 所有 `Collection` 回傳必須標示 key 與 value，例如
  `Collection<int, Event>`；public Collection property 使用對應的
  `@var Collection<int, T>`。
- 依文件順序回傳的陣列使用 `list<T>`；以名稱為 key 的 parameters 使用
  `array<string, string|list<string>>`；固定 keys 使用 array shape。
- `Calendar::toArray()`、`Calendar::toComponentArray()`、
  `CalendarIssue::toArray()`、`jsonSerialize()` 與相關 internal helpers 必須標示
  與固定輸出完全一致的巢狀 shape，包括 nullable singular values 與空 lists。
- 重複使用的長 shape 可以透過 PHPStan local type aliases 維護，但 public method
  的註解仍必須讓 IDE／PHPStan 能解析到最終結構，不得退化成無資訊的 `array`。
- `Property::$value`、`Property::$values` 與 mapper 對應回傳值必須列出目前真正
  支援的 `bool`、`int`、`float`、`string`、`CarbonImmutable`、`DateInterval`、
  list 與 `null` 組合。原生 `mixed` 可以保留在 RFC 開放邊界，但 PHPDoc 不得只寫
  `mixed`，也不得列入實作尚未產生的型別。
- Internal input arrays、validation issue arrays、timezone resolution results 與
  serialization helpers 同樣必須使用精確 list、map 或 shape；PHPDoc 完整性不只
  限於 public API。

#### 24.6.4 `@throws` 契約

正常、可由使用者輸入觸發且屬於穩定契約的例外必須標示 `@throws`。至少包含：

| API | 必須標示的正常失敗 |
| --- | --- |
| `Reader::read()` | `InvalidCalendar`、`CalendarTooLarge`、`InvalidConfiguration` |
| `Reader::tryRead()` | `CalendarTooLarge`、`InvalidConfiguration`；不得列為會拋出 `InvalidCalendar` |
| `Reader::fromPath()` | `InvalidCalendar`、`CalendarFileNotFound`、`CalendarFileUnreadable`、`CalendarTooLarge`、`InvalidConfiguration` |
| `Reader::tryFromPath()` | 與 throwing 版本相同，但排除 `InvalidCalendar` |
| `Reader::fromStream()` | `InvalidCalendar`、`InvalidCalendarSource`、`CalendarFileUnreadable`、`CalendarTooLarge`、`InvalidConfiguration` |
| `Reader::tryFromStream()` | 與 throwing 版本相同，但排除 `InvalidCalendar` |
| `Reader::fromUploadedFile()` | `InvalidCalendar`、`InvalidCalendarSource`、`CalendarFileUnreadable`、`CalendarFileNotFound`、`CalendarTooLarge`、`InvalidConfiguration` |
| `Reader::tryFromUploadedFile()` | 與 throwing 版本相同，但排除 `InvalidCalendar` |
| 非 `null` property／component name query methods | 空白名稱造成的 `InvalidArgumentException` |
| `Calendar::eventsBetween()` | `from >= until` 造成的 `InvalidArgumentException` |
| `Calendar::toJson()` | JSON 編碼失敗造成的 `JsonException` |

`try*()` 的 nullable return 只代表它會把 `InvalidCalendar` 轉成 `null`；來源、
資源、大小與設定錯誤仍須留在 PHPDoc。不可達的 programming error、PHP 原生
型別錯誤，或已轉換且不會穿越公開邊界的底層 Sabre exceptions 不必列出。
`events($uid)` 與 `hasEvents($uid)` 的 UID 不是 structural name，不 trim；空字串
不會造成 `InvalidArgumentException`。

#### 24.6.5 Public properties 與非直覺語意

Public properties 已有清楚原生型別時不強制逐一加入重複註解，但以下情況必須
補充 PHPDoc：

- Collection generic。
- `geo` 必須標示 `array{latitude: float, longitude: float}|null`，不得寬化成
  `array<string, mixed>`。
- `recurrenceRule` 的 nullable `Property` 語意，以及複合 shortcut Collections
  的 `Collection<int, Property>` generic、文件順序與 defensive container 語意。
- `DateInterval` 的 effective duration 語意。
- `endsAt` 對全天事件保持 exclusive 的語意。
- `lastDay` 只適用全天事件且是 inclusive convenience date。
- `allDay` 只反映 `DTSTART` 的 value type，且與 `isAllDay()`、`startIsDate`
  永遠一致。
- Event 與 Todo 的 DATE／floating flags 反映來源或推導 property 語意；Event 的
  recurrence flags 與 Todo 採相同規則。
- Event 的 exclusive end／inclusive `lastDay`，以及 Todo 的 effective
  due／duration 語意。
- Shallow readonly Collection／DateInterval 的 snapshot isolation 限制。
- `CalendarIssue::$level`、`$source` 等原生型別無法限制的允許值。

Class-level PHPDoc 必須簡述 domain object 的角色。不得為每個 `?string` property
加入只重複「string or null」的註解，避免真正重要的時間與集合語意被噪音淹沒。

#### 24.6.6 Facade 與 internal API 一致性

- Facade 的 `@method` 名稱、參數、回傳型別及 nullable 行為必須與 Reader 完全
  一致。
- Facade 文件不得宣稱 `try*()` 會隱藏來源錯誤。
- Internal method PHPDoc 可以使用 `@internal` 或 PHPStan aliases，但不得讓
  internal constructor／mapper 被誤認為 Semantic Versioning 保證的公開 API。
- PHPDoc 補強不得改變 runtime behavior、公開 signature、例外階層或 array／JSON
  shape；若文件無法誠實描述現況，必須另列功能缺陷處理。

#### 24.6.7 驗收與防退化

正式發布前必須同時符合：

- Reflection architecture test 確認每個 package-declared method 都有 doc comment。
- 人工 review 確認 docblock 具有實質內容，且與需求、實作及測試一致；reflection
  test 不能取代語意 review。
- PHPStan 驗證 generics、shapes、unions 與實際回傳值相容，不得新增 baseline、
  ignore 或寬化成 `mixed`／`array` 來避開問題。
- Pint、Pest、Composer validation、audit 與 `git diff --check` 全部通過。
- 不為此需求新增 phpDocumentor 等只用於一次性盤點的 dependency；既有
  reflection、PHPStan、Pint 與 Pest 已足以提供持續保護。

Reflection guard 除了 class 與 methods，還必須檢查 constructor-promoted public
properties：原生型別無法表達 Collection generic、list element、Property value
union 或非直覺語意時，對應 property 必須具有可由 Reflection 取得的 doc comment。
Guard 不需要解析自然語言品質，但不得讓只有 constructor `@param`、property 本身
沒有 `@var` 的宣告誤判為完整。

### 24.7 現況缺口補齊門檻

在再次宣稱完整符合本需求書前，必須針對已知缺口完成以下工作：

1. 將 `Property::$value`、`Property::$values` 與 mapper value list 從寬泛
   `mixed` 補成實際 union；union 必須包含 RRULE 等 parser structured value
   `array<array-key, string|list<string>>`，不得把陣列強制轉成字串。Normalized
   component tree 的每一層固定欄位必須使用 array shape；因 PHPStan 目前拒絕
   circular local type alias，只有遞迴 child edge 可使用明確記錄的
   `list<array<string, mixed>>` 邊界，並由 runtime recursive assertions 證明深層輸出。
2. 擴充 PHPDoc architecture test，使其保護 promoted public properties；語意與
   `@throws` 仍須人工對照 method body、callee 與測試。
3. 以 Pest 補齊第 24.1 節尚未被現有測試證明的 failure paths、時間邊界、
   recurrence properties、clone isolation、serialization 與 Laravel integration。
4. 加入可辨識來源的 Google、Outlook、Apple fixtures，以及 emoji、名稱大小寫、
   CRLF／LF 與 folding 的專用 fixtures 與 assertions。
5. 完成第 25 節雙語欄位表、升級指南與 quick-start example 驗證。

新增測試時先記錄目前行為並讓真正不符合需求的案例失敗；只有測試證明 runtime
契約不成立時才修改 production code。不得為了提高測試數量重寫已符合需求的流程，
也不得把無法由目前 Sabre 版本自然產生的情境偽裝成 integration coverage；這類
情境應以最小 internal/unit contract test 或清楚記錄的 upstream limitation 處理。

## 25. 文件需求

### 25.1 語言與主要版本

使用者文件必須同時支援英文與繁體中文，並以英文為主要及權威版本：

- `README.md` 使用英文。
- `README.zh-TW.md` 使用繁體中文。
- 兩份 README 頂部必須提供彼此的語言切換連結。
- 完整文件網站或 guide 預設路徑使用英文，繁體中文置於 `zh-TW` locale。
- 新增、修改或移除公開 API 時，英文與繁體中文核心文件必須在同一版本
  同步更新。
- 兩種語言的程式碼範例、方法名稱、設定 key 與行為契約必須一致。
- 翻譯遇到技術歧義時以英文文件為準，繁體中文頁面必須清楚標示此前提。
- API identifiers、iCalendar property names 與 exception class names 不翻譯。
- Changelog、CONTRIBUTING、SECURITY 與內部設計文件可以只使用英文；面向
  使用者的安裝、快速開始、API、設定、錯誤與限制文件必須雙語。

### 25.2 README 內容

README 必須包含：

1. 套件定位與非目標。
2. 安裝方式。
3. 30 秒快速開始。
4. 所有輸入來源。
5. Event、Todo、Attendee、Organizer 與 Alarm 欄位表。
6. 日期、時區、floating time、全天事件語意、Todo due／duration，以及
   `allDay`／`isAllDay()` 使用方式。
7. iCalendar validation、`read*()` 與 `try*()` 的失敗語意。
8. 未知及重複 property 的取得方式。
9. Recurrence 第一版限制。
10. 安全與輸入大小限制。
11. 升級指南與版本政策。

API 文件不得只有完整 method list；必須先以常見任務範例說明。
欄位表至少列出 public property、型別、nullable／Collection 元素型別及重要語意；
不得只用一段列舉名稱的 prose 取代。升級指南在尚無前一個 stable major 時仍須
提供 `Upgrading`／`升級` 章節，說明 0.x breaking changes 應先查閱 CHANGELOG、
固定輸出與公開 API 的相容政策，以及升級前應執行的測試。

### 25.3 雙語文件驗收

正式發布前必須確認：

- 英文與繁體中文的導覽都沒有失效連結。
- 兩種語言都能找到相同的核心功能與限制。
- 所有程式碼範例至少通過語法檢查；關鍵 quick-start 範例應納入測試。
- 繁體中文使用 `zh-TW`，不得混用簡體中文或未定義的自製 locale 名稱。

## 26. 版本與向後相容

### 26.1 Semantic Versioning

套件遵循 Semantic Versioning。

穩定版後，下列變更視為 breaking change：

- 公開 class、method 或 property 改名。
- 回傳型別改變。
- `toArray()` key 或語意改變。
- 日期時區解讀規則改變。
- exception class 或 warning code 改變。
- property 重複與排序行為改變。

新增 optional property、warning code 或支援 component 通常可作為 minor
version，但不得改變既有輸入的核心結果而未記錄。

使用者必須將未知的未來 warning code 視為可顯示或記錄的值，不得要求套件
為新增 warning code 發布 major version。刪除 code、改變既有 code 語意或將
原本合法輸入改判為不合法，則必須評估 major version。

### 26.2 發布與套件 metadata

- License 使用 MIT，repository 必須包含 `LICENSE`。
- `composer.json` 必須包含 package name、清楚的英文 description、license、
  keywords、PSR-4 autoload、Laravel auto-discovery 與 support links。
- Repository 必須包含英文 `CHANGELOG.md`、`CONTRIBUTING.md`、`SECURITY.md`
  與 GitHub issue／pull request templates。
- Packagist package 名稱固定為 `mattmy/laravel-icalendar-reader`。
- 每次穩定發布使用 signed 或 annotated `vMAJOR.MINOR.PATCH` tag，建立對應
  GitHub Release，並確認 Packagist 已索引該版本。
- 發布 tag 前必須由受保護分支的 Pull Request 通過完整 CI，不得從未經 CI
  的本機 commit 建立正式 tag。

## 27. 分階段交付

### Phase 1：核心 MVP

- Package skeleton 與 Laravel auto-discovery。
- Reader 的四種輸入來源。
- 每種輸入來源的 `read*()` 與 `try*()` API。
- Sabre/VObject 完整 validation 與 CalendarIssue mapping。
- Sabre/VObject 例外轉換。
- Calendar 與 Event。
- Calendar 的 `events()`、`event()` 與 `hasEvents()` UID 查詢。
- Property、CalendarIssue 與 generic Component access。
- 重複及未知 property 保留。
- UTC、TZID、floating、全天日期、floating timezone 優先序，以及沒有
  合法時區可用時的 UTC fallback。
- `max_bytes` 設定驗證與所有來源的實際 byte limit。
- `toArray()`、`toComponentArray()`、`jsonSerialize()` 與 `toJson()`。
- 最小 README 與核心測試。

Phase 1 是不可拆開宣稱完成的集合。Package skeleton、部分 Reader methods 或少數
Event fields 即使已有測試，也只代表開發里程碑；在 `Property`、generic
`Component`、未知／重複資料保留與 Phase 1 其餘項目完成前，不得稱為核心 MVP。

### Phase 2：事件與待辦相關資料

- Organizer。
- Attendee。
- Alarm 與 AlarmTrigger。
- Categories、URL、priority、sequence、status、classification。
- 第 10.2.1 節 RFC 5545 core VEVENT coverage，包括 GEO／TRANSP typed values、
  重複文字 collections 與複合 Property shortcuts；Todo 同步共同欄位。
- VTODO typed `Todo`、`todos()`／`todo()`／`hasTodos()`、時間語意與 nested alarms。
- Google、Outlook、Apple fixtures。

### Phase 3：穩定版準備

- 第 32 節 direct-property 查詢共用化與第 33 節已知 property／parameter
  名稱常數化。
- `eventsBetween()`。
- 效能 fixture 與 regression 檢查。
- CI compatibility matrix。
- 完整文件。
- API naming review。
- `1.0.0` release。

### 未排入 1.0

- Recurrence occurrence expansion。
- VJOURNAL typed API。
- VFREEBUSY typed API。
- iCalendar generator、writer 或 `.ics` serialization。
- 遠端 URL reader。

## 28. 重要設計決策

### 28.1 使用 Sabre/VObject

決策：以 `sabre/vobject` 作底層 parser。

理由：

- RFC parsing、escaping、folding 與時區處理不應重寫。
- 套件差異化是 Laravel-friendly API。
- 可集中資源處理 typed mapping、錯誤語意與開發體驗。

### 28.2 Laravel-only

決策：正式依賴 Illuminate Collection、Carbon 與 UploadedFile。

理由：

- 產品已明確只服務 Laravel。
- 不為尚不存在的 framework-neutral 使用者維護第二套 Collection 或 adapter。
- API 可以直接符合 Laravel 開發習慣。

### 28.3 不提供 generator

決策：不建立任何 iCalendar generator、builder 或輸出 `.ics` 的 serialization
API；供應用程式使用的 array／JSON read-model 輸出仍依第 18 節提供。

理由：

- 市場已有成熟 generator。
- Reader 是本套件的明確差異化。
- Writer 會大幅增加 validation、timezone 與跨 client 相容性責任。
- 讀取與產生不需要為了「功能完整」而綁在同一套件。

### 28.4 不提供 URL reader

決策：只讀取已取得的內容、本機檔案、stream 或 UploadedFile。

理由：

- Laravel HTTP Client 已能處理網路請求。
- 防止套件同時承擔 SSRF、redirect、timeout 與 retry。
- reader 能保持單一責任。

### 28.5 不以 associative map 儲存 properties

決策：properties 使用 ordered list。

理由：

- iCalendar 合法允許重複 property。
- `ATTENDEE` 等常見資料會因 associative key 被覆蓋。
- ordered list 能保留更多原始語意。

### 28.6 第一版不展開 recurrence

決策：保留 recurrence properties，但延後 occurrence expansion。

理由：

- recurrence 涉及 exception、override、DST 與無限規則。
- 不完整的 recurrence engine 比沒有 recurrence API 更危險。
- Sabre/VObject 的能力可以在後續以真實 fixtures 驗證後再包裝。

### 28.7 讀取一定驗證，不合法行為由方法名稱決定

決策：所有讀取都在 parse 後呼叫 Sabre/VObject `validate()`，`read*()`
throw，`try*()` 對不合法內容回傳 `null`。

理由：

- parser 成功不代表完整文件符合 iCalendar 規則。
- throwing API 保留詳細 issue，nullable API 提供簡潔控制流程。
- 明確方法名稱比 boolean 參數或全域 config 容易閱讀與靜態分析。
- 共用同一條 validation pipeline，避免兩種失敗策略產生不同判定。
- Reader 不使用 `REPAIR`，避免讀取動作偷偷修改輸入資料。

### 28.8 沒有合法 floating timezone 時 fallback 至 UTC

決策：無效的 floating timezone 設定不阻擋讀取，依第 11.3 節選擇 effective
timezone 並加入 warning。只有 package override 無效，或 package override 為
`null` 且 `config('app.timezone')` 無效時，才 fallback 至固定 `UTC`。

此決策依第 11.3 節的優先順序套用；合法 package override 存在時仍驗證
`config('app.timezone')` 並在無效時警告，但不使用它覆蓋合法 override。

理由：

- floating date-time 必須有可預期的解讀基準。
- `UTC` 永遠可用，不會形成 fallback chain failure。
- 設定錯誤不代表使用者提供的 iCalendar 不合法。
- warning 讓應用程式能發現部署設定問題，而不讓讀取流程直接中斷。

## 29. 風險

### 29.1 簡單 API 與完整語意衝突

緩解方式：常用欄位提供 typed convenience API，完整資料保留在 Property 與
Component escape hatch。

### 29.2 Sabre/VObject 升級

緩解方式：集中在 Reader 與 mapper 邊界使用 Sabre 類別；domain object 不
繼承 Sabre 類別。

### 29.3 日期解讀錯誤

緩解方式：分別測試 UTC、TZID、floating、全天與 DST。文件內未知 `TZID`
不自動降級；只有無合法 package override 可用，且應用程式 floating timezone
設定也無效時，才固定 fallback 至 UTC 並產生 warning。

### 29.4 真實 `.ics` 不符合規格

緩解方式：每次 parse 後執行完整 validation；level 2 保留結構化 warning，
level 3 依 `read*()` 或 `try*()` 契約失敗，並持續累積匿名 regression
fixtures。

### 29.5 API 範圍膨脹

緩解方式：以「是否直接改善讀取體驗」判斷功能；同步、儲存、下載與產生
維持在套件外。

## 30. 1.0 完成定義

`laravel-icalendar-reader` 可以發布 `1.0.0`，必須同時符合：

- 可由 Composer 安裝並透過 Laravel 自動發現。
- 四種正式輸入來源皆可運作。
- 常見 `VCALENDAR`、`VEVENT`、`VTODO` 與 `VALARM` 可讀取。
- Event 常見欄位具有清楚的 typed API。
- Event 完成第 10.2.1 節的 RFC 5545 core VEVENT coverage：穩定 scalar／list
  欄位可直接 typed 讀取，複合與 recurrence 欄位可由具名 `Property` shortcuts
  取得；只有開放式 IANA／`X-*` 使用 generic API，全部都有逐項無損驗收。
- VTODO 常見欄位具有 `Todo` typed API，且完整 properties 仍由 generic view 保留。
- 日期、時區、floating time 與全天事件行為有測試及文件。
- `Event::$allDay`、`Event::isAllDay()` 與 `Event::$startIsDate` 能讓使用者在
  不了解 `VALUE=DATE` 的情況下可靠判斷全天事件，三者永遠一致，且不以午夜或
  持續時間猜測。
- 多個 attendees 與其他重複 properties 不會被覆蓋。
- 未知 `X-*` 資料不會被丟棄。
- 不含 `VEVENT` 的合法 calendar 仍能透過 `component()`、`components()`、
  `hasComponent()`、`properties()` 與 `toComponentArray()` 取得完整 normalized
  語意；`events()` 回傳空 Collection 不得造成其他 component 資料從公開 API
  消失。
- 每個讀取入口都執行 Sabre/VObject 完整 validation。
- `read*()` 對不合法 iCalendar 拋出 `InvalidCalendar`。
- `try*()` 只將 `InvalidCalendar` 轉成 `null`，不隱藏來源或資源錯誤。
- level 2 validation issue 能隨 Calendar 回傳為 warning。
- 時區解析遵循第 11.3 節優先序：合法 package override 不得被無效
  app timezone 覆蓋；沒有合法 override 可用時才 fallback 至 UTC 並產生
  warning。
- `events($uid)`、`hasEvents($uid)`、`hasEvents()`、`hasProperty()`、`hasComponent()` 與
  `component()` 的查詢語意有測試及文件。
- `todos($uid)`、`todo($uid)`、`hasTodos($uid)` 與 `hasTodos()` 的查詢語意有測試
  及文件。
- `Calendar`、`Event`、`Todo` 與 generic `Component` 的 direct-property 查詢
  共用第 32 節的單一 internal implementation，並通過 parity 測試。
- Production code 的已知 property／parameter wire names 依第 33 節集中為
  typed class constants，同時保持公開字串查詢與 IANA／`X-*` 開放性。
- 所有底層一般解析錯誤都轉成套件例外。
- Array 與 JSON 輸出格式固定並有測試。
- CI 覆蓋所有宣告支援的 PHP 與 Laravel 組合。
- 實作符合 `LARAVEL_PACKAGE_CODE_STYLE_PHP_83.md`，並通過要求的格式、
  靜態分析、測試與 Composer 品質檢查。
- 第 24.6 節 PHPDoc 契約全部完成：每個 package-declared method 有實質 docblock，
  Collection generics、array shapes、value unions、`@throws`、`@internal` 與 Facade
  annotations 經自動化檢查及人工 review 驗證。
- 英文與繁體中文 README／核心文件包含完整快速開始與限制說明，且英文為
  權威版本。
- Composer metadata、MIT license、Changelog、contribution 與 security 文件
  完整，GitHub Release 與 Packagist 發布流程已驗證。
- 不包含 generator、URL downloader、CalDAV 或資料庫功能。

第一個穩定版的成功標準不是涵蓋整份 RFC，而是讓 Laravel 開發者能安全、
快速且不遺失資料地讀取最常見的 `.ics`。

## 31. VTODO typed read model 補充契約

本節是 Phase 2 與 1.0 的正式 normative requirement。它保留在既有第 1 至 30 節
之後，以免因後加入 VTODO 而重編所有既有章節與外部引用；前文的 Calendar、公開
API、輸出、測試、文件、分階段交付與完成定義均已同步納入本節。

### 31.1 名稱與範圍

RFC 元件名稱是 `VTODO`，不是 `VOTDO`。公開 PHP API 採 Laravel 慣用的領域名稱：

```php
$calendar->todos();
$calendar->todos($uid);
$calendar->todo($uid);
$calendar->hasTodos();
$calendar->hasTodos($uid);
```

不得增加拼錯的 `votdo()`／`votdos()`，也不增加只重複 wire name 的
`vtodo()`／`vtodos()` alias。

本功能只提供已存在 `VTODO` component 的 immutable typed snapshot、查詢與
既有 normalized serialization。它不建立 writer、狀態異動 API、工作流程引擎、
提醒排程器或 recurrence occurrence expansion。完整的 `VTODO` property 與
child `VALARM` 仍須透過既有 `Property`／`Component` API 無損取得。

RFC 5545 將 `VTODO` 定義為 action-item／assignment 的 component，允許零個以上
`VALARM` child components；完整 component grammar 以
[RFC 5545 §3.6.2](https://www.rfc-editor.org/rfc/rfc5545.html#section-3.6.2)
為準。

### 31.2 Calendar 查詢契約

`todos(?string $uid = null)`、`todo(string $uid)` 與
`hasTodos(?string $uid = null)` 必須比照第 10.1 節 Event API：

- `todos(null)` 依文件中的 `VTODO` component 順序回傳全部 Todo，包括具有
  `RECURRENCE-ID` 的 overrides；不合併、不展開 recurrence。
- `todos($uid)` 以解碼後的 typed `UID` 精確、大小寫敏感比對，保留所有符合
  components；找不到時回傳空的 `Collection<int, Todo>`。
- `todo($uid)` 優先回傳沒有 `RECURRENCE-ID` 的 master；沒有 master 時回傳文件
  順序中的第一個符合者；找不到時回傳 `null`。
- `hasTodos(null)` 回答是否存在任一 Todo；`hasTodos($uid)` 必須與
  `todos($uid)->isNotEmpty()` 共用相同比對規則。
- 空字串不 trim、不拋例外；每次 Collection 查詢回傳新的 container，不得修改
  Calendar snapshot。

recurrence master 與 override 使用相同 `UID`，並由 `RECURRENCE-ID` 指定某個
instance，因此 UID 查詢不得假設只會得到一筆；其 wire semantics 見
[RFC 5545 §3.8.4.4](https://www.rfc-editor.org/rfc/rfc5545.html#section-3.8.4.4)。

### 31.3 Todo typed 欄位

`Todo` 是 `final readonly class`。最低公開資料為：

```php
$todo->uid;               // ?string (UID)
$todo->timestamp;         // ?CarbonImmutable (DTSTAMP)
$todo->classification;    // ?string (CLASS)
$todo->completedAt;       // ?CarbonImmutable (COMPLETED)
$todo->createdAt;         // ?CarbonImmutable (CREATED)
$todo->description;       // ?string (DESCRIPTION)
$todo->startsAt;          // ?CarbonImmutable (DTSTART)
$todo->startIsDate;       // bool
$todo->startIsFloating;   // bool
$todo->dueAt;             // ?CarbonImmutable (DUE)
$todo->dueIsDate;         // bool
$todo->dueIsFloating;     // bool
$todo->duration;          // ?DateInterval (effective duration)
$todo->lastModifiedAt;    // ?CarbonImmutable (LAST-MODIFIED)
$todo->location;          // ?string (LOCATION)
$todo->organizer;         // ?Organizer
$todo->percentComplete;   // ?int (PERCENT-COMPLETE)
$todo->priority;          // ?int (PRIORITY)
$todo->recurrenceId;      // ?CarbonImmutable (RECURRENCE-ID)
$todo->recurrenceIdIsDate;     // bool
$todo->recurrenceIdIsFloating; // bool
$todo->sequence;          // ?int (SEQUENCE)
$todo->status;            // ?string (STATUS)
$todo->summary;           // ?string (SUMMARY)
$todo->url;               // ?string (URL)
$todo->attendees;         // Collection<int, Attendee>
$todo->categories;        // Collection<int, string>
$todo->alarms;            // Collection<int, Alarm>
$todo->geo;               // ?array{latitude: float, longitude: float}
$todo->comments;          // Collection<int, string>
$todo->contacts;          // Collection<int, string>
$todo->resources;         // Collection<int, string>
$todo->recurrenceRule;    // ?Property (RRULE)
$todo->attachments;       // Collection<int, Property>
$todo->exceptionDates;    // Collection<int, Property> (EXDATE)
$todo->requestStatuses;   // Collection<int, Property> (REQUEST-STATUS)
$todo->relatedTo;         // Collection<int, Property> (RELATED-TO)
$todo->recurrenceDates;   // Collection<int, Property> (RDATE)
```

`Todo` 同時提供與 Event 相同語意的 `properties()`、`hasProperty()`、`property()`
與 `rawComponent()`；後者回傳 deep-cloned `Sabre\VObject\Component\VTodo`。

缺少 optional property 時使用 `null`、`false` 或空 Collection，不得以空字串
假裝有值。`DTSTART`、`DUE` 與 `RECURRENCE-ID` 沿用第 11 節的 UTC、TZID、
floating 及 `VALUE=DATE` 解讀規則。顯式值的 `*IsDate`／`*IsFloating` flags 反映
各自原 property；`dueAt` 由 `DTSTART + DURATION` 推導時，due flags 繼承 start
flags；其他 property 不存在時對應 flags 為 `false`。`COMPLETED` 按 RFC 僅接受
UTC DATE-TIME。

`DTSTAMP`、`CREATED`、`LAST-MODIFIED` 與 `COMPLETED` 必須解讀為 UTC
DATE-TIME；`RECURRENCE-ID` 的 DATE／DATE-TIME value type 必須與該 recurrence set
的 `DTSTART` 一致。`percentComplete` 的 RFC 合法範圍是 0 至 100：0 表示
尚未開始，100 表示已完成；`priority` 的 RFC 合法範圍是 0 至 9，其中 0
表示未定義；`sequence` 是非負整數。`status` 保留 RFC token，標準 VTODO
值為 `NEEDS-ACTION`、`COMPLETED`、`IN-PROCESS`、`CANCELLED`；不以 closed enum
丟棄合法 extension data。上述不合法值仍由 Sabre validation pipeline 決定
level 2 warning 或 level 3 failure；若只是 warning，typed integer 保留來源值，
mapper 不靜默 clamp、猜測或改寫。數值語意分別來自
[RFC 5545 §3.8.1.8](https://www.rfc-editor.org/rfc/rfc5545.html#section-3.8.1.8)
與 [§3.8.1.9](https://www.rfc-editor.org/rfc/rfc5545.html#section-3.8.1.9)。
不得因提供 typed convenience 欄位而刪除原 Property 的 value type、parameters、
raw value 或重複順序。

與 Event 共用的 `GEO`、`COMMENT`、`CONTACT`、`RESOURCES` 依第 10.2.1 節提供相同
typed value；`RRULE`、`ATTACH`、`EXDATE`、`REQUEST-STATUS`、`RELATED-TO`、`RDATE`
提供相同 `Property` shortcuts。這些 shortcuts 不取代 `Todo::property()`／
`Todo::properties()` 或 normalized component tree；IANA properties 與 `X-*`
properties 因名稱與型別開放，仍只由 generic API 無損保留。
`LAST-MOD`、`PERCENT`、`RECURID`、`SEQ`、`RSTATUS`、
`RELATED`、`X-PROP`、`IANA-PROP` 是 RFC ABNF grammar symbol 的簡寫；實際 wire
names 分別依 §3.6.2 的 `LAST-MODIFIED`、`PERCENT-COMPLETE`、
`RECURRENCE-ID`、`SEQUENCE`、`REQUEST-STATUS`、`RELATED-TO` 及 extension
property 規則處理。

### 31.4 RFC cardinality 與關聯限制

Reader 必須以既有 Sabre/VObject validation pipeline 執行 RFC validation，不在
Todo mapper 建立第二套 validator。VTODO 的 property cardinality 為：

- 必要且最多一次：`DTSTAMP`、`UID`。
- optional 且最多一次：`CLASS`、`COMPLETED`、`CREATED`、`DESCRIPTION`、
  `DTSTART`、`GEO`、`LAST-MODIFIED`、`LOCATION`、`ORGANIZER`、
  `PERCENT-COMPLETE`、`PRIORITY`、`RECURRENCE-ID`、`SEQUENCE`、`STATUS`、
  `SUMMARY`、`URL`。
- optional 且 SHOULD NOT 超過一次：`RRULE`。
- optional 且可重複：`ATTACH`、`ATTENDEE`、`CATEGORIES`、`COMMENT`、
  `CONTACT`、`EXDATE`、`REQUEST-STATUS`、`RELATED-TO`、`RESOURCES`、`RDATE`、
  IANA properties、`X-*` properties。
- `DUE` 與 `DURATION` 各自最多一次且彼此互斥；出現 `DURATION` 時必須同時有
  `DTSTART`。
- 出現 `RRULE` 時必須有 `DTSTART`。

上述 grammar 與 cardinality 直接來自
[RFC 5545 §3.6.2](https://www.rfc-editor.org/rfc/rfc5545.html#section-3.6.2)；
`DUE` 與 `DTSTART` 同時存在時必須使用相同 DATE／DATE-TIME value type，且
`DUE` 必須晚於 `DTSTART`，詳見
[RFC 5545 §3.8.2.3](https://www.rfc-editor.org/rfc/rfc5545.html#section-3.8.2.3)。
`DURATION` 必須為正值；若 `DTSTART` 是 DATE，duration 只能使用日或週，詳見
[RFC 5545 §3.8.2.5](https://www.rfc-editor.org/rfc/rfc5545.html#section-3.8.2.5)。

`Todo::$dueAt` 與 `$duration` 使用 effective-value 規則：顯式 `DUE` 決定 due；
否則以 `DTSTART + DURATION` 推導 due。顯式 `DURATION` 決定 duration；沒有
`DURATION` 但有可比較的 `DTSTART` 與 `DUE` 時，以兩者差值推導。VTODO 沒有
VEVENT 的隱含一日 duration；資料不足時回傳 `null`。

### 31.5 Recurrence 與 alarm 語意

`RRULE`、`RDATE`、`EXDATE` 與 `RECURRENCE-ID` 只解析並保留，不展開 occurrence。
RFC recurrence set 由 `DTSTART` 加上 `RRULE`／`RDATE` inclusions，再移除
`EXDATE` exclusions；排除優先且重複 instance 合併，詳見
[RFC 5545 §§3.8.5.1–3.8.5.3](https://www.rfc-editor.org/rfc/rfc5545.html#section-3.8.5)。
Todo mapper 不自行計算 next occurrence、overdue occurrence 或 recurrence 完成狀態。

`Todo::$alarms` 必須重用既有 `Alarm` 與 `AlarmTrigger`。相對於 END 的 VTODO
alarm 必須有 `DUE`，或同時有 `DTSTART` 與 `DURATION`；此限制由既有 validation
pipeline 判定，來源為
[RFC 5545 §3.8.6.3](https://www.rfc-editor.org/rfc/rfc5545.html#section-3.8.6.3)。

### 31.6 Serialization 與相容性

- `Calendar::toComponentArray()` 對 `VTODO` 的 normalized tree 維持既有
  component/property shape，不為 typed Todo 建立第二套 wire serialization。
- `Calendar::toArray()` 增加 `todos` key，其 element shape 必須記錄並以 fixture
  鎖定；`jsonSerialize()` 與 `toJson()` 委派同一份資料。
- `Todo` 不提供 writer、`.ics` serialization、`toJson()` 或 `JsonSerializable`。
- 所有未建 typed 欄位、重複 property 與 extension data 均能由 generic
  `Property`／`Component` escape hatch 取得。

### 31.7 最低驗收

- 解析一個及多個 `VTODO`，保持文件順序。
- 覆蓋 `todos()`、`todos($uid)`、`todo($uid)`、`hasTodos()` 與
  `hasTodos($uid)`，包括 master／override 同 UID 情境。
- 覆蓋必要欄位、optional typed 欄位、重複 attendees/categories、nested alarms、
  extension properties 與 property order preservation。
- 覆蓋 Todo 與 Event 共用的 geo、文字 list 與複合 `Property` shortcuts，使用同一
  dataset 驗證型別、順序、parameters、多值邊界及 defensive Collection。
- 覆蓋 UTC、TZID、floating、DATE 型別的 `DTSTART`／`DUE`／`RECURRENCE-ID`、
  對應 DATE／floating flags，以及 UTC `DTSTAMP`／`CREATED`／`LAST-MODIFIED`／
  `COMPLETED`。
- 覆蓋 `DUE` + `DURATION`、缺少 `DTSTART` 的 `DURATION`、缺少 `DTSTART` 的
  `RRULE`、`DUE <= DTSTART` 等 RFC 違規內容；依 Sabre 實際 validation level
  確認 level 3 遵守 `read*()`／`try*()` 失敗契約，level 2 則保留 warning、原始
  properties 與本節指定的 typed precedence。
- 確認 `Calendar::toComponentArray()` 既有輸出不變，並鎖定新增 `todos` 的
  array／JSON shape。
- 確認合法但沒有 `VTODO` 的 calendar 回傳空 Collection／`null`／`false`，且
  VEVENT 行為無 regression。

### 31.8 Event／Todo 共同契約與差異

Event 與 Todo 對共同 RFC properties 必須採用同一套基本 typed mapping 語意：`UID`、
`DTSTART`、`RECURRENCE-ID`、`DTSTAMP`、`CREATED`、`LAST-MODIFIED`、
`CLASS`、`DESCRIPTION`、`LOCATION`、`ORGANIZER`、`ATTENDEE`、`CATEGORIES`、
`PRIORITY`、`SEQUENCE`、`STATUS`、`SUMMARY`、`URL`、`GEO`、`COMMENT`、`CONTACT`、
`RESOURCES`、`RRULE`、`ATTACH`、`EXDATE`、`REQUEST-STATUS`、`RELATED-TO`、`RDATE`、
nested `VALARM`、generic properties 與 raw component preservation。共同欄位不得因
分別由 `hydrateEvent()` 與 `hydrateTodo()` 建立而出現不同的型別、DATE、floating、
nullable、順序、parameter 或保留規則。

`TRANSP` 只屬於 VEVENT，因此只有 Event 提供 `transparency`。其他本節新增欄位必須
共用相同 mapper：GEO pair、重複 TEXT／TEXT-list 與具名 Property shortcuts 各只有
一套轉換邏輯；兩個 domain constructors 仍明確列出各自欄位。

兩者的 `DTSTAMP`、`CREATED` 與 `LAST-MODIFIED` 都依 RFC 解讀為 UTC DATE-TIME；
缺少時為 `null`，不以 floating timezone 補值。`DURATION` 的原始 property 解析與
保留方式相同，但 effective value 仍遵循各 component 的規則：Event 依第 11.6 節
處理 `DTEND` 與隱含結束，Todo 依第 31.4 節處理 `DUE`，不得共用一套結束時間演算法。

共同時間 flags 遵守以下規則：

- `*IsDate` 只在來源 property 的 value type 是 DATE 時為 `true`。
- DATE 與沒有 `TZID`／UTC suffix 的 DATE-TIME，其 `*IsFloating` 為 `true`；UTC 或
  有效 `TZID` 的 DATE-TIME 為 `false`。
- 顯式結束值使用該 property 自身的 flags；由 duration 或全天預設值推導的結束
  值繼承 start flags；沒有對應值時 flags 為 `false`。
- `RECURRENCE-ID` flags 只反映該 property 自身，不從 `DTSTART` 推導。無法解析
  TZID 時 typed value 為 `null`，但 flags 與 generic Property 仍保留來源語意。

對齊不代表抹除 component 語意。Todo 不新增 `allDay`、`isAllDay()`、`endsAt` 或
`lastDay`；Event 不新增 `dueAt`、`dueIsDate`、`dueIsFloating`、`completedAt` 或
`percentComplete`。實作必須讓 attendee、alarm 與共同時間判斷走共用的 internal
mapping path；direct-property 查詢依第 32 節使用 `@internal` trait。不得新增公開
base class、interface、trait 或通用欄位 array／DTO。

## 32. Direct property query 共用契約

### 32.1 適用範圍與 seam

`Calendar`、`Event`、`Todo` 與 generic `Component` 都是正式的 property-bearing
domain objects。它們的下列 public methods 必須由同一個 `@internal`
implementation 提供：

```php
/** @return Collection<int, Property> */
public function properties(?string $name = null): Collection;

public function hasProperty(?string $name = null): bool;

public function property(string $name): ?Property;
```

共用 seam 採最小 internal trait；trait 可要求 consuming class 提供自己的 ordered
`list<Property>`，但不得持有 Sabre component、改變 constructor interface、建立
public base class／interface，或使 domain objects 互相繼承。Trait 必須標示
`@internal`，使用者不需要直接引用它。

### 32.2 查詢行為

- `properties(null)` 依文件順序回傳全部 direct properties，且每次建立新的
  `Collection<int, Property>` container。
- 非 `null` 名稱先 trim；空字串或全空白名稱拋出 `InvalidArgumentException`。
- 合法名稱以大寫正規化後進行不區分大小寫的精確比對，不執行 partial、wildcard
  或 recursive search。
- `properties($name)` 保留同名重複 property 的文件順序；找不到時回傳空 Collection。
- `hasProperty($name)` 與 `property($name)` 必須委派 shared `properties($name)` path，
  不得各自重新實作 normalization 或 filtering。
- `hasProperty(null)` 回答是否存在任一 direct property；`property($name)` 回傳第一筆
  符合者，找不到時回傳 `null`。
- 呼叫端對回傳 Collection 的結構性修改不得影響後續查詢；hydrated `Property`
  snapshots 與既有 ordered storage 不得因重構而改變。

### 32.3 `rawComponent()` 保留類別實作

`rawComponent()` 不屬於 direct-property query seam。各類別必須繼續宣告並實作自己
的精確回傳契約：

```php
Calendar::rawComponent(): Sabre\VObject\Component\VCalendar;
Event::rawComponent(): Sabre\VObject\Component\VEvent;
Todo::rawComponent(): Sabre\VObject\Component\VTodo;
Component::rawComponent(): Sabre\VObject\Component;
```

每次呼叫仍須回傳 deep clone。不得為消除一行 `clone` 而將前三個 covariant return
types 弱化成 generic Sabre component，也不得讓 clone 與 hydrated snapshot 共用
可變 state。

### 32.4 向後相容與驗收

- 四個 public classes 的方法名稱、參數、回傳型別、Collection generics、例外與
  observable behavior 不變；這是 internal refactor，不是新功能。
- 使用同一 dataset 對四個 classes 驗證全部、指定名稱、大小寫、重複值、找不到、
  空字串、全空白與 defensive Collection parity。
- 分別驗證四種 `rawComponent()` 精確型別、deep clone 與 snapshot isolation。
- PHPDoc reflection guard 必須接受 trait 提供的方法，並確認每個 consuming public
  class 仍呈現完整 docblock 與 `@throws InvalidArgumentException`。
- 完整 Pest、PHPStan、Pint、Composer validation、audit 與 `git diff --check` 全部
  通過，array／JSON／component-tree outputs 不得改變。

## 33. iCalendar property 與 parameter 名稱常數化

### 33.1 目的與範圍

程式碼在讀取已知 RFC property 或 parameter 時，不得把 `STATUS`、
`PERCENT-COMPLETE`、`CN`、`ROLE` 等 wire names 散落在 hydration 與查詢邏輯。
這些名稱必須分別集中在兩個標示 `@internal` 的 constants-only modules：

- `Support\PropertyName`：套件實作會主動讀取的標準 property names。
- `Support\ParameterName`：套件實作會主動讀取的標準 parameter names。

兩者使用 PHP 8.3 typed class constants，例如：

```php
PropertyName::STATUS;             // 'STATUS'
PropertyName::PERCENT_COMPLETE;   // 'PERCENT-COMPLETE'
ParameterName::CN;                // 'CN'
ParameterName::ROLE;              // 'ROLE'
```

constant identifier 使用大寫與底線，value 保持 RFC wire name 的大寫與連字號。
Property 與 parameter 必須分類，不建立一個混合所有 token 的通用容器。
`@internal` 代表這兩個 classes 雖因 PHP visibility 可被引用，仍不屬於第 26 節
Semantic Versioning 承諾的公開 interface；使用者文件不將它們當成查詢入口。

本節只管理已知 property 與 parameter 名稱。Component names、value types、
boolean values、alarm relation values、輸出 array keys 與 fixture 內的原始 `.ics` 文字不納入
這個批次；除非後續出現獨立的重複變更需求，否則不預先建立更大的
protocol vocabulary framework。

### 33.2 Interface 與開放性

- `Reader`、`Calendar` 與其他 production code 只要以固定名稱存取已知
  property／parameter，必須引用對應 class constant。字串 literal 只保留在
  constant 宣告、原始 fixture、使用者範例與專門驗證 wire value 的測試。
- `firstProperty()`、`properties()`、`singleParameter()` 等既有 interfaces 繼續
  接受 `string`，不改成 enum 參數。`Property::$name`、`Property::$parameters` 與公開
  property query 也繼續保留原始字串。
- IANA names、`X-*` extensions 與尚未建立 typed shortcut 的標準名稱必須
  繼續可讀；constants 是套件內部已知名稱的 vocabulary，不是 allowlist。
- 不使用 backed enum。名稱查找邊界必須保持開放字串，enum 會額外引入
  `->value` 轉換，卻不能改善未知名稱的保留能力。
- 不新增 name registry、lookup map、config、factory 或名稱驗證器；Sabre 與既有
  generic Property interface 繼續負責解析及保留名稱。

### 33.3 RFC 改名與向後相容

常數化的主要價值是讓已知名稱的使用點可搜尋、可分類，並將 typo 限制在
一處；它不代表 RFC 改名時可直接改掉舊 wire value。假設未來 `ROLE` 被新規格
取代為 `ROLES`，實作必須依實際相容規則新增 `ROLES` constant 與明確的
fallback，並保留舊 `ROLE` 的讀取能力；不得只把 `ParameterName::ROLE`
的 value 改為 `ROLES` 而使舊 calendar 失效。停止讀取舊 wire name 會改變
可觀察行為，必須另依 Semantic Versioning 與當時的 RFC 相容要求決定。

### 33.4 驗收

- 盤點 `src/` 內所有用於已知 property／parameter 存取的固定名稱，並分別
  改由 `PropertyName` 或 `ParameterName` 提供；不得遺留只為過渡的別名常數。
- 現有 public method signatures、domain values、array／JSON shape、大小寫正規化、
  unknown property／parameter preservation 與 validation 行為完全不變。
- 保留以 literal wire names 寫成的整合 fixtures，證明 constants 實際能命中外部
  `.ics` 資料；再加入最小測試鎖定代表性的連字號名稱與 property／
  parameter 同時 hydration。測試不得全部以同一 constants 產生輸入，
  否則會隱藏錯誤的 wire value。
- Pint、PHPStan、相關 Pest、完整 `composer check` 與 `git diff --check` 全部通過。
