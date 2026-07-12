# TIPIGANAN — Project Status & Session Log

> **STANDING INSTRUCTION — update this file automatically, every session,
> without being asked.** Any time a feature is added, a bug is fixed, a
> behavior changes, or a decision is made (built now / deferred / declined),
> update the relevant section here (§2 Repository Structure, §3 Changelog,
> §4 Known Issues, §6 Feature Catalog if it's a new/changed capability)
> before considering the task done — not just at the end of a session or
> when explicitly asked. The user should never have to remember to say
> "update the md file."

> Snapshot written before a context compaction. This is the durable record of
> everything done, why, and what's still outstanding. Treat this file as the
> source of truth over memory/chat history if they ever disagree. Updated
> multiple times across a very long session — this revision covers
> everything through the security-hardening round and the digital-repository
> follow-through (PDF file versioning, deduplicated usage stats) — see §3's
> last two entries.
>
> **The user will ask for a complete list of every system functionality, as
> a PDF, for their capstone documentation.** §6 (Complete Feature Catalog) is
> built specifically to answer that request — it's organized by capability
> area (not chronologically like §3) and describes the system as it stands
> today, not what changed and when. When the PDF request comes: read §6,
> supplement with a fresh look at routes/pages for anything not yet logged
> there, then produce it as a real PDF via a new Blade view + DomPDF
> (following the `guide:generate` precedent — see §5) rather than a
> markdown reply.

- **Backend**: `C:\Users\conch\Codes\CAPSTONE\tipiganan-backend` (Laravel 13, PHP 8.3, MySQL, Sanctum, Scout+Meilisearch)
- **Frontend**: `C:\Users\conch\Codes\CAPSTONE\tipiganan-frontend` (React + Vite, Axios)
- **Team guide / blueprint source docs**: `C:\Users\conch\Desktop\CAPSTONE\*.pdf`
- **Team**: Group 7 — Concha, Esto, Mendez, Miano
- **Both repos are on `dev`.** Everything through the mobile-responsiveness audit (§3, "UI/UX polish" and earlier) is committed and pushed to `origin/dev`. **Everything from "Post-mobile-audit round" onward (dark/light theme through PDF file versioning and usage-stats dedup) is currently uncommitted working-tree changes on both repos.** Not committed/pushed because the user hasn't asked for that yet; do so when they do, not proactively.

---

## 1. Local Environment — Current Real State (this machine)

| Service | Status | Notes |
|---|---|---|
| MySQL (Laragon) | Running | Must be started manually via the Laragon app — not a Windows service. If it's down, `php artisan serve` 500s on anything touching the DB (sessions table included). |
| Meilisearch | Running | `C:\Users\conch\meilisearch\meilisearch.exe`, port 7700, no master key. Started manually this session (`--http-addr 127.0.0.1:7700 --no-analytics`). Index was flushed + re-imported (`scout:flush` + `scout:import`) after being found stale (25 docs indexed vs 21 actually in DB). **Auto-start is unreliable** — a Startup-folder script only fires at Windows login, not on crash-recovery; it's gone down and needed manual restart multiple times this session. |
| Redis (Memurai) | **Running** | Installed via `winget install --id Memurai.MemuraiDeveloper` (elevated PowerShell, run by the user). Registered as a real Windows service (`Get-Service Memurai` → Running, StartType Automatic) — survives reboots on its own, unlike Meilisearch. Verified genuinely caching (found real keys in Redis's `cache` logical DB, not just a lucky in-process hit). |
| OPcache | **Disabled** | Confirmed via `php -m` / `opcache_get_status()` — not loaded. This is very likely the dominant cause of the ~500ms-per-request baseline latency seen on every endpoint (PHP re-parses the entire Laravel framework from source on every request without it). **User explicitly declined to enable this** — it requires editing `C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.ini`, which is outside the project repo and affects all PHP on the machine, not just this project. Not touched. If revisited: `zend_extension=opcache`, `opcache.enable=1`, `opcache.validate_timestamps=1` (so code edits still take effect without a server restart). |
| Backend (`php artisan serve`) | Runs on `127.0.0.1:8000` | Needs MySQL up first. |
| Frontend (`npm run dev`) | Runs on `localhost:5173` (or next free port) | CORS now accepts any `localhost`/`127.0.0.1` port (see §3), so it no longer matters which port Vite actually lands on. |

---

## 2. Repository Structure — Cumulative, This Session

### Backend (`tipiganan-backend`) — new/significantly-changed files

```
app/
  Console/Commands/
    GenerateTeamGuide.php      `php artisan guide:generate`
    PurgeExpiredThesisFiles.php NEW — `php artisan theses:purge-expired-files`
    BackupDatabase.php         NEW — `php artisan backup:database` (mysqldump, rotated; §3 Tier 2)
    VerifyThesisChecksums.php  NEW — `php artisan theses:verify-checksums` (fixity; §3 Tier 3)
  Http/Middleware/
    SecurityHeaders.php        NEW — nosniff/frame/referrer/permissions/HSTS on API responses (§3 Tier 2)
  Http/Controllers/Api/
    CategoryController.php     subcategories removed, cover image upload, SafeCache (plain-array caching)
    ThesisController.php       status visibility (restricted), safe Meilisearch sync, updateStatus(),
                                findRelatedTheses() (keyword/abstract similarity scoring), q search param,
                                view-count dedup in generateViewToken(); replaceFile()/listFileVersions()/
                                restoreFileVersion()/deleteFileVersion() NEW (see §3)
    ThesisReportController.php NEW — user "report this item" + staff review queue
    CitationController.php     custom citation creation removed, generate() reads persisted text
    UserController.php         role-hierarchy delete check, permissionsList(), profile() now
                                includes recent_searches (own audit_log search rows only);
                                uploadAvatar()/removeAvatar() NEW
    ReportController.php       SafeCache (plain-array), role+date filters, Most Active Users REMOVED,
                                Peak Hours → Users Online (distinct users/hour, not raw activity count)
    SearchController.php       sanctum guard fix, includeRestricted flag
  Models/
    Category.php                parent()/children() removed, cover_image_path fillable
    Thesis.php                  reports() relation, toSearchableArray() includes `status`;
                                 fileVersions() relation NEW
    ThesisFileVersion.php       NEW — superseded-PDF history (pending/restored/purged)
    User.php                    HasFactory added (was missing — broke the test suite); avatar_path fillable
    ThesisReport.php            NEW
  Services/SearchService.php    SafeCache-wrapped health check, timeout 2s → 0.5s, status-aware fallback;
                                 both search paths now withCount views_count (see §3)
  Support/
    SafeCache.php              NEW — Cache:: wrapper, circuit breaker, self-heals from corrupted/
                                 incompatible cached objects (__PHP_Incomplete_Class detection)
    ThesisFilePurger.php       NEW — sweeps/deletes expired ThesisFileVersion files, see §3
  Providers/AppServiceProvider.php  NEW — Password::defaults() policy (min 8, mixed case, number)
  Jobs/ProcessThesisOcr.php     safe Meilisearch sync (was crashing uploads); keywords now fill-only-
                                 if-empty (was always-merge) + cleanKeywords() cleanup, see §3

ocr/
  extract.cjs           strips pdf-parse page-marker noise before digital/scanned check; scanned path
                         shells out to rasterize-ocr.cjs
  rasterize-ocr.cjs      NEW — isolated child process: pdf-to-img rasterization + Tesseract OCR
                          (front/back page-capped)

database/
  migrations/  ..._remove_parent_id_from_categories_table.php
               ..._add_cover_image_path_to_categories_table.php
               ..._create_thesis_reports_table.php
               ..._add_avatar_path_to_users_table.php   nullable
               ..._create_thesis_file_versions_table.php   NEW
               ..._add_indexes_to_theses_table.php   NEW — (status, created_at) composite + year_published (§3, scale prep)
  seeders/     PermissionSeeder.php (reset_passwords now default staff perm; manage_users removed)
               CategorySeeder.php (parent_id removed)
               ThesisSeeder.php (wrapped in withoutSyncingToSearch — was crashing `migrate --seed`
               with no Meilisearch running, a real onboarding blocker since Meilisearch is optional)

resources/views/pdf/
  pdf_report.blade.php    NEW — was referenced but never existed (broken export, now fixed)
  pdf_audit.blade.php     NEW — same issue, same fix
  team_guide.blade.php    NEW — full team guide, rendered by guide:generate
resources/images/
  mdc-logo.png            NEW — MDC logo used by the redesigned per-page PDF watermark (§3)

config/
  cors.php                allowed_origins_patterns matches any localhost/127.0.0.1 port (was hardcoded
                           to :5173); exposed_headers now includes Retry-After (see §3, rate limiting)
  sanctum.php              expiration now env('SANCTUM_TOKEN_EXPIRATION', 10080) — was null (never expired)
  thesis.php               NEW — old_file_retention_days (env THESIS_OLD_FILE_RETENTION_DAYS, default 30)
  permission.php           Spatie's own permission cache decoupled from CACHE_STORE via a dedicated
                           PERMISSION_CACHE_STORE env var (default 'file') — it isn't wrapped by
                           SafeCache, so with CACHE_STORE=redis and no Redis running, every
                           permission-gated route 500'd. A hardcoded 'file' value alone would have
                           broken phpunit's CACHE_STORE=array test isolation, hence the env var.
phpunit.xml               PERMISSION_CACHE_STORE=array added alongside CACHE_STORE=array
tests/Feature/AuditLogExportTest.php   fixed (was crashing on User::factory(), then failing the
                                        role-gate check — both pre-existing bugs, unrelated to this session)
routes/api.php             most-active route removed, peak-hours renamed to users-online; whole file now
                           throttle:120,1, login/register additionally throttle:5,1; file-version routes NEW
.env                        CACHE_STORE=redis, REDIS_CLIENT=predis, REDIS_MAX_RETRIES=0, Meilisearch config,
                           SANCTUM_TOKEN_EXPIRATION, THESIS_OLD_FILE_RETENTION_DAYS
.env.example                 fixed stale REDIS_CLIENT=phpredis → predis, documented REDIS_MAX_RETRIES,
                           SANCTUM_TOKEN_EXPIRATION, THESIS_OLD_FILE_RETENTION_DAYS
README.md                    added "Optional: Meilisearch" / "Optional: Redis" setup sections for teammates
.gitignore                   added *.traineddata (Tesseract's auto-downloaded language model cache)
```

### Frontend (`tipiganan-frontend`) — new/significantly-changed files

```
src/
  App.jsx                    every page is React.lazy() + Suspense (500KB+ → 253KB main bundle)
  main.jsx                   wraps the app in ThemeProvider (light/dark/system, see §3)
  components/
    Toast.jsx                 NEW — ToastProvider/useToast, replaced all native alert()
    ConfirmModal.jsx           `primary` (blue) style + `icon` override prop added
    Layout.jsx                 REWRITTEN sidebar logic — see §3, mobile drawer + backdrop; topbar
                                 chip now shows a real avatar photo when one's set
    Watermark.jsx               redesigned: logo-based mark (3x2 grid) instead of dense 6x3 text grid;
                                 rendered once per PDF page now, not once for the whole viewer (§3)
  contexts/ThemeContext.jsx    NEW — light/dark/system + reduceMotion, see §3
  utils/
    timeAgo.js                 NEW — extracted from Layout.jsx so DashboardPage could reuse it
    avatar.js                  NEW — builds a full avatar image URL from the stored relative path
    boldQuoted.jsx              NEW — replaces the 3 dangerouslySetInnerHTML XSS sites, see §3
    rateLimit.js                NEW — getRetryAfterSeconds() + useCountdown() for 429 responses
  pages/
    ThesisEditPage.jsx          staff-only edit form + citation editing; new "File" panel
                                 (replace/restore/delete-now/version history), see §3
    ThesisUploadPage.jsx         live citation preview, redirects to edit page after create;
                                 abstract/keywords no longer required — blank means "auto-fill from OCR"
    LoginPage.jsx / RegisterPage.jsx   429 rate-limit countdown UI; password `pattern` hint (Register)
    ReportedItemsPage.jsx        NEW — staff review queue for flagged theses
    CategoryManagementPage.jsx    subcategory UI removed, cover image upload
    CollectionManagementPage.jsx  restrict/unrestrict/unarchive, permission-gated delete, search bar
                                   now actually sends `q` (was sending `author`, which the backend
                                   never read either — search box did nothing at all before this)
    UserManagementPage.jsx        permission grant modal, role-hierarchy delete gating; table rows
                                   now show a real avatar photo when one's set; create-staff/reset-
                                   password fields gained the password-complexity pattern hint
    ProfilePage.jsx                Security tab: Change Password (moved here, complexity hint added) +
                                   Roles/Permissions panels. Preferences tab: theme picker + Reduce
                                   Motion. Both were empty "coming soon" placeholders before, see §3.
                                   Personal Info tab gained the avatar upload widget (camera-icon
                                   overlay + remove link)
    BookmarksPage.jsx              real cover images; handles a bookmark whose thesis was deleted
                                   (shows "no longer available" instead of a blank/broken card, and
                                   auto-cleans the stale favorite once the user tries to open it);
                                   real reading-progress from localStorage instead of a fake
                                   deterministic placeholder
    ReportsPage.jsx                 real PDF export; role+date filter bar; Most Active Users panel
                                   removed; Peak Usage Hours → Users Online panel
    AuditLogsPage.jsx                real "Export PDF" with date range
    ThesisDetail.jsx                  citation style picker, real report submission, bookmark state
                                     fix; handles opening a since-deleted thesis (404 → clear message
                                     + auto-removes it from the user's bookmarks)
    DashboardPage.jsx                 Continue Reading now sources the user's own reading_history
                                     (was showing site-wide recent uploads — identical for every
                                     account); Recent Activity now merges real bookmarks/views/
                                     searches sorted by timestamp (was 2 hardcoded fake entries)
    SearchPage.jsx                    filter sidebar now uses the same .results-layout/
                                     .results-sidebar-panel classes BrowsePage uses (was hand-rolled
                                     inline styles that silently missed the existing responsive rule)
    PdfViewer.jsx                     thumbnail rail defaults closed on mobile; toolbar gets
                                     overflow-x:auto (15 buttons don't fit a phone screen)
  contexts/AuthContext.jsx            exposes `permissions` + `hasPermission()`; updateUser() NEW
                                     (avatar/name/email edits now reflect in the sidebar immediately)
  api/                                 admin.js (thesesApi.replaceFile/listFileVersions/
                                     restoreFileVersion/deleteFileVersion NEW), index.js
                                     (usersOnline replacing peakHours, recent_searches support,
                                     uploadAvatar/removeAvatar NEW), axios.js (apiOrigin export)
styles.css                            sidebar/mobile-drawer rewrite, .flex-between/.search-row/.tabs
                                     wrap or scroll instead of assuming desktop width, .auth-right
                                     mobile padding, .cr-progress width/mobile-hide fix; dark theme
                                     tokens + .reduce-motion + .user-avatar-img/.avatar-upload-btn
index.html                            inline pre-paint script applies saved theme/motion prefs
```

---

## 3. Complete Changelog (chronological by topic)

### Subcategories — removed entirely (user decision)
Nested categories never worked; removed `parent_id`. **Do not reintroduce.**

### Meilisearch / search — reliability fixes
- cURL error 7 on thesis create/update/delete/OCR-job: all now wrapped in `withoutSyncingToSearch()` + best-effort retry, so a down Meilisearch never fails the request.
- `$request->user()` returns null on routes without `auth:sanctum` middleware (default guard is `web`) — fixed via `$request->user('sanctum')` wherever optional-auth detection matters. Root cause of bookmark state not persisting and guest-vs-logged-in search logging not working.
- `Thesis::toSearchableArray()` was missing `status`, so the visibility filter matched nothing and Meilisearch-backed search always silently returned zero results (200 OK, not an error, so the MySQL fallback never triggered). Fixed + re-imported.
- **Without Meilisearch running, search still works** via MySQL `LIKE` fallback — same fields, same filters, just no typo-tolerance/relevance ranking. Confirmed this is genuinely true, not just theoretical.
- Health-check timeout lowered from 2s to 0.5s (`SearchService::$timeout`) — a healthy check returns in single-digit ms, so 2s was only ever spent in the failure case, and on Windows a refused `localhost` connection doesn't always fail fast (IPv6 resolution can stall before falling back to IPv4). Verified: simulated-down Meilisearch now costs ~0.6s instead of ~2.6s on the one request per 15s (`SafeCache`-cached verdict) that pays this cost at all.
- `ThesisSeeder` used to crash `migrate --seed` outright if Meilisearch wasn't running — a real onboarding blocker since Meilisearch is documented as optional. Fixed with the same `withoutSyncingToSearch()` pattern.
- Related Theses (bottom of a thesis detail page) used to be "same category, arbitrary DB order" — now scored by actual keyword/abstract overlap (`ThesisController::findRelatedTheses()`), with same-category kept as a small baseline score so it never regresses to showing nothing. Found and guarded against a handful of test-seed theses with corrupted `keywords` (raw OCR text dumps from before the OCR fix below) via a term-length cap.
- Collection Management's search bar sent `q`, but `ThesisController::index()` only ever read `status`/`category_id`/`year_published`/`author` — the search box did nothing at all, silently. Fixed to match `q` against title OR authors.

### Citations
- Removed arbitrary "custom" citation creation — one APA + one MLA per thesis, auto-generated, editable in place.
- `CitationController::generate()` was always recomputing the default text even over a staff edit — now returns the persisted (possibly edited) text.
- Style picker (APA/MLA) added to "Cite this"; fixed a dropdown-clipping bug (`position: fixed` anchored to the button's bounding rect instead of relying on a parent with `overflow: hidden`).

### Permissions & roles
- Narrowed the Grant Permission UI to the two permissions that are actually individually grantable (`delete_documents`, `delete_accounts`) — it used to also list role-level permissions that always showed unchecked.
- `reset_passwords` is now a default staff permission (not grant-only). `manage_users` removed (never gated anything).
- Delete routes were hardcoded to `role:super_admin` even though the UI implied staff-with-permission could use them — fixed to check the actual permission.
- Role hierarchy enforced: staff can only delete student/teacher accounts, never a peer or Super Admin — both server- and client-side.
- All permission-gated buttons now hidden client-side when the user lacks the permission (previously relied only on a 403 from the backend).
- Spatie `laravel-permission`'s own internal cache (`config/permission.php`) was pointed at Redis via the global `CACHE_STORE`, but isn't wrapped by `SafeCache` — with Redis down, **every permission-gated route 500'd** instead of degrading. Decoupled via a dedicated `PERMISSION_CACHE_STORE` env var (default `file`), which also had to avoid being hardcoded so `phpunit.xml`'s `CACHE_STORE=array` test isolation still works.

### Thesis status: `restricted` vs `archived`
`restricted` = visible to any logged-in user, hidden from guests entirely. `archived` = hidden from everyone. Implemented across index/show/generateViewToken/both search paths. Restrict / Remove Restriction / Unarchive actions added to Collection Management.

### Reporting flagged content (new feature)
`thesis_reports` table, `ThesisReportController` (submit/list/resolve), staff-only Reported Items page.

### Reports & Analytics
- SafeCache added to all endpoints — caches **plain arrays**, not raw Eloquent objects (see the cache-corruption bug below for why this matters).
- Role (student/teacher/both) + date-range filters added to the user-activity reports (Most Cited, Most Searched, and now Users Online). Collections by Department/Year and the dashboard totals are about the theses themselves, not user behavior, so they stay unfiltered. Filters flow through to both the live endpoints and PDF export (which previously validated `date_from`/`date_to` but silently never applied them — fixed as part of this).
- **"Most Active Users" removed entirely** (per user request — the "Hours Active" approximation wasn't providing enough value). Route, controller methods, PDF export type, and every frontend reference all removed.
- **"Peak Usage Hours" replaced with "Users Online"** (per user request): same 24-hour-bucket grouping, but now `COUNT(DISTINCT user_id)` (distinct people active in that hour) instead of `COUNT(*)` (raw activity volume). Guests excluded (no user_id to distinguish them by). **Important semantic note discussed with the user**: since the grouping is only `HOUR(created_at)` with no date filter applied, an *unfiltered* view isn't "how many people are online right now" — it's "across the entire audit log history, how many distinct people have ever been active during this hour of day," so a user who logs in at 7pm every single day only ever counts once toward that bucket, not once per day. Verified this empirically (38 raw rows at hour 22 across multiple days → correctly reported as 5 distinct users). A true "average users per day at this hour" would need a different two-pass calculation (group by day+hour first, then average across days, dividing by *all* days in range including zero-activity days) — discussed as **Option B** with the user, not built, since they chose to keep the current "ever seen" framing.
- **Critical bug found and fixed**: a `Category` model schema change earlier in the session (added `cover_image_path`, removed `parent()`/`children()`) left a stale cached `Eloquent\Collection` in Redis. When PHP tried to unserialize it against the new class shape, it silently returned a broken `__PHP_Incomplete_Class` object instead of throwing — `SafeCache`'s try/catch never caught it, so `/api/categories` started returning garbage and crashed the frontend with `categories.find is not a function` (reproduced on a teacher's dashboard). Fixed two ways: `SafeCache` now detects this specific corruption and recomputes instead of returning it, and every cached endpoint now stores plain arrays (`->toArray()`) instead of raw Eloquent objects, removing the underlying fragility rather than just detecting it after the fact.
- Both export buttons were wired to Blade views that never existed (`pdf.pdf_report`, `pdf.pdf_audit`) — every export attempt was silently broken. Built both.

### Dashboard — Continue Reading & Recent Activity had the same underlying problem
Both sections were showing fake/global data identical for every account, despite looking like real per-user data:
- **Continue Reading** called `thesesApi.list({ sort: 'recent' })` — the site-wide most-recently-uploaded theses, not anything about what the logged-in user had actually read. Fixed to use `/api/profile`'s `history` field (real `reading_history` rows, already existed for the separate Reading History page, just never wired into the dashboard), deduped to one card per thesis (most recent view), correctly empty for a fresh account.
- **Recent Activity** had 2 hardcoded fake "Viewed"/"Searched" entries shown to every account, mixed with real bookmarks that just said "Recently" instead of a real timestamp. Added a new `recent_searches` field to `/api/profile` (the user's own search audit-log entries only — safe for any role, unlike the staff-only full audit log), then rebuilt the whole feed from three real sources (bookmarks, reading history, searches) merged and sorted by actual timestamp. Extracted the relative-time formatter (`timeAgo`) out of `Layout.jsx` into a shared `utils/timeAgo.js` rather than duplicating it.
- Also removed the fake "Recently Read: 37" stat and fake per-card "68%/32%/15% read" progress bars (no real page-position tracking exists in the schema) — replaced with the real history count and a "Last opened <date>" label.

### Bookmarks — deleted-thesis handling
A favorited thesis that later gets deleted used to render a blank/broken card (no title, no cover, dead link) with no explanation. Now shows a clear "no longer available" placeholder; the stale favorite is auto-removed the moment the user actually tries to open it (`ThesisDetail`'s 404 handler calls `favoritesApi.remove`). Also replaced a fake deterministic "% read" placeholder (`id * 37 % 100`) with a real value read from `localStorage`, and fixed `removeOne()`/the Read link using `t.id` (undefined once `t` defaults to `{}`) instead of `f.thesis_id`.

### Watermark redesign
Changed from a dense 6x3 grid of "MDC · TIPIGANAN" text blocks to a 3x2 grid of the actual MDC logo + identity stamp underneath each — the logo alone reads as the brand without needing many repeats, while the traceable info (name/email, thesis ID, timestamp) stays intact per mark.

### UI/UX polish (earlier rounds)
Favorites tab shown for every signed-in role; real cover images (was gradient-only); real toast notifications replacing `alert()`; `ConfirmModal` gained a neutral `primary` (blue) variant; category cover image feature; notification bell → real Recent Activity dropdown; landing page guest links fixed to point at `/browse`/`/search` instead of `/login`; Login/Register got a "Back to Home" link.

### Performance — Redis, Meilisearch, lazy loading
- **Lazy loading**: every page is `React.lazy()` + `Suspense`. Confirmed genuinely separate chunk files (main bundle 253KB, e.g. `PdfViewer` 428KB isolated).
- **Redis**: now actually installed and running (Memurai, a real auto-starting Windows service — see §1). `SafeCache` makes it a pure bonus, never a hard requirement; verified genuine caching (not just a lucky same-request hit) by finding real keys in Redis's `cache` logical database.
- **Investigated but chose not to fix**: OPcache is disabled and is very likely the dominant source of remaining latency (every endpoint takes ~450-650ms regardless of caching, and a *cached* response was no faster than an uncached one — proof the bottleneck isn't the app layer). User explicitly declined this since it requires a system-wide, outside-the-repo PHP config change. See §1.
- **Team guide PDF generation** moved in-house: `php artisan guide:generate`, using the project's existing DomPDF. This is what surfaced the missing `pdf_report`/`pdf_audit` views bug.
- **Not done**: Brotli compression (parked, needs a production hosting decision first). Moving OCR/uploads to a real async queue (currently `QUEUE_CONNECTION=sync`, so a long scanned-PDF upload blocks the request) — flagged, not built, wasn't asked for.

### OCR on scanned/image-only PDFs — fixed (multiple root causes)
Priority fix since most collections being digitized are old scanned theses.
1. Tesseract.js can't read raw PDFs (`Error in pixReadStream: Pdf reading is not supported`) — fixed by rasterizing each needed page to PNG via `pdf-to-img` first.
2. `pdf-parse` and `pdf-to-img` bundle conflicting `pdfjs-dist` versions that can't coexist in one process (pdfjs-dist registers a global "fake worker" on first load) — fixed by moving rasterize+OCR into an isolated child process (`ocr/rasterize-ocr.cjs`).
3. `pdf-parse` inserts a `-- N of M --` marker per page regardless of real content, which alone could exceed the "has real text" 100-char threshold on a multi-page scan, silently misclassifying real scans as digital and skipping OCR with no error. **This was already affecting a real thesis in storage**, not just synthetic tests. Fixed by stripping these markers before measuring.
4. Performance guard: only the first 12 + last 8 pages are rasterized+OCR'd (title/abstract/keywords/intro live at the front, conclusion at the back) — old theses can run 60-150+ pages and OCR-ing all of them inline would take minutes. Verified on a 25-page synthetic scan: 20/25 pages processed in ~11s.
- **Still true**: OCR runs synchronously inline with the upload request (no async queue), so a long scanned thesis noticeably lengthens the upload. Not fixed — bigger architectural change than this pass, not requested.

### CORS / login bug
A leftover Vite dev server kept port 5173 occupied, bumping fresh `npm run dev` runs to 5174+; `config/cors.php` only allowlisted the literal string `:5173`, so the browser silently blocked the (actually successful) login response, and the frontend's generic "Invalid credentials" fallback masked what was really a network/CORS failure with no response at all. Fixed: CORS now matches any `localhost`/`127.0.0.1` port via pattern, and `LoginPage`/`RegisterPage` now distinguish "no response" (shows "Could not reach the server...") from a real rejected login.

### Mobile responsiveness — critical bug + full audit
User asked to confirm the whole site is flexible to any screen size and mobile-friendly. Found the sidebar navigation was **completely inaccessible on any screen ≤768px wide**: the CSS mobile breakpoint hides the sidebar by default and expects a `.sidebar.open` class to reveal it, but `Layout.jsx`'s toggle only ever applied `.collapsed` (the desktop-only class) — they never matched, so there was no way to open it. Made worse by `.topbar-left { display: none }` at the same breakpoint hiding the menu icon itself, not just the "Active Term" text next to it. Rewrote as one consistent `sidebarOpen` boolean (`.sidebar.open` always means visible, at every screen size), defaulting open on desktop / closed on mobile, with a real mobile drawer UX (backdrop closes it on tap-outside, picking a nav destination closes it automatically).

Then did a full pass over the rest of the app for the same class of bug:
- `SearchPage.jsx`'s results sidebar was hand-rolled with inline styles instead of the `.results-layout`/`.results-sidebar-panel` classes `BrowsePage` already uses — silently missing the existing hide-below-992px rule. Fixed to use the real classes.
- `PdfViewer`: 148px thumbnail rail now defaults closed on mobile; ~15-control toolbar gets `overflow-x: auto` as a safety net instead of silently clipping buttons off-screen.
- `.flex-between` and `.search-row` (both widely reused for label+button/input+button bars) now wrap instead of assuming desktop width.
- `.tabs` (7 tabs on Audit Logs) scrolls horizontally instead of overflowing uncontained.
- `.auth-right` padding shrinks on phone widths.
- Fixed a self-introduced bug from the same session: Continue Reading's new "Last opened <date>" label is longer than the "68% read" text it replaced, but was still in a 90px `nowrap` column — widened it, and hidden entirely below 768px where a 44px cover + a date column would leave no room for the title anyway.
- Confirmed clean (no fix needed): every data table, every stat grid, the modal system, and the thesis-detail two-column layout were already properly responsive.
- **Important caveat**: no browser automation tool is available in this environment. Everything above was verified by tracing the actual CSS/JS logic carefully and confirming a clean production build — not by looking at rendered screenshots at each breakpoint. Recommended the user do a manual visual pass (resize the browser, or use the device toolbar) to confirm, especially the mobile drawer behavior on a real phone-sized viewport.

### Post-mobile-audit round (dark/light theme, profile picture, view counts, OCR auto-fill)

**Dark/Light/System theme** (user request: "utilize the empty Preferences tab, add a light/dark theme setting"):
- `src/contexts/ThemeContext.jsx` (NEW, frontend) — `theme` state (`'light' | 'dark' | 'system'`), persisted to `localStorage['tipiganan_theme']`, applied as `data-theme` on `<html>`; also owns a `reduceMotion` toggle (`localStorage['tipiganan_reduce_motion']`) that adds a `.reduce-motion` class forcing near-zero transition/animation durations app-wide.
- `index.html` gained a tiny inline `<script>` in `<head>` that stamps `data-theme`/`.reduce-motion` synchronously before first paint, from the same localStorage keys — avoids a flash-of-wrong-theme on reload.
- `styles.css` — the whole design system already used CSS custom properties (`--bg-main`, `--bg-white`, `--border-light`, `--text-primary`, etc.), so a dark palette is defined once under `@media (prefers-color-scheme: dark)` (for "system") and again under `:root[data-theme="dark"]` (for an explicit choice) — component rules never changed, only the token values. Also swept and fixed every hardcoded `#fff`/`#FAFBFE` background across `styles.css` and inline JSX styles (Layout, SearchPage, ReportsPage, BookmarksPage, ReadingHistoryPage, ThesisDetail, LandingPage) to route through the tokens instead.
- **Two follow-up bugs found and fixed after initial ship** (both reported live by the user with screenshots):
  1. `.search-input-wrap input` (User/Collection/Category Management, Audit Logs search boxes) and Bookmarks' own inline `<style>` block (`pageStyles` in `BookmarksPage.jsx`, a separate CSS source my first sweep didn't reach) had no explicit `background`/`color` at all, so they fell back to the browser's native white input styling regardless of theme. Fixed both.
  2. The "Cite this" (ThesisDetail) and report-export (ReportsPage) dropdown menu items had no explicit `color`, so their text stayed browser-default black on the now-dark dropdown background. Fixed both; grepped for the same button-style pattern elsewhere and confirmed no other instances.
- **Left deliberately unthemed**: the public Landing Page (`LandingPage.jsx`) — its own fully self-contained inline stylesheet with no design-system tokens at all. Treated as an intentional choice (marketing pages commonly stay on-brand rather than following the reader's theme) and flagged to the user rather than silently reworking ~60 hardcoded colors; not changed unless asked.

**Profile page — Security & Preferences tabs were empty placeholders, now real** (user request):
- **Security tab**: moved the (already-working) Change Password form here from Personal Info; added read-only Roles and Permissions panels sourced from `/api/profile`'s existing `roles`/`permissions` fields (was already fetched, just never displayed).
- **Preferences tab**: the theme picker above, plus the Reduce Motion toggle.
- Fixed a layout bug the user caught: `panel-grid-2` is a CSS grid with default `align-items: stretch`, so the single-panel Change Password column was stretching to match the taller two-panel Roles+Permissions column, leaving dead space below the button. Fixed with `alignItems: 'start'` scoped to just these two ProfilePage grid usages (not the shared class other pages use).

**Profile picture upload** (user request):
- `users.avatar_path` (new nullable migration), `POST /profile/avatar` (2MB max, replaces + deletes old file) and `DELETE /profile/avatar` (removes, reverts to initials) in `UserController`; old file also cleaned up on account deletion. Mirrors the existing category-cover-image upload pattern (`public` disk, `storage:link`).
- `src/utils/avatar.js` (NEW) — builds the full image URL from the relative stored path via `apiOrigin`.
- Photo now renders everywhere an avatar shows: Profile page (with a camera-icon overlay + "Remove photo"), the sidebar/topbar user chip, and the admin User Management table — falling back to the initials circle when there's no photo.
- `AuthContext` gained an `updateUser()` setter (was flagged as missing in an earlier session's code comment) so avatar/name/email edits reflect in the sidebar immediately instead of only after next login.
- **Bug found and fixed same-session**: the shared `.user-avatar-img` CSS class forced `width:100%; height:100%`, which — for the topbar chip's `<img>` specifically, since it had no explicit pixel size — has no resolvable percentage base inside an auto-sized flex container, so the browser fell back to the photo's raw native resolution instead of cropping to a circle. A user's uploaded (large) photo blew up to fill most of the screen. Fixed by dropping the percentage sizing from the shared class entirely; sizing now always comes from `.user-avatar`'s own 32px default or an explicit inline override at each call site.

**PDF watermark only showing on page 1** (user-reported bug):
- Root cause: `<Watermark>` was rendered once as a `position:absolute; inset:0` overlay on the whole scrollable canvas container, but that container's own box is only as tall as the viewport (not the full stacked height of every page) — so the overlay only ever covered whatever was scrolled to the very top initially (page 1) and scrolled away with it.
- Fixed by rendering one `<Watermark>` per page, inside each page's own wrapper div (which is now `position: relative`) — every page gets its own overlay that scrolls with it, which is also the *correct* security behavior (a screenshot of any single page shows the watermark, not just the first).

**"Views" count always showing 0 on Browse/Search** (user-reported bug):
- `views_count` was never a real column and was never computed by `ThesisController::index()` or `SearchService`'s two search paths — only the thesis-detail endpoint computed a live count, under a *different* key (`view_count`, singular). The frontend's `t.views_count || 0` fallback silently masked the `undefined`.
- Fixed by adding `->withCount(['readingHistory as views_count'])` to `index()` and `searchWithDatabase()`, and via Scout's `->query()` hydration-customization hook to `searchWithMeilisearch()` (Scout builders aren't plain Eloquent builders, so `withCount` can't be chained directly — `query()` is the documented way to modify the Eloquent query used to hydrate full models from the IDs Meilisearch returns). All three now count real `reading_history` rows, verified live against the running server.

**OCR abstract/keyword auto-fill — tightened, not removed** (user question then explicit request):
- Confirmed (by reading `ocr/extract.cjs` + `ProcessThesisOcr.php`) that OCR-based abstract/keyword extraction was still fully active, not a leftover from early testing — and that it was the exact mechanism that produced the long/messy keywords on earlier test uploads, because keywords were **unconditionally merged** with OCR output on *every* upload, even over manually-typed staff keywords.
- User asked to keep the feature but only fill fields the staff leaves blank, and keep keywords reasonably short. Changes:
  - `ThesisController::store()`: `abstract` validation relaxed from `required` to `nullable` (stored as `''` rather than `null` — the DB column itself stays `NOT NULL`, no migration needed, since `?:` already treats `''` the same as missing).
  - `ProcessThesisOcr::handle()`: keywords now follow the exact same "fill only if currently empty" rule abstract already used, instead of always merging.
  - New `cleanKeywords()` step: splits on real delimiters (comma/semicolon/newline), drops any "term" over 40 chars, caps at 8 terms, and — if fewer than 2 real terms survive — returns nothing at all rather than writing a mis-captured paragraph fragment into the field. (A single long blob with no delimiters is the exact shape of a failed heading-match; treating that as "extraction failed" beats writing it in.)
  - Frontend (`ThesisUploadPage.jsx`): abstract textarea no longer `required`; both fields' placeholder/help text now explain the blank-to-autofill behavior.
- **Verified end-to-end twice**, not just read in the code: (1) uploaded a real generated PDF with a proper digital text layer and Abstract/Keywords sections, blank fields, confirmed clean auto-fill; (2) built a synthetic **image-only PDF** (text rendered into a PNG, embedded with no text layer at all — structurally identical to a real scanned/compiled thesis) specifically to answer the user's question about whether scanned theses are supported, confirmed `extract.cjs` correctly detects it as `"method":"ocr"` (not `"digital"`) and Tesseract-recovers the abstract/keywords correctly (one minor single-word OCR misread observed, expected/normal for image-text recognition, not a pipeline bug).
- **Still true, not changed**: only the front 12 + back 8 pages of a scanned PDF are OCR'd (pre-existing performance guard) — fine since abstract/keywords are always front-matter; OCR still runs synchronously inline with upload (`QUEUE_CONNECTION=sync`), so a large scanned thesis still lengthens the upload request.

### Deploy-readiness Tier 1 — in-repo hardening (see §7 for the full checklist)
Ran a deploy-readiness diagnostic; Tier 1 (must-fix-before-live) split into in-repo prep (done now) and server-side deploy steps (documented in §7). The in-repo pieces:
- **CORS is now env-driven** (`config/cors.php`): production locks to `FRONTEND_URL` (comma-separated origins) with **no** localhost pattern; local dev keeps the any-localhost-port pattern (gated on `APP_ENV === 'local'`). Verified: local → origins `[]` + localhost pattern; simulated production → only `FRONTEND_URL`, pattern empty. Previously hardcoded to localhost:5173 + a permissive localhost pattern always on.
- **HTTPS + proxy** for production: `AppServiceProvider::boot()` calls `URL::forceScheme('https')` guarded to `environment('production')` (keeps signed PDF-viewer URLs/redirects correct behind a TLS-terminating proxy); `bootstrap/app.php` adds `$middleware->trustProxies(at: '*')` so the app sees the real client IP + original https scheme behind Nginx. Both inert in local dev.
- **`.env.production.example`** (backend, NEW, committed as a guide) — production-safe defaults (`APP_ENV=production`, `APP_DEBUG=false`, `LOG_LEVEL=warning`, `SESSION_SECURE_COOKIE=true`, Meilisearch bound to 127.0.0.1) with clearly-marked `CHANGE_ME_*` secret placeholders (DB user/password, Redis password, Meilisearch master key, `FRONTEND_URL`, `APP_URL`).
- **`.env.production.example`** (frontend, NEW) — `VITE_API_BASE_URL` template (copy to `.env.production`, set real API URL, `npm run build`).
- App boots, CORS verified both modes, suite green (4/4). Server-side Tier 1 (real credentials, Meilisearch key generation, TLS cert, PHP/Nginx upload limits, frontend build) left as the §7 checklist since they need the actual host.

### Deploy-readiness Tier 3 — timezone, fixity, automated tests, load-test findings
- **Timezone → Asia/Manila.** `config/app.php` now reads `env('APP_TIMEZONE', 'UTC')`; dev `.env` and the prod template set `Asia/Manila` so reports ("Users Online by hour"), audit timestamps, and activity feeds render in PH time. Verified `now()` returns +8. **Caveat documented**: set before go-live on a fresh DB — flipping on populated data leaves old (UTC) rows 8h off.
- **Fixity / checksums (was parked #12, user opted in).** `theses.checksum` (SHA-256, nullable migration); computed on `store()`, `replaceFile()`, and `restoreFileVersion()` (so the active file's hash always matches whatever file is live). New `php artisan theses:verify-checksums` backfills missing hashes and recomputes each file to detect corruption/tampering (logs `Log::warning` on mismatch/missing, non-zero exit for monitors); scheduled weekly. **Verified end-to-end**: backfilled 22 existing theses, detected a deliberately-corrupted hash as MISMATCH, flagged a file-missing thesis as MISSING.
- **Automated tests — 4 → 20 passing (42 assertions).** Added `SCOUT_DRIVER=collection` to `phpunit.xml` (keeps tests off Meilisearch). New feature tests: `AuthTest` (password policy, login token, wrong-password, login rate-limit 429), `RoleAccessTest` (guest 401, student 403, staff-with-permission 200, role-hierarchy delete gating), `ThesisVisibilityTest` (guest sees only active; logged-in sees active+restricted not archived; restricted detail 404 for guest / 200 for user), `ThesisFileVersionTest` (replace archives a version + updates checksum + preserves non-blank metadata; restore swaps file+checksum back — OCR mocked, `Storage::fake`). Gotchas fixed while writing: auth routes are `/api/auth/*` not `/api/*`; `UploadedFile::fake()->create()` yields empty files (both hashed to the empty-file SHA) so switched to `createWithContent` with a `%PDF` header to get distinct content that still passes `mimes:pdf`.
- **Load-test of the PDF watermark-serve path** (the one uncacheable per-view bottleneck). Measured in-process: `WatermarkService::stamp()` ≈ **34ms/view**, ~roughly flat across 0.6–1.3MB PDFs, ~50MB process peak. HTTP concurrency test (4-worker `artisan serve`, **OPcache OFF** — a deliberately pessimistic floor): warm single-request latency ~110ms; sustained ~**7–9 req/s** with latency climbing to ~1s at concurrency 8 (throughput plateaus because OPcache-off makes every request re-parse the framework → CPU-bound). **Interpretation for the user**: (1) this is a conservative floor — production FPM+OPcache erases the framework re-parse, leaving the ~34ms stamp + IO, so a 2-core box should do several-fold more; (2) "concurrent readers" ≠ "req/s" — a reader fetches once then reads for minutes, so even ~8 serves/s ≈ ~480 opens/min supports hundreds of simultaneous readers; (3) real limits to watch are RAM (≈50–80MB/worker caps worker count) and CPU (caps stamp throughput); (4) these were smallish PDFs — re-test with a large multi-hundred-page scanned thesis before any heavy-usage event, since stamp cost scales with page count.

### Deploy-readiness Tier 2 — in-repo pieces (security headers, scheduler, backups)
The three Tier 2 items with an in-repo component (all free), done now; their server-side halves stay in the §7 checklist.
- **Security headers** (`app/Http/Middleware/SecurityHeaders.php`, NEW; appended globally in `bootstrap/app.php`) — adds `X-Content-Type-Options: nosniff`, `X-Frame-Options: SAMEORIGIN`, `Referrer-Policy: strict-origin-when-cross-origin`, `Permissions-Policy` (camera/mic/geo off), and `Strict-Transport-Security` **only when the request is https** (inert on http dev). Verified live on `/api/theses`. Note: the most impactful place for these is the frontend HTML served by Nginx — this middleware covers the API; the Nginx header block stays a deploy step (§7).
- **Task scheduler** (`routes/console.php`) — registered `theses:purge-expired-files` (daily) and `backup:database` (daily 02:00) via the `Schedule` facade. Verified with `schedule:list`. Still needs the one OS cron entry (`php artisan schedule:run` every minute) at deploy — until then these don't fire on their own (purge still has its opportunistic fallback; backups do not, so wiring the cron is part of going live).
- **Database backups** (`app/Console/Commands/BackupDatabase.php`, NEW — `php artisan backup:database`) — `mysqldump` to `storage/app/backups`, timestamped, rotated (`--keep`, default 7). Credentials passed via a temp `--defaults-extra-file` (never on the command line). Chose a self-contained command over `spatie/laravel-backup` (no dependency, guaranteed version-compatible, reversible). Binary path configurable via `config/database.php` → `dump.binary` (env `DB_DUMP_BINARY`, default `mysqldump`) for machines where it isn't on PATH. **Verified end-to-end** against the real dev DB (112 KB valid dump) after fixing a path bug the test caught (had mixed the `Storage` disk root — `storage/app/private` in Laravel 11+ — with a raw `storage/app/backups` path; now creates the dir at the exact write path). **Reminder captured**: RAID/NAS is not a backup; the destination should be moved OFF the primary server at deploy (§7).

### Production-scale prep — DB indexes (user asked ahead of deploy: "thousands of theses expected")
- Added a migration indexing the `theses` table: a composite `(status, created_at)` (serves the default Browse/Collection Management query — filter by status, newest-first sort — in one index instead of a full scan + filesort) and `year_published` (year filter/facet). `category_id`/`uploaded_by` were already indexed via their foreign keys; `title`/`authors` are only queried with leading-wildcard `LIKE`, which no B-tree index can serve (that's Meilisearch's job). Verified both indexes present (`SHOW INDEX`) and the suite still green on SQLite.
- **Honest framing given to the user**: at a few thousand rows this is good-practice/future-proofing, not a launch blocker (MySQL scans a few thousand rows in ms) — it earns its keep at tens of thousands and under concurrent browse load. Storage-at-rest of that volume is comfortable; the real scale levers are ingestion throughput (async OCR — see #6) and keeping Meilisearch up (the MySQL `LIKE` fallback full-scans and doesn't scale as primary).
- **Storage: Synology NAS is the planned file store** (per the team's Chapter III design doc) — but the code currently writes every file to Laravel's `local` disk, not the NAS. See Known Issues #16 for the wiring gap the user will revisit with help later.

### Security hardening round
User asked "what am I missing / does this meet the requirements of a proper system", prompting a full audit (rate limiting, CSRF, auth token lifetime, password policy, 2FA, HTTPS enforcement, SQL injection surface, XSS, test coverage, CI/CD, accessibility, backups, error monitoring, logging) — findings discussed, then four were picked to fix now:
- **Rate limiting**: the whole API previously had zero throttling — brute-forcing `/auth/login` was completely unmitigated. `routes/api.php` now wraps every route in `throttle:120,1` (general abuse/scraping ceiling) with a much stricter `throttle:5,1` nested specifically on `/auth/login` and `/auth/register`. Verified live: a burst of requests correctly returns `429` once the limit is hit.
- **Auth tokens never expired**: `config/sanctum.php`'s `expiration` was `null` — a stolen or leftover token stayed valid forever. Now `env('SANCTUM_TOKEN_EXPIRATION', 10080)` (7 days). No self-service reset or silent token-refresh exists, so an expired token just bounces to `/login`, same as manual logout.
- **429 UX**: since login/register now actually throttle, added a live countdown ("Too many attempts, try again in Ns") to both pages (`src/utils/rateLimit.js` — `getRetryAfterSeconds()` + `useCountdown()` hook), reading Laravel's `Retry-After` header. Had to also add `'Retry-After'` to `config/cors.php`'s `exposed_headers` — CORS hides all but a small standard set of response headers from JS by default, so without this the frontend could see *that* it was throttled but not *how long* to wait. Verified the whole chain live (429 + `Retry-After: 51` + `Access-Control-Expose-Headers: Retry-After` all present together).
- **Stored XSS**: three `dangerouslySetInnerHTML` call sites (`Layout.jsx`'s activity dropdown, `DashboardPage.jsx`, `ProfilePage.jsx`) rendered a regex-bolded version of thesis titles / account names with no HTML escaping — either field containing a script payload would execute for anyone viewing that activity feed. Neither field is sanitized server-side (nor should title/name arbitrarily be over-restricted). Fixed by replacing the raw-HTML string-replace with a shared `src/utils/boldQuoted.jsx` that returns real React nodes (auto-escaped) instead of an HTML string — same bolded-quote visual, no injection surface. Confirmed no other `dangerouslySetInnerHTML` usage exists anywhere in the frontend.
- **Password complexity**: min 8 chars was the only rule anywhere (register, change-password, admin-create-staff, admin-reset-password). Added `Illuminate\Validation\Rules\Password` via `Password::defaults()` (one policy defined once in `AppServiceProvider::boot()`, referenced everywhere as `Password::default()`) — now also requires mixed case + a number. No `uncompromised()` (Have I Been Pwned) check — that's a live external HTTP call per password set, judged not worth the dependency/failure mode here. All 4 frontend password fields (Register, Profile Security tab, admin create-staff, admin reset-password) got a matching `pattern` attribute + hint text for instant client-side feedback, not just a server round-trip. Verified live: a weak password is rejected with a specific per-rule message, a strong one succeeds.
- **Explicitly declined/deferred by the user, not overlooked**: Terms of Service / Privacy Policy page (register form's checkbox links to `href="#"` — placeholder content still pending from the client, left as-is); self-service "forgot password" flow (deliberate decision — password resets stay staff-assisted only, no self-service reset is wanted).

### Digital repository audit & follow-through
Separate follow-up audit specifically against digital/institutional-repository domain standards (Dublin Core completeness, persistent identifiers, OAI-PMH, fixity/checksums, access embargoes, version history, usage-statistics accuracy) — **explicitly scoped to this being a single-school, closed-access repository** (only this school's own students/teachers/staff, all collections originate from this school, no external/public access at all). That scope materially changes which standard IR concerns are actually relevant:
- **Persistent identifiers (DOI/Handle)** — judged not relevant, not built. DOIs solve external cross-institutional citation resolution; with no outside access to the system at all, there's no external citation network to protect. The existing stable `/theses/{id}` route (IDs never recycled, soft-deletes preserve that) already covers the only part that matters at this scope — an internal permalink.
- **Dublin Core completeness** (missing `language`/`format`/`publisher`/`rights` fields) — judged not worth building *for DC-compliance's own sake* (nobody external is harvesting/reading this metadata programmatically), except **`rights`/`license`** survives as a real, standalone-justified feature (distinct from visibility `status`: whether a reader may quote/download a given thesis, independent of who can see it — e.g. an industry-sponsored thesis under NDA) — discussed, not built yet, may come back to it.
- **OAI-PMH / sitemap / SEO discoverability** — judged not relevant, explicitly dropped (not deferred — actually contrary to the system's design goal of staying internal-only; even if indexed, an outside visitor would just hit the access wall).
- **Fixity/checksums** (SHA-256 hash per file at upload, to detect silent corruption/tampering over time with no external mirror to fall back on) — the one item judged to still matter *because of*, not despite, the closed single-copy scope. **User asked to hold off on this one specifically ("may revisit after")** — not declined, just not now.
- **Time-based embargo** (auto-unlock a restricted thesis on a future date) — user decided to skip entirely, judged not necessary for this system.
- **PDF re-upload/replace with version history** — user approved, **built** (below).
- **Deduplicated ("real") usage statistics** — user approved, **built** (below).

**PDF file replace/restore/versioning** (new `thesis_file_versions` table + `ThesisFileVersion` model):
- `ThesisController::replaceFile()` (`POST /theses/{id}/file`) — staff can swap a thesis's PDF. The old file isn't deleted immediately: it's archived as a `ThesisFileVersion` row (`status: pending`, `purge_after` = now + `config('thesis.old_file_retention_days')`, default 30, env-configurable), logged to the audit trail (`replace_thesis_file`).
- **OCR re-run on replace**: if abstract/keywords are still blank after the swap, `ProcessThesisOcr` is re-dispatched against the *new* file — reuses the existing fill-only-if-empty logic from the earlier OCR round unchanged, so already-filled fields are never touched by a replace. Verified live with two different generated PDFs: replacing over already-filled fields left them untouched; replacing a thesis whose fields were genuinely still empty correctly pulled fresh abstract/keywords from the new file.
- Citations were *not* given similar "rerun" treatment — they're derived purely from `title`/`authors`/`year_published` (required fields, never touched by a file swap), not from OCR/file content at all, so there was nothing to rerun; clarified this to the user rather than building a no-op.
- **Restore** (`POST /theses/{id}/file-versions/{versionId}/restore`) — promotes a pending version back to active. Never loses data even across repeated restores: the file that's active *at the moment of restoring* gets archived into its own new pending version first, so undo-of-an-undo always still works. Verified live across a replace → restore → (both versions still present, correctly tagged `pending`/`restored`) chain.
- **Delete Now** (`DELETE /theses/{id}/file-versions/{versionId}`) — staff can purge a version early instead of waiting out the grace period.
- **Auto-purge past the grace period**: no reliable OS-level cron exists in this dev environment (empty `routes/console.php`, Task Scheduler creation blocked in this shell — see Known Issues), so rather than depend on one, built `App\Support\ThesisFilePurger::sweepIfDue()` — runs the actual delete query at most once/hour (via `SafeCache`, so a Redis outage degrades to "runs every time" rather than breaking), triggered opportunistically from the staff file-management endpoints themselves. **Also** ships a real `php artisan theses:purge-expired-files` command calling the same sweep unconditionally, for whenever this runs somewhere with actual cron — explained this tradeoff to the user rather than silently picking one path. Verified both paths live: backdated a version's `purge_after` via tinker, confirmed the opportunistic sweep deleted the file and flipped its status; ran the artisan command directly too.
- Watermarking needed no changes for any of this — confirmed it's applied dynamically in `servePdf()` on every request, never baked into the stored file, so swapping the underlying file doesn't touch it.
- Frontend: new "File" panel on `ThesisEditPage.jsx` — Replace File button (with a confirm modal), a Previous Versions table (replaced-by/when/days-left-to-restore), Restore and Delete Now actions per row.
- **Version identifiability follow-up** (user couldn't tell which archived file was which when restoring — a hashed storage name is meaningless, and the table never showed the *currently active* file as a reference): added (1) a "Current file" reference row (with size) at the top of the panel, (2) a **Size** column on every version row (two different PDFs almost always differ in bytes — an at-a-glance distinguisher), and (3) a **Preview** button on the current file and every version that still has its file, opening the actual PDF in a new tab so staff can visually confirm contents before/after restoring. Backend: `listFileVersions()` now returns `{ current: { size }, versions: [...] }` with a per-version `size` (null once purged); new staff-only `previewFile()` (`GET /theses/{id}/file-preview`) and `previewFileVersion()` (`GET /theses/{id}/file-versions/{versionId}/preview`) stream the raw PDF inline (un-watermarked, same as staff `download()` — internal verification, not reader access) via a shared `streamPdfInline()` helper. Frontend fetches the file as an auth'd blob and opens it via a pre-opened tab (popup-blocker-safe). Verified live end-to-end: each preview serves the exact bytes of its specific version (286KB vs 636KB test files distinguished correctly), 404 on a bad version id, 401 unauthenticated.

**Stale abstract/keywords after replacing with a file that has none — human-reviewed refresh** (user-reported: replacing the PDF with one that has no abstract/undetectable keywords left the old file's abstract/keywords on the record, silently describing a file that's no longer there). Root cause is by design: the fill-only-if-empty rule deliberately never overwrites existing metadata (to protect staff-typed values), and the system can't tell staff-typed from OCR-auto-filled, so blind overwrite was rejected. Chosen fix (user picked it over provenance-tracking): **warn + let staff review, never auto-overwrite.**
- `replaceFile()` now runs OCR on the new file once, up front: still auto-fills only *blank* fields (unchanged convenience), and returns the detected `{ abstract, keywords, method }` in the response (`{ thesis, detected }`) so the edit page can compare it against what's on record. Removed the old conditional `ProcessThesisOcr::dispatch` (this inline pass replaces it; timing unchanged since `QUEUE_CONNECTION=sync` ran the job inline anyway).
- New `extractMetadata()` (`POST /theses/{id}/extract-metadata`, staff-only) — re-runs OCR on the thesis's *current* file and returns what it detects **without saving**. Powers a manual "Re-extract from current file" button on the edit page (light Option 3).
- `cleanKeywords()` moved from `ProcessThesisOcr` (private) to `OcrService` (public) so the job and both new controller paths share one implementation.
- Frontend (`ThesisEditPage.jsx`): after a replace whose detected content differs from / is missing against the record, a review panel opens (also on-demand via the button) showing each field's state — **Not in current file** (stale), **Differs from record**, **Matches**, or **Nothing detected** — with per-field **Use detected** / **Clear stale value** actions. Nothing is applied until staff click; applying persists just that field via the normal update endpoint. Verified live against thesis #33 (current file genuinely has no detectable abstract while the record has one → correctly flagged stale).
- **Latent bug fixed as part of this**: `ThesisController::update()` would 500 on a NOT NULL violation if the abstract was cleared (UI sends `''` → `ConvertEmptyStringsToNull` → `null`). `update()` now validates abstract as `nullable` and coerces a cleared abstract back to `''`. Verified: clearing the abstract via the update endpoint now returns 200 (was a guaranteed 500). This also un-breaks a staff member manually emptying the Abstract textarea and saving.

**Bugfix — file replace/restore 500'd when Meilisearch is down** (found during user testing):
- `replaceFile()` and `restoreFileVersion()` both did `$thesis->update(['file_path' => ...])` *without* `withoutSyncingToSearch()`, unlike every other write in the controller. With Meilisearch down, Scout's synchronous `saved`-event sync threw `cURL error 7` **inside the DB transaction**, 500-ing the whole request (`POST /api/theses/{id}/file`). Swapping a file changes no searchable field (title/abstract/keywords/status), so both updates are now wrapped in `withoutSyncingToSearch()` — no re-push needed, and a down Meilisearch can no longer break the replace/restore. (If the post-replace OCR re-dispatch fills abstract/keywords, that job runs its own best-effort sync.) Verified against a genuinely-down Meilisearch: the wrapped update completes cleanly where the unwrapped one throws.
- Also fixed a cosmetic React console warning (`Removing a style property (borderColor) when a conflicting property (border) is set`) surfaced in the same session: `PdfViewer.jsx`'s thumbnail `thumbWrap` mixed the `border` shorthand with a `borderColor` longhand toggled by the active/hover state — converted the base to longhand (`borderWidth`/`borderStyle`/`borderColor`).

**Deduplicated usage statistics** (`ThesisController::generateViewToken()`):
- Previously inserted a brand-new `reading_history` row on *every* PDF-open, so refreshing the viewer repeatedly inflated both the public `view_count` and the user's own Reading History list with duplicate entries for one sitting.
- Now checks for an existing `reading_history` row for that user+thesis within the last 30 minutes — if found, just bumps its `viewed_at` instead of inserting a duplicate. Turns the count into "distinct reading sessions." No bot/anonymous concern to guard against separately — `generateViewToken()` already requires a logged-in user, so every view is already tied to a real known account.
- Verified live: opened the same thesis 3 times back-to-back, confirmed `view_count` stayed at 1 and only 1 raw `reading_history` row exists.

---

## 4. Known Issues / Explicitly Not Done

1. **OPcache is disabled**, likely the dominant remaining performance bottleneck (~500ms flat tax on every request, confirmed via direct timing — a cached response was no faster than an uncached one). User declined to fix this since it's a system-wide PHP config change outside the project repo. Not revisited unless asked again.
2. **Meilisearch auto-start is unreliable** — a Startup-folder script only fires at login, doesn't restart on crash. Discussed wrapping it as a real Windows service via NSSM (`winget install NSSM.NSSM`, `winget`-installable, same trust tier as Memurai) with a proper crash-restart — user said to leave it for now ("not a major issue, it just needs to be restarted").
3. **Brotli compression** — parked, needs a production hosting decision first (Nginx module vs. platform-native vs. not applicable to `artisan serve`).
4. **Docker Desktop is broken** on this machine (WSL2/virtualization issue) — routed around with native binaries instead of fixing it.
5. **Task Scheduler creation is blocked** in this Claude Code shell specifically (unrelated to #2 above, which is about Meilisearch's *reliability*, not this specific tool restriction).
6. **OCR still runs synchronously inline with the upload request** — correct now, just slow on long scanned documents (no async queue). Flagged, not built — bigger architectural change than requested. **Deliberately kept sync until the deploy host is chosen** (user is still weighing options as of 2026-07-12): sync deploys on any host with zero extra moving parts, whereas async needs a persistent supervised `queue:work` worker (Supervisor/NSSM/platform worker type) that not every host can run. Decision rule when the host is picked: if it can run a persistent background worker, switch `QUEUE_CONNECTION=database` + run a worker + add `queue:restart` to the deploy script + an `ocr_status` polling indicator on upload (only the upload path needs it — `replaceFile()`/`extractMetadata()` call OCR directly and stay sync by design); if it can't, stay sync. **Independent of the async question, the real deployment risk to verify early: the host must have Node.js** (+ `npm install` in `ocr/`, Tesseract language data, writable temp) — the OCR pipeline shells out to Node, so a PHP-only host can't do OCR at all, sync or async.
7. **A true "average users online per day at this hour"** metric (as opposed to the current "ever seen at this hour, cumulative across all history" framing) was discussed in detail with the user but not built — they chose to keep the current, simpler metric.
8. **Mobile responsiveness fixes are code-reviewed, not device-tested** — no browser automation tool available in this environment (see §3). Recommend a manual visual check.
9. **The Landing Page doesn't follow the dark/light theme** — it's a fully self-contained marketing page with its own hardcoded CSS, deliberately left alone (see §3, dark theme entry) rather than reworked unasked. Revisit if the user wants it themed too.
10. **Uploaded avatar photos are stored and served at their original size/resolution** — no server-side resize/compression on upload (same as category cover images, which have the same gap). Not a functional bug, just means a very large photo upload costs more storage/bandwidth than necessary. Not raised by the user, flagging only.
11. **User will request a complete functionality list for capstone documentation, delivered as a PDF** — the content is now compiled and ready at §6 (Complete Feature Catalog). What's *not* done yet: actually generating the PDF file itself. When asked, produce it via a new Blade view + DomPDF, following the existing `guide:generate` precedent (`app/Console/Commands/GenerateTeamGuide.php` / `resources/views/pdf/team_guide.blade.php`) rather than a markdown reply.
12. **Fixity/checksums** (SHA-256 hash per uploaded file, to detect silent corruption/tampering over time) — discussed as part of the digital-repository audit, judged genuinely relevant despite (really *because of*) this being a single-copy closed-access archive. **User asked to hold off, may revisit later** — not declined, just parked. See §3, "Digital repository audit & follow-through."
13. **Rights/license field** (per-thesis indicator of what a reader may actually do with it — quote, download, redistribute — independent of who can *see* it) — discussed as the one piece of the "Dublin Core completeness" gap that survives on its own merits at this system's scope. Not built, no timeline — see §3.
14. **Time-based embargo** (auto-unlock a restricted thesis on a future date) and **persistent identifiers / OAI-PMH / sitemap discoverability** — all explicitly evaluated and declined by the user during the digital-repository audit (not deferred, not overlooked). Do not build unless the user explicitly revisits the decision. See §3.
15. **PDF replace/restore's auto-purge has no real OS-level cron backing it in this dev environment** — works today via an opportunistic sweep piggybacked on staff traffic (`ThesisFilePurger::sweepIfDue()`, capped to once/hour) rather than a scheduled job, since Task Scheduler creation is blocked here anyway (#5 above) and no cron is configured. A real deployment should point actual cron at `php artisan theses:purge-expired-files` instead of relying on the opportunistic path alone.
16. **Synology NAS storage is planned but not wired — deferred, user will request help.** The team's design doc (Chapter III) specifies a Synology NAS as the PDF file store, but the code currently writes to Laravel's `local` disk (`storage/app`) everywhere (`store`/`replaceFile`/`servePdf`/`download`/`previewFile`/OCR/purge all reference `disk('local')`). Deploy task when revisited: add a filesystem disk for the NAS in `config/filesystems.php` (or repoint the `local` disk root at the NAS mount) and it flows through. **Critical constraint:** the NAS must be mounted as a real filesystem (SMB/NFS) with a usable OS path, NOT used as S3-style object storage — the OCR pipeline shells out to `node extract.cjs <absolute-path>` and needs `Storage::path()` to return a real path (object storage would break OCR unless files are copied to a local temp first). NAS RAID+backup also covers the parked fixity/preservation concern (#12). User asked to hold off — will need help wiring it at deploy time.
17. **Documentation (team's Chapter III doc) has drifted from the built system in places** — user is tracking/fixing this themselves, flagged for their defense: (a) "The system allows for custom citations for all theses" (Specific Objectives) — custom citations were *removed*; it's now one auto-generated APA + one MLA per thesis, editable in place; (b) guest access is self-contradictory in the doc (architecture section says guests browse titles/basic info, which the build does allow; Limitations says "No anonymous or guest access" — the build matches the architecture description, not the limitation). Not a code task — recorded so a future session doesn't "fix" the code to match an inconsistent doc. Offered a full doc-vs-build consistency pass; user declined for now.

---

## 5. Reference

**Test accounts** (all password: `password`): `superadmin@tipiganan.com`, `staff@tipiganan.com`, `student@tipiganan.com`, `teacher@tipiganan.com`

**Useful commands**:
```bash
php artisan scout:sync-index-settings          # push Meilisearch filterable-attribute config
php artisan scout:flush "App\Models\Thesis"    # wipe the search index (use before a re-import if it's stale)
php artisan scout:import "App\Models\Thesis"   # backfill/re-sync all theses into the search index
php artisan guide:generate                     # regenerate the team guide PDF
php artisan theses:purge-expired-files         # permanently delete superseded thesis PDFs past their grace period
php artisan test                                # full backend test suite (4 tests, all passing)
cd /c/Users/conch/meilisearch && ./meilisearch.exe --http-addr 127.0.0.1:7700 --no-analytics   # start Meilisearch manually if it's down
```

**Auto-memory files** (persist across Claude Code sessions, separate from this file):
`C:\Users\conch\.claude\projects\c--Users-conch-Codes-CAPSTONE-tipiganan-backend\memory\`
- `project_tipiganan_overview.md`
- `project_tipiganan_no_subcategories.md`
- `project_tipiganan_meilisearch_setup.md`
- `project_tipiganan_functionality_docs_request.md`

---

## 6. Complete Feature Catalog (for capstone documentation)

Organized by capability area, describing **the system as it stands today** — not a chronological changelog like §3. This is the direct source for the "complete list of every functionality" PDF the user will request; when that request comes, read this section, cross-check against routes/pages for anything not yet logged here, then render it as a real PDF (see Known Issues #11).

### 6.1 Accounts & Authentication
- Self-service registration for Student and Teacher accounts; Staff and Super Admin accounts can only be created by an existing Super Admin — no public signup for those two roles.
- Email/password login issuing a bearer token valid for 7 days (configurable) before requiring re-login.
- Self-service password change from the account's own Profile; admin-assisted password reset for any account (Staff/Super Admin only) — by deliberate design there is no self-service "forgot password" email flow.
- Password policy — minimum 8 characters, must include upper-case, lower-case, and a number — enforced identically everywhere a password is set (registration, self change, admin-created accounts, admin resets).
- Login and registration are rate-limited (5 attempts/minute) with a live "try again in Ns" countdown shown once triggered; every other endpoint is rate-limited too (120 requests/minute) as a general abuse/scraping guard.
- Profile picture upload (2MB max) shown everywhere an avatar appears (sidebar, admin user table, profile page), falling back to initials when none is set.

### 6.2 Roles & Access Control
- Four roles: Student, Teacher (both "regular users," identical repository privileges), Staff, and Super Admin.
- Staff and Super Admin inherit every regular-user privilege plus collection/user management; deleting a user account and deleting a document each additionally require an individually-grantable permission on top of the Staff role, so a Super Admin can extend or withhold those two specific capabilities per staff member.
- Role hierarchy enforced on account deletion — Staff can delete Student/Teacher accounts only, never a peer Staff member or a Super Admin.
- Every permission-gated action is hidden client-side for a user who lacks it, not just rejected server-side.

### 6.3 Collection (Thesis) Management
- Upload with full bibliographic metadata: title, author(s), adviser, year published, category/department, page count, abstract, keywords, cover image, and the PDF itself.
- Abstract and keywords may be left blank at upload — the system automatically extracts them from the PDF's own Abstract/Keywords section (§6.4) when the staff member doesn't type them in, without overwriting anything the staff member did type.
- Three-tier visibility: **Active** (visible to everyone, including guests), **Restricted** (visible to any logged-in school account, hidden from guests), **Archived** (hidden from everyone except staff explicitly browsing archived items).
- Full metadata editing after upload from a dedicated staff edit page.
- **File replacement with version history** — staff can swap a thesis's underlying PDF at any time. The previous file isn't deleted immediately: it stays restorable for a configurable grace period (30 days by default), can be restored or permanently deleted early on demand, and every replace/restore is logged to the audit trail. Restoring never loses data even across repeated swaps — whatever's currently active gets archived the same way before being replaced.
- Soft deletion — a deleted thesis is recoverable at the database level, not destroyed outright.
- Category/department management — staff can create, rename, and delete categories, each with its own cover image, used to organize Browse.

### 6.4 Digitization & OCR
- Handles both digitally-authored PDFs (real embedded text) and scanned/image-only PDFs (photographed physical pages compiled into one file) — automatically detects which kind a given upload is.
- For scanned PDFs, runs OCR against rasterized page images to recover machine-readable text; for very long scanned theses (60-150+ pages), only the first 12 and last 8 pages are processed (where title/abstract/keywords/introduction and conclusion live), keeping upload time reasonable rather than OCR-ing the entire document inline.
- From either kind of PDF, automatically locates and extracts the Abstract and Keywords sections specifically (heading-aware, not a raw text dump).
- Extracted keywords are cleaned before saving — a failed heading match producing a paragraph of body text is discarded rather than saved as garbage; genuine keyword lists are capped in count and per-term length.
- Full extracted text also feeds the search index, so even a scanned thesis with no typed metadata is still full-text searchable.

### 6.5 Search & Browse
- Full-text search across title, authors, adviser, abstract, and keywords — typo-tolerant and relevance-ranked, with an automatic fallback so search never goes fully down if the search engine is unavailable (just temporarily loses typo-tolerance).
- Filterable by department/category and publication year; sortable by relevance, newest, or most-viewed.
- Guests can search/browse Active theses; logged-in accounts additionally see Restricted items.
- Browse organized by department/category with cover images and per-item view counts.
- "Related theses" on a thesis's detail page, ranked by actual keyword/abstract content overlap with the item being viewed, not just "same category."
- A user's own recent searches feed their personal Dashboard/Activity view — never visible to anyone else, distinct from the staff-only full audit log.

### 6.6 Secure Reading & Citations
- View-only PDF viewer — theses are never offered as a direct downloadable file; access is via a short-lived signed URL generated per viewing session.
- Every page of a viewed PDF is watermarked live, per request, with the viewing account's name/email and a timestamp — not baked into the stored file — so a screenshot of any single page carries identifying information, not just the first.
- Auto-generated APA and MLA citations for every thesis, editable in place by staff; readers can copy either format, and copy events are logged (powers the Most Cited report).
- Bookmarking ("Favorites") for any signed-in account, with bulk select/remove/export and cover-image thumbnails; a bookmark whose thesis was later deleted shows a clear "no longer available" placeholder and quietly cleans itself up rather than showing a broken card.
- Personal reading history with real per-item progress tracking and a per-user "Continue Reading" list on the Dashboard.

### 6.7 Content Moderation
- Any signed-in user can report a thesis (wrong file, inappropriate content, factual issue, etc.) with a reason.
- Staff-only "Reported Items" queue to review and resolve open reports.

### 6.8 Reports & Analytics (staff/admin only)
- Dashboard totals: collections overall, by department, by year.
- Most Cited theses (from real citation-copy events) and Most Searched terms (from real search logs), both filterable by role (student/teacher/both) and date range.
- "Users Online" — distinct logged-in accounts active per hour-of-day, filterable the same way; guests are excluded (no identity to count).
- PDF export for any report, plus a separate audit-log PDF export with a date range.

### 6.9 Audit Trail
- Every significant account and content action is logged: registration, login/logout, password changes, profile edits, thesis upload/edit/status-change/file-replace/file-restore/deletion, category changes, permission grants/revokes, account activation/deactivation/deletion, citation edits, and thesis reports plus their resolution.
- Each entry records who did it, what it affected, a human-readable description, the acting IP address, and when — searchable and exportable by staff/Super Admin.

### 6.10 Personalization & Preferences
- Personal Dashboard: role-appropriate stats, "Continue Reading" (the account's own reads only, never shared across accounts), and a merged Recent Activity feed (bookmarks + views + searches, real timestamps, chronologically sorted).
- Light / Dark / System theme, applied instantly and remembered across sessions; a "Reduce Motion" accessibility toggle suppressing UI transitions/animations app-wide.
- Editable profile (name, email, profile picture) and a Security tab (password change, plus a read-only view of the account's own roles and individually-granted permissions).

### 6.11 Administration
- User account management — create Staff/Super Admin accounts, activate/deactivate any account, delete accounts (subject to the role hierarchy in §6.2), grant/revoke the two individually-grantable permissions, admin-assisted password reset.
- Collection management — the full thesis list with search/filter, restrict/unrestrict, archive/unarchive, permission-gated delete, plus the file-replace/version-history tools from §6.3.
- Category management with cover images.

### 6.12 Performance & Reliability
- Every non-database service degrades gracefully instead of breaking the app: the search engine going down falls back to database search automatically; the cache layer going down just means slightly slower responses, computed fresh, never a failure; health checks for both are themselves cached briefly so an actual outage doesn't add a repeated timeout tax to every request.
- Page code loads on demand per route rather than as one large bundle, so an initial visit only downloads what that page actually needs.
- Frequently-read, rarely-changed data is cached with automatic invalidation on write, and self-heals if a cached entry is ever left incompatible with a later schema change.

### 6.13 Responsive & Cross-Device Design
- A functioning slide-out navigation drawer on phone-width screens (distinct from the desktop push-over sidebar), horizontally-scrolling tab bars/toolbars instead of overflow clipping, and wrapping instead of overlap on every button/label row across the app.
- The secure PDF viewer's thumbnail rail and control toolbar are both mobile-aware (rail closed by default on phones, toolbar scrolls instead of clipping controls off-screen).

### 6.14 Security Hardening
- Rate limiting on every endpoint, with a much stricter limit specifically on login/registration to blunt password brute-forcing.
- Authentication tokens expire automatically (7 days) instead of remaining valid indefinitely.
- Password complexity enforced (length + mixed case + a number) everywhere a password is set.
- No user-supplied content (thesis titles, account names) can execute as script in another user's browser — all dynamic text rendering is auto-escaped, nowhere raw HTML.
- File upload validation (type + size) on every upload surface (thesis PDFs, cover images, avatars).

---

**Scoped as a single-institution, closed-access system** — only this school's own students, teachers, and staff, and every collection originates from this school; there is no public or external access at all. A few capabilities common in large public/multi-institution digital repositories were deliberately evaluated and left out as not relevant at this scope, rather than overlooked: persistent identifiers/DOIs, OAI-PMH metadata harvesting, and public search-engine discoverability. See §3, "Digital repository audit & follow-through," for the reasoning behind each, and §4 (Known Issues #12-14) for what's parked vs. explicitly declined.

---

## 7. Deploy Readiness Checklist

From the deploy-readiness diagnostic. **Tier 1 = must-do before going live; Tier 2 = strongly recommended; Tier 3 = quality/completeness.** In-repo prep for Tier 1 (#4 CORS, #5 https/proxy, env templates) is DONE (see §3); the items below are what remains **on the server at deploy time**. Not deploying yet as of 2026-07-12 — this is the guide for when the host is chosen (host still undecided; shortlist discussed: DigitalOcean 2GB Singapore via GitHub Student Pack, Oracle Always Free, or Hetzner).

### Tier 1 — deploy-blocking (server-side)
- [ ] **Copy `.env.production.example` → `.env`** on the server; run `php artisan key:generate`; fill every `CHANGE_ME_*`. (Sets `APP_ENV=production`, `APP_DEBUG=false` — stops stack-trace leakage.)
- [ ] **Set `FRONTEND_URL`** to the real frontend origin (locks CORS — the in-repo code already enforces this from the env var).
- [ ] **Meilisearch master key**: generate one, set `MEILISEARCH_KEY`, start Meilisearch with `--master-key`, and bind it to `127.0.0.1` (not internet-reachable).
- [ ] **Database**: create a dedicated limited-privilege MySQL user (NOT root) + strong password; set `DB_USERNAME`/`DB_PASSWORD`.
- [ ] **Redis**: set a password (or bind to 127.0.0.1 + protected-mode).
- [ ] **TLS cert** (Let's Encrypt/Certbot on Nginx) + HTTP→HTTPS redirect. (Laravel side already forces https in production + trusts the proxy.)
- [ ] **Raise upload limits** so 50MB PDFs work: PHP `upload_max_filesize=60M` + `post_max_size=60M`; Nginx `client_max_body_size 60M;`.
- [ ] **Frontend**: copy `.env.production.example` → `.env.production`, set `VITE_API_BASE_URL=https://<api-domain>/api`, `npm run build`, deploy `dist/`.
- [ ] `php artisan migrate --force` + `php artisan storage:link` on the server.
- [ ] `php artisan config:cache && php artisan route:cache && php artisan event:cache`.

### Tier 2 — strongly recommended
- [x] **Security headers** — API middleware DONE (`SecurityHeaders`). REMAINING (deploy): set the same headers on the frontend HTML in Nginx: `add_header X-Frame-Options SAMEORIGIN; add_header X-Content-Type-Options nosniff; add_header Referrer-Policy strict-origin-when-cross-origin; add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;`
- [x] **Task scheduler** — schedule DEFINED in `routes/console.php` (purge daily, `backup:database` daily 02:00). REMAINING (deploy): one cron entry — `* * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1` (Windows: a per-minute Task Scheduler task).
- [x] **Backups** — `php artisan backup:database` command DONE (mysqldump → `storage/app/backups`, rotated). REMAINING (deploy): (a) if mysqldump isn't on PATH, set `DB_DUMP_BINARY`; (b) **move backups OFF the primary server** — copy `storage/app/backups` to the NAS/offsite/cloud on a schedule (RAID ≠ backup).
- [ ] **PHP-FPM + OPcache** (concurrency + the ~500ms boot tax) — the runtime change that makes it production-grade; `artisan serve` is single-threaded (Known Issue #1). Optionally Laravel Octane to erase the boot cost entirely.
- [ ] Meilisearch + queue worker (if async) supervised (NSSM on Windows / systemd on Linux).

### Tier 3 — quality / repository completeness
- [x] **Automated tests** — DONE: 20 passing (auth/password-policy/rate-limit, RBAC + role hierarchy, thesis visibility, file-versioning + checksum). Room to grow (search, reports, citations) but the critical paths are covered.
- [x] **Timezone** — DONE: `Asia/Manila`, env-driven (`APP_TIMEZONE`). REMAINING (deploy): the prod template already sets it — just confirm it's applied on the fresh DB before go-live.
- [x] **Fixity/checksums (#12)** — DONE: SHA-256 per file + `theses:verify-checksums` (weekly). REMAINING (deploy): the weekly schedule fires via the same `schedule:run` cron as Tier 2.
- [x] **Load-test PDF-viewing** — DONE (dev floor measured): ~34ms/stamp, ~7–9 req/s at OPcache-off/4-worker; see §3 for the full interpretation + prod extrapolation. REMAINING: re-run against the deployed FPM+OPcache server with a large scanned thesis to get the real production ceiling.
- [ ] Still parked (user's call): **rights/license field (#13)**, **NAS wiring (#16)**, **documentation drift (#17)**.
