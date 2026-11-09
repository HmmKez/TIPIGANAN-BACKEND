# Deploying TIPIGANAN

For the IT staff setting up TIPIGANAN, the Mater Dei College online repository of
theses and special collections, on the school's server.

## Status of this guide

The system has not run on a live server yet.

| | What was tested |
| --- | --- |
| **Tested** | Fresh clones from GitHub, installed in production mode on Windows with MySQL 8.4: dependencies, migrations, seeding, caches, a real queue worker running OCR, backups, the scheduler, sign-in and PDF viewing. 271 automated tests pass. |
| **Not tested** | `provision.sh`, `update.sh` and `backup-offsite.sh` have only had a syntax check and a read-through. The Nginx, PHP, systemd and cron files, https, a 150 MB upload through a web server, and any Linux run are all untested. |

Expect to correct something on the first run, and treat the scripts as a starting
point. Please send any errors to the development team so the scripts can be fixed.

## What the server needs

### Software

| Requirement | Version | Used for |
| --- | --- | --- |
| Linux, ideally **Ubuntu 24.04** | | The scripts are written for this. Other systems use the manual steps below. |
| **PHP** | 8.3 or newer | The application |
| PHP extensions | `ctype`, `curl`, `dom`, `fileinfo`, `filter`, `gd`, `hash`, `iconv`, `json`, `libxml`, `mbstring`, `openssl`, `pcre`, `pdo_mysql`, `session`, `tokenizer`, `xml`, `zlib`, and OPcache | `composer check-platform-reqs --no-dev` confirms most of these |
| **Composer** | 2 | PHP packages |
| **Node.js** | 22.13 or newer, or 24 (tested on 24.15) | Reading scanned documents (OCR) and building the website |
| **MySQL** | 8 or newer (tested on 8.4), including `mysqldump` | Data and backups |
| **qpdf** | Any current version | Prepares PDFs for watermarking. Without it, many PDFs upload but will not open. |
| **Web server** | Nginx with PHP-FPM (config provided), or Apache | Website and API |
| **Meilisearch** | 1.49 | Search with typo tolerance. Required. |
| **Redis** | Any current version | Caching and login rate limits. Required. |

### Resources

| Resource | Minimum | Why |
| --- | --- | --- |
| Memory | 4 GB recommended | A 135 MB scan peaked at 290 MB when opened, and OCR on 20 pages peaked at about 240 MB. Each runs at the same time as MySQL and other users. |
| CPU | 2 cores | OCR on 20 pages took 47 seconds on a development machine |
| Disk | 20 GB, plus space for the PDFs and backups | Each PDF can be up to 150 MB, and is kept with a backup copy until replaced files expire. Estimate from the size of the collection. |

### Network and other items

- **A domain or subdomain with https.** The system expects https in production.
- **Inbound:** ports 80 and 443 only. MySQL, Redis and Meilisearch must stay private.
- **Outbound, during setup:** GitHub (also hosts the Meilisearch download), Composer (packagist.org), npm (npmjs.org) and
  the operating system's package source.
- **Outbound, once:** the first OCR downloads a 5 MB English language file into
  `storage/app/ocr-cache`. If the server has no outbound access, copy that file there
  from a machine that has already run OCR.
- **For users' browsers:** icons and fonts load from cdnjs.cloudflare.com and
  fonts.googleapis.com. If they are blocked, pages work but look wrong.
- **Scanner folder (optional):** a shared folder the server can read and write, where
  the library's ScanSnap computer saves PDFs. See [The library scanner](#the-library-scanner).
