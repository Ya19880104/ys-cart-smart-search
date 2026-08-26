# Changelog

## [1.5.3] - 2026-08-26 — 搜尋互動與資料一致性

### Changed / Fixed

- 每個前台搜尋表單改由單一互動狀態管理競態、取消、載入、錯誤與結果；chip 滑鼠／鍵盤操作只
  啟動一次有效搜尋，blocked-neutral 不再載入次要建議，合法零結果也不會被 fallback 失敗推翻。
  過期或失敗回應不再留下舊結果、proof、最近搜尋或錯誤分析事件。
- 搜尋框與結果面板補齊 combobox／listbox 鍵盤操作與狀態，彈窗支援焦點圈、Escape 關閉，
  載入期間同步回報 busy，並在關閉後把焦點還給實際開啟它的按鈕。
- 手動關鍵字新增／編輯保留通過安全判定的精確字串（含 `C++ <vector> 入門` 與 Windows path）；資料庫失敗、
  無效輸入與後台競態均回固定且如實的狀態，刪除、排序、啟停與設定操作不再假報成功。
- B 模式先取得商品總數再解析最後可見頁，分類／文章與商品共用 canonical page；快取鍵加入版本
  並只寫 resolved page，避免深分頁空結果、過大 OFFSET 與舊版 cache 污染。結果頁必須公開發布、
  不設密碼且在內容文字區含實際可執行的 shortcode（HTML attribute／comment／CDATA 與只顯示
  文字的 escaped 形式都不算）；
  設定頁會自我修復遺失頁面，頁面 ID 寫入失敗則回滾新頁。頁面分析與 REST 共用每分鐘 30 次
  預算，查看全部由落地頁單點記錄，不重複送出 client log。
- 公開 query／suggest／log 與 B 頁分析的 transient 計數改在 per-counter MySQL advisory lock 內
  串行化；鎖忙、可偵測的資料庫／counter 讀寫失敗或鎖釋放失敗皆 fail closed。這關閉平行舊讀超額
  與 `set_transient()` 失敗仍放行的缺口；B 頁只略過分析，不影響已完成的搜尋結果呈現。
- 分析的 SKU／ISBN／EAN／UPC／MPN／型號／料號辨識改為 token-local byte span；只豁免完整位於
  識別片段內的 token。搜尋與分析共用有界固定點 canonical closure；無 intl 時涵蓋 fullwidth、
  Mathematical Latin（含 dotless i/j）／digits 與明列 Letterlike ASCII subset，有 intl 時另加 NFKC。閉包未完成即
  fail closed，旁邊無關的亂數或已知參數仍會被分析入口忽略。
- 熱門建議失效加入 generation tombstone，並以一次未經 option cache 的資料庫快照共同判定目前
  generation 與 captured-generation marker；命中與發布前後都重驗，資料庫結果錯誤／模糊時
  fail closed，晚到的舊 generation 寫入會移除。已提交的後台變更仍以固定警示如實標示 cache
  可能延遲更新。
- 分析 receipt 的訪客身分改綁簽發時間；跨 UTC 午夜但仍在有效期內的同一 receipt 維持同一
  去重身分，且線上 wire version、HMAC、期限與舊 v1 receipt 相容性不變。
- 最近搜尋、自動熱門詞與 B 模式分析只以實際商品數為正向依據；filter-final 商品必須至少有
  一筆具可見 title 與安全 URL 的可渲染項目才可授權商品總數，空白／畸形項目不會進入正向 receipt 或
  搜尋記憶。分類／文章仍可呈現及留下可辨識的零商品分析，但不會進入商品搜尋記憶。

### Compatibility / Release Boundary

- 本版沒有資料庫 schema 變更，也沒有 public REST URL 變更。
- 本次候選僅準備本機與授權測試站驗收；未執行 Hub、S3 或 production 動作。

## [1.5.2] - 2026-08-26 — 搜尋入口與分析資料完整性收斂

### Security / Integrity

