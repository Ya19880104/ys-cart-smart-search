# YS Smart Search v1.5.3 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Release v1.5.3 as one bounded search-interaction and data-integrity batch without reopening the v1.5.2 security and cleanup work.

**Architecture:** Keep existing REST routes, database schema, and receipt wire version. Add one per-form front controller, make admin mutations and suggestion invalidation report authoritative outcomes, resolve B-mode pages against real totals, and make analytics/receipt identity decisions from token-local, product-positive, and signed-time data. All production changes are test-first and converge into one immutable candidate.

**Tech Stack:** WordPress/PHP 8.1+, vanilla JavaScript, WordPress REST, `$wpdb`, Node VM behavior harness, PowerShell release verification, Git/GitHub CLI.

**Spec:** `docs/superpowers/specs/2026-08-26-ys-smart-search-v1.5.3-design.md`

## Global Constraints

- Baseline is exact commit `00703e212b6d805d281e06fadc8bdfb78a23d745`; recovery branch is `backup/ys-cart-smart-search-v1.5.2-pre-v1.5.3-20260826`.
- Work directly on canonical `main`, as explicitly authorized; do not reset, stash, clean, rebase, merge, or overwrite unrelated work.
- No schema, public REST URL, external dependency, new search feature, broader attack heuristic, or full-clear background architecture.
- v1.5.0-v1.5.2 injection, neutral response, positive-recent, exact/full cleanup, receipt HMAC, and maintenance-lock contracts remain regression requirements.
- "Positive" for browser recent history and automatic terms means an actual product result; category/post-only results remain visible and analytically recordable but are zero-product events.
- Development uses focused RED/GREEN commands; run the complete suite only after the entire batch and version metadata are ready.
- Only `dev-newecommerce.wppro.cloud` may receive the exact candidate. Hub, S3, production/customer sites, `dev-checkout`, and `dev-yscart` GUI remain forbidden without new authorization.
- Never store or echo credentials or private-key bytes. Prefer an existing AI/YSAI SSH key at the test-site stage after matching its public identity.
- Every task ends in a focused commit and independent task review. The primary controller owns shared-tree integration, final gate, candidate identity, site acceptance, and Release judgment.
- After each completed task, append the SDD ledger and one credential-free memory note before moving to the next task.

---

### Task 1: Focused test selection

**Files:**
- Modify: `tests/run.php`

**Interfaces:**
- Consumes: optional CLI arguments in exact repo-relative form: `static/smart-search-contract.php`, `behavior/<file>.php`, or `js/<file>.js`.
- Produces: no-argument full-suite behavior identical to v1.5.2; selected files only when arguments exist; non-zero exit for missing, duplicated, absolute, traversal, or outside-suite selectors.

- [ ] **Step 1: Prove the selector is missing**

Run this against unchanged v1.5.2:

```powershell
$out = php tests/run.php behavior/harness-behavior.php 2>&1
if ($LASTEXITCODE -eq 0 -and ($out -join "`n") -match 'PASS=1 FAIL=0') { exit 0 }
exit 1
```

Expected: exit `1` because the current runner ignores the selector and reports the full suite.

- [ ] **Step 2: Implement exact selector resolution**

Refactor discovery into a literal relative-path map. The implementation must follow this shape:

```php
$all = [
    'static/smart-search-contract.php' => __DIR__ . '/static/smart-search-contract.php',
];
foreach ($behaviorFiles as $file) {
    $all['behavior/' . basename($file)] = $file;
}
foreach ($jsFiles as $file) {
    $all['js/' . basename($file)] = $file;
}

