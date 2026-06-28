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