- 所有公開搜尋入口改為 **raw-first** 判定：REST query/log、A 模式商品 shortcode 與 B 模式
  結果頁都在任何有損清理或搜尋執行前檢查原始輸入。遭攔截的探測不查商品、不寫分析、
  不回顯 payload，並回既有中性空結果；正常 CJK、URL、Windows path、C++ `<vector>` 與
  技術書名等字詞維持可搜尋。A 模式只在核心商品 shortcode 執行期間保留已通過 gate 的
  原字串，避免核心文字清理誤刪 `<vector>`；HTML event attribute 的空白與 `/` 分隔都會攔截。
- 分析 `/log` 不再信任 client `total`：`/query` 以短效 HMAC receipt 綁定 query、visitor、
  server-calculated total 與實際搜尋範圍；缺失、竄改、過期或不相符的 receipt 維持相同
  `{ok:true}` 回應但零寫入，不新增 validity oracle。同訪客／同詞的 check-and-insert 以
  advisory lock 串行化，避免併發重播雙寫。
- 熱門建議在 cached、fresh 與 filter-final 三個出口都重新通過輸入 gate；每次失效發行不重用的
  128-bit epoch token，避免併發 invalidation 回退並讓 late writer 的舊 cache key 再次成為 current；
  `count=0` 不洩漏候選詞。
- 分析寫入新增獨立 admission policy：已知 tracking/control query parameters、多組 UUID／hash／
  高信心機器 token 只會被分析忽略，不會阻止商品搜尋；可辨識的人類零結果仍保留為商機。
  自動熱門詞改以有結果事件（`hits - zero_hits`）資格與排序；瀏覽器最近搜尋也只在 matching
  server proof 顯示有結果時記憶，快速送出、REST 失敗或零結果不會寫入 localStorage。
- 後台逐詞刪除與「清除全部」共用 per-site maintenance lock；兩張分析表必須同為 InnoDB
  才執行交易，任一 DELETE／COMMIT 失敗即 rollback 並回固定安全錯誤，不外洩 SQL 或
  database detail。逾期清理達有界批次上限時不再假報完成，留待下次續跑。
- 歷史 heuristic「自動掃描並刪除注入紀錄」已從 repository 與 UI 實體退役；legacy
  `mode=injection` 固定回 409 `ys_ss_preview_required` 且零 mutation。缺少或未知 purge mode
  固定回 400，不再落入破壞性的 expired 預設。

### Admin UX

- exact／expired／all 清理均提供固定、可存取的狀態訊息；失敗會恢復控制、不 reload、也不
  顯示任意 server/network message。訊息 timer 會取消舊操作，避免舊成功提示抹掉新錯誤。

### Compatibility

- 前端每個搜尋表單使用單調 request sequence，舊的同字查詢回應不能覆蓋較新的結果或 receipt。
- 搜尋、分析、receipt、手動關鍵字與摘要截字共用 Unicode-safe 字元截斷，不再依賴 byte-based
  `substr()` fallback，也不要求 mbstring 才能保持合法 UTF-8。

### Security Boundary

- SQL injection 與輸出 XSS 的正式邊界仍是 prepared SQL、WordPress escaping 與 DOM
  `textContent`。輸入 classifier 是搜尋拒絕執行與分析防污染的 abuse control，不取代這些
  邊界，也不宣稱僅靠 pattern matching 能處理任意攻擊。
- 中性攔截回應不回顯原 payload、也不回明確的 classifier 錯誤，但可由空 `q`／空 receipt 與
  合法零結果區分；本版不宣稱 classifier decision 完全不可觀察。B 模式在攔截前可能讀取
  WordPress 設定或載入頁面資產；「不查商品」精確指不執行商品／分類／文章內容搜尋。

### Operational Note

- 為保證兩表可回滾，「清除全部」使用單一 InnoDB transaction 的兩個 DELETE；對異常龐大
  的分析資料集可能產生較長 undo／lock 時間。大型 timeout-safe maintenance job 不在本版範圍。

## [1.5.1] - 2026-08-25 — 結果頁防注入一致性

### Security

- B 模式搜尋結果頁（`/ys-search/`）補上與 `query` 端點一致的防注入攔截：辨識為攻擊探測的
  `ys_ec_search` 一律**拒絕執行商品／分類／文章內容搜尋**（不記錄、不回顯原字串），顯示中性
  「沒有符合的結果」提示。修補 v1.5.0 只護 REST `query` 端點、未護結果頁執行路徑的一致性缺口。

