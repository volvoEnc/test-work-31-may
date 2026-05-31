# Техническое задание: Proxy Manager

Версия: 1.0  
Дата: 2026-05-31  
Цель документа: дать Codex достаточно точный контракт для реализации небольшого, но не игрушечного приложения управления прокси-серверами на PHP 8.2+, Laravel 12, Vue 3 Composition API, MySQL и REST API.

---

## 1. Краткое описание

Нужно разработать веб-приложение для управления списком прокси-серверов:

- добавление прокси;
- редактирование прокси;
- удаление прокси;
- просмотр статуса работоспособности;
- автоматическое обновление статуса каждые 5 минут;
- ручной запуск проверки статуса.

Приложение должно быть простым в UX, но архитектурно аккуратным: контроллеры тонкие, бизнес-логика вынесена в сервисы, проверки прокси выполняются через очередь, статусы защищены от гонок, пароли не светятся ни в API, ни в логах. Иначе это будет не Proxy Manager, а маленький театр хрупкости 🎭.

---

## 2. Стек и ограничения

### 2.1. Обязательный стек

| Слой | Технология |
|---|---|
| Backend | PHP 8.2+, Laravel 12 |
| Frontend | Vue 3, Composition API, Single-File Components |
| DB | MySQL |
| API | REST JSON |
| Очереди | Laravel Queue, по умолчанию `database` driver |
| Планировщик | Laravel Scheduler |
| HTTP-клиент | Laravel HTTP Client поверх Guzzle/cURL |

### 2.2. Принятые продуктовые допущения

1. MVP является однопользовательским внутренним приложением. Авторизация не входит в обязательный объём, потому что она не заявлена в исходной задаче.
2. Код должен быть подготовлен так, чтобы API-роуты можно было позже закрыть middleware `auth:sanctum` без переписывания архитектуры.
3. Прокси могут содержать логин и пароль. Пароль хранится зашифрованным и никогда не возвращается в API.
4. Проверка работоспособности выполняется через запрос к одному конфигурируемому URL, а не через пользовательский URL из формы. Это снижает риск SSRF и убирает зоопарк ложных проверок.
5. Удаление в MVP физическое. `SoftDeletes` не использовать, чтобы не усложнять уникальность прокси. История проверок удаляется каскадно.

---

## 3. Цели и нецели

### 3.1. Цели

- CRUD для прокси.
- Надёжная проверка статуса через очередь.
- Автоматическое планирование проверок каждые 5 минут.
- Ручная проверка одного прокси и всех прокси.
- Понятный UI со статусами, фильтрами, пагинацией и действиями.
- Тестируемая архитектура без бизнес-логики в контроллерах и Vue-компонентах.
- Конфигурируемые таймауты и URL проверки.

### 3.2. Нецели MVP

- Многоарендность и роли пользователей.
- Массовый импорт из файлов.
- Экспорт прокси.
- WebSocket/SSE для realtime-статусов.
- Геолокация/IP reputation/blacklist scoring.
- Поддержка нестандартных протоколов кроме `http`, `https`, `socks4`, `socks5`.
- Проверка произвольных пользовательских URL.

---

## 4. Архитектурный подход

Использовать **модульный Laravel-монолит** с явным разделением слоёв. Микросервисы здесь не нужны: один список прокси не должен рожать флот контейнеров, как будто мы запускаем космодром.

### 4.1. Слои backend

```text
HTTP Layer
  Controllers
  Form Requests
  API Resources

Application Layer
  Actions / Services
  Jobs
  Scheduler entrypoint

Domain Layer
  Models
  Enums
  Value Objects
  Result DTOs

Infrastructure Layer
  Proxy HTTP checker implementation
  MySQL persistence
  Queue / cache locks
```

### 4.2. Основные правила

1. **Контроллеры** принимают запрос, вызывают action/service, возвращают resource/response.
2. **Form Request** отвечает за валидацию и подготовку данных.
3. **API Resource** отвечает за формат JSON и маскировку чувствительных данных.
4. **Job** отвечает за оркестрацию фоновой проверки, но не содержит низкоуровневый HTTP-код.
5. **ProxyChecker service** выполняет фактический запрос через прокси.
6. **Status service** применяет результат проверки к БД в транзакции.
7. **Enum** используется для статусов и протоколов, чтобы строки не расползались по проекту.
8. **Очередь** используется и для автоматических, и для ручных проверок. Проверка в controller synchronously запрещена.

---

## 5. Высокоуровневая схема

```mermaid
flowchart TD
    UI[Vue 3 SPA] -->|REST JSON| API[Laravel API]
    API --> REQ[Form Requests]
    API --> RES[API Resources]
    API --> ACT[Application Actions]
    ACT --> DB[(MySQL)]
    ACT --> Q[Queue: proxy-checks]
    SCHED[Laravel Scheduler every 5 min] --> DISPATCH[DispatchDueProxyChecksJob]
    DISPATCH --> Q
    Q --> JOB[CheckProxyStatusJob]
    JOB --> CHECKER[ProxyCheckerInterface]
    CHECKER --> TARGET[Configured check URL]
    JOB --> DB
```

---

## 6. Доменная модель

### 6.1. Сущность `ProxyServer`

Назначение: хранит настройки прокси и последний известный статус.

Поля:

