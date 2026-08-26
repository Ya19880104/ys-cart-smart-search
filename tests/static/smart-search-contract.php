<?php
declare(strict_types=1);

/**
 * v1.0.0 — ADR-058 P1 contract（user 7 需求 + 5 原則 + 安全要點鎖定）。
 */

$root = dirname(__DIR__, 2);
$pass = 0; $fail = 0;
$check = static function (string $label, bool $ok) use (&$pass, &$fail): void {
    if ($ok) { ++$pass; echo "[PASS] {$label}\n"; return; }
    ++$fail; echo "[FAIL] {$label}\n";
};
$read = static function (string $rel) use ($root): string {
    $full = $root . '/' . str_replace('/', DIRECTORY_SEPARATOR, $rel);
    return is_file($full) ? (string) file_get_contents($full) : '';
};

$main      = $read('ys-cart-smart-search.php');
$plugin    = $read('src/YSSmartSearchPlugin.php');
$schema    = $read('src/Database/YSSsSchema.php');
$settings  = $read('src/Database/YSSsSettings.php');
$queryRepo = $read('src/Database/YSSsQueryRepository.php');
$pubCtrl   = $read('src/Api/YSSsPublicController.php');
$admCtrl   = $read('src/Api/YSSsAdminController.php');
$menu      = $read('src/Admin/YSSsMenuBootstrap.php');
$settingsA = $read('src/Admin/YSSsSettingsAdmin.php');
$analytics = $read('src/Admin/YSSsAnalyticsAdmin.php');
$short     = $read('src/Frontend/YSSsShortcodes.php');
$cron      = $read('src/Cron/YSSsCronBridge.php');
$limiter   = $read('src/Security/YSSsRateLimiter.php');
$receipt   = $read('src/Security/YSSsLogReceipt.php');
$frontJs   = $read('assets/js/ys-ss-front.js');
$log       = $read('CHANGELOG.md');

$all_src = $main . $plugin . $schema . $settings . $queryRepo . $pubCtrl . $admCtrl . $menu . $settingsA . $analytics . $short . $cron . $limiter;

// C1 版本一致
preg_match('/Version:\s*([0-9.]+)/', $main, $vh);
preg_match("/YS_SMART_SEARCH_VERSION', '([0-9.]+)'/", $main, $vc);
$check('C1 version header/constant match >= 1.0.0 + CHANGELOG',
    '' !== ($vh[1] ?? '') && ($vh[1] ?? '') === ($vc[1] ?? '')
    && version_compare($vh[1] ?? '0', '1.0.0', '>=')
    && str_contains($log, '## [1.0.0]'));

// C2 需求⑥：三張獨立表、零碰核心 schema
$check('C2 three dedicated ys_ss_* tables via dbDelta',
    str_contains($schema, "ys_ss_queries") && str_contains($schema, "ys_ss_terms_daily")
    && str_contains($schema, "ys_ss_keywords") && str_contains($schema, 'dbDelta'));

// C3 需求⑤：保留預設 180、cron 掛核心每日 hook、批次清除
$check('C3 retention default 180 + ys_ec_cron_daily + batched purge',
    str_contains($settings, "'retention_days'      => 180")
    && str_contains($cron, "ys_ec_cron_daily")
    && str_contains($queryRepo, 'LIMIT 5000'));

// C4 需求③：混合建議（手動優先+自動補滿）、數量預設 8、快取
$suggest = $read('src/Services/YSSsSuggestService.php');
$check('C4 hybrid suggestions (manual first, auto fill, count default 8, cached)',
    str_contains($settings, "'suggest_count'       => 8")
    && str_contains($suggest, 'active_keywords')
    && str_contains($suggest, 'auto_terms')
    && str_contains($suggest, 'set_transient'));

// C5 需求②：兩個前台元件 + 彈窗 + 接管開關（後註冊覆蓋）
$check('C5 bar + icon popup shortcodes + takeover re-registration',
    str_contains($short, "add_shortcode( 'ys_ss_search'")
    && str_contains($short, "add_shortcode( 'ys_ss_search_icon'")
    && str_contains($short, "add_shortcode( 'ys_ec_search'")
    && str_contains($short, 'ys-ss-popup'));

// C6 需求⑦：分組管線（商品/分類/文章）+ item 呈現設定
$search = $read('src/Services/YSSsSearchService.php');
$check('C6 grouped pipeline (products/categories/posts) + display settings',
    str_contains($search, 'products_group') && str_contains($search, 'categories_group')
    && str_contains($search, 'posts_group') && str_contains($search, 'no_found_rows')
    && str_contains($settings, "'show_image'") && str_contains($settings, "'excerpt_len'"));

