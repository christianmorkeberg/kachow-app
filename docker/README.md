# Running Kachow in Docker on a VPS

Four containers: **caddy** (web server, automatic HTTPS) → **php** (PHP 8.4 FPM) → **db** (MariaDB), plus **cron** (the scheduled jobs, same schedule as before). Nothing in here holds secrets: `.env` files stay on the server and are git-ignored.

> Status: written but not yet run on a real server. Expect to fix small things on the first build, and note them here.

## 1. Server (once)
- Ubuntu/Debian VPS in Europe, 2 GB RAM is plenty. SSH keys only, no password login, firewall allowing 22 (or your SSH port), 80, 443 (tcp) and 443 (udp). Install Docker Engine + the compose plugin from Docker's own apt repository.
- Check it is a KVM machine (`systemd-detect-virt` prints `kvm`), then add **swap** so a memory spike slows things down instead of killing the database or a container. `free -h` first: skip this if the image already has swap. If `fallocate` fails on your filesystem, make the file with `sudo dd if=/dev/zero of=/swapfile bs=1M count=2048` instead.
  ```bash
  sudo fallocate -l 2G /swapfile
  sudo chmod 600 /swapfile
  sudo mkswap /swapfile
  sudo swapon /swapfile
  echo '/swapfile none swap sw 0 0' | sudo tee -a /etc/fstab
  free -h    # should now show 2 GB of swap
  ```
  Swap is a safety net, not extra memory: if `free -h` shows it in constant use, move to a bigger plan. Without swap (some providers refuse it) give the containers memory limits instead.
- Put both repos side by side:
  ```bash
  sudo mkdir -p /srv/kachow && cd /srv/kachow
  git clone https://github.com/christianmorkeberg/kachow-app.git
  git clone https://github.com/christianmorkeberg/kachow-web.git
  ```

## 2. Configure (once)
```bash
cd /srv/kachow/kachow-app
cp .env.example .env         # fill in everything; DB_HOST=db  DB_PORT=3306
cd docker
cp env.example .env          # SITE_ADDRESS, DB passwords (same DB_NAME/USER/PASS as above)
```
Copy the values from the old server's `.env`. `APP_ENCRYPTION_KEY` MUST be identical to the old one, or stored OAuth tokens stop working. Set `APP_BASE_URL` to the final address (and the Google/Microsoft redirect URIs in their consoles if the hostname changes).

## 3. Build and install dependencies
```bash
docker compose build
docker compose run --rm --no-deps php composer install --no-dev --optimize-autoloader
docker compose up -d db
```

## 4. Move the data
On the old server: `mysqldump --single-transaction --routines <db> | gzip > kachow.sql.gz`, copy it over, then:
```bash
gunzip -c kachow.sql.gz | docker compose exec -T db sh -c 'mariadb -u root -p"$MARIADB_ROOT_PASSWORD" "$MARIADB_DATABASE"'
```
Apply any newer file in `../migrations/` the dump lacks.

## 5. Test on a temporary hostname
Point a spare name (e.g. `new.<your-domain>`) at the VPS, set `SITE_ADDRESS` to it, then `docker compose up -d`. Open it, log in, chat, check the kiosk. Look at `docker compose logs -f php cron caddy` while you do.
Do not leave both servers running the cron jobs against the same database: the old cron entries must be stopped when the VPS goes live (or the VPS `cron` container kept stopped until the switch: `docker compose stop cron`).

## 6. Switch
1. A day earlier, lower the TTL of the domain's A/AAAA records.
2. Put the old site in maintenance (or just stop the old cron jobs), take a final dump, restore it on the VPS (step 4), `docker compose start cron`.
3. Set `SITE_ADDRESS` to the real hostname, `docker compose up -d`, change the DNS A (and AAAA) record to the VPS IP. Caddy gets the certificate automatically once DNS points here.
4. Leave MX/SPF/DKIM and other mail records alone: only the web records change. The landing site is a separate matter.
5. Update where the home gateway/Pico and OwnTracks post to only if the hostname changed.

## Daily use
- **Deploy:** `./deploy.sh` (pulls both repos, `composer install`, rebuilds). The old GitHub Actions deploy to the shared host keeps working until you retire it; when you do, change them to SSH into the VPS and run this script.
- **Migrations:** same as before, by hand: `docker compose exec -T db sh -c 'mariadb -u root -p"$MARIADB_ROOT_PASSWORD" "$MARIADB_DATABASE"' < ../migrations/<file>.sql`
- **Logs:** `docker compose logs -f php` (PHP errors), `... cron`, `... caddy`.
- **Backups:** `./backup.sh` from the host's crontab, and copy `backups/` off the server.
- **Updates:** `docker compose pull && docker compose up -d --build` now and then, plus `apt upgrade` on the host (or unattended-upgrades).
- If PHP reports it can't read `.env` or write somewhere, check ownership: the FPM user inside the container is `www-data` (uid 82).
