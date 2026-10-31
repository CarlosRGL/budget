# Self-hosting

Budget is a regular Laravel application. The simplest way to run it is the Docker image built from the `Dockerfile` at the root of the repository, next to a MariaDB or MySQL database.

## What the image runs

The `Dockerfile` has two stages:

1. **`build`**: installs the PHP dependencies (without dev packages) and builds the front end with Bun.
2. **`app`**: the image you run, based on [serversideup/php](https://serversideup.net/open-source/docker-php/) `8.5-fpm-nginx`. One container runs, under [s6-overlay](https://github.com/just-containers/s6-overlay):
    - **nginx + PHP-FPM**, listening on port **8080** (HTTP only, put a reverse proxy in front for HTTPS);
    - a **queue worker**: `php artisan queue:work --sleep=3 --tries=3 --max-time=3600` (`docker/s6/laravel-queue`);
    - the **scheduler**: `php artisan schedule:work` (`docker/s6/laravel-scheduler`). It runs the bank syncs 4 times a day (6:00, 11:00, 16:00, 21:00), the loan payments at 5:00 and a daily clean-up of expired invitations.

At start, `AUTORUN_ENABLED=true` makes the serversideup image run the database migrations and cache the Laravel configuration, routes and views. Because the configuration is cached, **restart the container after changing an environment variable**.

The image sets `TZ=Europe/Paris`; the app's own timezone is `APP_TIMEZONE` (default `Europe/Paris` too). Set both to your timezone: the sync times and the budget month follow it.

Sessions, cache and queued jobs are stored in the database (`SESSION_DRIVER=database`, `CACHE_STORE=database`, `QUEUE_CONNECTION=database`), so the container itself keeps no state. The app has no file uploads.

## Database

- **MariaDB 10.6+ or MySQL 8** is what production runs on.
- **SQLite** works too (the test suite runs on it): fine for a single household, but mount the database file on a volume.
- PostgreSQL is not tested.

## Environment variables

There is no `.env` file in the image: pass the variables to the container. `.env.example` lists them with comments. The ones that matter:

| Variable                                                                                                         | Required      | What it is                                                                                                                                                                                                                                                                                          |
| ---------------------------------------------------------------------------------------------------------------- | ------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `APP_KEY`                                                                                                        | yes           | Encryption key (sessions, two-factor secrets). Generate once with `php artisan key:generate --show` (or `echo "base64:$(openssl rand -base64 32)"`) and **never change it**: keep it with your backups.                                                                                             |
| `APP_URL`                                                                                                        | yes           | The public address, with `https://` (for example `https://budget.example.com`). Used for links in emails and for the bank redirect URL.                                                                                                                                                             |
| `APP_ENV`, `APP_DEBUG`                                                                                           | yes           | `production` and `false`.                                                                                                                                                                                                                                                                           |
| `APP_NAME`                                                                                                       | no            | Name shown in the browser title and emails.                                                                                                                                                                                                                                                         |
| `APP_LOCALE`                                                                                                     | no            | `en`, `fr` or `es`. The language for visitors who are not signed in and whose browser language is not supported, and the language of the names the app stores in the household's data (payees it creates, memos), so they are not duplicated per member. Each member then picks their own language. |
| `APP_TIMEZONE`                                                                                                   | no            | Defaults to `Europe/Paris`.                                                                                                                                                                                                                                                                         |
| `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`                               | yes           | The database (`mariadb`, `mysql` or `sqlite`).                                                                                                                                                                                                                                                      |
| `MAIL_MAILER`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME` | recommended   | Used for invitations, password resets and email changes. Without mail (`MAIL_MAILER=log`), invitations can still be shared with "Copy link", but forgotten passwords cannot be reset by email.                                                                                                      |
| `ENABLEBANKING_APP_ID`                                                                                           | for bank sync | Your EnableBanking application ID.                                                                                                                                                                                                                                                                  |
| `ENABLEBANKING_PRIVATE_KEY`                                                                                      | for bank sync | The application's private key (the `.pem` file), **base64-encoded**: `base64 < enablebanking.pem \| tr -d '\n'`. Easiest with Docker.                                                                                                                                                               |
| `ENABLEBANKING_PRIVATE_KEY_PATH`                                                                                 | for bank sync | Alternative: path to the `.pem` file (absolute, or relative to the app). Default `storage/app/private/enablebanking.pem`.                                                                                                                                                                           |
| `LOG_CHANNEL`                                                                                                    | no            | `stderr` sends the logs to `docker logs` instead of a file in the container.                                                                                                                                                                                                                        |

The other variables of `.env.example` (Redis, AWS, …) are Laravel defaults the app does not need.

## Docker Compose example

```yaml
services:
    app:
        build:
            context: .
            target: app
        restart: unless-stopped
        ports:
            - '8080:8080' # behind your reverse proxy
        environment:
            APP_NAME: Budget
            APP_ENV: production
            APP_DEBUG: 'false'
            APP_KEY: base64:change-me # php artisan key:generate --show
            APP_URL: https://budget.example.com
            APP_LOCALE: en
            APP_TIMEZONE: Europe/Paris
            TZ: Europe/Paris
            LOG_CHANNEL: stderr
            DB_CONNECTION: mariadb
            DB_HOST: db
            DB_PORT: '3306'
            DB_DATABASE: budget
            DB_USERNAME: budget
            DB_PASSWORD: change-me-too
            MAIL_MAILER: smtp
            MAIL_HOST: smtp.example.com
            MAIL_PORT: '587'
            MAIL_USERNAME: budget@example.com
            MAIL_PASSWORD: change-me
            MAIL_FROM_ADDRESS: budget@example.com
            MAIL_FROM_NAME: Budget
            ENABLEBANKING_APP_ID: ''
            ENABLEBANKING_PRIVATE_KEY: '' # base64 of the .pem file
        depends_on:
            db:
                condition: service_healthy

    db:
        image: mariadb:11
        restart: unless-stopped
        environment:
            MARIADB_DATABASE: budget
            MARIADB_USER: budget
            MARIADB_PASSWORD: change-me-too
            MARIADB_RANDOM_ROOT_PASSWORD: '1'
        volumes:
            - db-data:/var/lib/mysql
        healthcheck:
            test: ['CMD', 'healthcheck.sh', '--connect', '--innodb_initialized']
            interval: 10s
            retries: 10

volumes:
    db-data:
```

Then `docker compose up -d --build`. The migrations run when the `app` container starts.

Rather than `ENABLEBANKING_PRIVATE_KEY`, you can mount the key file read-only:

```yaml
volumes:
    - ./enablebanking.pem:/var/www/html/storage/app/private/enablebanking.pem:ro
```

The file must be readable by the `www-data` user of the container.

### Persistent data

Everything lives in the database: only the database volume (`db-data` above) needs to persist. The app container can be rebuilt or replaced at any time. If you use a key file rather than the environment variable, keep that file (and a copy of it) outside the container.

## Migrations

They run at every start of the container (`AUTORUN_ENABLED`). To run them by hand:

```bash
docker compose exec app php artisan migrate --force
```

Outside Docker: `php artisan migrate --force` after each update.

## The first member

Open the site: the first person to register creates the household and becomes its owner. Registration then closes; the others join through an invitation sent from the household settings.

From the command line instead (for example before opening the site to anyone):

```bash
docker compose exec app php artisan budget:install
```

It asks for a name, an email and a password (or takes `--name`, `--email`, `--password` and `--household`).

## HTTPS and reverse proxy

The container only speaks HTTP on port 8080. Put it behind a reverse proxy that terminates TLS (Caddy, Traefik, nginx…) and forwards the usual `X-Forwarded-*` headers. The app trusts the forwarded headers from any proxy (`trustProxies(at: '*')` in `bootstrap/app.php`), so:

- set `APP_URL` to the public `https://` address;
- do not expose port 8080 directly to the internet, only to the proxy.

HTTPS is required in practice: banks redirect back to `APP_URL/bank/callback`, passkeys need a secure context, and the app can be installed as a PWA only over HTTPS.

## Bank sync

See [enablebanking.md](enablebanking.md). Once the instance runs, **Bank connections → Set up bank sync** shows the exact redirect URL of your instance and a checklist of what is configured.

## Backups

Back up the database and the secrets; nothing else is needed.

```bash
docker compose exec db sh -c 'mariadb-dump -u"$MARIADB_USER" -p"$MARIADB_PASSWORD" --single-transaction "$MARIADB_DATABASE"' > budget-$(date +%F).sql
```

Also keep, somewhere safe and outside the server:

- `APP_KEY` (without it, two-factor authentication secrets cannot be decrypted);
- the EnableBanking private key (`.pem`) and application ID.

To restore: create an empty database, import the dump (`mariadb -u… budget < budget.sql`), start the app with the same `APP_KEY`.

## Updates

```bash
git pull
docker compose up -d --build
```

The new image runs the pending migrations when it starts. Back up the database first. Outside Docker: `git pull`, `composer install --no-dev`, `bun install && bun run build`, `php artisan migrate --force`, `php artisan optimize`, then restart the queue worker (`php artisan queue:restart`).

## Without Docker

Any PHP 8.4+ host works: point the web server at `public/`, build the front end (`bun install && bun run build`), run `php artisan migrate --force` and `php artisan optimize`, and keep two processes running:

```bash
php artisan queue:work --tries=3   # bank syncs and other jobs
php artisan schedule:work          # or a cron entry: * * * * * php /path/to/artisan schedule:run
```

Without the scheduler, the automatic bank syncs do not happen ("Sync now" still works).

## PaaS (Dokploy, Coolify, …)

Platforms that build from a Dockerfile work as is:

- build type **Dockerfile**, target stage **`app`**, container port **8080**;
- a MariaDB or MySQL database from the platform, its credentials in the `DB_*` variables;
- all the variables above in the platform's environment settings, with `ENABLEBANKING_PRIVATE_KEY` as base64 (no file to mount);
- the platform's domain and HTTPS settings for the public address, the same address in `APP_URL`;
- `php artisan budget:install` from the platform's container terminal, if you do not want to register through the site.

No volume is needed on the app container. Redeploying runs the migrations.
