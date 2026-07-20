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