| Поле | Тип | Обяз. | Описание |
|---|---:|:---:|---|
| `id` | BIGINT UNSIGNED | да | PK |
| `name` | VARCHAR(120), nullable | нет | Пользовательское имя |
| `scheme` | VARCHAR(10) | да | `http`, `https`, `socks4`, `socks5` |
| `host` | VARCHAR(255) | да | IPv4, IPv6 или домен без протокола |
| `port` | UNSIGNED SMALLINT | да | 1..65535 |
| `username` | VARCHAR(255), nullable | нет | Логин прокси |
| `password` | TEXT, nullable | нет | Зашифрованный пароль |
| `identity_hash` | CHAR(64) | да | SHA-256 для уникальности |
| `status` | VARCHAR(20) | да | `unknown`, `checking`, `online`, `offline` |
| `checking_started_at` | TIMESTAMP, nullable | нет | Когда началась текущая проверка |
| `last_checked_at` | TIMESTAMP, nullable | нет | Последняя завершённая проверка |
| `last_success_at` | TIMESTAMP, nullable | нет | Последняя успешная проверка |
| `response_time_ms` | INT UNSIGNED, nullable | нет | Последний latency |
| `failure_reason` | TEXT, nullable | нет | Последняя причина ошибки, без секретов |
| `created_at` | TIMESTAMP | да | Laravel timestamps |
| `updated_at` | TIMESTAMP | да | Laravel timestamps |

Индексы:

```text
PRIMARY KEY (id)
UNIQUE KEY proxy_servers_identity_hash_unique (identity_hash)
INDEX proxy_servers_status_last_checked_idx (status, last_checked_at)
INDEX proxy_servers_host_idx (host)
INDEX proxy_servers_scheme_idx (scheme)
```

`identity_hash` считать от нормализованной строки:

```text
lowercase(scheme) + '|' + lowercase(host) + '|' + port + '|' + lowercase(username or '')
```

Пароль в hash не включать: смена пароля не должна создавать новую идентичность прокси.

### 6.2. Сущность `ProxyCheck`

Назначение: хранит историю проверок и помогает объяснять, почему статус стал `offline`.

Поля:

| Поле | Тип | Обяз. | Описание |
|---|---:|:---:|---|
| `id` | BIGINT UNSIGNED | да | PK |
| `proxy_server_id` | BIGINT UNSIGNED | да | FK на `proxy_servers.id`, cascade delete |
| `source` | VARCHAR(20) | да | `auto`, `manual` |
| `status` | VARCHAR(20) | да | `online`, `offline` |
| `started_at` | TIMESTAMP | да | Старт проверки |
| `finished_at` | TIMESTAMP | да | Конец проверки |
| `response_time_ms` | INT UNSIGNED, nullable | нет | Время ответа |
| `http_status` | SMALLINT UNSIGNED, nullable | нет | HTTP-код целевого URL |
| `error_code` | VARCHAR(64), nullable | нет | Машинный код ошибки |
| `error_message` | TEXT, nullable | нет | Безопасное сообщение |
| `created_at` | TIMESTAMP | да | Laravel timestamps |
| `updated_at` | TIMESTAMP | да | Laravel timestamps |

Индексы:

```text
INDEX proxy_checks_proxy_created_idx (proxy_server_id, created_at)
INDEX proxy_checks_status_idx (status)
INDEX proxy_checks_source_idx (source)
```

### 6.3. Enum-значения

`ProxyScheme`:

```text
http
https
socks4
socks5
```

`ProxyStatus`:

```text
unknown   // ещё не проверяли или статус сброшен после изменения настроек
checking  // проверка поставлена в очередь или выполняется
online    // последняя проверка успешна
offline   // последняя проверка неуспешна
```

`ProxyCheckSource`:

```text
auto
manual
```

`ProxyCheckErrorCode`:

```text
timeout
connection_failed
proxy_auth_failed
bad_status
ssl_error
dns_error
stale_check
unexpected_error
```

---

## 7. Миграции

Создать миграции:

```bash
php artisan make:migration create_proxy_servers_table
php artisan make:migration create_proxy_checks_table
```

Схема должна использовать Laravel migrations. Статусы и схемы хранить как `string`, а не MySQL `ENUM`, чтобы изменение допустимых значений не превращалось в миграционный квест с фонариком.

Пример структуры `proxy_servers`:

```php
Schema::create('proxy_servers', function (Blueprint $table) {
    $table->id();
    $table->string('name', 120)->nullable();
    $table->string('scheme', 10);
    $table->string('host', 255);
    $table->unsignedSmallInteger('port');
    $table->string('username', 255)->nullable();
    $table->text('password')->nullable();
    $table->char('identity_hash', 64)->unique();
    $table->string('status', 20)->default('unknown');
    $table->timestamp('checking_started_at')->nullable();
    $table->timestamp('last_checked_at')->nullable();
    $table->timestamp('last_success_at')->nullable();
    $table->unsignedInteger('response_time_ms')->nullable();
    $table->text('failure_reason')->nullable();
    $table->timestamps();

    $table->index(['status', 'last_checked_at']);
    $table->index('host');
    $table->index('scheme');
});
```

Пример структуры `proxy_checks`:

