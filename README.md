# TIPIGANAN — Backend

MDC Online Repository of Special and Rare Collections  
Laravel REST API Backend

## Requirements

- PHP 8.3+
- Composer
- MySQL 8+
- Node.js 18+ (for npm)
- Laragon (recommended for Windows)

## Setup Instructions

### 1. Clone the repository
```bash
git clone https://github.com/YOUR_USERNAME/tipiganan-backend.git
cd tipiganan-backend
```

### 2. Install dependencies
```bash
composer install
```

### 3. Copy environment file
```bash
cp .env.example .env
php artisan key:generate
```

### 4. Configure `.env`
Update these values in your `.env` file:
DB_DATABASE=tipiganan
DB_USERNAME=root
DB_PASSWORD=

### 5. Run migrations and seeders
```bash
php artisan migrate
php artisan db:seed
```

### 6. Start the server
```bash
php artisan serve
```

API runs at `http://localhost:8000`

## Initializing a Fresh Clone

1. Make sure PHP 8.3+, Composer, Node.js 18+, and a MySQL database are installed.
2. Create a database for the project (for example, `tipiganan`).
3. Clone the repository and enter the project folder.
4. Install PHP dependencies:
   ```bash
   composer install
   ```
5. Install frontend dependencies:
   ```bash
   npm install
   ```
6. Copy the environment file and generate an app key:
   ```bash
   cp .env.example .env
   php artisan key:generate
   ```
7. Update `.env` with your database credentials, for example:
   ```env
   DB_CONNECTION=mysql
   DB_HOST=127.0.0.1
   DB_PORT=3306
   DB_DATABASE=tipiganan
   DB_USERNAME=root
   DB_PASSWORD=
   ```
8. Run the database migrations and seeders:
   ```bash
   php artisan migrate --seed
   ```
9. Build the frontend assets:
   ```bash
   npm run build
   ```
10. Start the backend:
    ```bash
    php artisan serve
    ```

## Optional: Meilisearch (better search)

The app works fine without this — search automatically falls back to a plain MySQL query. Meilisearch adds typo-tolerance and relevance ranking on top. Skip this section entirely unless you want that.