$requested = array_slice($argv ?? [], 1);
if ($requested) {
    if (count($requested) !== count(array_unique($requested))) {
        fwrite(STDERR, "Duplicate test selector.\n");
        exit(1);
    }
    foreach ($requested as $selector) {
        if (!is_string($selector) || !isset($all[$selector])) {
            fwrite(STDERR, "Unknown test selector: " . (string) $selector . "\n");
            exit(1);
        }
    }
    $selected = $requested;
} else {
    $selected = array_keys($all);
}
```

Execute the selected static/PHP/JS files through the existing real runner logic. Preserve the no-behavior-files fail-closed check for full mode; a focused JS/static run does not require a behavior file.

- [ ] **Step 3: Verify selector behavior**

Run:

```powershell
php tests/run.php behavior/harness-behavior.php
php tests/run.php js/front-receipt-race.js
php tests/run.php behavior/does-not-exist.php
php tests/run.php ../tests/bootstrap.php
```

Expected: first two commands pass only their named file; last two exit non-zero without running other suites.

- [ ] **Step 4: Verify syntax and commit**

Run `php -l tests/run.php` and `git diff --check`.

Commit:

```text
test: support focused smart search suites
```

---

### Task 2: Front interaction controller and accessibility

**Files:**
- Create: `tests/support/front-js-harness.js`
- Create: `tests/js/front-state-controller.js`
- Create: `tests/js/front-accessibility.js`
- Create: `tests/behavior/frontend-accessibility-behavior.php`
- Modify: `tests/js/front-receipt-race.js`
- Modify: `assets/js/ys-ss-front.js`
- Modify: `src/Frontend/YSSsShortcodes.php`
- Modify: `assets/css/ys-ss-front.css`

**Interfaces:**
- Consumes: existing `ysSsFront` configuration, `/suggest`, `/query`, `/log`, form markup, receipt and recent behavior.
- Produces: private per-form state `{generation, mode, proof, activeRequest, activeIndex, settleTimer}`, current-generation rendering, combobox/listbox ARIA, and popup focus restoration.

- [ ] **Step 1: Create a reusable real-script harness**

Move the existing VM/script loading, form, panel, element, fetch, timer, storage, and beacon behavior from `front-receipt-race.js` into `tests/support/front-js-harness.js`. Add complete attribute maps, `focus()`, `click()`, `closest()`, `contains()`, `querySelectorAll()`, deferred resolve/reject, an AbortController double with real signal state, and fake timer advancement. Assertions must inspect the production script's rendered DOM and side effects, not the doubles themselves.

- [ ] **Step 2: Write failing state tests**

`tests/js/front-state-controller.js` must use literal fixtures to prove:

```js
// Late empty-input suggest cannot replace a later successful nova query.
// Changing A to B immediately removes A links and A proof before debounce.
// 500, invalid JSON, and network rejection render CFG.i18n.error, clear busy,
// keep recent/beacon empty, and never retain A after B fails.
// A first zero-result request loads popular terms from a cold suggest cache;
// that late fallback cannot render after input changes again.
// Aborted A is silent while current B renders successfully.
```

The test fixture must set `CFG.i18n.error` to the literal `搜尋暫時無法使用，請稍後再試。` and assert that server error details never enter panel text.

- [ ] **Step 3: Write failing accessibility tests**

`tests/js/front-accessibility.js` must prove three ArrowDown presses select option IDs 0, 1, and 2 while focus remains on input; ArrowUp moves back; Enter calls only the current option; Escape clears `aria-activedescendant`, collapses the panel, and sets `aria-expanded=false`. Open the popup with the second of two triggers, prove Tab/Shift+Tab wraps within the dialog, then Escape and prove focus returns to that second trigger.

`tests/behavior/frontend-accessibility-behavior.php` must render the real bar and popup markup and assert unique panel IDs plus literal initial attributes: `role="combobox"`, `aria-autocomplete="list"`, `aria-expanded="false"`, matching `aria-controls`, `role="listbox"`, `aria-live="polite"`, and trigger `aria-haspopup="dialog"`.

- [ ] **Step 4: Run RED**

Run:

```powershell
php tests/run.php js/front-state-controller.js js/front-accessibility.js behavior/frontend-accessibility-behavior.php
```

Expected: behavior failures caused by late suggest/error handling, missing combobox/listbox state, broken second-arrow navigation, and missing focus restoration—not harness errors.

- [ ] **Step 5: Implement the controller**

Use these private helpers in `ys-ss-front.js`. `beginInteraction()` must perform the state transition literally in this order:

```js
function beginInteraction(form, mode) {
    var state = controller(form);
    state.generation += 1;
    state.mode = mode;
    clearTimeout(state.settleTimer);
    state.settleTimer = null;
    state.proof = null;
    form._ysSsLogProof = null;
    if (state.activeRequest) { state.activeRequest.abort(); }
    state.activeRequest = null;
    state.activeIndex = -1;
    renderPanelState(form, mode, null);
    return state.generation;
}
```

`controller(form)` creates/returns the one state object; `isCurrent(form, token, expectedValue)` requires matching generation and current input; `renderPanelState(form, mode, payload)` is the only DOM/ARIA writer; `loadSuggestions()` returns one deduplicated Promise and never renders; `requestSuggestions(form, token)` and `requestQuery(form, query, token)` render only through `isCurrent()`.

Use feature-detected `AbortController`; only superseded `AbortError` is silent. Every current HTTP/non-JSON/network failure renders the fixed localized error. Zero results await `loadSuggestions()` and append fallback chips only if the same generation, input, and empty-result mode remain current.

Keep DOM focus on the input and use option IDs plus `aria-activedescendant`; do not move focus to result links. Enter activates the selected element. Store the exact popup opener, cycle visible focusables on Tab/Shift+Tab, and restore it on every close path.

Add an `.is-active` option style that is visually equivalent to the existing hover/focus treatment so keyboard selection is observable without relying only on ARIA.

Add this localization key in `YSSsShortcodes`:

```php
'error' => __( '搜尋暫時無法使用，請稍後再試。', 'ys-cart-smart-search' ),
```

Render unique server-side panel IDs and the attributes mandated by the spec.

- [ ] **Step 6: Run focused GREEN and regressions**

Run:

```powershell
php tests/run.php js/front-state-controller.js js/front-accessibility.js js/front-receipt-race.js behavior/frontend-accessibility-behavior.php
node --check assets/js/ys-ss-front.js
php -l src/Frontend/YSSsShortcodes.php
```

Expected: all selected tests and syntax checks pass without warnings.

- [ ] **Step 7: Commit**

Commit:

```text
fix: unify smart search interaction state
```

---

### Task 3: Token-local analytics identifiers

**Files:**
- Modify: `tests/behavior/analytics-admission-behavior.php`
- Modify: `src/Analytics/YSSsAnalyticsAdmission.php`

**Interfaces:**
- Consumes: raw/query canonical candidates and existing fixed classification reason strings.
- Produces: residual opaque-token count after byte-span containment by recognizable identifier phrases.

- [ ] **Step 1: Add literal failing cases**

Add table-driven classifier and repository-write assertions for:

```php
[
    ['SKU-9F8A7B6C5D4E3F2A', 0, YSSsAnalyticsAdmission::ADMIT_HUMAN_ZERO],
    ['SKU-9F8A7B6C5D4E3F2A MPN-A1B2C3D4E5F6G7H8', 0, YSSsAnalyticsAdmission::ADMIT_HUMAN_ZERO],
    ['SKU-ABCD qwertyuiopasdfghjklzxcvb', 0, YSSsAnalyticsAdmission::REJECT_MACHINE_TOKEN],
    ['SKU-9F8A7B6C5D4E3F2A 123e4567-e89b-12d3-a456-426614174000', 0, YSSsAnalyticsAdmission::REJECT_MACHINE_TOKEN],
    ['ISBN-9781234567890 utm_source=test', 2, YSSsAnalyticsAdmission::REJECT_KNOWN_PARAMETER],
]
```

Also cover two long identifiers with positive total, percent-decoded/fullwidth candidates, and the same token appearing once inside an identifier and once outside it. Repository assertions must prove insert/no-insert, not only classifier return values.

- [ ] **Step 2: Run RED**

Run `php tests/run.php behavior/analytics-admission-behavior.php`.

Expected: the query-global exemption admits unrelated noise and rejects two legitimate long identifiers.

- [ ] **Step 3: Implement byte-span classification**

Replace the global boolean with these private interfaces:

```php
/** @return list<array{token:string,start:int,length:int}> */
private static function opaque_token_spans( string $query ): array;

