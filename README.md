Пакет Laravel для удобной работы с REST API Битрикс24 и написания приложений.

Включает в себя:
- Миграции для сбора статистики запросов и сохранения авторизации(токенов) пользователей
- Роуты в зависимости от типа приложения и запросов к нему
- Шаблоны для установки и работы приложения
- Проверку статуса порталов на которые было установлено приложение
- Автоматическое обновление токенов пользователей

Установка

```injectablephp
composer require x3group-dev/bitrix24-api-laravel
```

Выполнить публикацию (скопируются routes, blade, базовые контроллеры)
```injectablephp
php artisan vendor:publish --provider="X3Group\Bitrix24\Bitrix24ServiceProvider"
```

Выполнить миграции
```injectablephp
php artisan migrate
```

В адреса приложений вписываем

Приложение:
```injectablephp
https://host/app
```
Установка приложения:
```injectablephp
https://host/install
```

в файл .env добавляем и заполняем своими данными
```injectablephp
BITRIX24_PHP_SDK_APPLICATION_CLIENT_ID=
BITRIX24_PHP_SDK_APPLICATION_CLIENT_SECRET=
# вместо crm,user_brief укажите скоупы приложения
BITRIX24_PHP_SDK_APPLICATION_SCOPE="crm,user_brief"
BITRIX24_LOG_MAX_FILES=3
```

В сборку фронта добавить проброс авторизации в заголовках, чтобы работали роуты b24appFrontRequest

```injectablephp
BX24.ready(async function () {
    await BX24.init(async function () {
        window.axios.defaults.headers.common['X-b24api-access-token'] = BX24.getAuth().access_token;
        window.axios.defaults.headers.common['X-b24api-refresh-token'] = BX24.getAuth().refresh_token;
        window.axios.defaults.headers.common['X-b24api-domain'] = BX24.getAuth().domain;
        window.axios.defaults.headers.common['X-b24api-member-id'] = BX24.getAuth().member_id;
        window.axios.defaults.headers.common['X-b24api-expires-in'] = BX24.getAuth().expires_in;
    });
});
```

**Cron**

Для автообновления токенов приложения обязательно требуется добавить запись в crontab

```php
* * * * * cd /path-to-your-project && php artisan schedule:run >> /dev/null 2>&1
```

## Миграция на b24phpsdk ^3 (SDK 3)

Начиная с версии 3.0.0 пакет переведён на официальный `bitrix24/b24phpsdk ^3` и требует **PHP 8.4**. В SDK 3 адрес OAuth-сервера (обновление токенов) задаётся через объект `Endpoints` и хранится отдельно для каждого портала.

- Требуется **PHP 8.4** и `bitrix24/b24phpsdk ^3`.
- В таблице `b24_apps` добавлена колонка `oauth_server_url` (миграция включена) — адрес OAuth-сервера (v3 `Endpoints`), используемый для обновления токенов. Значение выводится автоматически из `SERVER_ENDPOINT` запроса установки/плейсмента; фолбэк — EAST `https://oauth.bitrix24.tech/` (захардкожен в `OauthServerUrlResolver`, переменная окружения не нужна).
- В таблице `b24_users` отдельной колонки нет — адрес OAuth-сервера резолвится из `b24_apps` по `member_id`.
- Потребителям при обновлении необходимо перейти на **PHP 8.4** и выполнить `php artisan migrate`.

## Установщики приложения (реестр, с 3.1.0)

Создание сущностей приложения (списки, entity, встройки, поля и т.п.) при установке вынесено в **реестр
установщиков** — приложению больше не нужен свой install-контроллер, только классы-установщики.

- Класс реализует интерфейс `X3Group\Bitrix24\Contracts\Bitrix24Installer`:
  ```php
  public function install(\Bitrix24\SDK\Services\ServiceBuilder $b24): void;
  ```
- Регистрируются упорядоченным списком в `config('bitrix24.installers')`:
  ```php
  'installers' => [
      App\Install\InstallEvents::class,
      App\Install\InstallLists::class,
      // ...в нужном порядке
  ],
  ```
- **Когда это выполняется.** Весь install-флоу (`InstallService`: запись app-токена + прогон
  установщиков) работает **только на установке/переустановке приложения** — это маршрут **`/install`**
  (`InstallController`) и серверное событие **`ONAPPINSTALL`** (`OnApplicationInstallController`). На
  обычных открытиях приложения (главный маршрут `/app`, плейсменты, front-запросы) install-флоу **НЕ
  запускается** и app-токен не трогается — там работает пер-юзерная авторизация (`b24_users` через
  middleware). Установщики вызываются **по порядку**, из одного места (идемпотентно).
- **Установщики ОБЯЗАНЫ быть идемпотентны.** Ошибка любого установщика логируется и пробрасывается
  (fail-loud), страница установки показывает `install-fail` — то есть неидемпотентный установщик (напр.
  «поле уже существует») превратит **повторную установку/переустановку** в экран ошибки.
