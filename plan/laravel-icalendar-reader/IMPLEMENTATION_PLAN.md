# Laravel iCalendar Reader 實作企劃書

## 1. 文件資訊

| 項目 | 內容 |
| --- | --- |
| 專案 | `mattmy/laravel-icalendar-reader` |
| 目標版本 | `1.0.0` |
| 文件用途 | 說明實作順序、架構、測試與發布方式 |
| 需求依據 | [`REQUIREMENTS.md`](./REQUIREMENTS.md) |
| 程式碼規範 | [`LARAVEL_PACKAGE_CODE_STYLE_PHP_83.md`](../code-style/LARAVEL_PACKAGE_CODE_STYLE_PHP_83.md) |
| 文件狀態 | 已評審 |

本文件不重新定義產品需求。公開 API、資料語意與驗收結果如有衝突，皆以
`REQUIREMENTS.md` 為準；本文件負責說明如何把需求逐步實作成可發布的套件。

## 2. 執行摘要

我會將套件實作成 `sabre/vobject` 之上的 Laravel-friendly read model：每次讀取
先驗證設定並解析 floating timezone，輸入再受 byte limit 保護，接著
進行嚴格 parse、完整 validation 與一次性 hydration，最後回傳 readonly
的 `Calendar` snapshot。

第一版包含五個核心責任邊界，但不要求每個責任各自成為類別；若其中一項只剩
少量且只由 Reader 使用的程式碼，應保留為具名 private methods：

1. `Reader`：公開入口與整體流程協調。
2. `BoundedInputReader`：安全讀取 string、path、stream 與 UploadedFile。
3. `CalendarValidator`：Sabre parse、root 檢查、validation 與 issue mapping。
4. `TimezoneResolver`：驗證設定並決定 floating timezone。
5. Hydration mapping：將 Sabre component tree 轉為 immutable domain objects。

第一版不建立 parser interface、來源 factory、repository、query builder、事件
subclass hierarchy 或額外 DTO framework。只有實作過程證明某段邏輯具有第二個
正式使用者，才再抽取共用類別或 trait。

### 2.1 執行、暫停與交付契約

當實作者收到「根據需求書與企劃開始實作」而沒有另外指定只做某個階段時，代表
依本企劃持續推進既定 `1.0` scope，不代表完成第一個小切片後即可把套件當成成品
交付。Vertical slice、stage 與 PR 是降低風險的開發單位，不是使用者可用性的
預設邊界。

只有以下情況可以在完整 scope 前暫停：

- 使用者明確要求只完成指定階段或 prototype。
- 發現需要使用者決策、外部權限或需求文件修正的實質 blocker。
- 本次工作環境中斷；此時必須以「未完成」交接，不能以「已完成套件」表述。

任何中途交接或 private development build 必須附上狀態表，至少列出：

1. 已完成且有測試的公開行為。
2. 尚未實作的需求書 section 與公開 APIs。
3. 已知會讓合法 `.ics` 看起來資料殘缺的 component 或 property 類型。
4. 目前可以進行的是技術驗證、功能驗收或 release 驗收中的哪一種。
5. 下一個必須完成的退出條件。

不得只因 Composer 可安裝、Facade 可呼叫、測試通過或 README 已存在，就把開發
切片描述成可正常驗收的套件版本。

## 3. 成功結果

完成後，Laravel 開發者應能用以下方式取得資料，而不必理解 Sabre/VObject：

```php
use Mattmy\ICalendar\Facades\ICalendar;

$calendar = ICalendar::fromUploadedFile($request->file('calendar'));

foreach ($calendar->events() as $event) {
    if ($event->allDay) {
        // DTSTART 的 value type 確實是 DATE。
    }

    echo $event->summary;
}
```

實作完成的判斷不是「類別都建立了」，而是需求書第 30 節的公開行為全部具有
測試、英文與繁體中文文件，並在宣告支援的 PHP／Laravel matrix 通過 CI。

## 4. 實作原則

### 4.1 風險優先

最先驗證會決定整體設計的高風險部分：Sabre validation 行為、時間型別、
floating timezone、全天事件、重複 property 與 raw component clone。若底層
套件的實際行為與需求假設不同，應先以最小 fixture 找出差異，再更新設計，
而不是讓錯誤假設擴散到所有 domain objects。

### 4.2 單一解析管線

所有輸入來源在各自完成 bounded read 後，都交給同一個 private parse／
validation／hydration path。`try*()` 只包裝對應的 throwing API 並捕捉
`InvalidCalendar`，不複製解析邏輯。

### 4.3 一次 hydration、唯讀查詢

Event、Todo、Property、Attendee 與 Alarm 在讀取時轉換一次。後續 accessor、
Collection 查詢及 serialization 不重新遍歷整棵 Sabre tree，避免結果隨底層
mutable object 改變，也避免重複轉換成本。

### 4.4 保留語意，不承諾 byte round-trip

typed fields 服務常見用途；ordered Property／Component tree 保留未知、重複
與多值資料。套件不保存原始 folding、換行與大小寫，也不提供 `.ics` writer。

### 4.5 每個切片都可驗收

每個階段會同時完成 production code、fixture、Pest 測試、靜態型別與必要文件，
不把測試與文件全部延至最後補做。

## 5. 系統架構

```text
read / fromPath / fromStream / fromUploadedFile
                     │
                     ▼
          Reader configuration checks
       max_bytes + TimezoneResolver result
                     │
                     ▼
            BoundedInputReader
          byte limit + source checks
                     │ string
                     ▼
             CalendarValidator
        strict parse → root → validate
                     │
          invalid ───┴─── valid + issues
             │                 │
             ▼                 ▼
      InvalidCalendar      Hydration mapping
                               │
                               ▼
       readonly Calendar / Event / Property / Component
                               │
                               ▼
       Collection queries / arrays / JSON / raw deep clones
```

管線固定順序如下：

1. 每次呼叫都重新讀取並驗證 `max_bytes`、`config('app.timezone')` 與 package
   floating timezone，不快取 Laravel config。
2. `max_bytes` 不合法時拋出 `InvalidConfiguration`；時區不合法時決定安全的
   effective timezone，並暫存對應 configuration warnings。
3. 依明確來源讀取，並以實際 bytes 強制限制大小。
4. 使用 Sabre Reader options `0` 解析。
5. 確認 root 是 `VCALENDAR`。
6. 呼叫 `validate()`，不使用 `REPAIR` 或 CalDAV profile。
7. `CalendarIssue::LEVEL_ERROR` 轉為 `InvalidCalendar`；
   `CalendarIssue::LEVEL_WARNING` 保留為 warnings。
8. Hydrate readonly domain snapshot，合併 configuration、validator 與 mapping
   warnings。
9. 回傳 `Calendar`；`try*()` 僅將第 4 至 7 步產生的 `InvalidCalendar` 轉成
   `null`。

## 6. 類別責任

### 6.1 公開入口

#### `Reader`

- 實作八個公開讀取方法。
- 統一配置驗證、來源讀取、解析、驗證、時區與 hydration 流程。
- 將底層一般解析錯誤轉為套件例外並保留 `$previous`。
- 只捕捉 Sabre 已知的 parsing／validation 例外，不以 `Throwable` 包住程式錯誤。
- 本身不保存單次讀取狀態，因此可安全註冊為 singleton；所有 config 每次讀取
  都重新取得及驗證。
- 不含 Laravel Facade 專屬行為。

#### `CalendarServiceProvider` 與 `Facades\ICalendar`

- 將 `Reader` 註冊為 container singleton。
- 合併並發布 `config/icalendar_reader.php`。
- 提供 Composer package discovery metadata。
- Facade 只代理同一個 `Reader` binding。

### 6.2 內部模組

#### `Support\BoundedInputReader`

- 接受 `Reader` 已驗證的正整數 `max_bytes`，對所有來源強制同一上限。
- string 使用 byte length 預先拒絕過大內容。
- path 以 PHP 的本機 stream 判斷拒絕 URL wrapper，不用一般 URI scheme 規則
  誤判 `C:\calendar.ics` 等 Windows drive path；開啟後再次以 stream metadata
  確認實際目標是可讀的一般本機檔案。
- stream 從目前位置分塊讀取，超限立即停止且不關閉 caller-owned resource。
- UploadedFile 驗證 upload 狀態後，透過同一個 bounded stream 邏輯讀取。
- 套件自行開啟的 stream 以 `finally` 關閉。

#### `Support\CalendarValidator`

- 執行 strict Sabre parse、root component guard 與 `validate()`。
- 將 parser、root 與 validator 結果映射成 `CalendarIssue`。
- 不解析 Sabre 的人類可讀 message 來猜 machine code。
- 回傳已驗證的 `VCalendar` 與 `CalendarIssue::LEVEL_WARNING` issues；
  `CalendarIssue::LEVEL_ERROR` 直接拋出 `InvalidCalendar`。

#### `Support\TimezoneResolver`

- 每次讀取先驗證 `config('app.timezone')`，即使合法的 package override 已足以
  決定解析結果，也仍保留無效 app timezone warning。
- 驗證 package override，只接受 PHP 支援的 IANA timezone identifier。
- 依需求書優先序回傳 effective timezone 與設定 warnings。
- fallback 固定為 `UTC`，不讓 Carbon 自行猜測。
- IANA 合法性採 PHP 提供的 timezone identifier 清單精確比對；不接受縮寫、
  固定 offset、空字串或 Carbon 可寬鬆解析但不在清單內的值。

#### `Support\PropertyName` 與 `Support\ParameterName`

- 作為已知 RFC property／parameter wire names 的單一宣告位置。
- 只提供 typed class constants，不解析、驗證或限制未知名稱。
- 兩個 classes 均標示 `@internal`，不屬於 Semantic Versioning 承諾的公開
  domain interface。

#### Hydration mapping

- 以文件順序建立 properties、components 與 events。
- 將已知 Sabre value 轉成 scalar、`CarbonImmutable`、`DateInterval` 或小型
  value object。