## [1.5.0] - 2026-08-25 — 搜尋注入防護 + 後台紀錄清理

### Security

- **進站防注入攔截**：新增 `YSSsInjectionGuard`（單一真相源），辨識 SSTI 模板注入
  （`{{7*7}}`、`${...}`、`#set(...)`）、XSS（`<svg onload=>`、事件處理器、`javascript:`）、
  路徑穿越（`../`、超長點序列）、SQLi（`union select` 等）、SSRF/RCE（`nslookup`、
  `popen`、`net::`、URL scheme）與控制字元。判準只針對「真實商品搜尋永不出現」的結構
  字元與高訊號 token，不誤殺中文（CJK）／英數／連字號等正常商品詞。
- 攻擊探測在**唯一寫入瓶頸 `YSSsQueryRepository::log()`** 一律不記錄，故不再污染搜尋分析
  與自動熱門建議。
- 公開 `query` 端點對攻擊探測**拒絕執行搜尋**、回傳不含原字串或明確偵測錯誤的空結果。
- 自動熱門詞 `auto_terms()` 對既有殘留注入詞加上縱深過濾，確保絕不出現在前台建議。
  （前後台呈現本就以 `textContent`／純 DOM 組裝，注入字串一律 inert；本版再從源頭阻斷。）

### Added

- 後台搜尋分析可**單筆刪除**某關鍵字的全部紀錄（原始 + 彙總）：`DELETE /admin/smart-search/term`，
  排行每列新增垃圾桶刪除鈕。
- 後台「**清除注入紀錄**」一鍵掃描並刪除攻擊探測列（只刪攻擊、保留正常搜尋含正常零結果）：
  `POST /admin/smart-search/purge` `mode=injection`。
- 保留既有「一次清理所有紀錄」（`mode=all` + 確認碼）與逾期清理（`mode=expired`）。

## [1.4.4] - 2026-07-28

### Fixed

- Stop the bundled YS Hub Client library from registering an invalid
  WooCommerce HPOS declaration from its vendor path.

## [1.4.3] - 2026-06-15 — review 收斂：B 模式「查看全部」落點 + 分析寫入去重

### Fixed

- **B 模式即時面板「查看全部」仍導向核心商店頁**（P1）：`YSSsSearchService::search()` 的
  `view_all` 不論結果模式都用 `shop_url()`，導致下拉面板「查看全部」（`ys-ss-front.js`
  直接用 `data.view_all`）在 B 模式仍落到 `/?ys_ec_search=`（核心商店）而非獨立混合
  結果頁 `/ys-search/`。表單 action 走 `form_action()` page 模式邏輯故頁面 smoke 會過、
  唯獨下拉「查看全部」路徑錯。改為集中於 mode-aware helper
  `YSSsResultsPage::search_url()`：B 模式且有有效結果頁 → 結果頁 + `ys_ec_search`；
  否則沿用商店頁（A 行為不變）。

### Security / Integrity

- **公開 `/smart-search/log` 端點可被重複呼叫污染搜尋分析**（P2）：先前僅結果頁
  `log_page()` 有 600 秒 server 端去重，下拉 `bar`/`popup` 寫入無去重（前端
  sessionStorage 去重非安全邊界）。將 **600 秒去重下沉至唯一寫入瓶頸 `log()`**
  （同訪客 + 同正規化詞、跨來源），公開端點即使被重複打也無法灌爆分析；`log_page()`
  簡化為委派、不再重複去重邏輯。沿用既有 `norm_time` 索引、無 schema 變更。

### Notes

- **權限模型維持 `manage_options`（OR `manage_ys_ecommerce`）不變**：經查核心自身選單
  即用同款 `manage_options` fallback、且未透過 `add_cap`/`map_meta_cap` 授予
  `manage_ys_ecommerce`；audiobooks 亦同款、affiliate/content-access 純 `manage_options`。
  生態系實際政策即 `manage_options`，本外掛已一致；若日後要全生態系遷移自訂 cap，應由
  核心主導 ADR，不在 addon 端單方變更。
