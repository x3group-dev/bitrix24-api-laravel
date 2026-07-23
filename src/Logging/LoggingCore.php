<?php

namespace X3Group\Bitrix24\Logging;

use Bitrix24\SDK\Core\Contracts\ApiClientInterface;
use Bitrix24\SDK\Core\Contracts\ApiVersion;
use Bitrix24\SDK\Core\Contracts\CoreInterface;
use Bitrix24\SDK\Core\Response\Response;
use Psr\Log\LoggerInterface;

/**
 * Декоратор CoreInterface: на каждый b24-вызов пишет одну структурированную запись
 * {request, response} в PSR-3 logger. Маскирование секретов/ПД и обрезку делают
 * процессоры канала (см. Task 3), сам LoggingCore кладёт params как есть.
 */
class LoggingCore implements CoreInterface
{
    public function __construct(
        private readonly CoreInterface $inner,
        private readonly LoggerInterface $logger,
        private readonly string $domain = '',
    ) {
    }

    public function call(string $apiMethod, array $parameters = [], ApiVersion $apiVersion = ApiVersion::v1): Response
    {
        $start = microtime(true);

        try {
            $response = $this->inner->call($apiMethod, $parameters, $apiVersion);
        } catch (\Throwable $e) {
            $this->write($apiMethod, $parameters, $apiVersion, [
                'ok' => false,
                'duration_ms' => $this->ms($start),
                'error' => ['code' => (string) $e->getCode(), 'description' => $e->getMessage()],
            ]);
            throw $e;
        }

        $this->write($apiMethod, $parameters, $apiVersion, $this->outcome($response, $this->ms($start)));

        return $response;
    }

    private function outcome(Response $response, int $ms): array
    {
        $outcome = ['ok' => true, 'duration_ms' => $ms];

        try {
            $outcome['http'] = $response->getHttpResponse()->getStatusCode();
        } catch (\Throwable) {
            // http-код недоступен — не критично
        }

        try {
            $result = $response->getResponseData()->getResult();
            if (array_is_list($result)) {
                $outcome['count'] = count($result);
            } elseif (isset($result['ID']) || isset($result['id'])) {
                $outcome['id'] = $result['ID'] ?? $result['id'];
            } else {
                $outcome['count'] = count($result);
            }
        } catch (\Throwable) {
            // тело недоступно/непарсибельно — оставляем только ok+duration
        }

        return $outcome;
    }

    private function write(string $method, array $params, ApiVersion $apiVersion, array $response): void
    {
        $this->logger->info('b24rest', [
            'channel' => 'b24rest',
            'request' => [
                'method' => $method,
                'params' => $params,
                'domain' => $this->domain,
                'apiVersion' => $apiVersion->value,
            ],
            'response' => $response,
        ]);
    }

    private function ms(float $start): int
    {
        return (int) round((microtime(true) - $start) * 1000);
    }

    public function getApiClient(): ApiClientInterface
    {
        return $this->inner->getApiClient();
    }

    public function setAuthConnector(?string $authConnector): void
    {
        $this->inner->setAuthConnector($authConnector);
    }

    public function getAuthConnector(): ?string
    {
        return $this->inner->getAuthConnector();
    }
}