// C7 需求④：報表（期間/KPI/趨勢/Top/零結果）+ CSV 公式注入防護
$check('C7 analytics overview + CSV formula-injection guard',
    str_contains($queryRepo, 'function overview')
    && str_contains($admCtrl, "'=', '+', '-', '@'")
    && str_contains($analytics, 'ys-ss-zero-body')
    && str_contains($analytics, 'data-ss-range'));

// C8 5 原則：核心 namespace、零 admin-ajax/admin_post、無自建主選單、YSAdminApp、ysca
$check('C8 addon 5 rules (core ns / no admin-ajax / no top menu / YSAdminApp / ysca)',
    str_contains($pubCtrl, "ys-ecommerce-headless/v1")
    && str_contains($admCtrl, "ys-ecommerce-headless/v1")
    && !preg_match('/wp_ajax_|admin_post_|add_menu_page/', $all_src)
    && str_contains($menu, "add_submenu_page")
    && str_contains($menu, "'ys-cart'")
    && str_contains($settingsA, 'YSAdminApp::open')
    && str_contains($settingsA, 'ysca-btn'));

// C9 安全：公開端點限流、raw-input gate、admin nonce+capability、簽發日驗證與 opaque event identity。
$check('C9 security (rate limits / raw log gate / admin nonce+cap / signed receipt event)',
    str_contains($pubCtrl, 'allow_public_query()')
    && str_contains($pubCtrl, "allow( 'log', 30 )")
    && str_contains($pubCtrl, 'YSSsSearchInput::inspect')
    && str_contains($admCtrl, "wp_verify_nonce")
    && str_contains($admCtrl, "manage_options")
	&& str_contains($limiter, 'SELECT GET_LOCK(%s, 0)')
	&& str_contains($limiter, 'SELECT RELEASE_LOCK(%s)')
	&& str_contains($limiter, 'ys_ss_rate_v1_')
	&& str_contains($limiter, 'ON DUPLICATE KEY UPDATE')
	&& str_contains($limiter, 'write_and_verify_state')
	&& ! str_contains($limiter, 'get_transient(')
	&& ! str_contains($limiter, 'set_transient(')
    && str_contains($limiter, 'function visitor_hash_at')
    && str_contains($limiter, 'self::visitor_hash_at( time() )')
    && str_contains($receipt, 'function verify_for_request')
    && str_contains($receipt, "visitor_hash_at( \$claims['iat'] )")
    && str_contains($pubCtrl, 'YSSsLogReceipt::verify_for_request')
    && str_contains($pubCtrl, 'ys-ss-receipt-event'));

// C10 分析旁路：log 失敗不擲回前台、cron 例外吞掉
$check('C10 analytics is a side-channel (log failures swallowed, cron guarded)',
    str_contains($pubCtrl, 'catch ( \\Throwable')
    && str_contains($cron, 'catch ( \\Throwable'));

// C11 前端：IME 守門、debounce、sessionStorage 去重、sendBeacon、無 innerHTML 注入（高亮走 DOM）
$check('C11 front JS (IME guard / debounce / dedupe / beacon / DOM-safe highlight)',
    str_contains($frontJs, 'compositionstart')
    && str_contains($frontJs, 'debounce')
    && str_contains($frontJs, 'sessionStorage')
    && str_contains($frontJs, 'sendBeacon')
    && str_contains($frontJs, 'highlightInto'));

// C12 fail-soft：缺核心休眠、表保留
$check('C12 fail-soft when YS CART missing',
    str_contains($plugin, 'has_ys_cart')
    && str_contains($plugin, 'return; // 休眠'));

// ── v1.1.0 ──
$menu = $read('src/Admin/YSSsMenuBootstrap.php');
$help = $read('src/Admin/YSSsHelpAdmin.php');

// C13 版本 >= 1.1.0 + CHANGELOG
$check('C13 version >= 1.1.0 + CHANGELOG records 1.1.0',
    version_compare($vh[1] ?? '0', '1.1.0', '>=')
    && str_contains($log, '## [1.1.0]'));

// C14 自成「進階搜尋」側欄群組（穩定插在核心 settings 群組之後）+ 報表分頁整合 + 說明頁
$check('C14 own 進階搜尋 nav group after settings + report-tab integration + help page',
    str_contains($menu, "'advanced_search'")
    && str_contains($menu, "'settings' === \$key")
    && str_contains($menu, 'SLUG_HELP')
    && str_contains($menu, "add_filter( 'ys_ec_report_tabs'")
    && str_contains($menu, 'ys_ec_report_render_tab_search')
    && str_contains($help, 'class YSSsHelpAdmin')
    && str_contains($help, 'YSAdminApp::open'));