- 建立 Calendar、Event、Todo、Organizer、Attendee、Alarm 與 AlarmTrigger。
- 保留未知 property／component 及 raw value。
- mapping 不能可靠完成但文件仍合法時，加入 `mapping_warning`，不製造錯誤值。
- `mapping_warning` 固定使用 `CalendarIssue::LEVEL_WARNING`；mapping 不得將
  validator 已判定合法的文件擅自升級為 `InvalidCalendar`。
- 初期由 `Reader` 的具名 private methods 負責；只有檔案明顯失控或邏輯能獨立
  測試時，才抽出 `CalendarHydrator`、`PropertyHydrator` 或 `DateTimeMapper`。

### 6.3 Domain objects

以下類別均為 `final readonly`，並使用 private constructor 搭配 named factory，
或將 constructor／factory 明確標示 `@internal`；不提供公開手動建構或
mutation API：

- `Calendar`
- `Event`
- `Todo`
- `Component`
- `Property`
- `CalendarIssue`
- `Organizer`
- `Attendee`
- `Alarm`
- `AlarmTrigger`

`Calendar`、`Event`、`Todo` 與 generic `Component` 對 direct properties 提供相同
的 `properties()`、`hasProperty()` 與 `property()` interface。這組行為已在四個
正式類別中形成相同 invariant，因此由小型 `@internal` trait 集中名稱正規化、
篩選、存在性與 first-match implementation；各 domain object 仍保有自己的
property storage，公開 type hierarchy 與方法簽名不變。Calendar 的 direct child
component 查詢仍由 Calendar 自己負責。

內部 authoritative multi-value data 使用連續 integer-keyed lists。公開方法每次
以 list 建立新的 Collection，避免呼叫端對 `shift()`、`pop()` 等 mutable
Collection 操作改變 domain object 的後續查詢結果。需求書指定為 readonly public
property 的 Collection（例如 `Event::$attendees`）則於 hydration 時建立獨立實例，
不與 hydrator 或其他 domain object 共用；PHP 8.3 的 `readonly` 無法讓 Collection
本身 deep-immutable。依需求書第 6.4 節，本套件保證 snapshot isolation，不宣稱
巢狀 Collection 或 DateInterval 是 deep-immutable；此限制必須有測試與文件。

`rawComponent()` 每次回傳完整 deep clone，domain object 不把 hydrator 保存的
mutable Sabre instance 暴露給使用者。此方法保留在各 domain class，維持
`VCalendar`、`VEvent`、`VTodo` 與 generic Sabre component 的精確 covariant 回傳
型別；不為一行 clone 建立會弱化型別的共用 abstraction。實作前以 fixture 驗證
Sabre clone 是否確實遞迴複製 child nodes；若不是，才集中在單一 internal clone
helper 補足，不改用 serialize/unserialize。

### 6.4 Exceptions

建立 `ICalendarException` marker interface，以及：

- `InvalidCalendar`
- `CalendarFileNotFound`
- `CalendarFileUnreadable`
- `CalendarTooLarge`
- `InvalidCalendarSource`
- `InvalidConfiguration`

例外 message 只包含可操作且安全的資訊，不附上完整 calendar 內容。

## 7. 預計檔案配置

```text
packages/laravel-icalendar-reader/
├── .github/
│   ├── ISSUE_TEMPLATE/
│   ├── workflows/tests.yml
│   └── pull_request_template.md
├── config/icalendar_reader.php
├── docs/
│   ├── guide/
│   └── zh-TW/guide/
├── src/
│   ├── Concerns/QueriesProperties.php
│   ├── Exceptions/
│   ├── Facades/ICalendar.php
│   ├── Support/
│   │   ├── BoundedInputReader.php
│   │   ├── CalendarValidator.php
│   │   ├── ParameterName.php
│   │   ├── PropertyName.php
│   │   └── TimezoneResolver.php
│   ├── Alarm.php
│   ├── AlarmTrigger.php
│   ├── Attendee.php
│   ├── Calendar.php
│   ├── CalendarIssue.php
│   ├── CalendarServiceProvider.php
│   ├── Component.php
│   ├── Event.php
│   ├── Organizer.php
│   ├── Property.php
│   ├── Todo.php
│   └── Reader.php
├── tests/
│   ├── Benchmark/
│   ├── Fixtures/
│   ├── Feature/
│   ├── Unit/
│   ├── Pest.php
│   └── TestCase.php
├── CHANGELOG.md
├── CONTRIBUTING.md
├── LICENSE
├── README.md
├── README.zh-TW.md
├── SECURITY.md
├── composer.json
├── phpstan.neon.dist
├── phpunit.xml.dist
└── pint.json
```

預設先放在現有 workspace 的 `packages/laravel-icalendar-reader`，方便沿用既有
套件的開發與 CI 習慣；正式發布時該目錄應成為獨立 GitHub repository 根目錄。
第一版文件與程式碼同 repository 維護，不先建立第二個 docs repository。

## 8. 關鍵實作決策

### 8.1 Input 與大小限制

所有來源最終產生一個受限制的 string。Stream 以固定大小 chunk 讀取，每次只讀
剩餘容量，剛好達到上限時再 probe 最多一個 byte 判斷是否超限；不得直接計算
可能溢位的 `max_bytes + 1`。metadata 只作快速拒絕，不作最終依據。

`fromPath()` 只允許 plain local path。實作會先區分不存在、非一般檔案與不可讀，
再開啟本機 stream 並檢查實際 handle metadata，避免透過 wrapper 或 symlink
目標誤觸網路；但不假裝替應用程式提供 authorization sandbox。呼叫端仍需限制
終端使用者可選擇的路徑。

來源錯誤固定對應如下：

| 情況 | 例外 |
| --- | --- |
| path 不存在 | `CalendarFileNotFound` |
| UploadedFile 的暫存 backing file 在實際開啟前消失 | `CalendarFileNotFound` |
| path、已開啟檔案或 stream 無法讀取 | `CalendarFileUnreadable` |
| stream resource 類型錯誤或 UploadedFile 狀態無效 | `InvalidCalendarSource` |
| 任一來源超過實際 byte limit | `CalendarTooLarge` |
| `max_bytes` 無法安全使用 | `InvalidConfiguration` |

`tryFromPath()`、`tryFromStream()` 與 `tryFromUploadedFile()` 不改變上述 mapping。

### 8.2 Validation 與失敗策略

四種 throwing 輸入方法只負責設定驗證與 bounded read，並將取得的 string
交給同一 private parse implementation。Nullable methods 只包裝對應的 throwing
method，例如：

```php
public function tryRead(string $contents): ?Calendar
{
    try {
        return $this->read($contents);
    } catch (InvalidCalendar) {
        return null;
    }
}
```

其他 `try*()` 使用相同模式。`CalendarTooLarge`、來源、設定與程式錯誤不會被
吸收。Parser exception 會保留為 `$previous`，但 issue/message 不包含完整輸入。

### 8.3 Property 表示

每個 component 的 direct properties 使用 ordered list。查詢時以正規化後的大寫
名稱做 case-insensitive comparison：

- `property($name)` 回傳第一筆。
- `properties($name)` 回傳全部。
- `properties(null)` 回傳全部 direct properties。
- 空白名稱在查詢邊界拋出 `InvalidArgumentException`。

不建立以名稱為 key 的 authoritative map，避免覆蓋 `ATTENDEE` 等重複資料。
若效能測量證明有需要，可在 object 內建立 derived index，但 ordered list 仍是
真實資料來源。

Calendar 的 direct child components 採相同的 ordered-list 查詢原則：

- `component($name)` 使用 trim 後的名稱進行 case-insensitive comparison，並
  回傳文件順序中的第一筆同名 direct child component。
- `components($name)` 回傳全部同名 direct child components；`components(null)`
  回傳所有 direct child components。
- `hasComponent(null)` 檢查是否有任意 direct child component；
  `hasComponent($name)` 檢查是否有指定名稱的 direct child component。
- 找不到時 `component($name)` 回傳 `null`；空字串或全空白名稱拋出
  `InvalidArgumentException`。
- 查詢不遞迴 descendants，也不另外建立會改變文件順序的 authoritative map。

Hydration 必須分清楚「同名 property 重複出現」與「單一 property 具有多個
values」：`values` 永遠是 list；零值時 `value` 為 `null`，單值時為該 typed
value，多值時 `value` 與 `values` 使用相同 list 內容。名稱與 parameter keys
正規化為大寫，type 正規化為 Sabre 能確認的小寫 canonical name；無法確認時用
`unknown` 並保留 raw value，不根據字串外觀猜型別。

Event collection 查詢使用 UID 語意：`events(null)` 回傳全部 hydrated events；
`events($uid)` 以 typed `UID` 作精確、大小寫敏感比對，保留文件順序並回傳新的
Collection。空字串不做 structural-name validation，也不 trim。非 `null` 查詢值
不匹配 `uid === null` 的 internal Event；一般讀取流程則會先由 validation 拒絕
缺少必要 UID 的不合法文件。`hasEvents($uid)` 必須直接使用相同篩選結果判斷是否
存在，不得重寫另一個迴圈或比較規則。

`event($uid)` 使用同一套精確、大小寫敏感 UID 比對，但只回傳一筆：先記住文件
順序中的第一個符合者，遇到沒有 `RECURRENCE-ID` 的 recurrence master 時立即
回傳 master；若全部符合者都是 overrides，則回傳第一個符合者；沒有符合者時
回傳 `null`。此單筆選擇不得刪除或重排 `events($uid)` 的完整集合。

### 8.4 日期、floating time 與全天事件

Mapper 必須同時讀取 typed value 與原始 property metadata，不能只看轉成
`DateTime` 後的結果：

- `Z` 保留 UTC。
- `TZID` 交由 Sabre／calendar `VTIMEZONE` 解讀，失敗時不偷換 UTC。
- 沒有 `Z`、`TZID` 且為 DATE-TIME 時，使用 resolved floating timezone，並將
  floating flag 設為 `true`。
- Hydration 只依 `DTSTART` 的 value type 是否為 `DATE` 建立 `Event::$allDay`；
  `isAllDay()` 直接回傳該 readonly property，不重複判斷。
