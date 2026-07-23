# Структурированное логирование — инфра-чек-лист (девопс)

> Это делает **девопс** на хосте/в кластере. **Код приложения от этого не зависит:**
> приложение просто пишет JSON-строки в `storage/logs/structured/app-*.json`
> (см. раздел «Структурированное логирование» в `README.md`). Файл-контракт (одна
> JSON-запись на строку) стабилен и не меняется от того, поднята доставка или нет.
> Пока Vector/ClickHouse не подняты — приложение всё равно работает, файл ротируется
> локально по `STRUCTURED_LOG_MAX_FILES`.

Задача этапа доставки: забрать записи из локального файла-буфера и сложить в ClickHouse
для долгого хранения и разбора.

## 1. Vector на хосте

Агент [Vector](https://vector.dev) читает файл приложения, парсит JSON и батчами шлёт в
ClickHouse.

- **source** — `file`: следит за `storage/logs/structured/app-*.json` (glob по дневным
  файлам, которые создаёт monolog `RotatingFileHandler`).
- **transform** — `remap` (VRL): распарсить строку как JSON (`. = parse_json!(.message)`),
  при необходимости поднять вложенные метки (`app`, `member_id`, `request_id`, `env`,
  `level`, `schema_version`) на верхний уровень записи.
- **sink** — `clickhouse` с батчингом (буфер по размеру и по таймауту), чтобы не долбить
  ClickHouse по записи.

```toml
# /etc/vector/vector.toml (пример)

[sources.app_structured]
type = "file"
include = ["/path-to-app/storage/logs/structured/app-*.json"]
read_from = "beginning"

[transforms.parse]
type = "remap"
inputs = ["app_structured"]
source = '''
  . = parse_json!(.message)
  .timestamp = to_timestamp!(.datetime)
'''

[sinks.clickhouse]
type = "clickhouse"
inputs = ["parse"]
endpoint = "http://clickhouse:8123"
database = "logs"
table = "app_events"
skip_unknown_fields = true

  [sinks.clickhouse.batch]
  max_events = 10000
  timeout_secs = 5

  [sinks.clickhouse.buffer]
  type = "disk"
  max_size = 268435488   # диск-буфер на случай недоступности ClickHouse
```

> Точный набор полей после `parse_json` зависит от формата записи пакета (см.
> `schema_version`). Держите `skip_unknown_fields = true`, чтобы новые поля не роняли sink
> до обновления схемы таблицы.

## 2. Схема таблицы ClickHouse

```sql
CREATE TABLE IF NOT EXISTS logs.app_events
(
    timestamp   DateTime64(3),
    app         LowCardinality(String),
    member_id   String,
    level       LowCardinality(String),
    message     String,
    request_id  String,
    env         LowCardinality(String),
    context     String                    -- сырой JSON записи (request/response и пр.)
)
ENGINE = MergeTree
PARTITION BY toYYYYMMDD(timestamp)
ORDER BY (app, member_id, timestamp)
TTL timestamp + INTERVAL 30 DAY;
```

- `ORDER BY (app, member_id, timestamp)` — типовые выборки идут по приложению и порталу
  во времени; такой ключ даёт по ним локальность и сжатие.
- `PARTITION BY toYYYYMMDD(timestamp)` — посуточные партиции: дёшево дропать старое и
  сканировать по датам.
- `TTL timestamp + INTERVAL 30 DAY` — автоматическая чистка (см. ретеншен).
- `context` — оставляем сырым JSON'ом (`request`/`response`, `error`, `duration_ms`, …);
  вытаскивать отдельные поля из него — по мере надобности через `JSONExtract`.

## 3. Ретеншен (двухуровневый — важно не путать)

- **Локально (приложение):** `STRUCTURED_LOG_MAX_FILES` дневных файлов (дефолт 14) —
  короткий буфер на случай, если Vector/ClickHouse недоступны. Это НЕ архив.
- **В хранилище (ClickHouse):** основное долгое хранение и чистка — через `TTL` таблицы
  (пример выше — 30 дней). Срок подгоняется под требования к глубине разбора и объёму
  диска; менять его — задача девопса, приложение об этом не знает.
