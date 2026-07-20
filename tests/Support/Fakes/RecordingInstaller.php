<?php

namespace X3Group\Bitrix24\Tests\Support\Fakes;

use Bitrix24\SDK\Services\ServiceBuilder;
use X3Group\Bitrix24\Contracts\Bitrix24Installer;

class RecordingInstaller implements Bitrix24Installer
{
    /** @param string[] $journal */
    public function __construct(private array &$journal, private string $tag, private ?\Throwable $throw = null) {}

    public function install(ServiceBuilder $b24): void
    {
        $this->journal[] = $this->tag;
        if ($this->throw !== null) {
            throw $this->throw;
        }
    }
}
