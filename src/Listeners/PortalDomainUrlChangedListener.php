<?php

namespace X3Group\Bitrix24\Listeners;

use Bitrix24\SDK\Events\PortalDomainUrlChangedEvent;
use X3Group\Bitrix24\Models\B24App;
use X3Group\Bitrix24\Models\B24User;

class PortalDomainUrlChangedListener
{
    public function __construct()
    {
        //
    }

    public function handle(PortalDomainUrlChangedEvent $event): void
    {
        B24App::query()
            ->where('domain', $event->getOldDomainUrlHost())
            ->update([
                'domain' => $event->getNewDomainUrlHost(),
            ]);

        B24User::query()
            ->where('domain', $event->getOldDomainUrlHost())
            ->update([
                'domain' => $event->getNewDomainUrlHost()
            ]);
    }
}
