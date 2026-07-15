<?php

namespace X3Group\Bitrix24\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use X3Group\Bitrix24\Support\OAuthErrorInspector;

class OAuthErrorInspectorTest extends TestCase
{
    public function test_detects_application_not_installed(): void
    {
        $this->assertTrue(OAuthErrorInspector::isApplicationNotInstalled(
            new \Exception('Getting new access token failure: unauthorized (error: NOT_INSTALLED, description: Application not installed)')
        ));

        // регистр не важен
        $this->assertTrue(OAuthErrorInspector::isApplicationNotInstalled(
            new \RuntimeException('application not installed')
        ));
    }

    public function test_ignores_other_errors(): void
    {
        $this->assertFalse(OAuthErrorInspector::isApplicationNotInstalled(
            new \Exception('Getting new access token failure: unauthorized (error: PAYMENT_REQUIRED, description: Subscription has been ended)')
        ));

        $this->assertFalse(OAuthErrorInspector::isApplicationNotInstalled(
            new \Exception('Connection timed out')
        ));
    }
}
