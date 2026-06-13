# Changelog

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