```php
Schema::create('proxy_checks', function (Blueprint $table) {
    $table->id();
    $table->foreignId('proxy_server_id')
        ->constrained('proxy_servers')
        ->cascadeOnDelete();
    $table->string('source', 20);
    $table->string('status', 20);
    $table->timestamp('started_at');
    $table->timestamp('finished_at');
    $table->unsignedInteger('response_time_ms')->nullable();
    $table->unsignedSmallInteger('http_status')->nullable();
    $table->string('error_code', 64)->nullable();
    $table->text('error_message')->nullable();
    $table->timestamps();

    $table->index(['proxy_server_id', 'created_at']);
    $table->index('status');
    $table->index('source');
});
```

Также подготовить таблицы очередей/cache при использовании database drivers:

```bash
php artisan queue:table
php artisan cache:table
php artisan migrate
```

---

## 8. Backend-структура файлов

Рекомендуемая структура:

```text
app/
  Actions/
    Proxies/
      CreateProxyAction.php
      UpdateProxyAction.php
      DeleteProxyAction.php
      ScheduleProxyCheckAction.php
      ScheduleAllProxyChecksAction.php
      ApplyProxyCheckResultAction.php
  Data/
    ProxyCheckResult.php
  Enums/
    ProxyScheme.php
    ProxyStatus.php
    ProxyCheckSource.php
    ProxyCheckErrorCode.php
  Http/
    Controllers/
      Api/
        V1/
          ProxyController.php
          ProxyCheckController.php
    Requests/
      Proxy/
        StoreProxyRequest.php
        UpdateProxyRequest.php
        IndexProxyRequest.php
    Resources/
      ProxyResource.php
      ProxyCheckResource.php
  Jobs/
    DispatchDueProxyChecksJob.php
    CheckProxyStatusJob.php
  Models/
    ProxyServer.php
    ProxyCheck.php
  Rules/
    ProxyHostRule.php
  Services/
    ProxyChecker/
      ProxyCheckerInterface.php
      LaravelHttpProxyChecker.php
      ProxyUriFactory.php
config/
  proxy-manager.php
routes/
  api.php
```

---

## 9. Backend-компоненты

### 9.1. Model: `ProxyServer`

Требования:

- Использовать `$fillable` или guarded-подход явно. Не оставлять массовое присваивание на самотёк.
- Использовать casts:

```php
protected function casts(): array
{
    return [
        'scheme' => ProxyScheme::class,
        'status' => ProxyStatus::class,
        'password' => 'encrypted',
        'checking_started_at' => 'immutable_datetime',
        'last_checked_at' => 'immutable_datetime',
        'last_success_at' => 'immutable_datetime',
    ];
}
```

- Не добавлять `password` в API resource.
- Добавить accessor `has_credentials` или считать его в resource.
- Добавить метод `displayAddress()` без пароля:

```text
scheme://username@host:port
```

Если username пустой:

```text
scheme://host:port
```

### 9.2. Model: `ProxyCheck`

Требования:

- `belongsTo(ProxyServer::class)`.
- Casts для `source`, `status`, `started_at`, `finished_at`.
- Не хранить секреты в `error_message`.

### 9.3. Form Requests

`StoreProxyRequest`:

```text
name: nullable|string|max:120
scheme: required|string|in:http,https,socks4,socks5
host: required|string|max:255|ProxyHostRule
port: required|integer|min:1|max:65535
username: nullable|string|max:255
password: nullable|string|max:2048
```

`UpdateProxyRequest`:

```text
name: sometimes|nullable|string|max:120
scheme: sometimes|required|string|in:http,https,socks4,socks5
host: sometimes|required|string|max:255|ProxyHostRule
port: sometimes|required|integer|min:1|max:65535
username: sometimes|nullable|string|max:255
password: sometimes|nullable|string|max:2048
```

Особые правила:

- `host` не должен содержать `://`.
- `host` не должен содержать `/`, query string или credentials.
- IPv6 допускается в чистом виде без квадратных скобок в БД. В display/API можно показывать с квадратными скобками при необходимости.
- Если при `PATCH` поле `password` отсутствует, пароль не менять.
- Если при `PATCH` `password: null`, пароль очистить.
- После изменения `scheme`, `host`, `port`, `username` или `password` сбросить статус в `unknown` и поставить проверку в очередь.
- При дубликате `identity_hash` вернуть `409 Conflict` или `422` с понятным сообщением. Предпочтительно `409`.

### 9.4. API Resources

`ProxyResource` должен возвращать:

```json
{
  "id": 1,
  "name": "Office proxy",
  "scheme": "http",
  "host": "203.0.113.10",
  "port": 8080,
  "username": "user1",
  "has_credentials": true,
  "display_address": "http://user1@203.0.113.10:8080",
  "status": "online",
  "checking_started_at": null,
  "last_checked_at": "2026-05-31T09:20:00Z",
  "last_success_at": "2026-05-31T09:20:00Z",
  "response_time_ms": 231,
  "failure_reason": null,
  "created_at": "2026-05-31T09:00:00Z",
  "updated_at": "2026-05-31T09:20:00Z"
}
```

Запрещено возвращать:

```text
password
identity_hash
raw exception traces
```

### 9.5. Service: `ProxyCheckerInterface`

Контракт:

```php
interface ProxyCheckerInterface
{
    public function check(ProxyServer $proxy): ProxyCheckResult;
}
```

