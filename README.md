# Proxy Manager

Dockerized Laravel 12 and Vue 3 application for managing proxy servers and asynchronous availability checks.

## Services

- Backend: http://localhost:8088
- Vite dev server: http://localhost:5173
- MySQL: localhost:3306

Published ports bind to `127.0.0.1` for local development. `APP_HTTP_PORT`, `VITE_DEV_HOST`, and `VITE_DEV_PORT` can be changed in `.env` if another local project has already annexed the port with imperial confidence. Add authentication and review the port bindings before exposing the service outside a trusted machine.

## Setup

```bash
docker compose up -d
```

The one-shot `setup` service installs Composer dependencies, creates `.env` from `.env.example` when it is missing, prepares Laravel storage directories, generates an application key when needed, clears cached bootstrap state, runs migrations, and writes `storage/framework/setup-complete` before long-lived PHP services start. The `ready` service waits for nginx and Vite health checks, so `docker compose up -d` returns only after the app is ready to open.

For a fresh database reset:

```bash
docker compose run --rm php-fpm php artisan migrate:fresh
```

## Queue And Scheduler

The `queue` service runs:

```bash
php artisan queue:work database --queue=proxy-checks,default --sleep=1 --tries=1 --timeout=30
```

The `scheduler` service runs:

```bash
php artisan schedule:work
```

Production still needs one system cron if scheduler is not run as a long-lived container:

```bash
* * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1
```

## Proxy Check Configuration

```dotenv
PROXY_CHECK_URL=https://example.com/
PROXY_CHECK_INTERVAL_MINUTES=5
PROXY_CHECK_TIMEOUT_SECONDS=8
PROXY_CHECK_CONNECT_TIMEOUT_SECONDS=3
PROXY_CHECK_SUCCESS_CODES=200,204,301,302
PROXY_CHECK_STALE_AFTER_SECONDS=120
PROXY_CHECK_QUEUE=proxy-checks
PROXY_CHECK_UNIQUE_FOR_SECONDS=300
```

## Verification

```bash
docker compose run --rm php-fpm php artisan test
docker compose run --rm node-vite npm run build
docker compose run --rm node-vite npm run lint
docker compose run --rm node-vite npm run test
curl -s http://127.0.0.1:8088/api/v1/health
```

## Status Rules

New and manually refreshed proxies are queued for asynchronous checking. The queue job calls the configured check URL through the proxy and writes `online` or `offline` results to `proxy_checks`. Stale `checking` rows are marked `offline` by the scheduled dispatcher.
