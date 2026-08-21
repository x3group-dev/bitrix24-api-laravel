<?php

namespace X3Group\Bitrix24\Tests\Feature;

use Bitrix24\SDK\Core\ApiClient;
use Bitrix24\SDK\Core\Credentials\ApplicationProfile;
use Bitrix24\SDK\Core\Credentials\AuthToken;
use Bitrix24\SDK\Core\Credentials\Scope;
use ReflectionObject;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use X3Group\Bitrix24\Core\B24HttpClientFactory;
use X3Group\Bitrix24\Core\B24ServiceBuilderFactory;
use X3Group\Bitrix24\Tests\TestCase;

/**
 * Потолок длительности исходящего REST-запроса.
 *
 * У Symfony HttpClient опция `timeout` — это таймаут ПРОСТОЯ (сколько ждать
 * очередной порции данных), а вовсе не общая длительность запроса. Общую
 * ограничивает только `max_duration`, и по умолчанию она равна 0, то есть
 * «без ограничения». Ни `HttpClient::create()` в обёртке, ни `CoreBuilder`
 * в SDK (там задан лишь `'timeout' => 120`) её не выставляли — значит портал,
 * который принял соединение и отвечает по капле, держал воркер вечно.
 *
 * Так и легло приложение dependent-fields 2026-08-20: 18 из 20 php-fpm
 * воркеров сидели по 2–5 часов в одном исходящем вызове (сигнатура —
 * ровно один voluntary context switch в секунду, то есть цикл
 * `curl_multi_select($mh, 1.0)`), пул исчерпался, nginx отдавал
 * `connect() failed (11: Resource temporarily unavailable)` — 84 128 ответов
 * 502 за сутки. Ни `max_execution_time` (не считает время в I/O), ни
 * `request_terminate_timeout` (в пуле не задан) такой запрос не снимали.
 *
 * Поэтому потолок задаём сами и проверяем оба места, где рождается клиент:
 * биндинг {@see ApiClient} (обновление токена) и {@see B24ServiceBuilderFactory}
 * (все обычные REST-вызовы).
 */
class HttpClientHardTimeoutTest extends TestCase
{
    /** Опции, с которыми реально создан клиент: Symfony держит их приватно. */
    private function defaultOptions(HttpClientInterface $client): array
    {
        $property = (new ReflectionObject($client))->getProperty('defaultOptions');
        $property->setAccessible(true);

        return $property->getValue($client);
    }

    /** Клиент, спрятанный в SDK-шном ApiClient. */
    private function clientOf(ApiClient $apiClient): HttpClientInterface
    {
        $property = (new ReflectionObject($apiClient))->getProperty('client');
        $property->setAccessible(true);

        return $property->getValue($apiClient);
    }

    public function test_factory_bounds_total_duration_by_default(): void
    {
        $options = B24HttpClientFactory::options();

        $this->assertSame(120.0, $options['max_duration']);
        $this->assertSame(60.0, $options['timeout']);
        $this->assertSame('2.0', $options['http_version']);
    }

    public function test_factory_options_are_configurable(): void
    {
        config()->set('bitrix24.http.timeout', 15);
        config()->set('bitrix24.http.max_duration', 45);

        $options = B24HttpClientFactory::options();

        $this->assertSame(45.0, $options['max_duration']);
        $this->assertSame(15.0, $options['timeout']);
    }

    /**
     * Ноль в Symfony означает «без ограничения» — ровно то состояние, из-за
     * которого воркеры и висли. Конфиг не должен уметь его вернуть.
     */
    public function test_non_positive_values_fall_back_to_defaults(): void
    {
        config()->set('bitrix24.http.timeout', 0);
        config()->set('bitrix24.http.max_duration', 0);

        $options = B24HttpClientFactory::options();

        $this->assertSame(120.0, $options['max_duration']);
        $this->assertSame(60.0, $options['timeout']);
    }

    public function test_created_client_carries_the_bounded_options(): void
    {
        $options = $this->defaultOptions(B24HttpClientFactory::make());

        $this->assertSame(120.0, $options['max_duration']);
        $this->assertSame(60.0, $options['timeout']);
    }

    /** Голый `HttpClient::create()` — тот самый непотолоченный клиент. */
    public function test_bare_symfony_client_is_unbounded(): void
    {
        $options = $this->defaultOptions(HttpClient::create());

        $this->assertSame(0, $options['max_duration'], 'Symfony по умолчанию не ограничивает длительность запроса');
    }

    public function test_api_client_binding_uses_bounded_client(): void
    {
        /** @var ApiClient $apiClient */
        $apiClient = $this->app->make(ApiClient::class, [
            'memberId' => 'member-1',
            'domain' => 'portal.bitrix24.ru',
            'accessToken' => 'access-1',
            'refreshToken' => 'refresh-1',
            'expires' => time() + 3600,
            'expiresIn' => 3600,
        ]);

        $options = $this->defaultOptions($this->clientOf($apiClient));

        $this->assertSame(120.0, $options['max_duration']);
    }

    public function test_service_builder_factory_uses_bounded_client(): void
    {
        $serviceBuilder = (new B24ServiceBuilderFactory(
            eventDispatcher: new \Symfony\Component\EventDispatcher\EventDispatcher(),
            log: new \Psr\Log\NullLogger(),
        ))->init(
            applicationProfile: new ApplicationProfile('client-id', 'client-secret', Scope::initFromString('crm')),
            authToken: new AuthToken('access-1', 'refresh-1', 3600),
            bitrix24DomainUrl: 'https://portal.bitrix24.ru',
            oauthServerUrl: 'https://oauth.bitrix24.tech/',
        );

        $apiClient = $serviceBuilder->core->getApiClient();
        $this->assertInstanceOf(ApiClient::class, $apiClient);

        $options = $this->defaultOptions($this->clientOf($apiClient));

        $this->assertSame(120.0, $options['max_duration']);
    }
}