- 午夜、24 小時或跨日均不作全天推測。
- 全天 `DTEND` 保持 exclusive；`lastDay` 是額外 convenience value。
- 只有 `DTSTART + DURATION` 時，以 duration 推算 `endsAt`；有 `DTEND` 時，
  `DTEND` 決定 `endsAt`。
- 全天事件只有 DATE `DTSTART` 時，以 calendar-day arithmetic 建立下一日的
  implicit exclusive `endsAt`、一日 effective duration 與等於開始日的
  `lastDay`；不固定加 86,400 秒。
- `Event::$duration` 表示 effective duration：只有 `DURATION` 時使用該值；只有
  可比較的 `DTSTART`／`DTEND` 時使用兩者差值；兩者同時存在且一致時使用共同
  duration。若 `DTEND` 與 `DURATION` 矛盾且 Sabre 僅回報 level 2，typed
  `endsAt` 與 effective duration 都以 `DTEND` 為準，原始 `DURATION` 仍留在
  property bag；若 Sabre 回報 level 3，文件直接無效。
- 由 `DTSTART`／`DTEND` 推導 duration 時，先轉成 PHP 原生
  `DateTimeImmutable` 再呼叫 `diff()`，避免不同 Carbon 版本讓
  `DateInterval::$days` 變成 `false`。

相關轉換全部以 immutable date-time 執行，並以 DST fixture 驗證「一天」不是
固定 86,400 秒。

### 8.5 Serialization

`Calendar::toArray()` 使用固定 snake_case keys，缺值保留 `null` 或空 list。
`Calendar::jsonSerialize()` 直接回傳 `toArray()`，`Calendar::toJson()` 強制合併
`JSON_THROW_ON_ERROR`。`CalendarIssue` 依需求書提供 `toArray()` 與
`jsonSerialize()`；`Property::toArray()` 提供 normalized property shape，作為
`Calendar::toComponentArray()` 的唯一 property 轉換來源。其他 domain objects
不額外承諾獨立 serialization API，其資料由 Calendar 的 domain-oriented output
統一轉換。

`toComponentArray()` 從 hydrated generic component tree 輸出 normalized 語意，
保留順序、重複、多值、parameter 與 unknown data。Calendar 與 recursive component
serialization 必須呼叫 `Property::toArray()`，不保留 private duplicate mapper。
它不呼叫 Sabre writer，也不宣稱可以 round-trip 回 `.ics`。

### 8.6 `eventsBetween()`

查詢只操作已 hydrated 的 events，採 half-open overlap：

```text
event_start < until AND effective_event_end > from
```

只有開始 DATE-TIME、沒有結束資訊的零長度事件改以
`from <= startsAt < until` 判斷。只有 DATE `DTSTART` 的全天事件使用隱含一日的
exclusive end。沒有可用開始時間的事件排除；recurrence master 與 overrides
各自判斷，不展開不存在於文件中的 occurrences。

## 9. 分階段實作

本節的「階段」是開發順序，比需求書第 27 節的產品交付 Phase 更細，不是另一套
scope。對應關係如下：

| 需求書交付 | 本企劃開發階段 |
| --- | --- |
| Phase 1：核心 MVP | 階段 0 至 4；階段 3／4 提供當期 domain 與 component serialization |
| Phase 2：事件與待辦相關資料 | 階段 5，接著執行第 21 節 typed VTODO 與第 22 節 core property coverage |
| Phase 3：穩定版準備 | 第 23 節 direct-property 共用化、第 24 節名稱常數化、階段 6 與階段 7 |

開發可以用較小 PR 依序完成，但對外宣稱某個產品 Phase 完成前，必須具備該列的
全部行為，不能把暫時可運作的 vertical slice 當成完整 MVP。

階段順序同時是資料完整性的依賴順序：階段 3 的 generic Property／Component
escape hatch 必須先於或與階段 4 的 typed Event convenience API 一起交付。不得
只完成 Event fields 就請使用者驗收「讀取 `.ics`」，因為沒有 `VEVENT` 的合法
calendar 會因此看似沒有資料。

第 21、22 節是 Phase 2 的正式實作步驟；第 23、24 節是進入固定輸出與
1.0 品質閘門前的內部收斂工作。完整順序為階段 5、第 21 節、第 22 節、
第 23 節、第 24 節、階段 6、階段 7。後補章節的文件編號不代表可延至
1.0 品質閘門之後處理。

### 階段 0：技術探勘與套件骨架

交付內容：

- 建立獨立 package skeleton、Composer metadata、autoload 與 Testbench/Pest。
- 鎖定能解出 PHP 8.3、Laravel 11／12／13、Sabre 5 的依賴範圍。
- 建立最小 Sabre spike fixtures，確認 parse options、validate issue shape、
  value type、TZID、DATE 與 deep clone 的實際行為。
- 加入 Pint、PHPStan、Composer validation 與基本 GitHub Actions。

退出條件：

- 套件可安裝、autoload 並執行空測試集。
- 技術探勘結果都有 regression test，不只留在筆記。
- 若依賴 matrix 無法成立，先縮小宣告範圍並更新需求，不帶著假設繼續。

### 階段 1：輸入安全與 Laravel 整合

交付內容：

- `BoundedInputReader` 與來源、大小及設定相關例外。
- 四種 throwing source methods 的 byte limit 與 resource ownership 行為。
- 設定檔、Service Provider、container binding 與 Facade。

核心測試：

- string、path、stream、UploadedFile 的成功、空白、無效來源與超限。
- metadata 與實際 bytes 不一致時仍能拒絕。
- stream current position、超限立即停止及 caller stream 不被關閉。
- URL wrapper、失效 upload、symlink target 與 unreadable file。
- 無效 `max_bytes` 一律拋出 `InvalidConfiguration`。

退出條件：四種來源都能安全產生相同 bytes，尚未需要複製 parser 邏輯。

### 階段 2：嚴格解析、完整驗證與 issue model

交付內容：

- `CalendarValidator`、`CalendarIssue` 與 `InvalidCalendar::issues()`。
- Parser、非 VCALENDAR root、level 2／3 mapping。
- 八個 `read*()`／`try*()` 的完整失敗契約。

核心測試：

- syntax error、非 calendar root、validation warning 與 validation error。
- 確認未啟用 `Reader::OPTION_FORGIVING`、
  `Reader::OPTION_IGNORE_INVALID_LINES`、`Node::REPAIR` 或
  `Node::PROFILE_CALDAV`。
- `try*()` 只捕捉 `InvalidCalendar`。
- `$previous` 被保留，exception 與 issue 不洩漏完整輸入。

退出條件：任何入口對同一份內容都有一致的合法性判定與 issue 結構。

### 階段 3：通用資料保留與 Calendar snapshot

交付內容：

- `Property`、generic `Component` 與 `Calendar`。
- Ordered/repeated/multi-value properties、parameters、unknown types 與 X-*。
- Calendar metadata、`properties()`、`property()`、`hasProperty()`、
  `hasComponent()`、`component()`、`components()` 與 warnings。
- 完整 normalized `toComponentArray()`，鎖定順序、重複、多值、parameters 與
  unknown data 的保留方式。
- `rawComponent()` deep clone。

核心測試：

- 同名 property 不覆蓋、property 與 component 文件順序不改變。
- 零值、單值、多值 property 的 `value`／`values` 契約，以及多值 parameters。
- Property 與 component 名稱查詢大小寫不敏感、空白名稱拋出例外、查詢不遞迴。
- `Calendar::component()` 回傳第一個同名 direct child，找不到時回傳 `null`；
  `components($name)` 仍保留並回傳所有同名 direct children。
- `Calendar::hasComponent()` 覆蓋無參數、指定名稱、大小寫不敏感、空白名稱與
  非遞迴查詢。
- VTODO、VJOURNAL、VFREEBUSY、VTIMEZONE 與 X-* 均可取得。
- `RRULE`、`RDATE`、`EXDATE` 與 `RECURRENCE-ID` 透過通用 Property API 保留
  Sabre 已解析的語意，但沒有 occurrence expansion API，也不承諾原始 bytes。
- 以只有 `VFREEBUSY`、沒有 `VEVENT` 的 fixture 驗證 Calendar 的 direct
  properties、generic component、重複 `FREEBUSY`、`FBTYPE` parameters、folded
  多值 PERIOD、Unicode COMMENT 與 URL 都能從公開 API 取得。
- 修改 raw clone 不影響 snapshot 或下一次 clone。

退出條件：即使尚未有 typed Event fields，也不會丟失 Sabre 已解析的資料語意。

### 階段 4：Event 與時間語意

交付內容：

- Event 的 UID、summary、description、location、開始／結束時間與 timestamps。
- `TimezoneResolver`、UTC／TZID／floating／DATE mapping。
- `allDay`、`isAllDay()`、`startsAt`、`endsAt`、DATE／floating flags、`lastDay`、
  duration，以及 typed `RECURRENCE-ID` value／flags。
- Calendar `events(?string $uid = null)`、`event(string $uid)` 與
  `hasEvents(?string $uid = null)`。
- Calendar 的 Phase 1 `toArray()`、`jsonSerialize()` 與 `toJson()`；此時輸出只承諾
  涵蓋已完成的 Phase 1 fields，尚未視為 `1.0` fixed shape。

核心測試：

- 合法／無效 app timezone 與 package override 的所有優先序組合。
- 每個無效設定來源各自產生 warning，effective timezone 正確公開。
- `allDay`、`isAllDay()` 與 `startIsDate` 對 DATE、DATE-TIME、缺 DTSTART、午夜
  與 24 小時事件，並驗證三者永遠一致。
- Explicit／implicit exclusive all-day end、DST、DURATION-derived end 與衝突處理。
- 在 lowest 與 stable dependency 組合驗證由 `DTSTART`／`DTEND` 推導的
  `DateInterval::$days`，防止 Carbon 版本差異使其變成 `false`。
- 無法解析文件 TZID 時 typed accessor 為 null，且不套用 UTC fallback。
- `events($uid)` 對 UID 的多筆、無結果、空字串與大小寫行為，
  並驗證 `hasEvents($uid)` 與其存在性結果一致。
