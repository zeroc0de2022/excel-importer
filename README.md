# Excel Importer

Импорт больших `.xlsx` файлов в PostgreSQL через очередь Laravel: потоковое чтение, обработка
чанками по 1000 строк в `Bus::batch`, валидация, отчёт об ошибках, прогресс в Redis и живой
прогресс-бар в браузере через Laravel Reverb.

Проект доведён до состояния, в котором его не стыдно запускать в продакшене: Docker, тесты на настоящих PostgreSQL и Redis, CI, статический анализ, измеренная производительность.

## Что умеет

- Загрузка `.xlsx` (колонки `id`, `name`, `date` в формате `d.m.Y`) за basic-авторизацией.
- Потоковое чтение файла: память не растёт с размером файла (38 МБ и на 100 000, и на 300 000 строк).
- Обработка через джобы чанками по 1000 строк, пакетная вставка `INSERT … ON CONFLICT DO NOTHING`.
- Валидация по правилам задания, поиск дубликатов внутри файла и среди уже импортированных строк.
- Отчёт `result.txt` в формате `<номер строки> - <ошибка1>, <ошибка2>` — скачивается из интерфейса.
- Прогресс импорта в Redis (уникальный ключ + число обработанных строк), атомарно и идемпотентно.
- Живой прогресс-бар (Reverb + Echo) с откатом на опрос API, история импортов.
- Выдача импортированных строк, сгруппированных по дате (двумерный массив), с пагинацией.
- Генератор демо-файлов с ошибками и дубликатами: `php artisan demo:generate-file --rows=100000`.

## Архитектура

```mermaid
flowchart LR
    U[Браузер] -- "POST /api/imports (xlsx)" --> C[ImportController]
    C -- "файл" --> FS[(storage)]
    C -- "Bus::batch" --> Q[[Очередь Redis]]

    Q --> R[ReadImportFile<br/>потоковое чтение]
    R -- "batch()->add()<br/>по 1000 строк" --> Q
    Q --> P1[ProcessImportChunk]
    Q --> P2[ProcessImportChunk]
    Q --> Pn[ProcessImportChunk …]

    P1 -- "INSERT … ON CONFLICT DO NOTHING" --> DB[(PostgreSQL<br/>rows)]
    P1 -- "Lua: счётчики + ошибки,<br/>один раз на чанк" --> RS[(Redis<br/>import:{id}:*)]
    P1 -- "RowsCreated<br/>(раз на чанк)" --> WS[Reverb]

    Q -. "batch finally()" .-> F[ImportService::finish<br/>result.txt, итоги]
    F --> DB
    F -- "ImportFinished" --> WS
    WS -- "WebSocket" --> U
    U -. "опрос, если WS недоступен" .-> C
```

## Стек

