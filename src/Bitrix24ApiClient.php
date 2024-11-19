<?php

namespace X3Group\Bitrix24;

use Bitrix24\SDK\Services\ServiceBuilder;

class Bitrix24ApiClient
{
    public function __construct(
        /**
         * Выполнение запросов с токеном приложения
         */
        public ServiceBuilder $app,

        /**
         * Выполнение запросов с токеном пользователя
         */
        public ServiceBuilder $user,
    )
    {

    }
}