// C15 Hub client 更新功能
$check('C15 Hub client wired (vendor autoload + register slug)',
    str_contains($main, 'vendor/autoload.php')
    && str_contains($main, 'YSPluginHubClient::register')
    && str_contains($main, "'slug'        => 'ys-cart-smart-search'"));

// ── v1.2.0 ──
$search   = $read('src/Services/YSSsSearchService.php');
$settingsP = $read('src/Database/YSSsSettings.php');
$settingsA = $read('src/Admin/YSSsSettingsAdmin.php');

// C16 v1.2.0 A：尊重核心 excluded_ids（與 slugs 一致）
$check('C16 respects core excluded_ids + excluded_slugs',
    str_contains($search, "ys_ec_search_excluded_ids")
    && str_contains($search, "ys_ec_search_excluded_slugs")
    && str_contains($search, 'id NOT IN'));

// C17 v1.2.0 B：商品欄位選擇 + 自有排除清單（設定+UI+查詢）
$check('C17 product field selection + own exclude list',
    str_contains($settingsP, "'fields'")
    && str_contains($settingsP, "'exclude'")
    && str_contains($search, "\$cfg['fields']")
    && str_contains($search, 'collect_exclusions')
    && str_contains($settingsA, 'data-ss-product-field')
    && str_contains($settingsA, 'products.exclude'));

// C18 v1.2.0：update() 對清單型欄位整段覆寫（修 array_replace_recursive 索引殘留）
$check('C18 update() replaces list fields wholesale (no index leak)',
    str_contains($settingsP, "\$merged['products']['fields'] = \$patch['products']['fields']")
    && str_contains($settingsP, "\$merged['posts']['post_types'] = \$patch['posts']['post_types']"));

// ── v1.2.1 ──
// C19：分類商品數批次化（單一 GROUP BY，消除 per-row COUNT 的 N+1）；
//      商品搜尋維持 CJK-safe 的 LIKE 子字串比對（刻意不用 FULLTEXT — 中文需 ngram、核心亦無此索引）。
$check('C19 v1.2.1 categories count batched (no per-row COUNT N+1) + products stay CJK-safe LIKE (not FULLTEXT)',
    str_contains($search, 'GROUP BY category_id')
    && str_contains($search, 'category_id IN')
    && strpos($search, 'category_id = %d') === false   // 舊 per-row COUNT 已移除
    && strpos($search, 'MATCH(') === false             // 未誤用 FULLTEXT（CJK 不安全）
    && strpos($search, 'AGAINST') === false
    && str_contains($search, 'title LIKE %s'));

// C20 v1.2.1 版本 + CHANGELOG
$check('C20 v1.2.1 version >= 1.2.1 + CHANGELOG records 1.2.1',
    version_compare($vh[1] ?? '0', '1.2.1', '>=')
    && str_contains($log, '## [1.2.1]'));

// ── v1.2.2 ──
// C21：接管模式同步接管核心篩選側邊欄搜尋（hook ys_ec_sidebar_search_form，核心 2.52.39+）
$check('C21 v1.2.2 takeover also hooks core sidebar search filter',
    str_contains($short, "add_filter( 'ys_ec_sidebar_search_form'")
    && str_contains($short, 'function sidebar_search_form')
    && version_compare($vh[1] ?? '0', '1.2.2', '>=')
    && str_contains($log, '## [1.2.2]'));

// ── v1.4.x（改名 + 報表整合 + review 修正）──
$results = $read('src/Frontend/YSSsResultsPage.php');
// C22：更名「進階搜尋」、舊核心 fallback、ensure_page 守門、search_page 快取
$check('C22 v1.4.x rename + old-core fallback + ensure_page cap-guard + search_page cache',
    // 更名（顯示用，slug 不變）
    str_contains($menu, "'進階搜尋設定'") && ! str_contains($settingsA, '智慧搜尋')
    // 舊核心 fallback：core_has_report_tabs 判斷 + 條件註冊獨立分析
    && str_contains($menu, 'function core_has_report_tabs') && str_contains($menu, "version_compare( YS_ECOMMERCE_VERSION, '2.52.44'")
    && str_contains($menu, '! self::core_has_report_tabs()')
    // ensure_page 縱深守門
    && str_contains($results, "current_user_can( 'manage_options' )") && str_contains($results, 'function ensure_page')
    // search_page 短 TTL 快取
    && str_contains($search, 'ys_ss_sp_') && str_contains($search, 'set_transient') && str_contains($search, 'MINUTE_IN_SECONDS')
    && version_compare($vh[1] ?? '0', '1.4.2', '>=') && str_contains($log, '## [1.4.2]'));

