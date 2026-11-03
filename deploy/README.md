# Deploying TIPIGANAN

For whoever sets up the server. Everything runs on one Ubuntu 24.04 server under
one domain: the website at `/` and the API at `/api`.

## What the server needs

- Ubuntu 24.04 with `sudo` and a terminal (SSH or the host's console). Shared
  hosting without a terminal will not work.
- At least 2 GB of memory, ideally 4 GB, plus disk space for the PDFs (scans can
  reach 150 MB each).
- A domain or subdomain pointing at the server. The system expects https.
- Internet access for setup and for the first document read, when OCR downloads
  its English language file (about 5 MB) into `storage/app/ocr-cache`.

## What runs

| Process | Job | Kept running by |
| --- | --- | --- |
| Nginx + PHP-FPM | Website and API | systemd |
| MySQL | Data | systemd |
| Queue worker | Reads uploaded documents (OCR) | `tipiganan-worker` service |
| Scheduler | Backups, file checks, clean-ups | `/etc/cron.d/tipiganan`, every minute |
| Meilisearch (optional) | Search with typo tolerance | `tipiganan-meilisearch` service |
| Redis (optional) | Faster caching | systemd |

Node.js, qpdf and mysqldump are called when needed and do not stay running.

## First-time setup

1. Merge the work into `main` and push both projects, since `update.sh` and a
   plain clone use `main`. Then clone both, as your normal user:

   ```bash
   sudo mkdir -p /var/www/tipiganan && sudo chown $USER /var/www/tipiganan
   git clone <backend repository> /var/www/tipiganan/backend
   git clone <frontend repository> /var/www/tipiganan/frontend
   ```

2. Run the setup. `EMAIL` turns on https, and needs the domain to point at the
   server already. Without it, the script prints the https command to run later.

   ```bash
   cd /var/www/tipiganan/backend
   sudo DOMAIN=repo.example.com EMAIL=you@example.com bash deploy/provision.sh
   ```

   It installs the software, creates `.env` with new random passwords, sets up the
   database, builds the frontend, starts the background processes and runs the
   check at the end. To skip Meilisearch, add `NO_MEILISEARCH=1`.

3. Create the first Super Admin. Let the librarian type their own password:

   ```bash
   sudo -u www-data php artisan accounts:create-super-admin
   ```

4. The librarian signs in and imports the school list on **School Records**. Their
   own ID number must be on it, and on every later list. Include library staff too.

5. Set up off-site backups (below).

## Check it works

```bash
sudo -u www-data php artisan deploy:check
```

`FAIL` must be fixed; `WARN` is worth reading. On a new server, expect warnings for
no backup yet and no school list until those exist. It cannot see PHP's upload limit
or whether the worker is running while idle, so also test by hand:

- Sign in, upload a scanned PDF, and watch the Abstract fill in.
- Open that PDF in the viewer and check the watermark.
- Upload a large PDF near 150 MB.
- Run `sudo -u www-data php artisan backup:database`, and check that a file appears
  in `storage/app/backups`.
- Reboot, then run `systemctl status tipiganan-worker` and `deploy:check` again.

## Updating

Push to `main`, then on the server:

```bash
cd /var/www/tipiganan/backend
sudo bash deploy/update.sh
```

To use another branch, add `BRANCH=dev`. It backs up the database first, shows a
maintenance response during the update, and restarts what needs restarting.

## Backups

The scheduler saves the database to `storage/app/backups` daily at 02:00. That is
the same disk as everything else, and it does **not** include the PDFs, so copy
both elsewhere. On the server:

```bash
sudo ssh-keygen -t ed25519 -f /root/.ssh/id_ed25519 -N ""
sudo ssh-copy-id backup@192.0.2.10
sudo nano /etc/tipiganan/backup.env     # BACKUP_TARGET=backup@192.0.2.10:/backups/tipiganan
sudo bash deploy/backup-offsite.sh      # try it once
```

Cron then runs it daily at 03:30; the log is `/var/log/tipiganan-backup.log`. Keep
a copy of `.env` somewhere safe as well. It is deliberately not copied, as it holds
every password.

**To restore:** set up a new server, copy `backups`, `private` and `public` back into
`storage/app`, restore `.env`, load the newest backup with
`mysql tipiganan < storage/app/backups/tipiganan-<date>.sql`, then run
`php artisan storage:link`. Try this once before relying on it.

## The library scanner

The scanner saves PDFs to a folder on the computer it is connected to, and the
Upload Item page lists a folder on the server (`SCAN_INBOX_PATH`).

- **Server at the school:** share a folder between them and set `SCAN_INBOX_PATH`
  to where it is mounted. Run `php artisan config:cache` after changing `.env`.
- **Rented server:** the scan inbox stays empty unless the folder is synced to the
  server. Staff can upload scans by hand on Upload Item, and everything else is
  the same.

## When something goes wrong

| Problem | Where to look |
| --- | --- |
| Documents are not being read | `systemctl status tipiganan-worker`, `journalctl -u tipiganan-worker -n 50` |
| Failed OCR jobs | `php artisan queue:failed` |
| Errors in the app | `storage/logs/` |
| Backups are not appearing | `cat /etc/cron.d/tipiganan`, `grep CRON /var/log/syslog` |
| Search has no typo tolerance | `systemctl status tipiganan-meilisearch` |
| A `.env` change has no effect | `sudo -u www-data php artisan config:cache`, then `sudo systemctl restart tipiganan-worker` |
| Search misses items after a restore | `sudo -u www-data php artisan scout:import "App\Models\Thesis"` |