| | |
|---|---|
| Backend | PHP 8.4, Laravel 13 |
| Очереди | Redis 8 (очередь, прогресс), `Bus::batch` |
| База | PostgreSQL 18 |
| Чтение xlsx | [OpenSpout](https://github.com/openspout/openspout) — потоковый ридер |
| Real-time | Laravel Reverb + Laravel Echo |
| Frontend | Blade + Vue 3 (две небольшие страницы), Tailwind CSS, Vite |
| Инфраструктура | Docker Compose: php-fpm, nginx, postgres, redis, queue, scheduler, reverb |
| Качество | PHPUnit, Laravel Pint, Larastan (level 8), GitHub Actions |

## Быстрый старт

Нужны Docker с Compose v2 и `make`.

```bash
cd excel-importer
make up
```

`make up` соберёт образы, установит зависимости, сгенерирует `APP_KEY`, соберёт фронтенд,
поднимет все сервисы, дождётся healthcheck'ов, выполнит миграции и выведет адрес и доступы:

```
  App:    http://localhost:8000
  Login:  admin / secret
```

Другие команды (`make help`):

| Команда | Что делает |
|---|---|
| `make test` | тесты (в контейнере, на PostgreSQL и Redis) |
| `make lint` | Pint + Larastan |
| `make bench` | бенчмарк на 100 000 строк |
| `make shell` | shell в контейнере приложения |
| `make logs` | логи всех сервисов |
| `make down` | остановить (данные в volume сохраняются) |

## Как работает импорт

1. **Загрузка.** `POST /api/imports` проверяет файл (`extensions:xlsx`, `mimes:xlsx` по содержимому,
   размер), сохраняет его в `storage/app/imports/{uuid}/source.xlsx`, создаёт запись в `imports`
   и запускает `Bus::batch` с одной джобой `ReadImportFile`. Ответ — `202 Accepted`.
2. **Чтение.** `ReadImportFile` читает файл потоково (OpenSpout), пропускает шапку и каждые
   1000 строк добавляет в *этот же батч* джобу `ProcessImportChunk` с номерами строк и данными.
   Пока читающая джоба не завершилась, батч не может считаться законченным.
3. **Обработка чанка.** `ProcessImportChunk`:
   - валидирует строки (`RowValidator`), ловит дубликаты внутри чанка;
   - вставляет валидные строки одним запросом `INSERT … ON CONFLICT (id) DO NOTHING` —
     первая импортированная строка с данным `id` остаётся, остальные становятся дубликатами;
   - одним Lua-скриптом в Redis увеличивает счётчики и записывает строки отчёта — ровно один раз
     на чанк, даже если джоба выполнится повторно;
   - отправляет событие `RowsCreated` в Reverb.
4. **Завершение.** Колбэк батча `finally()` собирает `result.txt` из Redis (отсортирован по номеру
   строки), сохраняет итоговые счётчики в `imports`, ставит статус и отправляет `ImportFinished`.
5. **Очистка.** Планировщик каждую ночь удаляет импорты старше `IMPORT_KEEP_DAYS` вместе с файлами
   и ключами Redis (`imports:prune`), а также старые записи батчей и упавших джоб.

Формат отчёта (реальный [result.txt](result.txt) для файла из задания):

```
7738 - name must contain only English letters and spaces
10024 - date must be a valid date in d.m.Y format
23636 - id must be an unsigned big integer
42361 - duplicate id 5475795 (first seen in row 5655)
```

## API

Все маршруты под basic-авторизацией. Учётные данные — `BASIC_AUTH_USER` / `BASIC_AUTH_PASSWORD`.

| Метод | URL | Описание |
|---|---|---|
| `POST` | `/api/imports` | загрузить файл (`multipart/form-data`, поле `file`) → `202` |
| `GET` | `/api/imports` | история импортов (пагинация по 20) |
| `GET` | `/api/imports/{uuid}` | статус и прогресс импорта |
| `GET` | `/api/imports/{uuid}/report` | скачать `result.txt` |
| `GET` | `/api/rows?page=1&per_page=100` | строки, сгруппированные по дате |
| `GET` | `/up` | health check (без авторизации) |

```bash
curl -u admin:secret -F file=@public/samples/sample-1000.xlsx http://localhost:8000/api/imports
```

```json
{
  "data": {
    "id": "01a0ecc2-e95e-7391-a0a0-7ef0b2194bc5",
    "status": "processing",
    "total_rows": 60000,
    "processed_rows": 15000,
    "failed_rows": 2,
    "imported_rows": 14998,
    "progress": 25,
    "report_url": null
  }
}
```

`GET /api/rows` возвращает двумерный массив «дата → строки»:

```json
{
  "data": {
    "01.01.1985": [
      { "id": "545745", "name": "Amani Greiner", "date": "01.01.1985" },
      { "id": "1391055", "name": "Paris Dennison", "date": "01.01.1985" }
    ]
  },
  "meta": { "current_page": 1, "last_page": 600, "per_page": 100, "total": 59988 },
  "links": { "next": "…?page=2", "prev": null }
}
```

Пагинация идёт по строкам (отсортированным по дате), поэтому одна дата может продолжиться на
следующей странице — зато размер страницы предсказуем даже если на одну дату пришлось 10 000 строк.

## Ключевые решения

Подробно, с альтернативами и компромиссами, — в [docs/decisions.md](docs/decisions.md). Коротко:

- **Батч + джоба-читатель, которая добавляет чанки в тот же батч.** Файл читается один раз,
  потоково; `finally()` гарантированно срабатывает после последнего чанка.
- **`INSERT … ON CONFLICT DO NOTHING`** вместо `exists()` + `create()`: один атомарный запрос
  на 1000 строк, без гонок между воркерами; ключи сортируются, чтобы параллельные чанки не
  ловили дедлоки.
- **Идемпотентность повторов** на двух уровнях: строки помнят, какой импорт и какая строка файла
  их создали, а Redis-скрипт засчитывает каждый чанк ровно один раз.
- **Событие на чанк, а не на строку:** 100 событий вместо 100 000 на файл в 100 000 строк.
- **`id` хранится как `numeric(20,0)`,** а в PHP — строкой: беззнаковый bigint (до
  18 446 744 073 709 551 615) не помещается ни в `bigint` PostgreSQL, ни в `int` PHP.

## Тесты и качество

```bash
make test   # 85 тестов, 185 проверок
make lint   # Pint + Larastan level 8
```

- **Unit** — `RowValidator` (каждое правило: 29.02 в високосный/невисокосный год, 31.04, пробелы,
  ведущие нули, `id` на границе unsigned bigint, неразрывный пробел в имени, кириллица…),
  потоковый ридер.
- **Feature** — basic-auth (401 без логина), валидация загрузки (422 для другого типа, подменённого
  расширения, превышения размера), счастливый путь с `Bus::fake()`, статус, история, отчёт,
  структура ответа `/api/rows`.
- **Integration** — настоящий конвейер (`Bus::batch`, PostgreSQL, Redis) на маленьком файле:
  строки в БД, дубликаты между чанками и с существующими данными, точный текст отчёта, прогресс
  в Redis, повторное выполнение чанка, «сбой» между записью в БД и в Redis, лимит строк, очистка.

Тесты работают на тех же PostgreSQL и Redis, что и приложение (отдельная база `testing`
и отдельная база Redis), — без SQLite и моков хранилищ.

CI (GitHub Actions) на каждый push и PR: Pint `--test`, Larastan, тесты с сервис-контейнерами
`postgres` и `redis`, сборка production-образа.

## Бенчмарк

`make bench` генерирует файл на 100 000 строк (~5% строк с ошибками или дубликатами) и прогоняет
весь конвейер в одном процессе через `sync`-очередь — ту же работу, что делает один воркер.

| Строк | Время | Скорость | Пиковая память |
|---:|---:|---:|---:|
| 100 000 | 7,3–7,4 с | ~13 500 строк/с | 38,0 МБ |
| 300 000 | 22,8–23,2 с | ~13 000 строк/с | 38,0 МБ |

Та же загрузка 100 000 строк через HTTP с одним воркером очереди: ~7 с от загрузки до готового
отчёта, память контейнера воркера — до 47,5 МБ (`docker stats`).

Файл из задания (60 000 строк) — 5–6 с от загрузки до готового отчёта.

Машина: Intel Core Ultra 7 165H, 16 ГБ RAM, Fedora 44, контейнеры в rootless Podman 5.8.
Числа — из реальных запусков (по 2–3 прогона), на другой машине будут другими.

Что даёт основной выигрыш:

- потоковое чтение и кэш строк в памяти — на файле из задания 1,7 с вместо 22 с у настроек
  OpenSpout по умолчанию (см. [decisions.md](docs/decisions.md#2-потоковое-чтение-xlsx));
- одна вставка на 1000 строк вместо 2000 запросов (`exists` + `insert`) в исходной версии;
- одно событие на чанк вместо одной queued-джобы на каждую строку.

## Структура

```
app/
├── Console/Commands/     imports:prune, demo:generate-file, import:benchmark
├── Enums/ImportStatus    pending → processing → completed / failed
├── Events/               RowsCreated (на чанк), ImportFinished
├── Http/                 ImportController, RowController, BasicAuth, StoreImportRequest
├── Import/
│   ├── ImportService     старт батча, финализация, отчёт
│   ├── ImportProgress    ключи Redis + Lua-скрипт
│   ├── RowValidator      правила задания
│   └── SpreadsheetReader потоковое чтение xlsx
└── Jobs/                 ReadImportFile, ProcessImportChunk
docker/                   nginx, php.ini, init-скрипт PostgreSQL
resources/js/             Echo, две Vue-страницы
tests/                    Unit, Feature, Integration
```
