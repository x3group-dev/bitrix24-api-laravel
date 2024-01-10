Пакет laravel для удобной работы с REST API Битрикс24 и написания приложений

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

Выполнить публикацию (скопируются routes и blade)
```injectablephp
php artisan vendor:publish --provider="\X3Group\B24Api\Providers\B24ApiServiceProvider"
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
B24API_CLIENT_ID=
B24API_CLIENT_SECRET=
```

В сборку фронта добавить проброс авторизации в заголовках
чтобы работали роуты b24appFrontRequest

```injectablephp
BX24.ready(async function () {
    await BX24.init(async function () {
        window.axios.defaults.headers.common['X-b24api-access-token'] = BX24.getAuth().access_token;
        window.axios.defaults.headers.common['X-b24api-domain'] = BX24.getAuth().domain;
        window.axios.defaults.headers.common['X-b24api-member-id'] = BX24.getAuth().member_id;
    });
});
```

**Cron**

Для автообновления токенов приложения обязательно требуется добавить запись в crontab

```php
* * * * * cd /path-to-your-project && php artisan schedule:run >> /dev/null 2>&1
```
