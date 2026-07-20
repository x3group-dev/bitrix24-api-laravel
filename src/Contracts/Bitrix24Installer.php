<?php

namespace X3Group\Bitrix24\Contracts;

use Bitrix24\SDK\Services\ServiceBuilder;

interface Bitrix24Installer
{
    public function install(ServiceBuilder $b24): void;
}