// C23：B 模式 view_all 落點集中 mode-aware（P1）+ 分析事件重播去重位於唯一寫入瓶頸（P2）
$check('C23 view_all mode-aware helper + exact-event replay dedupe centralized in log()',
    // P1：view_all 改用 YSSsResultsPage::search_url（非直接 shop_url）；helper 為 mode-aware
    preg_match("/'view_all'\\s*=>\\s*YSSsResultsPage::search_url\\(/", $search)
    && str_contains($results, 'function search_url')
    && str_contains($results, 'results_mode')
    && str_contains($results, "add_query_arg( 'ys_ec_search'")
    && str_contains($results, 'self::page_url()')
    // 前端「查看全部」用 data.view_all（修 server 端 view_all 即修下拉連結）
    && str_contains($frontJs, 'data.view_all')
    // P2：exact event 去重位於 log()（唯一寫入瓶頸）；page 與 receipt 各建立事件身分。
    && substr_count($queryRepo, 'created_at >= %s AND visitor_hash = %s') === 1
    && str_contains($queryRepo, 'string $event_hash')
    && str_contains($queryRepo, 'ys-ss-page-event')
    && str_contains($pubCtrl, 'ys-ss-receipt-event')
    // 版本
    && version_compare($vh[1] ?? '0', '1.4.3', '>=') && str_contains($log, '## [1.4.3]'));

// ── v1.5.0（搜尋注入防護 + 後台清理）──
$guard = $read('src/Security/YSSsInjectionGuard.php');
$input = $read('src/Security/YSSsSearchInput.php');
$admission = $read('src/Analytics/YSSsAnalyticsAdmission.php');

// C24 防注入：單一真相源 is_attack + 進站攔截（log 瓶頸 + query 拒絕執行）+ 建議過濾
$check('C24 v1.5.2 raw-first guard: centralized ingress + A/B/REST closure + suggestion defense',
    // guard 與 raw-first 決策器存在，並以高訊號模式取代 broad syntax-character ban
    str_contains($guard, 'class YSSsInjectionGuard')
    && str_contains($guard, 'function is_attack')
    && str_contains($input, 'class YSSsSearchInput')
    && str_contains($input, 'function inspect')
    && str_contains($input, 'pre_do_shortcode_tag')
    // 唯一寫入瓶頸 log() 走 analytics admission；admission 再委派相同 raw input SOT
    && str_contains($admission, 'class YSSsAnalyticsAdmission')
    && str_contains($admission, 'YSSsSearchInput::inspect')
    && str_contains($queryRepo, 'YSSsAnalyticsAdmission::should_record')
    // REST、A/list 與 B/page 共用 raw ingress，query route 不先 sanitize
    && str_contains($pubCtrl, 'YSSsSearchInput::inspect')
    && ! str_contains($pubCtrl, "'sanitize_callback' => 'sanitize_text_field'")
    && str_contains($plugin, 'YSSsSearchInput::register')
    && str_contains($pubCtrl, 'empty_result')
    && str_contains($search, 'function empty_result')
    && str_contains($read('src/Frontend/YSSsResultsPage.php'), 'YSSsSearchInput::inspect')
    // 建議縱深防禦：bounded over-fetch 後走相同 input SOT，service 在 cache/filter 後 final gate
    && str_contains($queryRepo, '$scan_limit')
    && str_contains($queryRepo, 'YSSsSearchInput::inspect')
    && str_contains($suggest, 'finalize_payload'));