- 缺少必要 UID 的文件在建立 Calendar 前被 validation 拒絕；若測試直接涵蓋
  internal hydration，另驗證 `uid === null` 不符合任何非 `null` UID 查詢。
- 相同 UID 的 master／override 保留順序，以及 `event($uid)` 的單筆選擇規則。
- `startIsDate`／`endIsDate` 與 typed recurrence value／flags，涵蓋 explicit end、
  duration-derived end、隱含全天結束、缺少結束值及無法解析的 recurrence TZID。

退出條件：所有時間行為能僅透過公開 API 驗證，且使用者不必理解
`VALUE=DATE` 才能可靠判斷全天。

### 階段 5：事件關聯資料

交付內容：

- Organizer、Attendee、Alarm、AlarmTrigger、categories、URL、priority、sequence、
  status、classification、GEO、TRANSP、comments、contacts 與 resources。
- RRULE、ATTACH、EXDATE、REQUEST-STATUS、RELATED-TO、RDATE 的具名
  `Property`／`Collection<int, Property>` shortcuts；IANA／`X-*` 保持 generic。
- `mailto:` convenience email、parameter preservation、delegation lists。
- Relative／absolute trigger 與 RELATED semantics。
- 匿名化 Google、Outlook、Apple fixtures。

核心測試必須確認 `AlarmTrigger` 恰好是 relative 或 absolute 其中之一；relative
未指定 `RELATED` 時回傳 `START`，absolute 的 `relatedTo()` 固定為 `null`。角色、
狀態、CU type 與未知 parameter 保留原值，不因 closed enum 丟失廠商擴充資料。
另以 RFC 5545 §3.6.1 matrix 逐項驗證 Event core properties，涵蓋 GEO 座標順序、
TRANSP 缺省與明確來源值、重複文字欄位、複合 Property shortcuts 及 defensive
Collections。

退出條件：跨三種主要 calendar client 的常見 events 能映射，重複 attendees、
alarms 與未知參數不遺失。

### 階段 6：查詢與輸出契約

交付內容：

- `eventsBetween()`。
- 完成 `Calendar::toArray()`、`Calendar::jsonSerialize()` 與 `Calendar::toJson()` 的
  `1.0` fixed shape，並完成 `CalendarIssue::toArray()`／`jsonSerialize()` 與
  `Property::toArray()`。
- 複查階段 3 的 `toComponentArray()` normalized contract；其 property entries
  委派 `Property::toArray()`。不為 Event、Component、Organizer、Attendee、Alarm
  或 AlarmTrigger 額外新增需求書未定義的 public serialization methods。

核心測試：

- Half-open boundary、overlap、顯式／隱含結束的全天事件、DATE-TIME 零長度、
  缺 DTSTART 與 recurrence 限制。
- 固定 keys、snake_case、ISO 8601、date-only、duration、空 list 與 null。
- Event array 涵蓋需求書第 10.2 節的全部 typed fields，包括 `start_is_date`、
  `end_is_date`、`recurrence_id` 與其 flags；`is_all_day` 固定來自
  `Event::$allDay` 且與 `Event::isAllDay()`、`start_is_date` 相同，不得另增
  重複的 `all_day` key。
- GEO 使用固定 latitude／longitude shape；RRULE 與所有複合 shortcuts 委派
  `Property::toArray()`，文字多值輸出 list，不建立第三套 property serializer。
- Todo array 涵蓋需求書第 31.3 節的全部 typed fields，並鎖定 DATE／floating
  flags、effective due／duration 與 nested alarms；warnings 固定使用
  `CalendarIssue::toArray()`。
- JSON options 與 `JSON_THROW_ON_ERROR`。
- normalized tree 的順序、重複、多值及 unknown preservation。
- `Property::toArray()` 固定六個 keys，並與 Calendar 根層及 recursive component
  tree 中的 property entries 完全相同。

退出條件：公開輸出 shape 以 snapshot test 或精確 array assertion 鎖定，避免
1.0 後無意造成 breaking change。

### 階段 7：文件、品質與 1.0 發布準備

交付內容：

- 英文 `README.md` 與繁體中文 `README.zh-TW.md`，英文為權威版本。
- `docs/guide` 英文 guide 與 `docs/zh-TW/guide` 繁體中文 guide；雙語涵蓋安裝、
  快速開始、所有來源、API、時間、validation、限制與安全。
- CHANGELOG、CONTRIBUTING、SECURITY、MIT license 及 GitHub templates。
- 小型、100 events、1,000 events／接近上限的 benchmark fixtures。
- 可重複執行的 benchmark command 與基準紀錄，用於比較明顯 regression；不把
  開發機器的單次毫秒數當成 1.0 SLA。
- 完整 PHP／Laravel CI matrix 與 release checklist。
- 完成第 19 節 PHPDoc 契約與第 20 節需求符合性補強，包括 method docblocks、
  必要行為測試、client fixtures、雙語文件、CalendarIssue constants 與
  Property normalized array interface；PHPDoc 部分包含 Collection generics、
  array shapes、value unions、`@throws`、`@internal`、Facade annotations 與
  reflection 防退化測試。

退出條件：需求書第 30 節及其引用的第 31 至 33 節逐項通過，才能建立
`v1.0.0` annotated 或 signed tag、
GitHub Release 與 Packagist 版本。

## 10. 測試策略

### 10.1 測試分層

- Unit：名稱正規化、timezone resolver、interval 判斷、array shape 與小型 value
  object invariants。
- Integration：以真實 `.ics` 經 Sabre parse、validate、hydrate，驗證公開 API。
- Laravel feature：package discovery、config publish、container 與 Facade。
- Compatibility：Google、Outlook、Apple 及 PHP／Laravel matrix。
- Regression：每個真實 bug 建立最小匿名 fixture，修復前先讓測試失敗。

不 mock Sabre parser；只有不涉及 parsing 的純邏輯適合 unit test。日期斷言必須
同時驗證 instant、timezone、floating flag 與全天語意，不能只比格式化字串。

Validation 測試除了斷言 level 與 message，也必須驗證穩定的 `code`、`source`、
可取得時的 component／property，以及 parser exception 的 `$previous`。Line number
只能在 Sabre 提供結構化位置時填入，不得解析 message 猜測。

### 10.2 Fixture 管理

Fixtures 依用途分類：

```text
tests/Fixtures/
├── invalid/
├── validation/
├── dates/
├── properties/
├── recurrence/
├── clients/google/
├── clients/outlook/
├── clients/apple/
└── performance/
```

手寫最小 fixture 用於精確邊界；vendor fixtures 用於互通性。真實資料必須移除
姓名、email、地點、會議內容與不可散布資料後才可提交。

### 10.3 每次提交的品質閘門

```bash
composer validate --strict
vendor/bin/pint --test
vendor/bin/phpstan analyse
vendor/bin/pest
composer audit
```

需要自動修改格式時先執行 `vendor/bin/pint`，再重跑上述唯讀檢查。測試不得
連線至網路，也不得依賴開發機器的預設 timezone。

## 11. CI 與相容性策略

CI 至少分成兩類：

1. 快速品質工作：在主要支援組合執行 Pint、PHPStan、Pest、Composer
   validation 與 audit。
2. 相容 matrix：對每個可解析的 PHP 8.3+ 與 Laravel 11／12／13 組合執行
   測試，並明確排除上游依賴無法成立的組合。

Composer 的宣告範圍最後由實際 matrix 決定，不先承諾 CI 沒跑過的組合。
Dependency 使用 lowest 與 stable 兩種解析方式，可提早發現最低版本宣告不實。

## 12. 文件策略

文件以使用者任務排序，不以 class list 排序：

1. 30 秒讀取第一個 calendar。
2. 從 upload、path、stream 或 string 讀取。
3. 取得事件，並以 `allDay`、`isAllDay()` 或 `startIsDate` 判斷全天。
4. 處理時區與 floating time。
5. 選擇 throwing 或 nullable 失敗流程。
6. 取得 attendees、alarms、重複與未知 properties。
7. 查詢時間範圍及理解 recurrence 限制。
8. 安全限制與問題排查。

英文文件先確定技術語意，再於同一個變更同步繁體中文。兩種語言頂部互相連結，
繁體中文使用 `zh-TW` 並標示英文為權威版本。程式碼範例共用測試或至少做語法
驗證，避免雙語文件逐漸產生不同 API。Changelog、CONTRIBUTING、SECURITY 與
內部設計文件維持英文即可。

## 13. 發布流程

### 13.1 Pre-release

- 先發布 `0.x` 版本讓公開 API 經過真實 `.ics` 驗證。
- 每一個 pre-release 都維護 CHANGELOG，不把尚未完成的功能寫成已支援。
- 在 API freeze 前完成 method/property naming review，特別檢查時間與輸出語意。

### 13.2 `1.0.0` 閘門

- 所有必要行為、failure path 與 fixtures 通過。
- 支援 matrix、lowest dependencies、PHPStan 與安全檢查通過。
- 所有 public classes、methods、properties 與 typed class constants 具備原生型別；
  Collection generics、array shapes 與正常失敗路徑的 `@throws` 已完整標註。
- 英文及繁體中文核心文件同步。
- 受保護預設分支的 PR 已通過完整 CI。
- Composer metadata、license、support links 與 Packagist package name 正確。

### 13.3 正式發布

1. 合併 release PR。
2. 在通過 CI 的 commit 建立 annotated 或 signed `v1.0.0` tag。
3. Push tag 並建立相同版本的 GitHub Release。
4. 確認 Packagist 已索引版本。
5. 重新執行乾淨環境安裝與 README quick-start smoke test。

## 14. 主要風險與控制方式

