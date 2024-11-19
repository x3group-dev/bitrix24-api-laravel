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
php artisan vendor:publish --provider="\X3Group\Bitrix24\Bitrix24ServiceProvider"
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