`ProxyCheckResult` должен быть immutable DTO:

```php
final readonly class ProxyCheckResult
{
    public function __construct(
        public ProxyStatus $status,
        public CarbonImmutable $startedAt,
        public CarbonImmutable $finishedAt,
        public ?int $responseTimeMs,
        public ?int $httpStatus,
        public ?ProxyCheckErrorCode $errorCode,
        public ?string $errorMessage,
    ) {}
}
```

`status` в result может быть только `online` или `offline`.

### 9.6. Service: `LaravelHttpProxyChecker`

Алгоритм:

1. Получить `check_url` из `config('proxy-manager.check.url')`.
2. Построить proxy URI через `ProxyUriFactory`.
3. Выполнить `GET` через Laravel HTTP Client:

```php
$response = Http::connectTimeout($connectTimeout)
    ->timeout($timeout)
    ->withOptions([
        'proxy' => $proxyUri,
        'allow_redirects' => false,
    ])
    ->get($checkUrl);
```

4. Если HTTP-код входит в `success_status_codes`, статус `online`.
5. Если код не входит, статус `offline`, `error_code = bad_status`.
6. При timeout/connection/SSL/DNS/proxy auth ошибках вернуть `offline` с безопасным `error_code`.
7. Измерять `response_time_ms` через `hrtime(true)`, не через `microtime`, чтобы меньше ловить системные часы за хвост.
8. Не логировать полный proxy URI, если он содержит пароль.

Конфигурация по умолчанию:

```php
return [
    'check' => [
        'url' => env('PROXY_CHECK_URL', 'https://example.com/'),
        'interval_minutes' => (int) env('PROXY_CHECK_INTERVAL_MINUTES', 5),
        'timeout_seconds' => (int) env('PROXY_CHECK_TIMEOUT_SECONDS', 8),
        'connect_timeout_seconds' => (int) env('PROXY_CHECK_CONNECT_TIMEOUT_SECONDS', 3),
        'success_status_codes' => array_map('intval', explode(',', env('PROXY_CHECK_SUCCESS_CODES', '200,204,301,302'))),
        'stale_after_seconds' => (int) env('PROXY_CHECK_STALE_AFTER_SECONDS', 120),
        'queue' => env('PROXY_CHECK_QUEUE', 'proxy-checks'),
        'unique_for_seconds' => (int) env('PROXY_CHECK_UNIQUE_FOR_SECONDS', 300),
    ],
];
```

### 9.7. Service: `ProxyUriFactory`

Создаёт URI для Guzzle `proxy` option.

Правила:

```text
http   -> http://user:pass@host:port
https  -> https://user:pass@host:port
socks4 -> socks4://user:pass@host:port
socks5 -> socks5h://user:pass@host:port
```

Для IPv6 host оборачивать в `[]` при построении URI.

Username/password кодировать через `rawurlencode`.

### 9.8. Action: `ApplyProxyCheckResultAction`

Обновляет `proxy_servers` и создаёт `proxy_checks` в одной транзакции.

Для `online`:

```text
status = online
last_checked_at = finished_at
last_success_at = finished_at
response_time_ms = result.response_time_ms
failure_reason = null
checking_started_at = null
```

Для `offline`:

```text
status = offline
last_checked_at = finished_at
response_time_ms = result.response_time_ms
failure_reason = sanitized error message
checking_started_at = null
last_success_at не менять
```

Всегда создать запись в `proxy_checks`.

### 9.9. Job: `CheckProxyStatusJob`

Требования:

- Implements `ShouldQueue` и `ShouldBeUnique`.
- Queue name: `proxy-checks`.
- `uniqueId()` возвращает `proxy:{id}`.
- `uniqueFor` брать из config.
- `$tries = 1` или максимум `2`. Обычная недоступность прокси не должна считаться failed job.
- `$timeout` должен быть больше HTTP timeout, но меньше `retry_after` queue connection.
- В начале `handle()` загрузить свежую модель из БД. Если прокси удалён, завершиться без ошибки.
- Перед проверкой установить:

```text
status = checking
checking_started_at = now()
```

- Выполнить `ProxyCheckerInterface::check()`.
- Применить результат через `ApplyProxyCheckResultAction`.
- `failed(Throwable $e)` должен безопасно перевести прокси в `offline` с `unexpected_error`, если модель ещё существует.

Дополнительная страховка: можно добавить middleware `WithoutOverlapping("proxy-check:{$id}")`, но основной механизм против дублей должен быть `ShouldBeUnique`, чтобы не забивать очередь одинаковыми задачами.

### 9.10. Job: `DispatchDueProxyChecksJob`

Назначение: каждые 5 минут найти прокси, которым пора обновить статус, и поставить индивидуальные `CheckProxyStatusJob`.

Алгоритм:

1. Считать `interval_minutes` и `stale_after_seconds` из config.
2. Сначала обработать зависшие `checking`:

```text
status = checking AND checking_started_at < now() - stale_after_seconds
```

Такие записи перевести в `offline`, `failure_reason = stale_check`.

3. Найти кандидатов:

```text
last_checked_at IS NULL
OR last_checked_at <= now() - interval_minutes
```

