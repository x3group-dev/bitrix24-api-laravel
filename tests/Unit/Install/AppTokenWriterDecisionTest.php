<?php

namespace X3Group\Bitrix24\Tests\Unit\Install;

use PHPUnit\Framework\TestCase;
use X3Group\Bitrix24\Application\Local\Infrastructure\Database\AppTokenWriter;

class AppTokenWriterDecisionTest extends TestCase
{
    public function test_writes_on_first_install_regardless_of_admin(): void
    {
        $this->assertTrue(AppTokenWriter::shouldWrite(appExists:false, isAdmin:false));
        $this->assertTrue(AppTokenWriter::shouldWrite(appExists:false, isAdmin:true));
    }
    public function test_admin_may_overwrite_existing(): void
    {
        $this->assertTrue(AppTokenWriter::shouldWrite(appExists:true, isAdmin:true));
    }
    public function test_non_admin_never_overwrites_existing(): void
    {
        $this->assertFalse(AppTokenWriter::shouldWrite(appExists:true, isAdmin:false));
    }

    public function test_propagates_when_user_is_the_installer(): void
    {
        $this->assertTrue(AppTokenWriter::shouldPropagateFromUser(ownerUserId: 221, userId: 221));
    }

    public function test_does_not_propagate_for_another_user(): void
    {
        // Ядро фикса: токен рядового сотрудника не должен попадать в b24_apps.
        $this->assertFalse(AppTokenWriter::shouldPropagateFromUser(ownerUserId: 221, userId: 162));
    }

    public function test_does_not_propagate_when_installer_is_unknown(): void
    {
        // NULL = владелец не установлен или не доверен -> fail-closed.
        $this->assertFalse(AppTokenWriter::shouldPropagateFromUser(ownerUserId: null, userId: 221));
    }
}