- `bar`/`popup` 的 `total`/`has_results` 仍取自 client payload（結果頁 `page` 來源為
  server 端計算）。去重已大幅降低可灌入量；如需 KPI 完全不信任 client，需於 `/log`
  端重跑搜尋取真實筆數（成本較高），暫不納入。

## [1.4.2] - 2026-06-15 — 工程團隊 review 修正

### Fixed

- **舊核心（< 2.52.44）搜尋分析無入口**（regression）：核心無報表分頁擴充點時，
  fail-soft 保留獨立「搜尋分析」子選單與側欄入口；核心 2.52.44+ 才走報表分頁。
- **`ensure_page()` 縱深防禦**：自動建立結果頁僅限有管理權限情境（`manage_options` /
  `manage_ys_ecommerce`），防 CLI／import／他 addon 寫此 option 時在非預期身分下建立
  publish 頁面。
- **側欄「進階搜尋」群組位置穩定化**：改插在核心「商店設定」(settings) 群組之後（自然
  落在「有聲書」之前，且不依賴有聲書是否安裝；先前無有聲書時會排到最末）。
- **移除隱藏核心報表工具列的 `<style>` 注入**：改由核心 2.52.45+ 對 addon 報表分頁不
  渲染日期工具列（不再單方面 hack 隱藏核心 UI；需核心 2.52.45+ 才有乾淨外觀）。

### Performance

- **結果頁 `search_page()` 短 TTL 快取（60 秒）**：避免大型商品表每次結果頁／翻頁重跑
  `LIKE '%q%'` 全表掃描 + `COUNT(*)`（中文熱門詞命中率高）。鍵含 norm／頁碼／影響結果
  的設定；分析記錄（`log_page`）另行呼叫、不受快取影響。
- **結果頁頁碼上限 100**：防病態深分頁產生巨大 `OFFSET`。

### Changed

- `query` REST 端點 args 補 `sanitize_callback`（宣告式 hardening；實際 sanitize 原已
  在 callback 內，無破口）。

> 已知保留項：`YSSsRateLimiter` 用 transient 計數為「近似」滑動窗（高併發 TOCTOU 可些微
> 超量，屬 DoS 放大非資料安全）；已由 60/分鐘 + LIKE 僅掃短欄位 + 結果快取多重緩衝，待
> 有持久 object cache 環境再評估原子化。

## [1.4.1] - 2026-06-15 — 設定頁改用核心 ysca 設計系統（頁籤式）

### Changed

- **設定頁全面改用核心 YS CART ysca 設計系統**（不再自製 `.ys-ss-*` 樣式）：
  `ysca-card` / `ysca-section-card` / `ysca-form-grid` / `ysca-field` / `ysca-input` /
  `ysca-select` / `ysca-switch`，與 YS CART 後台外觀一致。
- **設定改頁籤式**（4 分頁：前台與接管／搜尋內容／熱門建議／資料與保留），頁籤切換
  直接用核心 `ys-cart-admin-shell.js` 的 `data-ysca-tabs` 機制（不自寫 tab JS）。
- 後台選單端點改名「**進階搜尋設定**」（側欄群組仍為「進階搜尋」）。
- 說明頁新增「**搜尋分析報表**」引導卡（按鈕直達 報表分析 → 搜尋分析）。

## [1.4.0] - 2026-06-15 — 改名「進階搜尋」＋搜尋分析整合進報表分析

### Changed

- **更名「智慧搜尋」→「進階搜尋」**（外掛名、後台選單／頁面標題、Hub 名稱、說明頁）。
  技術識別不變（slug `ys-cart-smart-search`、namespace、資料表、REST、短代碼）以維持
  更新與相容。
- **後台側欄自成「進階搜尋」群組**（設定＋說明），排在「有聲書」群組之前（priority 20
  確保有聲書已注入後插入；無有聲書時 append）。不再併入「商店設定」群組。

### Added