4. Исключить свежие `checking`, не являющиеся stale.
5. Обрабатывать через `chunkById(200)`.
6. Для каждого кандидата dispatch `CheckProxyStatusJob`.
7. Не выполнять HTTP-проверки внутри этого job. Он только диспетчер.

---

## 10. Планировщик

В `routes/console.php` или `bootstrap/app.php` добавить расписание:

```php
use App\Jobs\DispatchDueProxyChecksJob;
use Illuminate\Support\Facades\Schedule;

Schedule::job(new DispatchDueProxyChecksJob(), config('proxy-manager.check.queue'))
    ->name('proxy-manager:dispatch-due-checks')
    ->everyFiveMinutes()
    ->withoutOverlapping(10)
    ->onOneServer();
```

Для single-server `onOneServer()` не мешает, для multi-server спасает от дублей. Но для него нужен общий cache driver: `database`, `redis`, `memcached` или другой driver с atomic locks.

На сервере должен быть один cron:

```bash
* * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1
```

В development можно использовать:

```bash
php artisan schedule:work
```

---

## 11. REST API

Base path:

```text
/api/v1
```

Headers:

```text
Accept: application/json
Content-Type: application/json
```

### 11.1. Список прокси

```http
GET /api/v1/proxies
```

Query params:

| Параметр | Тип | Описание |
|---|---|---|
| `search` | string | Поиск по `name`, `host`, `username` |
| `status` | string | `unknown`, `checking`, `online`, `offline` |
| `scheme` | string | `http`, `https`, `socks4`, `socks5` |
| `page` | int | Страница |
| `per_page` | int | 10..100, default 20 |
| `sort` | string | `created_at`, `last_checked_at`, `status`, `host` |
| `direction` | string | `asc`, `desc` |

Response `200`:

```json
{
  "data": [
    {
      "id": 1,
      "name": "Office proxy",
      "scheme": "http",
      "host": "203.0.113.10",
      "port": 8080,
      "username": "user1",
      "has_credentials": true,
      "display_address": "http://user1@203.0.113.10:8080",
      "status": "online",
      "checking_started_at": null,
      "last_checked_at": "2026-05-31T09:20:00Z",
      "last_success_at": "2026-05-31T09:20:00Z",
      "response_time_ms": 231,
      "failure_reason": null,
      "created_at": "2026-05-31T09:00:00Z",
      "updated_at": "2026-05-31T09:20:00Z"
    }
  ],
  "meta": {
    "current_page": 1,
    "per_page": 20,
    "total": 1
  }
}
```

### 11.2. Получить один прокси

```http
GET /api/v1/proxies/{id}
```

Response `200`:

```json
{
  "data": {
    "id": 1,
    "name": "Office proxy",
    "scheme": "http",
    "host": "203.0.113.10",
    "port": 8080,
    "username": "user1",
    "has_credentials": true,
    "display_address": "http://user1@203.0.113.10:8080",
    "status": "online"
  }
}
```

### 11.3. Создать прокси

```http
POST /api/v1/proxies
```

Request:

```json
{
  "name": "Office proxy",
  "scheme": "http",
  "host": "203.0.113.10",
  "port": 8080,
  "username": "user1",
  "password": "secret"
}
```

Response `201`:

```json
{
  "data": {
    "id": 1,
    "name": "Office proxy",
    "scheme": "http",
    "host": "203.0.113.10",
    "port": 8080,
    "username": "user1",
    "has_credentials": true,
    "status": "checking"
  }
}
```

После создания приложение должно поставить проверку в очередь. Статус может быть `checking` сразу после ответа.

### 11.4. Обновить прокси

```http
PATCH /api/v1/proxies/{id}
```

Request:

```json
{
  "name": "Office proxy renamed",
  "port": 8081
}
```

Response `200`:

```json
{
  "data": {
    "id": 1,
    "name": "Office proxy renamed",
    "scheme": "http",
    "host": "203.0.113.10",
    "port": 8081,
    "username": "user1",
    "has_credentials": true,
    "status": "checking"
  }
}
```

Если изменились сетевые параметры или credentials, статус сбрасывается и проверка ставится в очередь.

### 11.5. Удалить прокси

```http
DELETE /api/v1/proxies/{id}
```

Response:

```http
204 No Content
```

Удаление физическое, связанные `proxy_checks` удаляются каскадно.

### 11.6. Запустить ручную проверку одного прокси

```http
POST /api/v1/proxies/{id}/check
```

Response `202`:

```json
{
  "data": {
    "id": 1,
    "status": "checking",
    "queued": true
  }
}
```

Проверка асинхронная. UI должен показать `checking` и обновить список через polling.

### 11.7. Запустить ручную проверку всех прокси

```http
POST /api/v1/proxies/check
```

Response `202`:

```json
{
  "data": {
    "queued": true,
    "candidate_count": 42
  }
}
```

`candidate_count` означает количество найденных кандидатов. Из-за `ShouldBeUnique` фактическая постановка дублей в очередь может быть пропущена, и это нормально.

### 11.8. История проверок прокси

```http
GET /api/v1/proxies/{id}/checks
```

Query params:

| Параметр | Тип | Описание |
|---|---|---|
| `page` | int | Страница |
| `per_page` | int | 10..100, default 20 |

Response `200`:

