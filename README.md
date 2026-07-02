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

## Using the Audit Log PDF Export

Only users with the `export_reports` permission can download the audit log as a PDF.

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
  -o audit-log.pdf
```

Custom date range example:

```bash
curl -G "http://localhost:8000/api/audit-logs/export" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  --data-urlencode "period=custom" \
  --data-urlencode "date_from=2026-07-01" \
  --data-urlencode "date_to=2026-07-02" \
  -o audit-log.pdf
```

If the user does not have the `export_reports` permission, the API will return a `403` response.

## Using the Report PDF Export

Only users with the `export_reports` permission can download reports as a PDF.

### Endpoint
- `GET /api/reports/export`

### Query parameters
- `report=dashboard|most-cited|by-department|by-year|most-searched|most-active|peak-hours`
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

Most active users export with date range:

```bash
curl -G "http://localhost:8000/api/reports/export" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  --data-urlencode "report=most-active" \
  --data-urlencode "date_from=2026-07-01" \
  --data-urlencode "date_to=2026-07-02" \
  -o report-most-active.pdf
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
