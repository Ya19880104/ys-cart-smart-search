# Changelog

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
