# Proxy Manager Design

Date: 2026-05-31
Status: approved for planning

## Context

The project starts from an intentionally empty directory that contains only the original technical specification, `proxy-manager-technical-spec.md`. The implementation will create a new Laravel 12 and Vue 3 application from scratch.

The application is a single-user internal Proxy Manager. It must provide CRUD for proxy servers, asynchronous status checks, scheduled refresh every five minutes, manual refresh actions, a small but useful Vue interface, and a backend structure that does not hide business logic inside controllers or models like a guilty footnote.

The project must run in Docker. The chosen infrastructure is a custom `docker-compose` setup rather than Laravel Sail.

## Confirmed Decisions

- Build a new Laravel 12 backend and Vue 3 frontend in the current workspace.
- Use PHP 8.2+, Laravel 12, Vue 3 Composition API, MySQL, REST JSON, Laravel Queue, Laravel Scheduler, and Laravel HTTP Client.
- Run services through custom Docker Compose.
- Expose backend/nginx on `http://localhost:8080`.
- Expose Vite dev server on `http://localhost:5173`.
- Keep queue worker and scheduler as separate containers.
- Use the database queue driver by default.
- Use database-backed cache locks by default.
- Use TypeScript for Vue code.
- Configure Vitest for focused frontend tests.
- Do not add authentication in MVP, but keep API routes ready for later `auth:sanctum` middleware.
- Do not accept arbitrary check URLs from users. The check URL is configured through env/config.

## Docker Architecture

The Docker setup will contain these services:

- `nginx`: public backend entrypoint, serves Laravel through PHP-FPM.
- `php-fpm`: Laravel runtime for HTTP requests and Artisan commands.
- `mysql`: application database.
- `node-vite`: frontend development server with HMR on `localhost:5173`.
- `queue`: runs `php artisan queue:work database --queue=proxy-checks,default`.
- `scheduler`: runs Laravel Scheduler through `php artisan schedule:work`.

The backend and frontend use separate local addresses during development. Vite will proxy API requests to the backend so the frontend can call `/api` without turning CORS into a ceremonial bonfire.

## Backend Architecture

The backend is a modular Laravel monolith with explicit boundaries:

- HTTP layer: controllers, form requests, API resources.
- Application layer: actions, services, jobs, scheduler entrypoints.
- Domain layer: models, enums, value objects, result DTOs.
- Infrastructure layer: proxy HTTP checker, persistence, queue, cache locks.

Controllers stay thin. They validate through Form Requests, call actions/services, and return Resources or simple JSON responses.

Business logic goes into focused actions:

- `CreateProxyAction`
- `UpdateProxyAction`
- `DeleteProxyAction`
- `ScheduleProxyCheckAction`
- `ScheduleAllProxyChecksAction`
- `ApplyProxyCheckResultAction`

Proxy checking is isolated behind `ProxyCheckerInterface`. The first implementation is `LaravelHttpProxyChecker`, which uses Laravel HTTP Client and Guzzle proxy options.

## Domain Model

`ProxyServer` stores proxy configuration and the latest known status.

Important fields:

- `scheme`: enum-backed string, one of `http`, `https`, `socks4`, `socks5`.
- `host`: IPv4, IPv6, or domain without protocol, path, query, or credentials.
- `port`: 1..65535.
- `username`: nullable.
- `password`: nullable encrypted cast.
- `identity_hash`: SHA-256 of normalized scheme, host, port, and username.
- `status`: enum-backed string, one of `unknown`, `checking`, `online`, `offline`.
- `checking_started_at`, `last_checked_at`, `last_success_at`, `response_time_ms`, `failure_reason`.

`ProxyCheck` stores check history and belongs to `ProxyServer`. Checks are deleted by cascade when the proxy is deleted.

The password is never returned by API resources, never included in display addresses, and never manually placed in jobs or logs.

## API Design

Base path: `/api/v1`.

Endpoints:

- `GET /proxies`: paginated list with search, status filter, scheme filter, sorting, and direction.
- `GET /proxies/{id}`: single proxy.
- `POST /proxies`: create proxy and enqueue an initial check.
- `PATCH /proxies/{id}`: update proxy; preserve password if omitted, clear password if explicitly `null`.
- `DELETE /proxies/{id}`: physically delete proxy and cascade checks.
- `POST /proxies/{id}/check`: enqueue manual check for one proxy.
- `POST /proxies/check`: enqueue manual checks for all candidates.
- `GET /proxies/{id}/checks`: paginated check history.

HTTP statuses:

- `200`: list, view, update.
- `201`: create.
- `202`: asynchronous check accepted.
- `204`: delete.
- `409`: duplicate proxy identity.
- `422`: validation errors.
- `404`: missing resource.
- `500`: unexpected error without production stack trace.

## Proxy Check Flow

Creating or updating a proxy dispatches a check when network parameters or credentials change. Manual refresh does the same. HTTP checks are never performed synchronously from controllers.

Flow:

1. UI calls REST API.
2. Controller invokes an action.
3. Action stores changes and dispatches `CheckProxyStatusJob(proxy_id)`.
4. Job reloads the current `ProxyServer` from the database.
5. Job marks the proxy as `checking`.
6. `LaravelHttpProxyChecker` builds a proxy URI through `ProxyUriFactory`.
7. Checker calls the configured `PROXY_CHECK_URL`.
8. Checker returns immutable `ProxyCheckResult`.
9. `ApplyProxyCheckResultAction` updates `proxy_servers` and inserts `proxy_checks` in one transaction.