/** @return list<array{start:int,length:int}> */
private static function identifier_spans( string $query ): array;

private static function residual_opaque_count( string $query ): int;
```

Both regexes use `PREG_OFFSET_CAPTURE`. An opaque match is exempt only when `token_start >= identifier_start` and `token_end <= identifier_end`. Deduplicate only residual token values; if one occurrence is identifier-contained and another occurrence of the same value is outside, the outside occurrence remains residual.

Across canonical candidates use the maximum residual count. Reject at residual count `>=2`, or at `>=1` for a zero-result query. Known parameters keep priority.

- [ ] **Step 4: Run focused GREEN and lint**

Run:

```powershell
php tests/run.php behavior/analytics-admission-behavior.php
php -l src/Analytics/YSSsAnalyticsAdmission.php
```

- [ ] **Step 5: Commit**

Commit:

```text
fix: scope analytics identifiers to matching tokens
```

---

### Task 4: Signed-time receipt identity

**Files:**
- Modify: `tests/behavior/log-receipt-behavior.php`
- Modify: `src/Security/YSSsRateLimiter.php`
- Modify: `src/Security/YSSsLogReceipt.php`
- Modify: `src/Api/YSSsPublicController.php`
- Modify: `tests/static/smart-search-contract.php`

**Interfaces:**
- Consumes: v1 signed claims, current request IP/UA, salts, query, and optional test clock.
- Produces: `visitor_hash_at(int $timestamp)`, issue/verify optional clock, and verified `visitor_hash` claim handed to the repository.

- [ ] **Step 1: Add cross-midnight failing tests**

Use literal timestamps `1787702370` (2026-08-25 23:59:30 UTC) and `1787702415` (2026-08-26 00:00:15 UTC). With fixed IP/UA, prove:

```php
$issue = 1787702370;
$verify = 1787702415;
$visitor = YSSsRateLimiter::visitor_hash_at($issue);
$receipt = YSSsLogReceipt::issue('nova', 5, 'products', $visitor, $issue);
$claims = YSSsLogReceipt::verify_for_request($receipt, 'nova', $verify);
ysss_assert_same($visitor, $claims['visitor_hash'] ?? null);
```

Add repository/controller coverage proving a before/after-midnight replay has one dedupe identity, plus different IP, different UA, expiry, two-day-old, tamper, future `iat`, and legacy v1 payload compatibility.

- [ ] **Step 2: Run RED**

Run `php tests/run.php behavior/log-receipt-behavior.php`.

Expected: current verify API cannot use signed `iat` and the midnight case fails.

- [ ] **Step 3: Implement signed-time visitor binding**

Add:

```php
public static function visitor_hash_at( int $timestamp ): string;
public static function visitor_hash(): string {
    return self::visitor_hash_at( time() );
}
```

Keep issue wire version `1`, adding optional `$now` only for a consistent clock:

```php
public static function issue(
    string $query,
    int $total,
    string $content_types,
    string $visitor_hash,
    ?int $now = null
): string;