// C25 v1.5.2 後台清理：精確刪詞原子化；heuristic injection bulk-delete 退役；全清保留。
$adminJs = $read('assets/js/ys-ss-admin.js');
$check('C25 v1.5.2 safe admin cleanup: atomic exact delete + retired heuristic purge + full clear',
	str_contains($queryRepo, 'function delete_term')
	&& str_contains($queryRepo, 'function delete_terms')
	&& ! str_contains($queryRepo, 'function purge_injection')
	&& ! str_contains($queryRepo, 'TRUNCATE TABLE')
	&& str_contains($queryRepo, 'START TRANSACTION')
	&& str_contains($queryRepo, "false === \$dq")
	&& str_contains($queryRepo, "false === \$dd")
	&& str_contains($queryRepo, 'COMMIT')
	&& str_contains($queryRepo, 'ROLLBACK')
	&& str_contains($queryRepo, 'GET_LOCK')
	&& str_contains($queryRepo, 'assert_transactional_tables')
	&& str_contains($queryRepo, 'information_schema.TABLES')
	&& str_contains($queryRepo, 'function purge_all')
    && str_contains($admCtrl, "\$base . '/term'")
    && str_contains($admCtrl, "\$base . '/terms'")
    && str_contains($admCtrl, 'function delete_term')
	&& str_contains($admCtrl, 'function delete_terms')
    && str_contains($admCtrl, "'injection' === \$mode")
    && str_contains($admCtrl, 'ys_ss_preview_required')
    && str_contains($admCtrl, 'WP_REST_Server::DELETABLE')
	&& str_contains($admCtrl, "'permission_callback' => [ \$this, 'permission_admin' ]")
	&& ! str_contains($analytics, 'ys-ss-purge-injection')
	&& str_contains($analytics, 'ys-ss-action-msg')
	&& str_contains($adminJs, 'deleteTerm')
	&& str_contains($analytics, 'ys-ss-delete-selected')
	&& str_contains($analytics, 'ys-ss-delete-all-analytics')
	&& str_contains($adminJs, "api('/terms'")
	&& str_contains($adminJs, 'ys-ss-action-msg')
	&& str_contains($adminJs, "method: 'DELETE'")
	&& str_contains($adminJs, '清理失敗，請稍後再試。')
	&& ! str_contains($adminJs, "mode: 'injection'"));

// C26 v1.5.0 版本 + CHANGELOG
$check('C26 v1.5.0 version >= 1.5.0 + CHANGELOG records 1.5.0',
    version_compare($vh[1] ?? '0', '1.5.0', '>=')
    && str_contains($log, '## [1.5.0]'));

// C27 v1.5.1 結果頁防注入一致性 + 版本 + CHANGELOG
$check('C27 v1.5.1+ results-page raw-first guard + version >= 1.5.1 + CHANGELOG',
    str_contains($read('src/Frontend/YSSsResultsPage.php'), 'YSSsSearchInput::inspect')
    && version_compare($vh[1] ?? '0', '1.5.1', '>=')
    && str_contains($log, '## [1.5.1]'));

// C28 v1.5.3 候選 metadata：外掛 header、常數與首個 CHANGELOG 版本須精確一致。
preg_match('/^## \[([0-9]+\.[0-9]+\.[0-9]+)\]/m', $log, $vl);
$check('C28 v1.5.3 exact metadata parity + release floor',
    '' !== ($vh[1] ?? '')
    && ($vh[1] ?? '') === ($vc[1] ?? '')
    && ($vh[1] ?? '') === ($vl[1] ?? '')
    && version_compare($vh[1] ?? '0', '1.5.3', '>='));

// C29 fix-5：完整 ingress receipt／分析分流、recent v2，以及 REST/B 唯一 query 預算。
$check('C29 v1.5.3 fix-5 full-ingress analytics + shared public query authority',
    str_contains($limiter, 'function allow_public_query')
    && str_contains($limiter, "self::allow( 'query', 60 )")
    && str_contains($pubCtrl, 'allow_public_query()')
    && str_contains($results, 'allow_public_query()')
    && str_contains($receipt, 'ys-ss-log-receipt-v2')
	&& str_contains($receipt, "'eid'")
    && str_contains($receipt, 'signature_input( $payload, $raw )')
    && str_contains($receipt, 'verified_claims( $receipt, $query, $raw, $now, false )')
    && str_contains($pubCtrl, "\$request->get_param( 'ingress' )")
    && str_contains($pubCtrl, "\$input['raw']")
    && str_contains($results, "\$input['raw']")
    && str_contains($queryRepo, 'string $admission_ingress')
    && str_contains($frontJs, "ysss_recent_v2")
    && str_contains($frontJs, 'ingress: ingress')
    && ! str_contains($frontJs, "var RECENT_KEY = 'ysss_recent';"));

$check('C30 original-scope analytics, current-product suggestions, and list fallback',
	str_contains($admission, 'YSSsSearchInput::inspect')
	&& ! str_contains($admission, 'utm_')
	&& str_contains($search, 'function has_product_match')
	&& str_contains($suggest, 'YSSsSearchService::has_product_match')
	&& str_contains($short, 'function maybe_log_list_search')
	&& str_contains($short, 'YSSsLogReceipt::verify_for_request')
	&& str_contains($frontJs, 'ys_ss_log_receipt'));

echo "\nv1.5.3 contract: PASS={$pass} FAIL={$fail}\n";
if ($fail > 0) {
    throw new RuntimeException("ys-cart-smart-search contract FAILED ({$fail})");
}