The scheduler dispatches `DispatchDueProxyChecksJob` every five minutes. This job marks stale `checking` rows as `offline` with `stale_check`, finds due proxies, and dispatches individual check jobs. It does not run HTTP checks itself.

## Concurrency Design

`CheckProxyStatusJob` implements `ShouldQueue` and `ShouldBeUnique`.

- Unique key: `proxy:{id}`.
- Unique duration: `PROXY_CHECK_UNIQUE_FOR_SECONDS`.
- Queue: `proxy-checks`.
- Job payload: only `proxy_id`.
- Tries: one by default, two at most.
- Timeout: greater than HTTP timeout and lower than queue `retry_after`.

Scheduler uses:

- `withoutOverlapping(10)`.
- `onOneServer()`.
- Database cache locks by default.

Stale checks are detected through `checking_started_at < now() - PROXY_CHECK_STALE_AFTER_SECONDS`.

## Error Handling And Security

Validation is handled through Laravel Form Requests and a custom `ProxyHostRule`.

Proxy check errors are normalized into enum codes:

- `timeout`
- `connection_failed`
- `proxy_auth_failed`
- `bad_status`
- `ssl_error`
- `dns_error`
- `stale_check`
- `unexpected_error`

All error messages stored in `failure_reason` and `proxy_checks.error_message` are sanitized. The application must not log passwords, full credential-bearing proxy URIs, raw exception traces, or request headers containing secrets.

Manual refresh endpoints should be throttled. The API group can use `throttle:60,1`, while `POST /proxies/check` should use a stricter limit such as `throttle:10,1`.

## Maintenance

The application will include a `proxy-checks:prune` Artisan command. It removes old check history according to the MVP rule: delete checks older than 30 days. The scheduler runs this command daily with `onOneServer()`.

This keeps the history table useful without letting it slowly become a museum of stale latency.

## Frontend Design

The frontend uses Vue 3 Single-File Components, Composition API, and TypeScript.

Main files:

- `resources/js/api/http.ts`: base API client, JSON headers, error normalization, abort support.
- `resources/js/api/proxies.ts`: proxy REST methods.
- `resources/js/composables/useProxies.ts`: orchestration for list loading, CRUD, checking, history, polling.
- `resources/js/composables/useProxyFilters.ts`: filter and pagination state.
- `resources/js/components/proxies/ProxyTable.vue`
- `resources/js/components/proxies/ProxyFormModal.vue`
- `resources/js/components/proxies/ProxyStatusBadge.vue`
- `resources/js/components/proxies/ProxyActions.vue`
- `resources/js/components/proxies/ProxyChecksDrawer.vue`
- `resources/js/components/ui/ConfirmDialog.vue`
- `resources/js/components/ui/Pagination.vue`
- `resources/js/components/ui/EmptyState.vue`
- `resources/js/components/ui/LoadingButton.vue`
- `resources/js/pages/ProxiesPage.vue`

The page contains a title, add button, refresh-all button, status filter, scheme filter, search, table, pagination, create/edit modal, delete confirmation, and check history drawer.

The edit form never displays the saved password. An empty password field means "do not change password"; a separate clear-password action sends `password: null`.

## Frontend Polling

Global list refresh runs every 30 seconds while the page is open.

After checking one proxy, the UI refreshes after one second and then every three seconds until the proxy leaves `checking`, with a maximum of 30 seconds.

Timers and pending requests are cleaned up when the page unmounts. Frontend polling only reflects backend state; it does not replace scheduler-driven checks.

## Testing Strategy

Backend feature tests:

- Paginated list.
- Create proxy and mask password.
- Reject duplicate scheme, host, port, username.
- Update proxy.
- Preserve password when omitted.
- Clear password when `password: null`.
- Delete proxy and cascade checks.
- Enqueue one proxy check.
- Enqueue all proxy checks.
- Return check history.

Backend unit tests:

- `ProxyUriFactory` builds `http`, `https`, `socks4`, and `socks5h` URIs.
- Credentials are rawurlencoded.
- IPv6 hosts are wrapped in brackets for proxy URIs.
- `LaravelHttpProxyChecker` returns online for configured success statuses.
- `LaravelHttpProxyChecker` returns offline on timeout.
- `ApplyProxyCheckResultAction` updates proxy and creates history in one transaction.
- `DispatchDueProxyChecksJob` selects due proxies and skips fresh `checking`.
- Stale `checking` rows become offline.

Frontend tests use Vitest for focused composable and component coverage:

- `useProxies` loading and error states.
- `ProxyStatusBadge` for all statuses.
- `ProxyFormModal` create payload.
- Backend validation errors near fields.
- Polling after `check(id)`.

Verification commands:

- `docker compose build`
- `docker compose up -d`
- `docker compose exec php-fpm php artisan test`
- `docker compose exec node-vite npm run build`
- `docker compose exec node-vite npm run lint`
- `docker compose exec node-vite npm run test`

Pint and frontend linting are part of the verification path.

## Implementation Boundaries

The MVP intentionally excludes:

- authentication and roles;
- multi-tenancy;
- import/export;
- WebSocket or SSE;
- arbitrary user-provided check URLs;
- geolocation, blacklist scoring, IP reputation;
- protocols other than `http`, `https`, `socks4`, `socks5`;
- soft deletes.

These exclusions keep the first implementation focused. A small proxy manager does not need to enter the room dressed as a distributed platform.
