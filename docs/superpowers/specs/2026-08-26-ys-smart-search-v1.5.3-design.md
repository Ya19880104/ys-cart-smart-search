# YS Smart Search v1.5.3 Design

## Outcome

Ship one bounded **Search Interaction and Data Integrity** release on the canonical `main` line. v1.5.3 must keep the v1.5.2 security and cleanup behavior intact while closing the confirmed UI-state, accessibility, admin-mutation, pagination, analytics-classification, cache-invalidation, and UTC-midnight receipt gaps.

The approved workflow is: recoverable branch at the released baseline, one coherent implementation batch, focused RED/GREEN tests while composing, one complete local candidate gate, `dev-newecommerce` runtime and GUI acceptance, then a GitHub Release using the identical tested ZIP.

## Frozen baseline and boundaries

- Baseline commit: `00703e212b6d805d281e06fadc8bdfb78a23d745` (`v1.5.2`).
- Recovery branch: `backup/ys-cart-smart-search-v1.5.2-pre-v1.5.3-20260826`.
- Work directly on `main`, as explicitly authorized by the user.
- `dev-newecommerce.wppro.cloud` is the only runtime and GUI acceptance site.
- Do not touch Hub, S3, customer or production sites, `dev-checkout`, or `dev-yscart` GUI.
- Credentials and private-key material remain session-only and never enter Git, plans, logs, commands, reports, or memory.

## Product contracts

### 1. One front-form interaction authority

Each rendered search form owns one controller state:

```text
generation: monotonically increasing integer
mode: idle | suggest | loading | results | error
proof: null | { input, query, receipt, total }
activeRequest: null | AbortController
activeIndex: integer for aria-activedescendant
settleTimer: null | timer
```

- Every input mutation increments `generation`, cancels the pending analytics timer, clears proof, aborts a superseded request when `AbortController` exists, and immediately removes stale clickable results.
- Suggest, query, zero-result fallback, error, and loading render only when their captured generation is still current and the input/mode still matches.
- HTTP non-2xx, invalid JSON, and network rejection render one fixed translated error. They never display a server detail, retain old results, create a proof, write recent terms, or trigger analytics.
- A first zero-result response obtains popular terms through the same guarded suggestion loader. A late fallback cannot render after a newer input.
- Existing positive-result proof, delayed log, recent-search, IME, A/list and B/page behavior remain unchanged.

### 2. Combobox and popup accessibility share the same controller

- Every input has a unique controlled panel ID plus `role=combobox`, `aria-autocomplete=list`, `aria-controls`, `aria-expanded`, and `aria-activedescendant` state.
- Every panel has `role=listbox`, `aria-live=polite`, and `aria-busy`; rendered results, chips, and view-all entries receive stable option IDs.
- ArrowDown and ArrowUp traverse all current entries without moving the DOM focus away from the input; Enter activates the active entry; Escape closes the panel and clears the active descendant.
- The popup remembers the exact opener, traps Tab within the visible dialog, closes on Escape/backdrop/close, and restores focus to the opener.
- Mouse, touch, and IME input remain usable.

### 3. Manual-keyword mutations preserve approved bytes and tell the truth

- Create and update inspect the REST-ready raw keyword with `YSSsSearchInput::inspect()`.
- Blocked or empty values return a fixed 400 response and perform no repository mutation or cache invalidation.
- Valid terms such as `C++ <vector> 入門` are stored as the approved `query` bytes.
- Repository insert, update, or delete failure returns a fixed 500 error with no SQL detail and no cache invalidation. `create()` must not reuse a stale `$wpdb->insert_id` after an insert failure.
- The admin client serializes keyword mutations through one runner, disables the active control, keeps the last authoritative item list, restores it on error, shows a fixed success/failure/cache-warning message, and cannot let an older response overwrite a later operation.

### 4. B-mode pages resolve against the actual last page

- Requested pages are bounded to `1..100`, then resolved to `1..total_pages` after the product count is known.
- An out-of-range page returns the actual last page's products and pager rather than a non-zero total with an empty-result message.
- Categories and posts use the resolved page, not the untrusted requested page.
- The result and cache key expose the resolved page. Existing page-1-only analytics remains page-1-only.
- No redirect is required; server-side clamping is the fixed contract.

### 5. Analytics identifier recognition is token-local

