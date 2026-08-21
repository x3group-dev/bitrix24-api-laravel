<?php

namespace X3Group\Bitrix24\Core;

use Bitrix24\SDK\Core\Batch;
use Bitrix24\SDK\Core\BulkItemsReader\BulkItemsReaderBuilder;
use Bitrix24\SDK\Core\CoreBuilder;
use Bitrix24\SDK\Core\Credentials\ApplicationProfile;
use Bitrix24\SDK\Core\Credentials\AuthToken;
use Bitrix24\SDK\Core\Credentials\Credentials;
use Bitrix24\SDK\Core\Credentials\DefaultOAuthServerUrl;
use Bitrix24\SDK\Core\Credentials\Endpoints;
use Bitrix24\SDK\Core\Credentials\WebhookUrl;
use Bitrix24\SDK\Core\Exceptions\InvalidArgumentException;
use Bitrix24\SDK\Services\ServiceBuilder;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Замена SDK-шному {@see \Bitrix24\SDK\Services\ServiceBuilderFactory} с одним
 * отличием: ядро собирается с HTTP-клиентом из {@see B24HttpClientFactory},
 * у которого есть потолок длительности запроса.
 *
 * Почему не наследование: `ServiceBuilderFactory::getServiceBuilder()` —
 * приватный метод, а статический `createServiceBuilderFromPlacementRequest()`
 * жёстко делает `new ServiceBuilderFactory(...)`. Подменить сборку ядра в
 * потомке нечем, поэтому сборка повторена здесь целиком — она короткая.
 *
 * Зачем вообще: у `CoreBuilder` в конструкторе задан только `'timeout' => 120`,
 * то есть таймаут простоя, а `max_duration` остаётся нулём — «без
 * ограничения». Подробности ловушки — в {@see B24HttpClientFactory}.
 */
final class B24ServiceBuilderFactory
{
    public function __construct(
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly LoggerInterface $log,
    ) {
    }

    /**
     * @param non-empty-string $bitrix24DomainUrl
     *
     * @throws InvalidArgumentException
     */
    public function init(
        ApplicationProfile $applicationProfile,
        AuthToken $authToken,
        string $bitrix24DomainUrl,
        string $oauthServerUrl,
    ): ServiceBuilder {
        return $this->getServiceBuilder(
            Credentials::createFromOAuth(
                $authToken,
                $applicationProfile,
                new Endpoints($bitrix24DomainUrl, $oauthServerUrl),
            )
        );
    }

    /**
     * @param non-empty-string $webhookUrl
     *
     * @throws InvalidArgumentException
     */
    public function initFromWebhook(string $webhookUrl): ServiceBuilder
    {
        return $this->getServiceBuilder(Credentials::createFromWebhook(new WebhookUrl($webhookUrl)));
    }

    /**
     * Повторяет контракт одноимённого статического метода SDK, включая разбор
     * и валидацию GET-параметра `DOMAIN`.
     *
     * @throws InvalidArgumentException
     */
    public static function createServiceBuilderFromPlacementRequest(
        Request $placementRequest,
        ApplicationProfile $applicationProfile,
        ?EventDispatcherInterface $eventDispatcher = null,
        ?LoggerInterface $logger = null,
        ?string $oauthServerUrl = null,
    ): ServiceBuilder {
        if (!in_array('DOMAIN', $placementRequest->query->keys(), true)) {
            throw new InvalidArgumentException('key «DOMAIN» not found in GET request arguments');
        }

        $rawDomainUrl = trim((string) $placementRequest->query->get('DOMAIN'));
        if ($rawDomainUrl === '') {
            throw new InvalidArgumentException('DOMAIN key cannot be empty in request');
        }

        $eventDispatcher ??= new EventDispatcher();
        $logger ??= new NullLogger();

        if ($oauthServerUrl === null) {
            $logger->warning('oauthServerUrl not set, you must set it manually or use DefaultOAuthServerUrl presets');
            $oauthServerUrl = DefaultOAuthServerUrl::default();
        }

        return (new self($eventDispatcher, $logger))->init(
            $applicationProfile,
            AuthToken::initFromPlacementRequest($placementRequest),
            $rawDomainUrl,
            $oauthServerUrl,
        );
    }

    /** @throws InvalidArgumentException */
    private function getServiceBuilder(Credentials $credentials): ServiceBuilder
    {
        $core = (new CoreBuilder())
            ->withEventDispatcher($this->eventDispatcher)
            ->withLogger($this->log)
            ->withHttpClient(B24HttpClientFactory::make())
            ->withCredentials($credentials)
            ->build();

        $batch = new Batch($core, $this->log);

        return new ServiceBuilder(
            $core,
            $batch,
            (new BulkItemsReaderBuilder($core, $batch, $this->log))->build(),
            $this->log,
        );
    }
}