- **搜尋分析整合進核心「報表分析」**：透過核心 2.52.44+ 的 `ys_ec_report_tabs` /
  `ys_ec_report_render_tab_search` 擴充點，新增「搜尋分析」分頁；分析 UI 由
  `YSSsAnalyticsAdmin::render_body()` 直接渲染於該分頁（自帶區間列＋CSV 匯出，並隱藏
  核心日期工具列避免雙控制）。舊核心（無此擴充點）時自動無效、不影響其餘功能。
  獨立「搜尋分析」後台頁已移除（改由報表分頁進入）。

### Fixed

- **分析頁配色**：KPI 四卡改帶語意色（總量／獨立＝正向藍綠、零結果／零結果率＝警示
  黃紅）＋每日趨勢柱改實心漸層、hover 加深，不再全灰單調。

## [1.3.0] - 2026-06-14

### Added

- **搜尋結果模式（A／B 可切換）**：商店設定 → 智慧搜尋新增「搜尋結果頁」設定。
  - **A 商品列表頁（預設）**：送出後落到核心商店頁（`?ys_ec_search=`，只顯示商品）——
    維持原行為，核心搜尋亦同此模式。
  - **B 獨立混合結果頁**：新短代碼 `[ys_ss_search_results]`，一頁呈現 **商品 grid（分頁）
    ＋分類 chips ＋文章／頁面** 分區（沿用下拉同一套搜尋與排除規則）。選 B 並儲存後
    自動建立「搜尋結果」頁並把所有智慧搜尋表單的送出落點導向該頁（頁首／短代碼／側邊欄
    接管一致）。每頁商品數可設定（預設 24）。
- **分析報表正確計入結果頁搜尋**：落地獨立結果頁的搜尋於 **server 端**記錄
  （`source='page'`），並對「同訪客＋同詞」做 **600 秒去重**，避免與下拉 JS `/log`
  重複計數、也防結果頁重整／分頁灌水；零結果搜尋一樣計入（零結果商機）。B 模式下
  下拉 JS 不再於 submit 時記錄（交由結果頁），雙保險不重複。

### Changed

- `YSSsSearchService` 抽出 `build_products_where()`／`map_product_rows()` 供下拉與結果頁
  共用；新增 `search_page()`（商品 `COUNT` 分頁 + 分類／文章於第一頁呈現）。

## [1.2.3] - 2026-06-14

### Fixed

- **搜尋框送出鈕在非 Blocksy 容器（側邊欄／短代碼）被主題塗成米色方塊**：佈景主題
  以較高權重對 `button[type="submit"]` 套自家 palette 底色、並壓掉 `input[type="search"]`
  的右側留白（放大鏡疊到文字）。先前只有 Blocksy 頁首元件層做了防護，側邊欄接管與
  一般短代碼沒罩到。改在智慧搜尋外掛**自身 CSS** 比照核心 `.ys-ec-search` 用
  `inputwrap > 子選擇器 + 屬性選擇器`提高權重壓過主題（不用 `!important`），讓送出鈕
  在任何主題、任何位置都是內嵌透明放大鏡、輸入框右側保留 44px 空間。

## [1.2.2] - 2026-06-14

### Added

- **接管模式同步接管「篩選側邊欄」搜尋**：接管模式（`takeover`）開啟時，除了原本
  覆蓋核心 `[ys_ec_search]`／`[ys_ec_search_icon]` 短代碼，現在也透過核心
  `ys_ec_sidebar_search_form` filter（需核心 2.52.39+）把商店篩選側邊欄的「搜尋商品」
  表單整段換成智慧搜尋框 —— 頁首、短代碼、側邊欄三處搜尋體驗一致。核心較舊（無此
  filter）時自動無效，不影響既有行為。

## [1.2.1] - 2026-06-13

### Performance

- **分類結果商品數改為批次查詢**：開啟「顯示商品數」時，分類分組原本對每個分類各發一次
  `COUNT(*)`（N+1）。改以單一 `GROUP BY category_id` 一次取回所有分類的商品數，
  搜尋下拉的 DB round-trip 從「分類數 + 1」降為 2。結果完全一致（無對應商品的分類仍為 0）。

### Notes

