<?php

namespace X3Group\Bitrix24\Tests\Feature;

use Bitrix24\SDK\Core\Credentials\ApplicationProfile;
use Bitrix24\SDK\Core\Credentials\AuthToken;
use Bitrix24\SDK\Core\Credentials\Credentials;
use Bitrix24\SDK\Core\Credentials\Endpoints;
use Bitrix24\SDK\Core\Credentials\Scope;
use Bitrix24\SDK\Core\CoreBuilder;
use Bitrix24\SDK\Core\Contracts\CoreInterface;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use X3Group\Bitrix24\Core\B24Credentials;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventDispatcher;
use X3Group\Bitrix24\Core\B24ServiceBuilderFactory;
use X3Group\Bitrix24\Tests\TestCase;

class PortalDomainChangeTest extends TestCase
{
    private function credentialsFromFactory(string $domainUrl): Credentials
    {
        $serviceBuilder = (new B24ServiceBuilderFactory(new EventDispatcher(), new NullLogger()))->init(
            applicationProfile: new ApplicationProfile('client-id', 'client-secret', Scope::initFromString('crm')),
            authToken: new AuthToken(accessToken: 'access', refreshToken: 'refresh', expires: time() + 3600, expiresIn: 3600),
            bitrix24DomainUrl: $domainUrl,
            oauthServerUrl: 'https://oauth.bitrix.info/',
        );

        return $serviceBuilder->core->getApiClient()->getCredentials();
    }

    public function test_domain_change_on_redirect_is_applied(): void
    {
        $credentials = $this->credentialsFromFactory('https://old.bitrix24.ru');

        $credentials->changeDomainUrl('https://new.bitrix24.ru');

        self::assertSame('https://new.bitrix24.ru', $credentials->getDomainUrl());
    }

    /** @param MockResponse[] $responses */
    private function coreWith(array $responses, Credentials $credentials): CoreInterface
    {
        return (new CoreBuilder())
            ->withHttpClient(new MockHttpClient($responses))
            ->withCredentials($credentials)
            ->withLogger(new NullLogger())
            ->build();
    }

    private function oauthCredentials(string $class): Credentials
    {
        return new $class(
            webhookUrl: null,
            authToken: new AuthToken(accessToken: 'access', refreshToken: 'refresh', expires: time() + 3600, expiresIn: 3600),
            applicationProfile: new ApplicationProfile('client-id', 'client-secret', Scope::initFromString('crm')),
            endpoints: new Endpoints('https://old.bitrix24.ru', 'https://oauth.bitrix.info/'),
        );
    }

    /** @return MockResponse[] */
    private function redirectThenOk(): array
    {
        return [
            new MockResponse('', [
                'http_code' => 302,
                'response_headers' => ['Location' => 'https://new.bitrix24.ru/rest/crm.deal.get'],
            ]),
            new MockResponse('{"result":{"ID":1}}', ['http_code' => 200]),
        ];
    }

    public function test_redirect_is_retried_against_the_new_domain(): void
    {
        $responses = $this->redirectThenOk();

        $this->coreWith($responses, $this->oauthCredentials(B24Credentials::class))->call('crm.deal.get', ['id' => 1]);

        self::assertStringStartsWith('https://new.bitrix24.ru/', $responses[1]->getRequestUrl());
    }

    /**
     * Канарейка на дефект SDK: базовые Credentials смену домена игнорируют,
     * поэтому повтор уходит на тот же старый адрес — отсюда и бесконечный цикл.
     * Когда апстрим это починит, тест покраснеет: значит B24Credentials больше не нужен.
     */
    public function test_vendor_credentials_still_ignore_domain_change(): void
    {
        $responses = $this->redirectThenOk();

        $this->coreWith($responses, $this->oauthCredentials(Credentials::class))->call('crm.deal.get', ['id' => 1]);

        self::assertStringStartsWith('https://old.bitrix24.ru/', $responses[1]->getRequestUrl());
    }
}