```json
{
  "data": [
    {
      "id": 10,
      "source": "auto",
      "status": "offline",
      "started_at": "2026-05-31T09:15:00Z",
      "finished_at": "2026-05-31T09:15:08Z",
      "response_time_ms": null,
      "http_status": null,
      "error_code": "timeout",
      "error_message": "Connection timed out"
    }
  ],
  "meta": {
    "current_page": 1,
    "per_page": 20,
    "total": 1
  }
}
```

---

## 12. HTTP-статусы и ошибки API

| Сценарий | HTTP |
|---|---:|
| Успешный список/просмотр/обновление | 200 |
| Создание | 201 |
| Асинхронная проверка поставлена | 202 |
| Удаление | 204 |
| Валидация | 422 |
| Дубликат прокси | 409 |
| Не найдено | 404 |
| Неожиданная ошибка | 500 |

Формат ошибки:

```json
{
  "message": "Proxy already exists.",
  "errors": {
    "host": ["A proxy with the same scheme, host, port and username already exists."]
  }
}
```

Для `500` не отдавать stack trace в production.

---

## 13. Frontend

### 13.1. Подход

Использовать Vue 3 SFC и Composition API. Для MVP глобальный store не обязателен: достаточно composables. Pinia можно подключить только если состояние начнёт расползаться между несколькими страницами. Подключать state manager для одной таблицы — это как нанять дирижёра для дверного звонка.

### 13.2. Структура frontend

```text
resources/js/
  app.js|app.ts
  api/
    http.js|http.ts
    proxies.js|proxies.ts
  composables/
    useProxies.js|useProxies.ts
    useProxyFilters.js|useProxyFilters.ts
    useAsyncState.js|useAsyncState.ts
    useToast.js|useToast.ts
  components/
    proxies/
      ProxyTable.vue
      ProxyFormModal.vue
      ProxyStatusBadge.vue
      ProxyActions.vue
      ProxyChecksDrawer.vue
    ui/
      ConfirmDialog.vue
      Pagination.vue
      EmptyState.vue
      LoadingButton.vue
  pages/
    ProxiesPage.vue
```

TypeScript предпочтителен, но не обязателен, если проект не настроен под TS. Если используется JavaScript, добавить JSDoc typedefs для API-моделей.

### 13.3. Главная страница

`ProxiesPage.vue` должна содержать:

- заголовок;
- кнопку “Добавить прокси”;
- кнопку “Обновить все статусы”;
- фильтр по статусу;
- фильтр по протоколу;
- поиск;
- таблицу;
- пагинацию;
- модальное окно создания/редактирования;
- confirm dialog перед удалением;
- drawer/modal истории проверок.

### 13.4. Таблица

Колонки:

| Колонка | Описание |
|---|---|
| Status | Badge: `unknown`, `checking`, `online`, `offline` |
| Name | Имя или `—` |
| Proxy | `scheme://username@host:port`, без пароля |
| Response | `response_time_ms`, если есть |
| Last checked | дата/время, если есть |
| Last error | краткая причина, если offline |
| Actions | edit, delete, check, history |

Статусы нельзя обозначать только цветом. Нужен текст, иначе доступность будет грустно смотреть в окно.

### 13.5. Форма прокси

Поля:

- `name`;
- `scheme` select;
- `host`;
- `port`;
- `username`;
- `password`.

Правила UX:

- На edit пароль не показывать.
- Показать подпись: “Оставьте пустым, чтобы не менять пароль”.
- Добавить отдельное действие “Очистить пароль”, которое отправит `password: null`.
- Ошибки backend validation показывать рядом с полями.
- Submit button disabled во время запроса.

### 13.6. Polling

Так как WebSocket/SSE не входят в MVP:

- После ручной проверки одного прокси обновить список через 1 секунду, затем каждые 3 секунды до выхода из `checking`, максимум 30 секунд.
- Общий список обновлять каждые 30 секунд, пока страница открыта.
- Не делать polling каждые 5 минут как замену backend scheduler. Frontend polling только отображает состояние, а не выполняет проверку.
- При уходе со страницы отменять timers и pending requests через `AbortController`.

### 13.7. API client

`api/http`:

- единая настройка base URL `/api/v1`;
- `Accept: application/json`;
- нормализация ошибок `422`, `409`, `500`;
- поддержка abort signal.

`api/proxies`:

```text
listProxies(params)
getProxy(id)
createProxy(payload)
updateProxy(id, payload)
deleteProxy(id)
checkProxy(id)
checkAllProxies()
listProxyChecks(id, params)
```

### 13.8. `useProxies` composable

Возвращает:

```text
items
meta
filters
loading
saving
checkingIds
error
load()
create(payload)
update(id, payload)
remove(id)
check(id)
checkAll()
openHistory(id)
```

В composable держать orchestration UI/API, но не помещать туда бизнес-правила backend. Frontend не должен сам решать, “живой” прокси или нет.

---

## 14. Безопасность

1. Пароли хранить только encrypted cast Laravel.
2. Пароли не возвращать в API.
3. Пароли не писать в логи, exception message, job payload вручную или frontend state после сохранения.
4. `ProxyUriFactory` должен иметь метод безопасного display URI без пароля.
5. Проверочный URL задаётся только через env/config.
6. Не принимать check URL из запроса.
7. Ограничить manual refresh rate:
   - минимум middleware `throttle:60,1` для API group;
   - для `POST /proxies/check` можно строже: `throttle:10,1`.
