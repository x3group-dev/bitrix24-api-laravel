<?php
namespace X3Group\Bitrix24\Tests\Unit;

use PHPUnit\Framework\TestCase;
use X3Group\Bitrix24\Application\Local\OauthServerUrlResolver;

class OauthServerUrlResolverTest extends TestCase
{
    public function test_derives_from_server_endpoint_host(): void
    {
        $this->assertSame('https://oauth.bitrix24.tech/', OauthServerUrlResolver::fromServerEndpoint('https://oauth.bitrix24.tech/rest/'));
        $this->assertSame('https://oauth.bitrix.info/', OauthServerUrlResolver::fromServerEndpoint('https://oauth.bitrix.info/rest/'));
    }
    public function test_empty_or_invalid_falls_back_to_east(): void
    {
        $this->assertSame('https://oauth.bitrix24.tech/', OauthServerUrlResolver::fromServerEndpoint(null));
        $this->assertSame('https://oauth.bitrix24.tech/', OauthServerUrlResolver::fromServerEndpoint(''));
        $this->assertSame('https://oauth.bitrix24.tech/', OauthServerUrlResolver::fromServerEndpoint('garbage'));
        $this->assertSame('https://oauth.bitrix24.tech/', OauthServerUrlResolver::EAST);
    }
    public function test_or_default_returns_east_for_empty_stored(): void
    {
        $this->assertSame('https://oauth.bitrix24.tech/', OauthServerUrlResolver::orDefault(null));
        $this->assertSame('https://x/', OauthServerUrlResolver::orDefault('https://x/'));
    }
}
