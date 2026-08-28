# Aureus ERP Docker stack

The root `docker-compose.yml` builds the current checkout into separate PHP-FPM
and Nginx images. MySQL, the queue worker, and the scheduler run as independent
services. MySQL data and Laravel storage use named volumes.

Redis is intentionally not included: the repository uses Laravel's
database-backed cache, queue, and session drivers. Add Redis only after changing
and validating those application settings.

## First setup on Windows

```powershell
powershell -ExecutionPolicy Bypass -File .\docker\setup-env.ps1
docker compose build
docker compose up -d
```

Before starting, review `.env`. `APP_KEY`, `DB_PASSWORD`, and
`MYSQL_ROOT_PASSWORD` must be non-empty and secret. For local HTTP use:

```dotenv
APP_URL=http://localhost:8080
ASSET_URL=
FORCE_HTTPS=false
SESSION_SECURE_COOKIE=false
```

For HTTPS behind an AWS load balancer or reverse proxy, use the public HTTPS URL,
set `FORCE_HTTPS=true` and `SESSION_SECURE_COOKIE=true`, and forward the standard
`X-Forwarded-*` headers. The application trusts proxy headers; no domain or IP is baked into the images.

For a new empty database, run the migrations and required reference/permission
seeders explicitly. These commands are never run automatically at container
startup:

```powershell
docker compose exec app php artisan migrate --force
docker compose exec app php artisan db:seed --force
docker compose exec app php artisan accounting:sync-permissions
docker compose exec app php artisan hr:sync-permissions
docker compose exec app php artisan erp:doctor
```

The root seeder contains only security, support/reference, plugin-state, and
permission configuration. It does not create local companies, journals,
transactions, imported files, or demo business records. Never seed after
restoring an existing production database.

Create the first company and administrator through the normal installation or
onboarding workflow. Until then, `php artisan erp:doctor` will correctly report
that no company or login user exists.

The application is available at `APP_URL` in `.env`, which the setup script sets
to `http://localhost:8080` only when the template value is still unchanged.

## Common commands

```powershell
docker compose exec app php artisan about
docker compose exec app php artisan migrate:status
docker compose exec app php artisan erp:doctor
docker compose exec app composer --version
docker compose logs -f app nginx queue scheduler mysql
```

Rebuild and recreate the application services after source changes:

```powershell
docker compose build
docker compose up -d --force-recreate
```

Stop without deleting data:

```powershell
docker compose stop
```

`docker compose down` removes containers and networks but retains the named
volumes. Do not add `--volumes` unless permanent database and uploaded-file
deletion is explicitly intended.

## Safe upgrade and restart

Take a database backup and a storage-volume backup before deploying a new image.
Then build, stop traffic, apply forward-only migrations explicitly, and restart:

```powershell
docker compose build
docker compose stop nginx queue scheduler
docker compose up -d mysql app
docker compose exec app php artisan migrate --force
docker compose up -d nginx queue scheduler
docker compose exec app php artisan erp:doctor
```

Container startup never runs migrations, seeders, installers, database wipes,
or permission synchronization. Rebuilding or recreating services therefore does
not alter existing database records.

## Existing database import

Stop application traffic and take a backup before importing. Copy the SQL dump
into MySQL and restore it explicitly:

```powershell
docker compose stop nginx queue scheduler
docker compose cp .\existing.sql mysql:/tmp/aureuserp-import.sql
docker compose exec mysql sh -lc 'exec mysql -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE" < /tmp/aureuserp-import.sql'
docker compose exec mysql rm -f /tmp/aureuserp-import.sql
docker compose start nginx queue scheduler
```

Import only into the intended database. Do not run the fresh seed after
restoring an existing ERP database.

## Database backup

```powershell
docker compose exec mysql sh -lc 'exec mysqldump -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" --single-transaction --routines --triggers "$MYSQL_DATABASE" > /tmp/aureuserp-backup.sql'
docker compose cp mysql:/tmp/aureuserp-backup.sql .\aureuserp-backup.sql
docker compose exec mysql rm -f /tmp/aureuserp-backup.sql
```

Root-level SQL files are ignored by Git, but backups must still be moved to a
secure backup location.

Restore only during a controlled outage and only after verifying the target
database name. The restore command above does not drop or create the database.

## Uploaded-file backup

Back up the persistent Laravel storage volume separately from MySQL:

```powershell
docker run --rm -v aureuserp-storage:/source:ro -v ${PWD}:/backup alpine sh -c "cd /source && tar czf /backup/aureuserp-storage.tar.gz ."
```

Restore into an empty replacement volume only after retaining the existing
volume as a rollback checkpoint.

## AWS deployment notes

- Run the Compose stack on an EC2 host with Docker Compose, or adapt the same
  immutable app/web images to ECS while keeping MySQL and storage persistent.
- Do not expose MySQL publicly; the Compose host binding is loopback-only.
- Terminate TLS at an ALB, CloudFront/reverse proxy, or managed endpoint.
- Keep `.env` and database credentials outside the image and repository; AWS
  Secrets Manager or SSM Parameter Store are recommended.
- Back up both MySQL and `aureuserp-storage` before upgrades.
- An external managed database requires an environment-specific Compose override
  that removes the bundled `mysql` dependency and supplies the external DB host;
  do not edit production data volumes in place.

## Health and troubleshooting

```powershell
docker compose ps
docker compose exec app php artisan migrate:status
docker compose exec app php artisan queue:monitor database:default --max=100
docker compose logs --tail=200 app nginx queue scheduler mysql
```

The app service checks PHP-FPM directly. Nginx checks Laravel's `/up` endpoint,
so a healthy web service proves Nginx can reach PHP-FPM and Laravel can boot.
MySQL has its own authenticated readiness check.

If Docker reports a healthy database but the app is unhealthy, verify `APP_KEY`,
database credentials, writable storage, and pending migrations. Do not fix a
startup problem by deleting volumes.