- 釐清並以註解與測試鎖定：商品搜尋採 `LIKE '%q%'` 子字串比對（與核心 `YSCatalogService` 一致），
  **刻意不使用 FULLTEXT** —— 本系統面向中文(CJK)商品，MySQL FULLTEXT 預設 parser 不斷中文詞、
  需 InnoDB ngram 且單字查詢會失配，核心 `ys_ec_products` 表本身亦無 FULLTEXT 索引。若日後要做
  索引級全文檢索，應於「核心」以 ngram FULLTEXT 統一改造（ADR），不在 addon 端變更核心 schema。

## [1.2.0] - 2026-06-13

### Added

- **商品搜尋欄位選擇**：設定頁可個別開關「商品名稱／SKU／網址代稱（slug）」作為比對欄位（預設全開、至少保名稱）。
- **自有排除商品清單**：設定頁可填入額外排除的商品（ID 或 slug，空白／逗號分隔），與核心「商店設定 → 搜尋」的排除設定合併套用。

### Fixed

- 商品搜尋現在同時尊重核心的**排除 ID（`ys_ec_search_excluded_ids`）**與排除 slug，與核心內建搜尋的排除行為完全一致（先前只讀 slug）。
- 修正設定儲存時清單型欄位（搜尋欄位／文章類型／分組順序）以索引合併導致「縮短清單時殘留舊值」的問題，改為整段覆寫。

## [1.1.0] - 2026-06-13

### Added

- **整合進核心「商店設定」**：智慧搜尋／搜尋分析／**新「智慧搜尋說明」頁**三個端點現在出現在 YS CART 後台「商店設定」導覽群組內（核心結構變動時自動退回獨立群組）。
- **使用說明頁**：快速開始、短代碼參考、Blocksy 頁首元件（含偵測狀態）、接管核心搜尋說明、建議運作原理、分析與隱私、FAQ。
- **自動更新**：內建 YS Plugin Hub Client（v2.0.2），透過 YS Plugin Hub 接收版本更新（與其他 YS 外掛相同機制）。

## [1.0.0] - 2026-06-13

首發（ADR-058 Phase 1）。

### Added

- **前台**：`[ys_ss_search]` 搜尋框、`[ys_ss_search_icon]` 圖示彈窗；「接管核心搜尋短代碼」開關（後註冊覆蓋、關閉即還原）；focus 顯示混合式熱門搜尋 chips（手動置頂優先＋自動統計補滿、數量可設預設 8）＋瀏覽器端最近搜尋；輸入即時分組結果（商品／分類／文章頁面，關鍵字高亮、鍵盤導航、零結果 fallback＋熱門詞出路）。
- **搜尋管線**：商品直查 `ys_ec_products`（標題前綴 > 標題含 > SKU 相關性、尊重核心搜尋排除設定）；分類 `ys_ec_categories`；文章／頁面 WP_Query（public post type 白名單）。
- **後台（YSAdminApp + ysca）**：「智慧搜尋」設定頁（元件／建議數量與取樣窗／手動關鍵字 CRUD／內容類型與 item 呈現設定／保留天數與資料量／清理操作）；「搜尋分析」頁（期間篩選、KPI×4、每日趨勢 CSS 圖、熱門排行＋一鍵轉關鍵字、零結果排行、CSV 匯出含公式注入防護）。
- **資料**：3 張獨立資料表（`ys_ss_queries` 原始紀錄／`ys_ss_terms_daily` 日彙總／`ys_ss_keywords` 手動關鍵字）；每日 cron（掛核心 `ys_ec_cron_daily`）：前 3 日 rollup（冪等）＋保留期批次清除（預設 180 天）＋建議快取重建。
- **REST**（全在核心 namespace `ys-ecommerce-headless/v1`）：公開 `query`／`suggest`／`log`（IP 限流 60/60/30 每分鐘、log 嚴格 sanitize、無 PII：當日鹽 16 字訪客雜湊）；管理 `overview`／`export`／`keywords` CRUD／`settings`／`purge`（capability＋wp_rest nonce）。
- 行為紀錄三時機（送出／點擊結果／停頓 1.2s 且 ≥2 字），sessionStorage 同詞 10 分鐘去重，絕不逐鍵記錄；`sendBeacon` fire-and-forget，分析失敗絕不影響搜尋。
- Fail-soft：缺 YS CART 時全休眠（資料保留、外掛頁提示、無 fatal）。