- Known parameters continue to reject analytics only and never block search execution.
- Use `PREG_OFFSET_CAPTURE` byte offsets for both high-confidence opaque tokens and recognizable identifier phrases.
- Exempt an opaque token only when its complete byte span is contained in an identifier phrase such as SKU, ISBN, EAN, UPC, MPN, 型號, or 料號.
- Count unique residual opaque token values after span exemption.
- Two legitimate long identifiers are admitted; a short or long SKU beside an unrelated opaque token does not exempt the unrelated token.
- Do not retrospectively delete or reclassify historical rows.

### 6. Suggestion invalidation fails closed

`YSSsSuggestService::invalidate()` returns one of these constants:

```php
YSSsSuggestService::INVALIDATION_ROTATED      // new generation persisted
YSSsSuggestService::INVALIDATION_BYPASS_FRESH // old generation durably tombstoned
YSSsSuggestService::INVALIDATION_FAILED       // neither authority write persisted
```

- Invalidation first creates and verifies an autoload-disabled option keyed by a SHA-256 digest of the old generation, then attempts a never-reused generation rotation. This closes the interval between a builder's final eligibility check and its actual transient write.
- If the re-read generation differs from the captured generation, rotation succeeded (whether by this caller or a concurrent caller): delete old/legacy cache and remove the old tombstone. If generation is unchanged but the tombstone is durable, the result is fail-closed bypass. If neither authority write persists, the result is failed.
- A reader encountering a tombstone attempts one new generation rotation. If healing succeeds it removes the old tombstone and resumes caching; otherwise it computes fresh suggestions without reading or writing cache.
- Before any `set_transient()`, re-read generation and tombstone state. A superseded or tombstoned builder may return its fresh payload to its own caller but cannot publish it.
- Mutation responses include `cache_status`; only `INVALIDATION_FAILED` adds a fixed `cache_warning`. A committed mutation is not falsely reported as uncommitted merely because cache invalidation failed.
- Cron accepts `rotated` or `bypass_fresh`; on `failed` it throws into the existing guarded cron error path so a failed cache authority change is not silently reported as a successful rebuild.

### 7. Receipt visitor identity follows signed issue time

- Add `YSSsRateLimiter::visitor_hash_at(int $timestamp): string`; `visitor_hash()` remains the current-time wrapper.
- The query endpoint captures one `$now`, derives the visitor hash with `visitor_hash_at($now)`, and passes both to receipt issuance.
- Existing `issue(..., string $visitor_hash, ?int $now = null)` and `verify(..., string $visitor_hash, ?int $now = null)` remain callable. A new request-only `verify_for_request(string $receipt, string $query, ?int $now = null)` first validates canonical token, HMAC, claims, TTL, query, and bounds, then derives the current request's expected visitor hash using the signed `iat`, compares it to signed `vh`, and returns signed `visitor_hash` with the trusted claims.
- The log endpoint uses `verify_for_request()` and writes its returned signed visitor hash, not a newly calculated current-day hash. A receipt replayed immediately before and after UTC midnight therefore reaches the same 600-second dedupe identity.
- Wire version stays `1`; v1.5.2 receipts issued before deployment remain verifiable until their original 120-second expiry.
- Different IP/UA, expired tokens, future tokens, malformed tokens, and tampering remain neutral no-write responses.

## Test strategy

- Extend the existing runner with explicit relative-file selectors; no arguments preserve the current complete suite. A missing or outside-suite selector exits non-zero.
- During implementation, run only the new/affected PHP behavior files, Node behavior files, and syntax checks. Every production change begins with a behavior test observed RED for the intended reason.
- At candidate freeze, run one complete `php tests/run.php`, all first-party PHP lint, all first-party JS syntax checks, `php -n` Unicode fixtures, and `git diff --check`.
- Obtain fresh whole-candidate API/UI and data-integrity reviews before packaging.
- Build the candidate from the exact clean commit with one plugin root; exclude `.git`, `.github`, `.superpowers`, `docs/superpowers`, and `tests`.
- On `dev-newecommerce`, run one consolidated matrix for query/suggest races, errors, IME, keyboard, popup focus, manual-keyword exact bytes/failures, B-mode deep page, analytics token cases, cache behavior, midnight fixture-equivalent runtime contracts, existing attack neutrality, positive-only recent terms, and console/network health.

## Explicit non-goals

- No new search feature, public REST URL, database table, schema migration, or broader attack heuristic.
- No heuristic bulk deletion restoration.
- No large-table full-clear background job, DDL/table swap, cron state machine, or changed atomic-clear semantics.
- No CI, branch protection, automatic publishing, Hub sync, S3, or production deployment in this version.