8. При публичном деплое обязательно включить auth middleware. Архитектура route group должна позволять сделать это одной строкой.
9. CORS настраивать только если frontend и backend на разных origin. Для same-origin Laravel + Vite CORS не нужен.
10. Не отключать TLS verification для `check_url` по умолчанию.

---

## 15. Конкурентность и гонки

Проблемы, которые надо предотвратить:

| Риск | Решение |
|---|---|
| Ручная и автоматическая проверка одного прокси одновременно | `ShouldBeUnique` по `proxy:{id}` |
| Scheduler стартовал на двух серверах | `onOneServer()` + общий cache driver |
| Предыдущий scheduler run ещё работает | `withoutOverlapping(10)` |
| Прокси навсегда завис в `checking` | `stale_after_seconds` guard |
| Очередь забита дублями | Unique jobs |
| Пароль попал в job payload | Передавать только `proxy_id`, не модель с decrypted fields |
| Статус перезаписан старым результатом | В `ApplyProxyCheckResultAction` можно сравнивать `started_at >= checking_started_at` при необходимости |

---

## 16. Производительность

### 16.1. Целевые рамки MVP

- До 500 прокси без изменения архитектуры.
- Список прокси должен отвечать менее чем за 300 мс на типовой dev/staging БД при 500 строках.
- Проверки выполняются фоново и не блокируют UI.

### 16.2. Расчёт количества queue workers

Чтобы все прокси реально успевали провериться за 5 минут, нужно оценивать workers:

```text
required_workers ≈ ceil(proxy_count * avg_check_seconds / 300)
```

Пример:

```text
500 прокси * 3 секунды / 300 секунд = 5 workers
```

Если поставить один worker на тысячу медленных прокси, scheduler будет честно подкидывать работу каждые 5 минут, но очередь превратится в болото с табличкой “я работаю”.

### 16.3. Queue worker

Пример supervisor command:

```bash
php artisan queue:work database \
  --queue=proxy-checks,default \
  --sleep=1 \
  --tries=1 \
  --timeout=30
```

`retry_after` для database queue должен быть больше `--timeout`, например 60 секунд.

---

## 17. Наблюдаемость и обслуживание

### 17.1. Логи

Логировать:

- `proxy_id`;
- `source`;
- `status`;
- `response_time_ms`;
- `error_code`;
- безопасное сообщение без credentials.

Не логировать:

- password;
- полный proxy URI с credentials;
- raw request headers с секретами.

### 17.2. Очистка истории

Добавить daily scheduled cleanup:

```text
Удалять proxy_checks старше 30 дней
или оставить последние 100 проверок на каждый proxy_server_id
```

Минимально допустимо реализовать команду:

```bash
php artisan proxy-checks:prune
```

и расписание:

```php
Schedule::command('proxy-checks:prune')->daily()->onOneServer();
```

---

## 18. Тестирование

### 18.1. Backend feature tests

Обязательные тесты:

1. `GET /api/v1/proxies` возвращает пагинированный список.
2. `POST /api/v1/proxies` создаёт прокси и не возвращает `password`.
3. Дубликат `scheme + host + port + username` отклоняется.
4. `PATCH /api/v1/proxies/{id}` обновляет поля.
5. `PATCH` без `password` не стирает пароль.
6. `PATCH` с `password: null` стирает пароль.
7. `DELETE` удаляет прокси и его checks.
8. `POST /api/v1/proxies/{id}/check` возвращает `202` и ставит job.
9. `POST /api/v1/proxies/check` возвращает `202`.
10. `GET /api/v1/proxies/{id}/checks` возвращает историю.

### 18.2. Backend unit tests

Обязательные тесты:

1. `ProxyUriFactory` строит URI для `http`, `https`, `socks4`, `socks5`.
2. `ProxyUriFactory` корректно кодирует username/password.
3. IPv6 host корректно оборачивается в `[]` для URI.
4. `LaravelHttpProxyChecker` возвращает `online` при success status.
5. `LaravelHttpProxyChecker` возвращает `offline` при timeout.
6. `ApplyProxyCheckResultAction` обновляет `proxy_servers` и создаёт `proxy_checks` в транзакции.
7. `DispatchDueProxyChecksJob` выбирает due proxies и пропускает fresh checking.
8. Stale `checking` переводится в `offline`.

### 18.3. Frontend tests

Если в проекте есть Vitest:

1. `useProxies` загружает список и выставляет loading/error states.
2. `ProxyStatusBadge` корректно отображает все статусы.
3. `ProxyFormModal` отправляет payload создания.
4. Ошибки валидации показываются возле полей.
5. После `check(id)` запускается polling.

### 18.4. Минимальные команды проверки

```bash
composer test
php artisan test
npm run build
```

Если настроены линтеры:

```bash
./vendor/bin/pint
npm run lint
```

---

## 19. Acceptance criteria

### 19.1. CRUD

- Пользователь может добавить прокси с `scheme`, `host`, `port`, опциональными `name`, `username`, `password`.
- Пользователь может изменить любой параметр прокси.
- При изменении сетевых параметров статус становится `checking` или `unknown`, затем обновляется после проверки.
- Пользователь может удалить прокси.
- Пароль никогда не виден после сохранения.