| 風險 | 控制方式 |
| --- | --- |
| Sabre 實際型別或 validation 行為與假設不同 | 階段 0 先做 spike，所有發現轉為 fixture |
| 日期或 DST 語意錯誤 | 分開測試 UTC、TZID、floating、DATE、DST 與 exclusive end |
| Typed API 遺失未知資料 | Ordered Property／Component tree 作 escape hatch |
| `try*()` 隱藏來源錯誤 | 只捕捉 `InvalidCalendar`，為每種其他例外寫測試 |
| 大檔造成記憶體壓力 | 解析前以實際 bytes 強制上限，超限立即停止 stream |
| Raw Sabre object 破壞 readonly snapshot | 每次 `rawComponent()` 回傳 deep clone |
| API 在 1.0 後難以調整 | 0.x 真實驗證、固定輸出 contract tests、發布前 naming review |
| 雙語文件內容分歧 | 同 PR 同步核心頁面，程式碼範例納入檢查 |
| 範圍膨脹拖延發布 | 嚴守非目標清單，新需求先驗證是否直接改善讀取體驗 |

## 15. 明確延後項目

以下內容不會在實作途中順手加入：

- `.ics` generator、writer 或 `toIcs()`。
- 遠端 URL reader、HTTP client 或 CalDAV。
- Recurrence expansion。
- VJOURNAL、VFREEBUSY 的 typed domain classes。
- Eloquent、同步、queue、scheduler 或 UI。
- 可替換 parser interface 與自訂 query builder。

遇到上述需求時先記入後續版本候選，不讓它阻擋 1.0 的 reader 品質。

## 16. 第一個實作批次

企劃核准後，第一個批次必須先建立不遺失資料的最小讀取骨架，再加入 Event
convenience fields：

1. 建立 package skeleton、Composer 依賴與 Pest。
2. 實作 `Reader::read()` 的 string byte limit。
3. 以 Sabre options `0` parse、確認 VCALENDAR root 並執行 `validate()`。
4. 建立 `CalendarIssue`、`InvalidCalendar` 與最小 `Calendar`。
5. 實作 ordered `Property`、generic `Component`、Calendar
   `properties()`／`hasComponent()`／`component()`／`components()` 與
   `toComponentArray()`；先確保任何合法 direct property、重複 property 與未知
   component 都能取得。
6. 以至少兩份 integration fixtures 驗證資料完整性：一份 `VEVENT` calendar；
   一份只有 `VFREEBUSY`、包含重複 FREEBUSY、parameters、folding 與 Unicode 的
   calendar。
7. 在 escape hatch 已成立後，建立最小 `Event` convenience fields，讀出 UID、
   SUMMARY、DTSTART、`allDay`、`isAllDay()` 與 `startIsDate`。
8. 為 invalid syntax、非 VCALENDAR root、validation level 3、DATE 與 DATE-TIME
   建立 integration tests。

這個批次同時驗證「容易取得常用 Event 資料」與「未 typed 資料不會消失」兩個
核心價值。此時 domain class 仍只包含該批次需要的最小 internal shape，尚不視為
公開 API freeze，也不代表 Phase 1 或核心 MVP 完成。完成後必須繼續依階段退出
條件推進；若因 blocker 暫停，依第 2.1 節明確列出所有未完成 API，不得把此批次
單獨描述為可供完整功能驗收的 package。

## 17. 企劃驗收清單

開始實作前，需要確認：

- [ ] 接受以 `packages/laravel-icalendar-reader` 作為初始開發位置。
- [ ] 接受本文的五個核心責任邊界；hydration 初期保留於 `Reader`，不為此預先
  加入獨立 service、interface 或 factory。
- [ ] 接受先做 vertical slice，再依階段擴充完整 API。
- [ ] 接受 vertical slice 不是預設停點；未明確限制 scope 時持續推進至既定
  `1.0` 完成條件，或依第 2.1 節以未完成狀態交接。
- [ ] 接受 0.x 驗證後才 freeze 並發布 `1.0.0`。
- [ ] 接受英文為權威文件、繁體中文同步維護。
- [ ] 接受 generator、URL reader 與 recurrence expansion 留在 1.0 之外。

以上項目確認後，實作將從第 16 節的第一個批次開始，並以各階段退出條件作為
進度回報與合併判斷，而不是只用完成檔案數量衡量進度。

## 18. 執行歧義修正紀錄

### 18.1 發現的問題

原企劃第 9 節將 generic Property／Component 安排在階段 3，並要求先建立不遺失
資料的 escape hatch；但原第 16 節「第一個實作批次」直接從 parsing 跳到最小
Event fields，省略階段 3。這讓「照第一批次執行」與「照階段依賴順序執行」產生
兩種合理但結果不同的解讀。

同時，原企劃沒有定義收到「開始任務」後何時可以暫停，也沒有要求中途交接列出
尚未實作的公開 API。結果是技術切片在 Composer、Facade、README 與測試都可運作
後，被錯誤描述成可供套件功能測試的版本；使用者以只有 `VFREEBUSY` 的合法文件
測試時，`events()` 正確為空，但缺少 `components()`／`properties()` 使其餘資料
無法從主要 API 取得，看起來像 parser 遺失資料。

### 18.2 責任判定

- `REQUIREMENTS.md` 已明確要求 Property、generic Component、VFREEBUSY 保留與
  `toComponentArray()`，產品需求本身沒有漏寫。
- `IMPLEMENTATION_PLAN.md` 原第 16 節的批次內容與第 9 節順序不一致，是主要文件
  問題。
- 執行時沒有以「未完成版本」列出缺少 API，並在完整 Phase 1 前停止，是交付與
  溝通問題。

### 18.3 修正

- 第一批次改為先完成 Property／generic Component escape hatch，再加入 Event
  convenience fields。
- 第一批次強制加入一份只有 VFREEBUSY 的資料完整性 fixture。
- 第 2.1 節明確定義開始、暫停與中途交接契約。
- 需求書第 1.1 節明確區分「可安裝」、「測試通過」、「Phase 完成」與「1.0
  完成」。

本紀錄保留在企劃中，避免後續實作者再次把開發里程碑誤認為產品完成狀態。

## 19. PHPDoc 契約補強計畫

### 19.1 問題與修復範圍

需求書第 4.5、22、24.6 與 30 節，以及
`plan/code-style/LARAVEL_PACKAGE_CODE_STYLE_PHP_83.md` 已要求：每個方法具有
有意義的 PHPDoc、Collection 標示 generic、已知陣列標示 array shape、正常輸入
可能觸發的例外標示 `@throws`。現有實作雖有原生型別與部分 PHPDoc，仍未把上述
規則一致套用到 `packages/laravel-icalendar-reader/src` 的全部類別。

本次工作只補強真實契約，不改變公開 API、執行結果、例外階層或序列化 shape。
若盤點時發現 PHPDoc 無法誠實描述目前行為，應先記錄為獨立功能問題；不得用
不正確註解掩蓋實作缺陷，也不得在 PHPDoc 工作中順手擴充功能。

掃描範圍包含：

- `Reader`、`Calendar`、`Event`、`Property`、`Component` 與
  `CalendarIssue`。
- `Organizer`、`Attendee`、`Alarm` 與 `AlarmTrigger`。
- `Support`、`Exceptions`、Service Provider 與 Facade。
- Constructor、static factory、public／protected／private methods、promoted public
  properties，以及 Facade 的 `@method` 宣告。

測試檔案不強制為 Pest closures 加入 PHPDoc；本階段只處理套件 `src` 的正式
程式碼。既有 Sabre stub 只在靜態分析確實需要時調整，不把第三方 API 文件複製
進套件。

本節是橫跨各實作階段的品質工作，不是獨立的產品 Phase。新增或修改 method 時
應在同一個切片完成對應 PHPDoc；既有缺口最遲於階段 7 的 `1.0` 品質閘門前全部
補齊，不得集中到發布後處理。

### 19.2 PHPDoc 完整性標準

每個 method docblock 必須先用一句話描述責任或結果，不以「Get X」、方法名稱
改寫或重複原生 signature 充數。接著只加入原生型別無法表達、或使用者需要知道
的契約：

- `Collection` 回傳與 public property 使用
  `Collection<int, Event>`、`Collection<int, Property>` 等精確 key／value generic。
- Ordered list 使用 `list<T>`；以名稱為 key 的資料使用
  `array<string, T>`；固定結構使用完整 array shape。
- `Calendar::toArray()`、`toComponentArray()`、`CalendarIssue::toArray()` 與所有
  internal serialization helpers 的 shape 必須與實際固定 keys、nullable values
  及巢狀 list 一致。為避免重複超長 shape，可在同一類別使用 PHPStan local type
  aliases，但 public method 仍要讓 IDE 與 PHPStan 能追蹤到最終結構。
- `Property::$value`、`$values` 與 mapper 回傳值必須列出目前真正支援的 scalar、
  `CarbonImmutable`、`DateInterval`、list 與 `null` union；不得只寫無資訊的
  `mixed`，也不得宣告實作尚未產生的型別。
- Promoted public Collection properties 必須有 `@var Collection<int, T>`；
  `DateInterval`、exclusive end、floating flag 等非直覺語意，使用簡短 property
  說明補足，不重複 `?string`、`?int` 等已由原生型別完整表達的資訊。
- Hydration-only constructors／factories 保留 `@internal`，並完整描述 list、array
  與 Collection 參數；公開 API 不應因補文件而誤把 internal constructor 宣告成
  建議使用方式。
- 正常呼叫可能觸發的穩定例外使用 `@throws`。不可達的程式錯誤或單純重複底層
  所有例外不列入；轉換後的 package exception 才是公開 Reader 契約。
- `@param`／`@return` 若沒有補充 generic、shape、語意或限制，應省略，避免與
  原生型別形成兩份可能分歧的文件。

### 19.3 例外文件矩陣

實作前先依實際 call path 建立例外矩陣，再寫 `@throws`：

| API 群組 | 必須核對的正常失敗 |
| --- | --- |
| `Reader::read()`／`tryRead()` | `CalendarTooLarge`、`InvalidConfiguration`，throwing 版本另含 `InvalidCalendar` |
| Path methods | 上述例外加 `CalendarFileNotFound`、`CalendarFileUnreadable` |
| Stream methods | 上述例外加 `InvalidCalendarSource`、`CalendarFileUnreadable` |
| UploadedFile methods | 上述例外加 `InvalidCalendarSource`、`CalendarFileUnreadable`；暫存 backing file 在開啟前消失時另含 `CalendarFileNotFound` |
| `properties()`／`property()`／`hasProperty()`／`hasComponent()`／`component()`／`components()` | 非 `null` 空白名稱造成的 `InvalidArgumentException` |
| `eventsBetween()` | `from >= until` 造成的 `InvalidArgumentException` |
| `toJson()` | JSON 編碼失敗時的 `JsonException` |

