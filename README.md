Пакет laravel для удобной работы с REST API Битрикс24 и написания приложений

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