/** @return array{query:string,total:int,content_types:string,visitor_hash:string}|null */
public static function verify(
    string $receipt,
    string $query,
    string $visitor_hash,
    ?int $now = null
): ?array;

/** @return array{query:string,total:int,content_types:string,visitor_hash:string}|null */
public static function verify_for_request(
    string $receipt,
    string $query,
    ?int $now = null
): ?array;
```

Extract one private verified-claims parser. Existing `verify()` retains explicit visitor-hash verification for PHP ABI compatibility. After HMAC/claim/TTL/query validation, `verify_for_request()` computes `YSSsRateLimiter::visitor_hash_at($claims['iat'])`, compares with signed `vh`, and returns signed `vh` as `visitor_hash`.

The query controller captures one `$now`, calls `visitor_hash_at($now)`, and passes both to `issue()`. The log controller calls `verify_for_request()` and passes `$claims['visitor_hash']` to `YSSsQueryRepository::log()`.

Update static contract C9 to exercise `visitor_hash_at`/request verification rather than requiring a direct `gmdate('Ymd')` literal.

- [ ] **Step 4: Run focused GREEN and lint**

Run:

```powershell
php tests/run.php behavior/log-receipt-behavior.php
php -l src/Security/YSSsRateLimiter.php
php -l src/Security/YSSsLogReceipt.php
php -l src/Api/YSSsPublicController.php
php tests/run.php static/smart-search-contract.php
```

- [ ] **Step 5: Commit**

Commit:

```text
fix: bind analytics receipts to issue day
```

---

### Task 4B: Product-only positive memory authority

**Files:**
- Modify: `tests/behavior/log-receipt-behavior.php`
- Modify: `tests/behavior/search-input-behavior.php`
- Modify: `tests/js/front-receipt-race.js`
- Modify: `tests/js/front-state-controller.js`
- Modify: `tests/js/front-accessibility.js`
- Modify: `src/Services/YSSsSearchService.php`
- Modify: `src/Api/YSSsPublicController.php`
- Modify: `src/Security/YSSsLogReceipt.php` (documentation only)
- Modify: `assets/js/ys-ss-front.js`

**Interfaces:**
- Consumes: filter-final grouped results, existing aggregate display total, receipt v1 claim `t`, and front proof/recent behavior.
- Produces: additive `products_total`, product-count receipt authority, and fail-closed browser recent eligibility.

- [ ] **Step 1: Write product-positive RED cases**

Prove with real query/controller behavior that category/post-only results keep aggregate `total > 0` but expose `products_total=0` and sign receipt `total=0`; product plus category preserves aggregate total while signing only the product count. Prove a filter that removes product items revokes product positivity, a trusted filter adding a nonempty product group grants it, and a product group with only a forged positive `total` but empty `items` does not.

Update the blocked empty-result shape to include `products_total=0`. In production front-JS tests, prove `total>0/products_total=0` still retains a valid analytics receipt but cannot write browser recent history, while `products_total>0` keeps exact positive recent behavior. Missing/non-numeric `products_total` must fail closed to zero.

- [ ] **Step 2: Run focused RED**

```powershell
php tests/run.php behavior/log-receipt-behavior.php behavior/search-input-behavior.php js/front-receipt-race.js js/front-state-controller.js js/front-accessibility.js
```

The meaningful RED must demonstrate aggregate category/post counts currently grant product-positive receipt/recent authority. Fixture-shape updates alone are not RED evidence.

- [ ] **Step 3: Implement the split authority**

After `ys_ss_result_groups`, retain existing aggregate `total` and calculate `products_total` only from array groups whose exact type is `products` and whose `items` is a nonempty array. Each product group contributes `max(count(items), max(0, (int) total))`; sum groups and bound both totals independently in `YSSsPublicController`.

Issue the unchanged v1 receipt with `products_total` as claim `t`; do not add `pt`, change TTL, or change HMAC/visitor verification. The REST response retains aggregate `total` and adds `products_total`. Front proof uses finite nonnegative `products_total` with no fallback to aggregate `total`; zero-product proof may still log analytics, but only a positive product count enters recent history.

B-mode product-only logging is closed in Task 6 while `YSSsResultsPage` is already being edited for canonical pagination.

- [ ] **Step 4: Run focused GREEN and syntax checks**

```powershell
php tests/run.php behavior/log-receipt-behavior.php behavior/search-input-behavior.php js/front-receipt-race.js js/front-state-controller.js js/front-accessibility.js
php -l src/Services/YSSsSearchService.php
php -l src/Api/YSSsPublicController.php
php -l src/Security/YSSsLogReceipt.php
node --check assets/js/ys-ss-front.js
git diff --check
```

- [ ] **Step 5: Commit**

```text
fix: require product results for search memory
```

---

### Task 5: Fail-closed suggestion invalidation and honest keyword CRUD

**Files:**
- Create: `tests/behavior/keyword-mutation-behavior.php`
- Create: `tests/behavior/cron-invalidation-behavior.php`
- Create: `tests/js/admin-keyword-feedback.js`
- Create: `tests/support/admin-js-harness.js`
- Modify: `tests/bootstrap.php`
- Modify: `tests/behavior/suggestion-cache-behavior.php`
- Modify: `tests/behavior/admin-purge-mode-behavior.php`
- Modify: `tests/js/admin-delete-feedback.js`
- Modify: `tests/js/admin-purge-feedback.js`
- Modify: `src/Services/YSSsSuggestService.php`
- Modify: `src/Database/YSSsKeywordRepository.php`
- Modify: `src/Api/YSSsAdminController.php`
- Modify: `src/Cron/YSSsCronBridge.php`
- Modify: `assets/js/ys-ss-admin.js`

**Interfaces:**
- Consumes: WordPress options/transients, keyword repository boolean/id results, shared raw-first input, existing admin response item lists.
- Produces: three invalidation statuses, durable per-generation tombstone, pre-write generation recheck, raw-preserving keyword mutations, fixed errors, additive `cache_status`/`cache_warning`, and serialized admin feedback.

- [ ] **Step 1: Extend fakes only as required by real boundaries**

Add resettable option handlers and access logs to `YSSsWpFake` so tests can make `add_option()`, `update_option()`, and `delete_option()` fail or interleave while retaining current defaults and recording `autoload`. Add transient get/set/delete handlers and access logs. Extend the fake `$wpdb` insert/update/delete methods with resettable handlers, recorded arguments, and real `insert_id` reset semantics. Add only the REST-fake methods and boolean sanitizer required by the production controller (`has_param()`, `get_json_params()`, `rest_sanitize_boolean()`). Do not put test-only methods in production classes.

- [ ] **Step 2: Write failing cache tests**

Add behavior cases that prove:

```text
update_option false + tombstone persisted -> old transient is never read
late writer recreates old transient -> tombstoned reader still bypasses it
tombstoned reader heals to a new token -> tombstone removed and new cache works
generation and tombstone persistence both fail -> invalidate returns failed
builder sees changed generation before set_transient -> returns payload but writes no cache
successful rotation removes its old tombstone
tombstone option is created with autoload=false
```

- [ ] **Step 3: Write failing PHP keyword tests**

`keyword-mutation-behavior.php` must exercise real controller/repository code and prove exact create/update preservation for `C++ <vector> 入門`; blocked/non-scalar/empty/no-patch returns 400 with zero DB and cache work; insert/update/delete failure returns fixed 500 without `last_error` or SQL; successful mutations invalidate exactly once; complete invalidation failure returns a committed mutation with `cache_status=failed` and the fixed warning.

- [ ] **Step 4: Write failing admin JavaScript tests**

`admin-keyword-feedback.js` must drive add, edit, sort, toggle, and delete through the production script. For HTTP 500 and network rejection, assert authoritative values and controls are restored, the fixed local message appears, and fixture text `SECRET SQL` never appears. Prove duplicate events while one operation is pending do not send a duplicate mutation and an older completion cannot redraw after a newer queued operation.

Use one shared test-only admin harness for keyword, exact-delete, and purge UI behavior. Include the analytics-page “設為關鍵字” write in the same production mutation runner; it is not a separate authority.

- [ ] **Step 5: Run RED**

Run:

```powershell
php tests/run.php behavior/suggestion-cache-behavior.php behavior/keyword-mutation-behavior.php behavior/admin-purge-mode-behavior.php behavior/cron-invalidation-behavior.php js/admin-keyword-feedback.js js/admin-delete-feedback.js js/admin-purge-feedback.js
```

Expected: cache failure reads/writes stale data, technical keyword bytes are stripped, DB false paths report success, and admin controls do not recover.

- [ ] **Step 6: Implement fail-closed invalidation**

Add public constants and return type:

```php
public const INVALIDATION_ROTATED = 'rotated';
public const INVALIDATION_BYPASS_FRESH = 'bypass_fresh';
public const INVALIDATION_FAILED = 'failed';
public static function invalidate(): string;
```

Use tombstone option name `ys_ss_suggest_tombstone_` plus `hash('sha256', $generation)`. Persist it with autoload disabled and verify readback *before* attempting a random generation rotation. Re-read current generation after both attempts: a changed generation is `rotated` and permits old-marker cleanup; unchanged generation with a durable marker is `bypass_fresh`; unchanged generation without a marker is `failed`. Always delete the captured generation cache and legacy cache. A tombstoned reader attempts one rotation; if it cannot rotate, it computes fresh without transient read/write. Before `set_transient()`, require the captured generation still equals `generation()` and has no tombstone.

- [ ] **Step 7: Implement honest keyword mutations**

Add a private controller parser returning `string|WP_Error` from raw `wp_unslash()` plus `YSSsSearchInput::inspect()`. Use fixed 400 for blocked/empty/non-scalar/no-patch and fixed 500 `ys_ss_keyword_write_failed` for repository failure. Check `$wpdb->insert()` before using `insert_id`. Only successful repository work invalidates.

Return mutation payloads through one helper:

```php
private function mutation_response( array $payload ): \WP_REST_Response {
    $status = YSSsSuggestService::invalidate();
    $payload['cache_status'] = $status;
    if ( YSSsSuggestService::INVALIDATION_FAILED === $status ) {
        $payload['cache_warning'] = __( '資料已更新，但熱門建議快取可能延遲更新。', 'ys-cart-smart-search' );
    }
    return rest_ensure_response( $payload );
}
```

Apply the same additive cache status to settings save, exact delete, expired purge, and full purge after their database mutation succeeds. In `YSSsCronBridge`, accept `rotated`/`bypass_fresh`; on `failed`, throw into the existing guarded cron error path and do not claim a successful rebuild.

Because `YSSsSettings::update()` intentionally has no boolean mutation ABI, the controller must re-read `YSSsSettings::all()` and compare it with the expected normalized settings before invalidating. A matching readback also treats an idempotent WordPress `update_option() === false` as success; a mismatching readback returns one fixed 500 with no invalidation.

In `ys-ss-admin.js`, keep one authoritative `keywordItems` snapshot and serialize all keyword writes—including the analytics-page add action—through `runKeywordMutation(path, options, control)`. Disable the active control, accept only server `items`, restore the snapshot on failure, use only fixed local messages, and surface `cache_warning` after committed success. Settings save, exact delete, and both purge controls must also surface the additive warning without treating a committed mutation as failed.

- [ ] **Step 8: Run focused GREEN and syntax checks**

Run:

```powershell
php tests/run.php behavior/suggestion-cache-behavior.php behavior/keyword-mutation-behavior.php behavior/admin-purge-mode-behavior.php js/admin-keyword-feedback.js js/admin-delete-feedback.js js/admin-purge-feedback.js
php tests/run.php behavior/cron-invalidation-behavior.php
php -l tests/bootstrap.php
php -l src/Services/YSSsSuggestService.php
php -l src/Database/YSSsKeywordRepository.php
php -l src/Api/YSSsAdminController.php
php -l src/Cron/YSSsCronBridge.php
node --check assets/js/ys-ss-admin.js
git diff --check
```

- [ ] **Step 9: Commit**

Commit:

```text
fix: make keyword mutations and cache state authoritative
```

---

### Task 6: Canonical B-mode pagination

**Files:**
- Create: `tests/behavior/results-pagination-behavior.php`
- Modify: `tests/bootstrap.php`
- Modify: `src/Services/YSSsSearchService.php`
- Modify: `src/Frontend/YSSsResultsPage.php`

**Interfaces:**
- Consumes: requested page, settings/group order, exact product count, existing result shape and transient cache.
- Produces: resolved `page`, bounded `total_pages`, canonical cache key, and rows from resolved offset.

- [ ] **Step 1: Write failing pagination tests**

Using literal fake DB totals/rows, prove:

```text
total=25, per=24, request=99 -> page=2, total_pages=2, OFFSET 24, final item present
total=0, request=99 -> page=1, total_pages=1
total=48, request=2 -> page=2, OFFSET 24
request<=0 -> page=1
GET ys_ss_page=[] -> no warning and page=1
request=99 and canonical page=2 share canonical payload; no page99 transient
total=5000, request=101 -> page=100, total_pages=100, OFFSET 2376
products=0 plus category/post groups at request99 -> resolved page1 groups remain visible
products last in group_order -> resolution still precedes category/post page decisions
category/post-only page analytics -> row retained with results_total=0 and has_results=0
products>0 page analytics -> has_results=1
```

- [ ] **Step 2: Run RED**

Run `php tests/run.php behavior/results-pagination-behavior.php`.

Expected: current code produces empty deep pages, huge offsets, non-scalar warning risk, and wrong first-page group decisions.

- [ ] **Step 3: Implement count-first resolution and canonical caching**

Keep the public signature and response fields. Split private work into:

```php
private static function search_page_cache_key( string $norm, int $page, array $settings ): string;
/** @return array{total_count:int,page:int,total_pages:int} */
private static function products_page_meta( string $q, array $cfg, int $per_page, int $requested_page ): array;
private static function products_group_at_page( string $q, array $cfg, int $per_page, int $page, int $total_count ): array;
```

Bound request to `1..100`; check its cache; on miss obtain COUNT, calculate `total_pages=min(100,max(1,ceil(total/per_page)))` and resolved page, then check only the canonical key. Resolve before the `group_order` loop. Query rows with `(resolved_page - 1) * per_page`, store only the canonical result, and include `YS_SMART_SEARCH_VERSION` in the cache-key hash to prevent pre-v1.5.3 deep-page cache reuse.

In `YSSsResultsPage`, accept `ys_ss_page` only when scalar; otherwise use `1`. Keep the original-request page-1 analytics gate, but write only exact `products_total`; do not add category/post item counts. Extend the test-only `WP_Query` fake in `tests/bootstrap.php` with resettable posts and constructor-argument recording so products-last category/post behavior is executable without a production test seam. If the non-scalar case is already green on the local PHP runtime, record it as a defensive regression rather than RED evidence.

- [ ] **Step 4: Run focused GREEN and lint**

Run:

```powershell
php tests/run.php behavior/results-pagination-behavior.php
php -l tests/bootstrap.php
php -l src/Services/YSSsSearchService.php
php -l src/Frontend/YSSsResultsPage.php
```

- [ ] **Step 5: Commit**

Commit:

```text
fix: resolve smart search result pages canonically
```

---

### Task 7: v1.5.3 metadata and immutable local candidate

**Files:**
- Modify: `ys-cart-smart-search.php`
- Modify: `CHANGELOG.md`
- Modify: `tests/static/smart-search-contract.php`
- Verify: `.gitattributes`

**Interfaces:**
- Consumes: all completed task commits and tests.
- Produces: exact v1.5.3 header/constant/changelog parity and `G:\tmp\ys-cart-smart-search-1.5.3.zip` from a clean exact commit.

- [ ] **Step 1: Make metadata contract RED**

Change C28 into a version-parity behavior that extracts plugin header, `YS_SMART_SEARCH_VERSION`, and the first CHANGELOG heading, asserts all three equal, and sets the release floor to `1.5.3`. Keep C24/C25 historical v1.5.2 labels unchanged.

Run `php tests/run.php static/smart-search-contract.php`.

Expected: RED because production metadata is still 1.5.2.

- [ ] **Step 2: Update release metadata**

Set the plugin header and constant to `1.5.3`. Add the first CHANGELOG section:

```markdown
## [1.5.3] - 2026-08-26 — 搜尋互動與資料一致性
```

Document the eight shipped contracts (including the later product-positive-memory addendum) and explicitly state no schema or public REST URL change.

- [ ] **Step 3: Run metadata GREEN**

Run `php tests/run.php static/smart-search-contract.php` and `php -l ys-cart-smart-search.php`.

- [ ] **Step 4: Run the one complete local gate**

Run once on the integrated bytes:

```powershell
php tests/run.php
```

Then run all first-party non-vendor PHP lint, all first-party JavaScript `node --check`, the existing `php -n` Unicode/no-mbstring focused fixtures, `git diff --check`, tracked-test inventory, and credential-pattern scan. Any bug found invalidates this candidate: add a focused RED test, fix it, rerun only focused checks, then repeat this complete gate once on the replacement bytes.

- [ ] **Step 5: Commit and fresh-review the whole candidate**

Commit:

```text
release: prepare smart search v1.5.3
```

Require clean worktree. Obtain fresh independent API/UI and data-integrity reviews over `00703e2..HEAD`; no P0/P1 may remain. Reproduce every accepted P2 against exact bytes and ledger its impact.

- [ ] **Step 6: Build and verify exact ZIP**

If `G:\tmp\ys-cart-smart-search-1.5.3.zip` already exists, move it to a timestamped backup under `G:\tmp` after resolving both absolute paths. Build twice with:

```powershell
git archive --format=zip --prefix=ys-cart-smart-search/ -o G:\tmp\ys-cart-smart-search-1.5.3.zip HEAD
```

Require identical SHA256 on both builds, one root, exact source/file parity, version 1.5.3, clean-clone and extracted-ZIP autoload smoke, and zero `.git`, `.github`, `.superpowers`, `docs/superpowers`, `tests`, credentials, or forbidden development files.

---

### Task 8: dev-newecommerce acceptance and GitHub Release

**Files:**
- Update ignored ledger/memory notes only; do not change candidate bytes.

**Interfaces:**
- Consumes: exact reviewed v1.5.3 commit and ZIP hash.
- Produces: recoverable dev-site deployment, consolidated acceptance evidence, annotated tag `v1.5.3`, and a GitHub Release carrying the identical tested ZIP.

- [ ] **Step 1: Authenticate safely and revalidate target**

Inspect only filenames/metadata in the user-named SSH key folder, derive public fingerprints without displaying private bytes, select a matching AI/YSAI key, and connect only to the authorized `dev_newecommerce` account/host. Reconfirm WordPress root, active v1.5.2 path/version, site URL, and rollback location before mutation.

- [ ] **Step 2: Create rollback and deploy exact candidate**

Create a server-side v1.5.2 archive outside the plugin tree, record its hash, upload the exact local ZIP to an isolated staging directory, verify remote hash/ZIP/PHP lint/version, then swap directories without deletion. Confirm WP-CLI reports v1.5.3 active and no new debug error.

- [ ] **Step 3: Run one consolidated site matrix**

Verify real HTTP/browser/WordPress behavior for:

```text
query/suggest race and fixed error recovery
IME, Arrow/Enter/Escape, popup Tab trap and opener restoration
product-positive proof/recent, category/post-only no-memory, and zero-result popular fallback
C++ <vector> manual keyword exact create/update/suggest/delete
blocked keyword and unauthenticated admin no-write behavior
B-mode request past last page resolves to the last page
two valid SKUs admitted; SKU plus unrelated opaque token ignored by analytics
attack inputs remain neutral, unreflected, zero-search/zero-analytics where required
cache mutation produces current suggestions
home/query/suggest/B page 200; console errors/warnings 0
```

Use additive, uniquely prefixed fixtures. Restore exact original settings and delete only the fixtures; prove residue zero.

- [ ] **Step 4: Freeze acceptance evidence**

Reverify local/remote commit, ZIP hash, site version, worktree cleanliness, and public smoke. If any candidate byte changes, the old ZIP/hash/reviews/site evidence become stale and Task 7 restarts at focused RED.

- [ ] **Step 5: Publish normal GitHub Release**

Create and push annotated tag `v1.5.3` at the exact accepted commit. Create a non-draft, non-prerelease GitHub Release and upload the identical tested ZIP without rebuilding. Download the release asset separately and verify size, SHA256, root, version, and file parity.

- [ ] **Step 6: Stop at the release boundary**

Record final progress and memory. Do not sync Hub, deploy production/customer sites, use S3, or touch `dev-checkout`/`dev-yscart` GUI.