`try*()` 只移除 `InvalidCalendar`，其他來源、大小與設定例外仍須保留在 PHPDoc；
不得因回傳型別是 nullable 就讓使用者誤以為所有失敗都會變成 `null`。Private
methods 只標示其呼叫者確實需要理解或靜態分析需要傳遞的例外。
`events($uid)` 與 `hasEvents($uid)` 不驗證 structural name，空字串不會造成
`InvalidArgumentException`，因此不需要 `@throws`。

### 19.4 執行順序

#### 批次 1：建立契約盤點表

1. 以 `src/**/*.php` 為 authoritative file list。
2. 列出每個 declared method、promoted public property、現有 docblock、Collection、
   array、`mixed` 與可能例外。
3. 對照需求書第 22 節簽名、第 10 至 18 節語意及實際測試，先確認行為再寫文件。
4. 將缺口分類為：缺少 summary、generic、shape、value union、`@throws`、
   `@internal` 或 Facade annotation。

退出條件：每個正式 class 都出現在盤點表，不能只修正公開 domain models 而漏掉
Support、Exceptions 或 Service Provider。

#### 批次 2：公開 API 與 domain objects

依序處理 `Calendar`、`Event`、`Property`、`Component`、`CalendarIssue`、
`Organizer`、`Attendee`、`Alarm`、`AlarmTrigger` 與 `InvalidCalendar`：

1. 先補 class purpose 與公開 method responsibility。
2. 補 Collection generics、public property generics 與 Property value union。
3. 鎖定 domain／normalized array shapes。
4. 補查詢、JSON 與 deep-clone API 的例外及非直覺語意。

退出條件：使用者只看 IDE hover 即能理解回傳集合元素、nullable 行為、全天／
floating／exclusive end、raw clone 與 serialization 契約，不必閱讀 Sabre 原始碼。

#### 批次 3：輸入、Laravel 整合與 internal pipeline

依序處理 `Reader`、`BoundedInputReader`、`TimezoneResolver`、所有 package
exceptions、Service Provider 與 Facade：

1. 依第 19.3 節補齊八個 Reader methods 的差異化 `@throws`。
2. 說明 stream ownership、current position、local path 與 byte limit。
3. 為 validator／hydrator／mapper helpers 補責任、input/output shapes 與必要
   internal exceptions。
4. 核對 Facade `@method` 與 Reader public signatures 完全一致。

退出條件：throwing／nullable API、四種來源及 configuration warning 的文件不會
互相矛盾，Facade 與 dependency injection 看到相同的方法契約。

#### 批次 4：自動化防退化與完整檢查

1. 加入一個小型 architecture test，以 reflection 掃描 `src` 中 package 自行
   宣告的方法，確認每個方法都有 doc comment；不檢查繼承自 framework／第三方的
   methods，也不以固定註解文字作 snapshot。
2. 同一個 guard 掃描 constructor-promoted public properties；原生型別無法表達
   Collection generic、list element、Property value union 或非直覺語意時，確認
   property 本身具有 Reflection 可取得的 doc comment，不以 constructor `@param`
   取代 property `@var`。
3. PHPStan 負責驗證 generics、array shapes、value unions 與實際回傳值相容；
   不新增 baseline 或 ignore 來隱藏問題。
4. Pint 負責 PHPDoc 排版，但不能當作語意完整性的證明。
5. 執行窄範圍 architecture test 後，再依序執行 `composer validate --strict`、
   `vendor/bin/pint --test`、`vendor/bin/phpstan analyse`、`vendor/bin/pest` 與
   `composer audit`。

不新增 phpDocumentor 或其他只為這次盤點服務的 dependency；reflection guard、
PHPStan 與既有品質工具已足以保護本套件的實際契約。

### 19.5 Review 與完成定義

PHPDoc 補強只有在以下條件全部成立時才算完成：

- `src` 中每個 package-declared method 都有描述責任或結果的 docblock。
- 所有 Collection methods 與 public properties 都有正確 generic。
- 所有已知 public／internal arrays 都使用 list、map 或 array shape，而非無資訊的
  `array`；只有真正開放的 RFC value 邊界保留受說明的寬型別。
- Reader、query、JSON 與 input boundary 的正常失敗都有正確 `@throws`，且
  `try*()` 文件沒有錯稱會吞掉來源錯誤。
- Public signatures、Facade annotations、README examples 與 PHPDoc 沒有前後
  不一致。
- 未因補 PHPDoc 改變 runtime behavior 或新增 speculative abstraction。
- Architecture guard、Pint、PHPStan、Pest、Composer validation 與 audit 全部
  通過，`git diff --check` 沒有格式問題。

Review 時逐一把 PHPDoc 與 method body、callee、測試及需求書比對；不能只用
「docblock 存在」作為人工驗收。若文件揭露既有行為與需求不同，該差異另開功能
修復，不在註解中宣稱尚未成立的契約。

## 20. 需求符合性補強執行計畫

本節處理 2026-08-04 依需求書重新審查套件後確認的缺口。它不新增產品功能，
也不改變既有 UID 查詢、validation、時間或輸出契約；工作原則是先補驗收證據，
只有新測試證明 runtime 不符合需求時才修改 production code。

### 20.1 批次 A：PHPDoc 與靜態契約

1. 盤點 `Property::$value`、`Property::$values`、`Reader::propertyValues()`、
   `Calendar::toComponentArray()` 及 recursive serialization helpers 的實際型別。
2. 建立可重用的 PHPStan local aliases，表達 Property atom/value（包含 RRULE 等
   structured parser value）、parameter map 與 normalized property；normalized
   component 每一層使用固定 shape。PHPStan 不支援 circular local type alias，
   因此只在 child recursion edge 保留 `list<array<string, mixed>>`，並以遞迴輸出
   測試補足該工具邊界；不複製多份容易分歧的長型別。
3. 為 promoted public properties 加入 property-level `@var` 或語意 doc comment，
   確保 ReflectionProperty 能取得，而不只在 constructor 使用 `@param`。
4. 擴充 PHPDoc guard，檢查 methods 與需要補充型別／語意的 public properties。
5. 先執行 PHPDoc guard 與 PHPStan，再執行 Pint 及完整 Pest。

退出條件：正式 `src` 不再以 `list<mixed>` 或 `array<string, mixed>` 描述已知固定
邊界；唯一例外是 PHPStan 無法表達的 component child recursion edge。PHPStan、
Reflection guard、遞迴 runtime assertions 與人工 review 都能確認 union／shape 與
runtime 一致，且沒有為文件工作改變公開 signature 或輸出內容。

### 20.2 批次 B：必要行為與 failure-path 測試

依風險分組新增最小 Pest cases：

1. Input matrix：string、path、stream、UploadedFile 各自的實際 byte limit、空白／
   invalid content，以及全部 throwing／nullable methods 的對稱結果；另覆蓋 invalid
   upload、URL wrapper／非一般檔案與 caller-owned stream。
2. Validation／configuration：level 2 warning（若目前 Sabre 版本有可穩定產生的
   iCalendar case）、缺少必要 UID、合法 app timezone 搭配無效 package override，
   並確認每個 warning code、source 與可取得的 component／property。
3. Event semantics：缺 DTSTART、午夜、24 小時非全天、DTSTART + DURATION、
   DTEND + DURATION 一致／衝突、duration-derived floating end、DST、lastDay 三種
   情境與 `DateInterval::$days`。
4. Collections／recurrence：多個 alarms、`RDATE`、`EXDATE`、同 UID overrides、
   `events()`／`hasEvents()` 一致性與 defensive Collection containers。
5. Escape hatches／outputs：Calendar、Event、Todo、Component raw clone isolation，完整
   normalized recursive shape、CalendarIssue array／JSON、Calendar jsonSerialize／
   toJson options 與可重現的 JsonException path。
6. Laravel integration：config merge、publish mapping、singleton、Facade annotations
   與 quick-start example。

每組先跑單一 test file；若新增測試失敗，追到共同 production boundary 作最小修復，
不得只為測試加入特殊分支。Sabre 目前版本無法自然產生的 level 2 情境，先以其真實
能力記錄 limitation；可在不 mock parser 的前提下測到穩定 mapping 時才加入整合案例。

退出條件：需求書第 24.1 節每一項都能指向至少一個具體測試，且 throwing／nullable、
時間、clone、serialization 與 Laravel integration 不再只由人工推論。

### 20.3 批次 C：Client 與文字互通 fixtures

1. 建立 `tests/Fixtures/clients/google`、`outlook`、`apple`，加入匿名化且能辨識來源
   類型的 export samples；若無法安全取得真實 export，不以自製通用 `PRODID`
   假稱 client compatibility。
2. 加入專用 fixtures 覆蓋中文與 emoji、CRLF、LF、folded content line，以及
   property／parameter 大小寫差異。
3. 每份 fixture 以公開 API 斷言關鍵資料，不只做「能讀取」測試；未知、重複與
   vendor `X-*` 資料必須能從 generic API 取得。
4. 在 fixture 或測試旁記錄來源類型與匿名化狀態，不保存個資或不可散布內容。

退出條件：需求書第 24.2 節每種來源與文字／格式邊界都有可識別 fixture 和 assertion，
而不是由六份通用 sample 間接推定。

### 20.4 批次 D：雙語文件完成度

1. 英文 README／guide 先加入 Event、Todo、Organizer、Attendee、Alarm 欄位表，包含型別、
   nullable／Collection 元素與重要時間語意，再同步繁體中文。
2. 新增 `Upgrading`／`升級` 章節，說明 0.x breaking changes、CHANGELOG、固定輸出、
   SemVer 與升級前測試流程。
3. 明確文件化 invalid package timezone 直接 fallback UTC、即使 app timezone 合法
   也不改用 app timezone；避免「package、app、UTC」簡寫造成錯誤理解。