- **App-токен портала** (`b24_apps`) — пишется **только на установке/переустановке** (`/install` /
  `ONAPPINSTALL`) и только если строки ещё нет (первая установка) **или** устанавливающий —
  администратор (`profile->ADMIN`); токен не-админа существующий не перезатирает (`AppTokenWriter`,
  анти-угон). По умолчанию `installers` пуст — обратная совместимость.

## Вычистка мёртвых порталов

```bash
php artisan bitrix24:remove-uninstalled --dry-run
php artisan bitrix24:remove-uninstalled
```

Удаляет токены порталов, которые уже не оживут: с которых приложение удалено
(OAuth `NOT_INSTALLED`) и у которых подписка кончилась больше `--expired-days`
назад (OAuth `PAYMENT_REQUIRED`, по умолчанию 30 дней). Сетевые ошибки и таймауты
не трогает.

- Опции: `--limit=200`, `--threshold=1`, `--expired-days=30`, `--dry-run`.
- Срок подписки считается по `b24_apps.expires` — оно обновляется только при успешном
  продлении токена, поэтому миграция не требуется.
- Каждый портал пробивается одним OAuth-обновлением токена. У живого портала Битрикс
  при этом ротирует токен, и новый сохраняется — это происходит и в `--dry-run`.

## Структурированное логирование

Пакет умеет собирать структурированный JSON-лог REST-вызовов Битрикс24 и ошибок
приложения — для последующей централизованной доставки (Vector → ClickHouse) и разбора.
**По умолчанию выключено: пока не включишь — поведение пакета не меняется вовсе, ничего
не пишется.** Подключение zero-config: пакет уже установлен, канал регистрируется через
auto-discovery, включается парой переменных `.env`.

**Как включить**

```injectablephp
STRUCTURED_LOG_ENABLED=true
APP_LOG_NAME=base
# необязательные:
STRUCTURED_LOG_PATH=storage/logs/structured/app.json
STRUCTURED_LOG_MAX_FILES=14
STRUCTURED_LOG_TRUNCATE_AT=200
```

- `APP_LOG_NAME` — метка `app` в каждой записи (какое приложение пишет); если пуст,
  берётся `config('app.name')`. Задавайте явно, когда логи многих приложений сливаются
  в одно хранилище.

**Что пишется**

- **REST-вызовы Битрикс24** — ровно одна запись на вызов: метод, параметры (для
  воспроизведения) и исход (`ok`/`http`/`duration_ms`, `id` либо `count`, при ошибке —
  `error`). Полный `result` не тащится. Многословный debug-поток родного SDK в этот
  канал не течёт.
- **Ошибки приложения** — записи уровня `WARNING` и выше (`Log::warning/error`,
  необработанные исключения) дублируются в тот же файл. Стек логирования приложения при
  этом не переопределяется — канал работает как дополнительный приёмник.

**Где файл и ротация**

`storage/logs/structured/app.json` через monolog `RotatingFileHandler` (daily) — реально
пишется `app-ГГГГ-ММ-ДД.json`, старые файлы monolog удаляет сам, оставляя последние
`STRUCTURED_LOG_MAX_FILES` дней. Локальный файл — это короткий буфер на случай
недоступности доставки, а не архив; долгое хранение — в ClickHouse по TTL (см. ниже).

**Что маскируется**

- **Секреты** — значения ключей `auth`/`AUTH`, `access_token`, `refresh_token`,
  `application_token`, `webhook_token` и JWT-подобные строки (`eyJ…`) → `***`,
  рекурсивно по всей записи.
- **Персональные данные** — только для методов `user.*` вырезаются `NAME`, `LAST_NAME`,
  `SECOND_NAME`, `EMAIL`, `PERSONAL_MOBILE`, `PERSONAL_PHONE`, `WORK_PHONE`, `LOGIN`.
  Вне `user.*` не трогаются (чтобы, например, `NAME` заголовка статьи сохранился).
- **Длинный контент** — любая строка длиннее `STRUCTURED_LOG_TRUNCATE_AT` (дефолт 200)
  обрезается с пометкой полной длины: `«…первые 200 символов… (всего N символов)»`.

Списки ключей-масок и лимит обрезки настраиваются в `config/structured-logging.php`
(`php artisan vendor:publish --provider="X3Group\Bitrix24\Bitrix24ServiceProvider"` для
переопределения; работает и с дефолтами без публикации).

**Поле `schema_version`**

Каждая запись помечена `schema_version` (старт `"1"`) — константой формата в коде пакета.
При несовместимом изменении состава полей версия поднимается, чтобы хранилище и разбор
различали старые и новые записи и не ломались на них.

Доставка лога в хранилище (Vector → ClickHouse) — задача инфраструктуры, от кода
приложения не зависит; чек-лист девопсу: [`docs/structured-logging-infra.md`](docs/structured-logging-infra.md).
