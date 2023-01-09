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