- **Backup destination:** another machine or storage for off-site copies. See
  [Backups](#backups).

## What must keep running

| Process | Job | How it stays running |
| --- | --- | --- |
| Web server + PHP-FPM | Website and API | A system service |
| MySQL | Data | A system service |
| **Queue worker** | Reads uploaded documents | `tipiganan-worker` service: `php artisan queue:work database --tries=1 --timeout=900` |
| **Scheduler** | Backups at 02:00, purging replaced files, weekly file checks, monthly log archiving | Cron, every minute: `php artisan schedule:run` |
| **Meilisearch** | Search with typo tolerance | `tipiganan-meilisearch` service, reachable only from the server itself |
| **Redis** | Caching and login rate limits | A system service, password protected |

If the worker stops, documents silently stop being read. If the scheduler stops,
backups silently stop. If Redis or Meilisearch stops, the system falls back to slower
caching and plainer search rather than going down. `php artisan deploy:check` reports
all of these.

## Getting the code

Both GitHub repositories are private, so ask the development team for read access,
or for a copy of the code. Use the `main` branch.

- Backend: `https://github.com/HmmKez/TIPIGANAN-BACKEND`
- Frontend: `https://github.com/HmmKez/TIPIGANAN-FRONTEND`

## Install on Ubuntu 24.04 (IF AVAILABLE)

1. Clone both projects, as your normal user:

   ```bash
   sudo mkdir -p /var/www/tipiganan && sudo chown $USER /var/www/tipiganan
   git clone https://github.com/HmmKez/TIPIGANAN-BACKEND.git /var/www/tipiganan/backend
   git clone https://github.com/HmmKez/TIPIGANAN-FRONTEND.git /var/www/tipiganan/frontend
   ```

2. Run the setup. `EMAIL` turns on https with Let's Encrypt, and needs the domain to
   point at the server already. Without it, the script prints the https command to
   run later. If the school provides its own certificate, leave `EMAIL` out and add
   the certificate to the Nginx site.

   ```bash
   cd /var/www/tipiganan/backend
   sudo DOMAIN=repo.example.edu EMAIL=you@example.edu bash deploy/provision.sh
   ```

   It installs the software, creates `.env` with new random passwords, sets up the
   database, Redis and Meilisearch, builds the website and starts the background
   processes. A `FAIL` for no Super Admin at the end is expected.

3. Continue with [Before people use it](#before-people-use-it).

## Install on another system (ALTERNATIVE)

Use these steps on another Linux system, or on Windows Server with IIS or Apache.

**Backend:**

```bash
git clone https://github.com/HmmKez/TIPIGANAN-BACKEND.git backend
cd backend
composer install --no-dev --optimize-autoloader
npm ci --omit=dev
cp .env.production.example .env        # then fill in every CHANGE_ME value
php artisan key:generate --force
php artisan migrate --force
php artisan db:seed --force            # only roles and permissions when APP_ENV=production
php artisan storage:link
php artisan config:cache
php artisan route:cache
php artisan event:cache
php artisan view:cache
```

Create a dedicated MySQL database and user, not root. The web server's user must be
able to write to `storage` and `bootstrap/cache`.

**Redis and Meilisearch:** both must run on this server, without public access.
- Set a password on Redis.
- Download Meilisearch 1.49.0 from its GitHub releases, and start it with
  `--http-addr 127.0.0.1:7700 --env production` and a master key.
  `deploy/systemd/tipiganan-meilisearch.service` shows how.
- Use the same password and key in `.env`, and once Meilisearch is running, run
  `php artisan scout:sync-index-settings`.

**Frontend:**

```bash
git clone https://github.com/HmmKez/TIPIGANAN-FRONTEND.git frontend
cd frontend
echo "VITE_API_BASE_URL=https://repo.example.edu/api" > .env.production
npm ci
npm run build
```

**Web server:**
- Serve `frontend/dist` at `/`, and send any path that isn't a file to `index.html`.
- Send `/api` and `/up` to `backend/public/index.php`.
- Serve `/storage/` from `backend/public/storage/`.
- Allow requests up to 160 MB and a timeout of 300 seconds.
- `deploy/nginx/tipiganan.conf` shows all of this.

**PHP settings** (in `deploy/php/99-tipiganan.ini`): `upload_max_filesize` and
`post_max_size` 160M, `memory_limit` 512M, `max_execution_time` 300.

**Background processes:** set up the worker and scheduler from
[What must keep running](#what-must-keep-running). On Windows, run the worker as a
service with NSSM, and the scheduler as a Task Scheduler task every minute. In `.env`,
set `DB_DUMP_BINARY` and `QPDF_BINARY` to their full paths, using forward slashes.

### Settings to fill in

| Setting | Value |
| --- | --- |
| `APP_ENV` / `APP_DEBUG` | `production` / `false` |
| `APP_URL` | The system's https address |
| `FRONTEND_URL` | The website's https address. The same as `APP_URL` when both share a domain. |
| `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | The dedicated database user |
| `QUEUE_CONNECTION` / `DB_QUEUE_RETRY_AFTER` | `database` / `1000`. Keep these as they are. |
| `CACHE_STORE`, `REDIS_PASSWORD` | `redis`, with its password |
| `SCOUT_DRIVER`, `MEILISEARCH_KEY` | `meilisearch`, with its master key |
| `SCAN_INBOX_PATH` | The scanner folder. Blank uses `storage/app/scan-inbox`. |

After changing `.env`, run `php artisan config:cache` and restart the worker.

## Before people use it

1. **Check the setup:**

   ```bash
   sudo -u www-data php artisan deploy:check
   ```

   `FAIL` must be fixed, and `WARN` is worth reading. It cannot see the web server's
   upload limit, or whether an idle worker is running.

2. **Create the first Super Admin.** This works only while no active Super Admin
   exists, so let the librarian type their own password:

   ```bash
   sudo -u www-data php artisan accounts:create-super-admin
   ```

3. **Import the school list.** The librarian signs in and uses School Records. The
   page has a CSV template. The list must include the librarian's own ID number and
   all library staff, or they cannot sign in.

4. **Test by hand:**
   - Upload a scanned PDF, and watch the Abstract fill in.
   - Open that PDF in the viewer, and check the watermark.
   - Upload a large PDF, near 150 MB.
   - Run `php artisan backup:database`, and check for a file in `storage/app/backups`.
   - Restart the server, then check the worker and run `deploy:check` again.
   - Try restoring a backup once, on a test database.

Demo accounts are never created when `APP_ENV=production`. Do not copy a development
database to the server, because its demo accounts use the password `password`.

## Updating

On Ubuntu with the provided setup:

```bash
cd /var/www/tipiganan/backend
sudo bash deploy/update.sh
```

It backs up the database first, shows a maintenance response during the update, and
restarts what needs restarting. To use another branch, add `BRANCH=dev`.

On another system, run the install steps again with `git pull` first, then restart
the web server's PHP processes and run `php artisan queue:restart`.

## Backups

The scheduler saves the database to `storage/app/backups` daily at 02:00. That is the
same disk as everything else, and it does **not** include the PDFs, so copy both
elsewhere: `storage/app/backups`, `storage/app/private` and `storage/app/public`.

On Ubuntu, `deploy/backup-offsite.sh` does this with rsync over SSH:

```bash
sudo ssh-keygen -t ed25519 -f /root/.ssh/id_ed25519 -N ""
sudo ssh-copy-id backup@192.0.2.10
sudo nano /etc/tipiganan/backup.env     # BACKUP_TARGET=backup@192.0.2.10:/backups/tipiganan
sudo bash deploy/backup-offsite.sh      # try it once
```

Cron then runs it daily at 03:30, and the log is `/var/log/tipiganan-backup.log`. Any
other backup system the school uses is fine, as long as it covers those three folders.

Keep a copy of `.env` somewhere safe as well. It is deliberately not copied, because it
holds every password, but `APP_KEY` and the database password are needed to restore.

**To restore:** set up a new server, copy `backups`, `private` and `public` back into
`storage/app`, restore `.env`, load the newest backup with
`mysql tipiganan < storage/app/backups/tipiganan-<date>.sql`, then run
`php artisan storage:link`.

## The library scanner

The scanner saves PDFs to a folder on the computer it is connected to. The Upload Item
page lists the folder set by `SCAN_INBOX_PATH`, so share one folder between that
computer and the server, and point this setting at it. The web server's user needs read
and write access, because filed scans are removed from the folder. Without a shared
folder, staff can still upload scans by hand.

## When something goes wrong

| Problem | Where to look |
| --- | --- |
| Documents are not being read | `systemctl status tipiganan-worker`, `journalctl -u tipiganan-worker -n 50` |
| Failed OCR jobs | `php artisan queue:failed` |
| Errors in the app | `storage/logs/` |
| PDFs upload but will not open | Check that qpdf is installed, then run `php artisan deploy:check` |
| Readers see "Failed to load PDF file." but staff Preview works | The browser console shows `pdf.worker` sent as `application/octet-stream`. The `.mjs` block under `/assets/` in the nginx config is missing. |
| Large uploads fail | The web server's and PHP's upload limits |
| Backups are not appearing | `cat /etc/cron.d/tipiganan`, `grep CRON /var/log/syslog` |
| Search has no typo tolerance | `systemctl status tipiganan-meilisearch`, then `php artisan deploy:check` |
| Pages are slow, or Redis errors in the logs | `systemctl status redis-server`, and that `REDIS_PASSWORD` matches |
| A `.env` change has no effect | `php artisan config:cache`, then restart the worker |
| Search misses items after a restore | `php artisan scout:import "App\Models\Thesis"` |