1. Download the latest Windows binary from [github.com/meilisearch/meilisearch/releases](https://github.com/meilisearch/meilisearch/releases) (look for `meilisearch-windows-amd64.exe`) and save it somewhere permanent, e.g. `C:\meilisearch\meilisearch.exe`.
2. Run it (no install needed, it's a single executable):
   ```powershell
   C:\meilisearch\meilisearch.exe --http-addr 127.0.0.1:7700 --no-analytics
   ```
   Keep this running in its own terminal window, or set it up to run at login (any "run this at startup" method works — a shortcut in `shell:startup`, Task Scheduler, etc.).
3. In your `.env`, make sure these are set (they're already the defaults in `.env.example`):
   ```env
   SCOUT_DRIVER=meilisearch
   MEILISEARCH_HOST=http://localhost:7700
   MEILISEARCH_KEY=
   ```
4. Push your existing theses into the index:
   ```bash
   php artisan scout:sync-index-settings
   php artisan scout:import "App\Models\Thesis"
   ```

If Meilisearch isn't running (or you skip this whole section), search silently falls back to MySQL — nothing breaks.

## Optional: Redis / Memurai (faster caching)

Also optional — without it, the app just recomputes things like the Reports & Analytics dashboard on every request instead of caching them for a few minutes. Nothing errors or breaks either way.

1. Windows doesn't run Redis directly, so install **Memurai** (a Redis-compatible server for Windows) from an **elevated/administrator** PowerShell:
   ```powershell
   winget install --id Memurai.MemuraiDeveloper --source winget --accept-source-agreements --accept-package-agreements
   ```
   It installs as a Windows service and starts automatically — no need to keep a terminal open for it.
2. In your `.env`:
   ```env
   CACHE_STORE=redis
   REDIS_CLIENT=predis
   REDIS_HOST=127.0.0.1
   REDIS_PORT=6379
   REDIS_MAX_RETRIES=0
   ```
   (`predis`, not `phpredis` — it's a pure-PHP client, no extra PHP extension to compile.)
3. That's it — no import/sync step needed, caching just starts working on the next request.

If Redis/Memurai isn't running, `CACHE_STORE=redis` is still safe to leave set — the app detects the failure once, skips retrying it for the next 15 seconds, and just computes everything fresh instead.

## Using the Audit Log CSV Export

The audit log exports as **CSV, not PDF** — unlike the analytics reports, which
are still PDFs. It is the only export that grows without bound (a row per login,
view and search), and it is a *record* rather than a report: you filter, sort and
pivot it in a spreadsheet. It is streamed, so the download stays flat in memory
however large the log gets.

Only users with the `export_reports` permission can download it.

### Required permission
- Staff and super admin accounts seeded by the project already have this permission.
- If you create a custom user, assign the permission manually:
  ```bash
  php artisan tinker
  >>> $user = App\Models\User::find(1);
  >>> $user->givePermissionTo('export_reports');
  ```

### Endpoint
- `GET /api/audit-logs/export`

### Query parameters
- `period=day` → last 1 day
- `period=week` → last 1 week
- `period=month` → last 1 month
- `period=custom` → custom date range using `date_from` and `date_to`
- Optional filters:
  - `user_id`
  - `action`

### Example requests

Login first to get a Sanctum token, then call the export endpoint:

```bash
curl -X GET "http://localhost:8000/api/audit-logs/export?period=week" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -o audit-log.csv
```

Custom date range example:

```bash
curl -G "http://localhost:8000/api/audit-logs/export" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  --data-urlencode "period=custom" \
  --data-urlencode "date_from=2026-07-01" \
  --data-urlencode "date_to=2026-07-02" \
  -o audit-log.csv
```

If the user does not have the `export_reports` permission, the API will return a `403` response.

### Columns
`ID, Timestamp, User, Role, Action, Description, IP Address`

The ID is there because two genuinely distinct entries can otherwise be
identical — the same person searching the same term twice in one second — and an
audit record has to stay distinguishable.

### If the timestamps show as `#####` in Excel
That is Excel, not the file: it recognises the column as a date and fills the
cell with hashes when the column is too narrow to render one. Double-click the
right edge of the column header to auto-fit. A plain CSV carries no column
widths, so there is nothing to set on the export side.

## Audit log retention (`audit:prune`)

`audit_logs` is the one table that grows forever. Left alone it reaches millions
of rows and the Audit Logs page — and the reports built on it — slow to a crawl.

```bash
php artisan audit:prune --dry-run   # report what would go, change nothing
php artisan audit:prune             # archive to CSV, verify, then delete
```

- **Only usage analytics are pruned** (`view_thesis`, `search`), after
  `AUDIT_RETENTION_DAYS` (default 365). Security and administrative entries —
  logins, permission grants, uploads, deletions — are **never** pruned
  automatically. They are what an auditor asks for.
- **Nothing is destroyed outright.** Rows are archived to CSV on
  `AUDIT_ARCHIVE_DISK` (default `local` → `storage/app/private/audit-archives`),
  the archive is read back off the disk and its rows counted, and only if that
  matches does anything get deleted.
- The prune is itself recorded in the audit log, and that entry is not prunable.
- Runs monthly via the scheduler (see `routes/console.php`), which needs the one
  cron entry from the deploy checklist. Until that cron exists it never fires on
  its own — run it by hand if the table gets large.

## Using the Report PDF Export

Only users with the `export_reports` permission can download reports as a PDF.

### Endpoint
- `GET /api/reports/export`

These stay PDF (unlike the audit log above): each one is a small, fixed-size
summary meant to be read and printed — the largest is 24 rows.

### Query parameters
- `report=dashboard|most-cited|by-department|by-year|most-searched|users-online`
- `date_from` (optional) — required for custom filtering ranges if used with report-specific logic
- `date_to` (optional) — required for custom filtering ranges if used with report-specific logic

### Example requests

Dashboard summary export:

```bash
curl -X GET "http://localhost:8000/api/reports/export?report=dashboard" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -o report-dashboard.pdf
```

Most cited theses export:

```bash
curl -X GET "http://localhost:8000/api/reports/export?report=most-cited" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -o report-most-cited.pdf
```

Users-online export with date range:

```bash
curl -G "http://localhost:8000/api/reports/export" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  --data-urlencode "report=users-online" \
  --data-urlencode "date_from=2026-07-01" \
  --data-urlencode "date_to=2026-07-02" \
  -o report-users-online.pdf
```

If the user does not have the `export_reports` permission, the API will return a `403` response.

## Default Test Accounts

| Role | Email | Password |
|---|---|---|
| Super Admin | superadmin@tipiganan.com | password |
| Staff | staff@tipiganan.com | password |
| Student | student@tipiganan.com | password |
| Teacher | teacher@tipiganan.com | password |

## Team

Group 7 — Capstone Project  
Concha · Esto · Mendez · Miano