4. 將關鍵 quick-start 放入可執行測試或共用 snippet；其他 fenced PHP examples
   至少進行語法檢查，並檢查雙語方法名稱與連結一致。

退出條件：需求書第 25 節逐項可從英、繁中入口找到，欄位表不是 prose 列舉，
升級政策具體可操作，所有關鍵範例使用現行 API。

### 20.5 批次 E：CalendarIssue severity 常數化

`CalendarIssue::$level` 的 `2` 與 `3` 是 Sabre severity 對應的固定技術值，不是
可設定策略，也不是需要獨立行為的業務狀態。本批次使用 typed class constants，
不新增 enum、config 或額外 mapper：

1. 在 `CalendarIssue` 宣告穩定公開常數
   `public const int LEVEL_WARNING = 2` 與
   `public const int LEVEL_ERROR = 3`；`$level`、`toArray()` 與 JSON 仍輸出 `int`，
   不造成公開資料 shape 或序列化格式變更。
2. `CalendarValidator` 的 parser failure、invalid root、Sabre level 門檻、level
   正規化與 warning/error code 選擇全部引用上述 constants。Sabre 回傳的 raw
   integer 只保留在第三方輸入邊界，不在內部繼續以裸數字表達語意。
3. `TimezoneResolver` 建立 configuration warning，以及 `Reader` 建立
   `mapping_warning` 時，同樣使用 `CalendarIssue::LEVEL_WARNING`；不得只修改最初
   發現問題的兩個 Support 類別而留下 sibling caller。
4. Constructor PHPDoc 以 constants 表達允許值；既有 array shape 仍標示 `level: int`，
   因為固定輸出契約是數字，不把 enum object 洩漏到 public API。
5. 加入最小測試，鎖定兩個 constant 的數值、parser／validator error 與
   configuration／mapping warning 的輸出；再以 `rg` 確認 `src` 中沒有建立或比較
   CalendarIssue level 的裸 `2`／`3`。

退出條件：`CalendarIssue` 是 severity 數值的唯一命名來源；`CalendarValidator`、
`TimezoneResolver` 與 `Reader` 不再含有 issue level magic numbers，既有例外、
warning、array 與 JSON 行為完全不變，Pest、PHPStan 與 Pint 全部通過。

### 20.6 批次 F：Property normalized array interface

1. 在 `Property` 宣告 `PropertyArray` local type alias，並新增無參數
   `toArray(): array`；固定輸出 `name`、`type`、`value`、`values`、`parameters`、
   `raw_value`，直接使用已 hydration 的 snapshot，不重新解析 Sabre data。
2. 零值、單值與多值沿用 constructor invariant：`value` 分別為 `null`、唯一 atom
   或完整 list；`values` 永遠是 list。Unknown、structured value、parameters 與
   raw value 不得因便利輸出遺失。
3. `Calendar` 以 `@phpstan-import-type PropertyArray from Property` 重用 shape；
   `toComponentArray()` 的 root properties 與 recursive component properties 都
   呼叫 `Property::toArray()`，刪除只負責複製欄位的 private `propertyArray()`。
4. 更新 Public API architecture test：只從「禁止 standalone serializers」dataset
   移除 `Property`，其他 nested domain models 仍不得因此取得 `toArray()` 或
   `jsonSerialize()`。不讓單一新增需求擴張成全套 serializers。
5. 加入最小 Pest cases，精確斷言六個 keys、空／單／多值、parameter 與 raw value，
   並斷言同一 Property 的 `toArray()` 等於 `Calendar::toComponentArray()` 對應 entry。
   PHPStan 必須驗證 imported alias 與 runtime shape 一致。

退出條件：呼叫端可直接取得單一 Property 的完整 normalized array；Calendar 不再
維護重複 property mapper；既有 `toComponentArray()` shape、順序與資料語意完全
不變，且沒有新增 `Property::toJson()`、`JsonSerializable` 或其他 nested serializers。

### 20.7 補強批次驗證與交付

第 20 節每個補強批次完成後執行窄範圍測試；本節批次全部完成後依序執行：

```bash
composer validate --strict
vendor/bin/pint --test
vendor/bin/phpstan analyse --memory-limit=1G
vendor/bin/pest
composer audit
composer benchmark
git diff --check
```

最後以需求書第 24、25、30 至 33 節建立逐項對照表，確認 GitHub Actions stable matrix、
lowest dependencies 與 quality job 全部通過。只有所有缺口都有程式、測試、fixture
或文件證據，且沒有未說明的 upstream limitation，才能宣稱重新符合完整 plan。

## 21. Typed VTODO 擴充計畫

本擴充只為 RFC 5545 `VTODO` 加入 Laravel-friendly read model；不建立完整 RFC
class hierarchy，也不順帶加入 VJOURNAL、VFREEBUSY、recurrence expansion 或
todo query builder。公開名稱使用 `Todo`、`todo()`、`todos()`，避免把 wire-format
前綴帶進應用程式 API；`votdo` 是拼字錯誤，不列為 alias。

### 21.1 Domain 與 Calendar API

1. 新增 readonly `Todo`，沿用 Event 已存在的 Property、Organizer、Attendee、
   Alarm、CarbonImmutable、DateInterval 與 raw deep-clone patterns，不新增平行 DTO。
2. `Calendar` 新增 `todos(?string $uid = null)`、`todo(string $uid)` 與
   `hasTodos(?string $uid = null)`；UID 比對、文件順序、defensive Collection、
   recurrence master 優先與找不到時的結果，全部與 Event API 相同。
3. Reader 在同一次 hydration 建立 VTODO typed snapshots；既有
   `components('VTODO')` 與 `toComponentArray()` 仍保留相同 VTODO，不因 typed view
   遺失未知或重複 properties。
4. 只有兩個正式 component 的重複查詢或 mapping 邏輯確實形成相同 invariant 時，
   才抽取最小 private helper；不先建立 public base class、interface 或 trait。

### 21.2 Property mapping 與時間語意

1. Typed convenience fields 依需求書第 31.3 節實作；與 Event 共用的 GEO、重複
   TEXT／TEXT-list 與複合 `Property` shortcuts 使用同一 mapper。所有來源
   properties 仍完整存在於 Property API；grammar token `x-prop`／`iana-prop`
   不建立假的 typed 欄位。
2. `DTSTART`、`DUE` 分別保留 DATE／DATE-TIME 與 floating flags。`DUE` 與
   `DURATION` 互斥；`DURATION` 必須搭配 `DTSTART`。若 validator 只給 warning，
   typed precedence 依需求書執行並保留原 property；level 3 仍拒絕文件。
3. `dueAt` 與 `duration` 使用最小的 effective-value 規則：顯式 `DUE` 優先；否則
   以 `DTSTART + DURATION` 推導 due；沒有 `DURATION` 但 DTSTART／DUE 可比較時推導
   duration。VTODO 不套用 VEVENT 缺少結束時間時的隱含一日規則。
4. `RRULE`、`RDATE`、`EXDATE` 與 `RECURRENCE-ID` 只保留與用於 master 選擇，
   不產生不存在於文件中的 occurrences。

### 21.3 Event／Todo 對齊與共用 hydration

1. 在 `Event` 補上 `startIsDate`、`endIsDate`、`recurrenceId`、
   `recurrenceIdIsDate` 與 `recurrenceIdIsFloating`；語意與需求書第 10.2、11、31.8
   節一致。`allDay`、`isAllDay()` 與 `startIsDate` 必須由同一判斷結果建立。
2. `hydrateEvent()` 與 `hydrateTodo()` 繼續共用既有 `dateTimeValue()`、`isDate()`、
   `isFloating()`、`hydrateOrganizer()`、`stringValues()` 與 `hydrateProperties()`；
   `DTSTAMP`、`CREATED` 與 `LAST-MODIFIED` 也走相同 UTC mapping，不複製第二套
   時間判斷。
3. 新增 GEO pair 與 hydrated Property 篩選的最小 private helpers；Event 與 Todo
   先各自 hydrate direct properties 一次，再由同一份 ordered list 建立
   `recurrenceRule` 與複合 shortcut Collections，避免相同 property 被重複映射。
4. 抽出最小 private `hydrateAttendees()` 與 `hydrateAlarms()` helpers，讓 VEVENT
   與 VTODO 共用 attendee parameter mapping、alarm traversal 及 floating timezone
   傳遞。保留 Event 結束時間與 Todo due／completed 等 component-specific 分支。
5. 不建立 public parent class、interface、通用 component DTO 或動態欄位 map；
   Event 與 Todo constructors 保持明確。共同 direct-property 查詢依第 23 節使用
   `@internal` trait，該 trait 不形成使用者必須依賴的公開 hierarchy。
6. `event($uid)` 的 master 判斷仍以 `RECURRENCE-ID` property 是否存在為準，
   不能因 typed value 解析失敗而誤選。
7. 以同一組 fixture／dataset 對 Event 與 Todo 的共同 properties 建立 parity
   assertions，包括共同 UTC timestamps；另分別測試 Event-only end／all-day 與
   Todo-only due／completed／effective duration，避免共用錯誤的結束時間演算法。

### 21.4 輸出、測試與文件

1. `Calendar::toArray()`／JSON 固定加入 `todos` list；每筆 Todo 使用需求書定義的
   snake_case keys、ISO 8601／date-only／duration 表示，以及 Organizer、Attendee、
   Alarm 的既有 nested shapes。`Todo` 本身不新增 standalone serializer。
2. Event array 同步加入第 21.3 節新增的 DATE／recurrence keys，並以相同 fixture
   驗證 typed properties 與輸出 shape，避免 domain object 與 serializer 不同步。
3. Event 與 Todo 的 GEO、文字 lists 與 Property shortcuts 同步加入固定 output
   shape；Property entries 一律委派既有 `Property::toArray()`。
4. 最小 fixtures 必須覆蓋：單一與多個 VTODO、同 UID master／override、DATE 與
   DATE-TIME DTSTART／DUE／RECURRENCE-ID、floating／TZID／UTC、DUE／DURATION
   推導、共同 UTC timestamps、完成狀態、多值 attendee／categories／alarm，以及
   unknown／X-* preservation。
