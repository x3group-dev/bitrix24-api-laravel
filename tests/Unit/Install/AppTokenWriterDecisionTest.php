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
}