### 19.2. Статусы

- Новый прокси получает статус `checking` и проверяется в фоне.
- Статус может быть `unknown`, `checking`, `online`, `offline`.
- В списке видны last checked, latency и last error.
- Offline-статус содержит понятную безопасную причину.

### 19.3. Автообновление

- Scheduler каждые 5 минут ставит due proxies в очередь.
- HTTP-проверки не выполняются в scheduler job напрямую.
- Повторные scheduler runs не создают дублей для одного proxy id.
- Зависшие `checking` не остаются навечно.

### 19.4. Ручное обновление

- Пользователь может запустить проверку одного прокси.
- Пользователь может запустить проверку всех прокси.
- API возвращает `202 Accepted`.
- UI показывает `checking` и обновляет статус без перезагрузки страницы.

### 19.5. Архитектура

- Контроллеры тонкие.
- Валидация вынесена в Form Requests.
- JSON формат вынесен в API Resources.
- Проверка прокси вынесена в `ProxyCheckerInterface`.
- Queue jobs передают только `proxy_id`, а не секретные данные.
- Есть тесты ключевых сценариев.

---

## 20. Реализационный план для Codex

### Шаг 1. Базовая конфигурация

- Проверить Laravel 12 app structure.
- Добавить `config/proxy-manager.php`.
- Настроить `.env.example`:

```dotenv
PROXY_CHECK_URL=https://example.com/
PROXY_CHECK_INTERVAL_MINUTES=5
PROXY_CHECK_TIMEOUT_SECONDS=8
PROXY_CHECK_CONNECT_TIMEOUT_SECONDS=3
PROXY_CHECK_SUCCESS_CODES=200,204,301,302
PROXY_CHECK_STALE_AFTER_SECONDS=120
PROXY_CHECK_QUEUE=proxy-checks
PROXY_CHECK_UNIQUE_FOR_SECONDS=300
QUEUE_CONNECTION=database
CACHE_STORE=database
```

### Шаг 2. БД и модели

- Создать migrations.
- Создать enums.
- Создать models и relationships.
- Реализовать hashing identity.

### Шаг 3. Backend API

- Создать Form Requests.
- Создать API Resources.
- Создать actions.
- Создать controllers.
- Добавить routes `/api/v1`.

### Шаг 4. Проверки прокси

- Создать `ProxyCheckerInterface`.
- Создать `ProxyUriFactory`.
- Создать `LaravelHttpProxyChecker`.
- Создать `ProxyCheckResult` DTO.
- Создать jobs.
- Добавить scheduler.

### Шаг 5. Frontend

- Создать API client.
- Создать composables.
- Создать страницу и компоненты.
- Реализовать CRUD, ручные проверки, polling, pagination.

### Шаг 6. Тесты

- Backend feature/unit tests.
- Frontend tests, если окружение уже настроено.
- Проверить build.

### Шаг 7. Документация

Обновить `README.md`:

- installation;
- env variables;
- migrations;
- scheduler cron;
- queue worker;
- how to run tests;
- how proxy status is calculated.

---

## 21. Запрещённые решения

- Не выполнять проверку всех прокси синхронно из controller.
- Не писать HTTP-логику в Eloquent model.
- Не хранить пароль открытым текстом.
- Не возвращать пароль в API “временно для удобства”. Такие “временно” живут дольше некоторых империй.
- Не использовать random string statuses без enum.
- Не использовать hard-coded check URL внутри service.
- Не проверять прокси через `ping`: прокси — прикладной сервис, ICMP тут почти гадание на сетевой гуще.
- Не делать cron на каждый прокси.
- Не использовать frontend polling как замену backend scheduler.
- Не добавлять WebSocket/SSE в MVP.

---

## 22. Открытые вопросы для владельца продукта

Эти вопросы не блокируют MVP, но важны перед production:

1. Нужна ли авторизация пользователей?
2. Нужен ли импорт списка прокси из CSV/TXT?
3. Сколько прокси ожидается: 100, 1 000, 50 000?
4. Какой URL считать эталонным для проверки?
5. Нужно ли проверять географию/IP, а не только доступность?
6. Нужно ли хранить историю дольше 30 дней?

---

## 23. Справочные источники

- Laravel 12 Scheduler: scheduled jobs, `everyFiveMinutes`, `withoutOverlapping`, `onOneServer`, single cron entry: <https://laravel.com/docs/12.x/scheduling>
- Laravel 12 Queues: unique jobs, job timeouts, backoff, overlapping middleware: <https://laravel.com/docs/12.x/queues>
- Laravel 12 HTTP Client: `timeout`, `connectTimeout`, `retry`, Guzzle options: <https://laravel.com/docs/12.x/http-client>
- Laravel 12 Validation: Form Requests: <https://laravel.com/docs/12.x/validation>
- Laravel 12 Eloquent Resources: API transformation layer: <https://laravel.com/docs/12.x/eloquent-resources>
- Laravel 12 Migrations: schema versioning: <https://laravel.com/docs/12.x/migrations>
- Vue 3 Composables: reusable stateful logic: <https://vuejs.org/guide/reusability/composables>
- Vue 3 Single-File Components: SFC and `<script setup>`: <https://vuejs.org/guide/scaling-up/sfc>