5. 加入 validation boundary cases：DUE + DURATION、沒有 DTSTART 的 DURATION、
   DTSTART／DUE／RECURRENCE-ID value type 不一致，以及 RRULE 缺 DTSTART；
   assertion 以 Sabre 5 實際 validation level 為準，不複製 parser 規則。
6. 更新 public API、PHPDoc generics／array shapes、Facade 使用範例、英／繁中欄位表
   與 recurrence limitation；先跑相關 Pest，再依第 20.7 節執行完整品質命令。

退出條件：合法 VTODO 可由 typed 與 generic views 同時、無資料遺失地取得；Event
與 Todo 的共同 properties 具有相同 typed 語意，attendee／alarm hydration 已集中；
UID 查詢、時間、RFC 結構與固定輸出都有測試，且未新增 occurrence expansion、writer、
公開共用 hierarchy 或其他 component 的空殼 API。

## 22. RFC 5545 core Event property coverage

本批次補齊需求書第 10.2.1 節，不延伸到後續 RFC 或 IANA registry 中無界的 property
集合。完成標準是每個 RFC 5545 §3.6.1 core VEVENT property 都有具名 Event accessor，
或因名稱本身開放而能由 generic API 無損取得；不是為每個 property 新建 class。

### 22.1 Domain API

1. Event 新增 `geo`、`transparency`、`comments`、`contacts`、`resources`。
2. Event 新增 `recurrenceRule` 與 `attachments`、`exceptionDates`、
   `requestStatuses`、`relatedTo`、`recurrenceDates` Property shortcuts。
3. Todo 同步加入除 `transparency` 外的共同欄位，維持需求書第 31.8 節 parity。
4. GEO 使用 `?array{latitude: float, longitude: float}`；不新增只有一個使用點的
   value object。複合欄位重用 readonly `Property` snapshot。

### 22.2 Reader 與輸出

1. 每個 component 只執行一次 `hydrateProperties()`；typed shortcuts 從該 ordered
   list 篩選，不能再次 hydrate 或建立不同語意的 Property instance。
2. GEO mapper 僅接受兩個有限且分別落在 -90..90、-180..180 的 float，並保持
   latitude／longitude wire order；validator 只回 warning 時 malformed GEO 映射為
   `null`，mapper 不 clamp，generic Property 與 warning 繼續保留。
3. TRANSP 保留 source value：缺少時為 `null`，不把 RFC effective default `OPAQUE`
   假裝成來源 property。所有非空 token 正規化大寫；validator 只回 warning 時仍
   保留非標準 source token。
4. COMMENT／CONTACT 使用 repeated-TEXT helper，一個 property 對應一個 string，
   不依逗號拆值；CATEGORIES／RESOURCES 使用 repeated-TEXT-list helper，同時保留
   property 順序與每行 value 順序。Event 與 Todo 共用這兩條明確 mapping path，
   所有 public Collections 都使用 defensive containers。
5. Calendar array／JSON 加入對應 snake_case keys；複合 shortcuts 委派
   `Property::toArray()`，`toComponentArray()` 完全不變。

### 22.3 驗證

1. 建立一份涵蓋 §3.6.1 全部 core properties 的 Event fixture，以及同時含 VEVENT／
   VTODO 的 parity fixture。
2. 測試 GEO 正負／邊界／malformed pair 及 warning 時的 `null` typed value、TRANSP
   缺少／兩個標準 token／warning-only 非標準 token、COMMENT 中合法 escaped comma
   在 parser 解碼後仍是一個 TEXT、TEXT-list escaping、Property shortcut 順序／
   parameters／多值型別、空值與 defensive Collections。
3. 精確鎖定需求書第 18.2 節列出的 EventArray／TodoArray 新 keys，並確認 generic
   properties、raw values、normalized component tree 與 master／override 查詢沒有
   regression。
4. 依序執行相關 Pest、Pint、PHPStan、完整 Pest 與 `git diff --check`。

退出條件：`$event->geo` 等穩定值可直接取得，所有 RFC 5545 core VEVENT properties
都有可發現的具名入口；複合資料沒有被壓平，Event／Todo 共用欄位沒有兩套 mapper，
且 IANA／`X-*` 的開放性仍由 generic API 承接。

## 23. Direct property query 共用化

本批次只集中已穩定的重複 implementation，不改變任何公開行為。適用類別為
`Calendar`、`Event`、`Todo` 與 generic `Component`；四者都查詢自己的 ordered
direct-property list，並已具有相同的名稱正規化、Collection 與錯誤契約。

### 23.1 Internal seam

1. 新增標示 `@internal` 的 `Concerns\QueriesProperties` trait，集中
   `properties()`、`hasProperty()`、`property()` 與 property-name normalization。
2. Trait 透過最小的 protected property-list hook 取得 consuming class 的
   `list<Property>`；不持有 Sabre component、不參與 hydration，也不增加 public
   constructor 參數。
3. `Calendar`、`Event`、`Todo` 與 `Component` 使用此 trait，並各自保留既有
   private ordered property storage。不得新增 public base class 或 interface。
4. `rawComponent()` 不納入 trait。各類別繼續回傳自己的精確 Sabre subtype 並執行
   deep clone，避免將 typed low-level escape hatch 降級為通用 `SabreComponent`。

### 23.2 行為與驗證

1. 公開方法名稱、參數、回傳型別、PHPDoc 與例外契約完全不變。
2. `properties(null)` 每次回傳新的 Collection；指定名稱時先 trim、拒絕空白名稱、
   再不區分大小寫比對，並保留文件順序與重複 properties。
3. `hasProperty($name)` 與 `property($name)` 必須委派同一個 shared query path；前者
   回答存在性，後者回傳文件順序中的第一筆或 `null`。
4. 以同一 dataset 對四個 consuming classes 執行 parity assertions，涵蓋全部、
   指定名稱、大小寫、重複值、找不到、空字串、全空白與 defensive Collection。
5. 既有 raw-component clone 測試需繼續分別驗證 `VCalendar`、`VEvent`、`VTodo` 與
   generic component 的精確型別及 snapshot isolation。
6. 更新 PHPDoc reflection guard，確保 trait 提供的方法在四個公開類別上仍具有完整
   docblock、Collection generic 與 `@throws InvalidArgumentException`；最後執行相關
   Pest、Pint、PHPStan、完整 `composer check` 與 `git diff --check`。

退出條件：direct-property 查詢規則只有一套 implementation，四個公開 interfaces
保持向後相容，raw-component typed return contracts 不變，且沒有新增使用者需要理解
的公開 hierarchy。

## 24. iCalendar property 與 parameter 名稱常數化

本批次實作需求書第 33 節的內部 vocabulary 宣告位置，只集中 production code
會主動存取的已知 property 與 parameter names。不改變 parser、hydration、
public query interface 或輸出，也不順便為 component names 與 token values 建立完整
registry。

### 24.1 最小模組

1. 新增標示 `@internal` 的 `Support\PropertyName` 與 `Support\ParameterName`
   final classes，內部只宣告 PHP 8.3 `public const string`，不新增 methods、
   enum、registry array 或 config。
2. Constant identifiers 直接對應 wire names：單字 token 保持原名，連字號
   改為底線，例如 `STATUS`、`PERCENT_COMPLETE`、`CN`、`ROLE`；constant
   values 則分別是 `STATUS`、`PERCENT-COMPLETE`、`CN`、`ROLE`。
3. 以 property／parameter 分類提供 locality，不合併成同一個 `Names` class。
   這兩個 classes 是 implementation detail，不新增使用者必須學習的
   public domain interface。

### 24.2 取代範圍

1. 使用 `rg` 盤點 `src/` 中傳入 `stringProperty()`、`upperStringProperty()`、
   `integerProperty()`、`firstProperty()`、`firstHydratedProperty()`、
   `hydratedProperties()`、`directProperties()`、`stringValues()`、`textValues()`、
   `singleParameter()`、`upperParameter()` 與 `parameterList()` 的固定字面名稱。
2. `Reader` 內的已知 property names 全部改用 `PropertyName::*`，已知
   parameter names 全部改用 `ParameterName::*`。`Calendar` 選擇 recurrence
   master 時的 `RECURRENCE-ID` 也使用同一 property constant。
3. Helpers 繼續接受 `string`；不把 enum 或 constants-only class 泄漏到
   `Property`、`Calendar`、`Event`、`Todo` 或 generic `Component` 的公開
   interfaces，不限制 IANA／`X-*` names。
4. 不取代原始 `.ics` fixtures、README 使用者範例、array keys、`TRUE`／
   `FALSE`、`DATE`、`START` 或 `VEVENT`／`VTODO`／`VALARM` 等非本批次
   property／parameter names。

### 24.3 相容變更規則

1. 未來 RFC 或 IANA 新增名稱時，先新增對應 constant，再在最小的
   shared mapping path 加入明確 fallback。
2. 不得以修改舊 constant value 代替相容邏輯。例如新規格以 `ROLES`
   取代 `ROLE` 時，保留 `ROLE` 並新增 `ROLES`，再依規格決定優先順序；
   這能讓舊檔案與新檔案共存，而不是只把失效點從多處移到一處。

### 24.4 驗證與交付

1. 保留現有 literal `.ics` fixtures，讓整合測試仍以真實 wire names 驗證
   constants 與 hydration 的連接。
2. 加入一個最小測試，至少同時覆蓋普通名稱、連字號 property name 與
   parameter name；驗證以 literal 預期值寫成，不以被測 constants 生成 fixture。
3. 以 `rg` 重新檢查 production lookup call sites，確認沒有遺漏固定
   property／parameter literals；不建立會掃描所有字串的易碎 architecture test。
4. 依序執行相關 Pest、Pint、PHPStan、完整 `composer check` 與
   `git diff --check`。

退出條件：production code 的已知 property／parameter 名稱各有單一宣告處，
現有 public interfaces 與 observable behavior 不變，unknown／extension names 仍可保留，
且沒有引入 enum、registry 或更廣的 token framework。
