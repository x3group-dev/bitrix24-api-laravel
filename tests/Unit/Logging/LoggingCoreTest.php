<?php

namespace X3Group\Bitrix24\Tests\Unit\Logging;

use Bitrix24\SDK\Core\Contracts\ApiClientInterface;
use Bitrix24\SDK\Core\Contracts\ApiVersion;
use Bitrix24\SDK\Core\Contracts\CoreInterface;
use Bitrix24\SDK\Core\Response\DTO\ResponseData;
use Bitrix24\SDK\Core\Response\Response;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Contracts\HttpClient\ResponseInterface;
use X3Group\Bitrix24\Logging\LoggingCore;

class LoggingCoreTest extends TestCase
{
    private function fakeCore(callable $onCall): CoreInterface
    {
        return new class($onCall) implements CoreInterface {
            /** @var callable */
            private $onCall;

            public function __construct(callable $onCall)
            {
                $this->onCall = $onCall;
            }

            public function call(string $apiMethod, array $parameters = [], ApiVersion $apiVersion = ApiVersion::v1): Response
            {
                return ($this->onCall)($apiMethod, $parameters);
            }

            public function getApiClient(): ApiClientInterface
            {
                throw new \LogicException('n/a');
            }

            public function setAuthConnector(?string $authConnector): void
            {
            }

            public function getAuthConnector(): ?string
            {
                return null;
            }
        };
    }

    private function recordingLogger(array &$records): AbstractLogger
    {
        return new class($records) extends AbstractLogger {
            /** @var array<int, array<string, mixed>> */
            private array $records;

            public function __construct(array &$records)
            {
                $this->records = &$records;
            }

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
            }
        };
    }

    /**
     * Собираем настоящий Response нельзя без сети, поэтому используем PHPUnit-мок
     * с методами, к которым обращается LoggingCore::outcome().
     */
    private function response(array $result, int $status = 200): Response
    {
        $http = $this->createMock(ResponseInterface::class);
        $http->method('getStatusCode')->willReturn($status);

        $data = $this->createMock(ResponseData::class);
        $data->method('getResult')->willReturn($result);

        $response = $this->createMock(Response::class);
        $response->method('getHttpResponse')->willReturn($http);
        $response->method('getResponseData')->willReturn($data);

        return $response;
    }

    public function test_logs_one_record_per_call_with_request_and_outcome(): void
    {
        $records = [];
        $inner = $this->fakeCore(fn ($m, $p) => $this->response(['ID' => 42]));

        $core = new LoggingCore($inner, $this->recordingLogger($records), 'portal.bitrix24.ru');
        $core->call('entity.item.add', ['ENTITY' => 'BASE_A', 'auth' => 'secret']);

        $this->assertCount(1, $records);
        $ctx = $records[0]['context'];
        $this->assertSame('entity.item.add', $ctx['request']['method']);
        $this->assertSame('BASE_A', $ctx['request']['params']['ENTITY']);
        $this->assertSame('portal.bitrix24.ru', $ctx['request']['domain']);
        $this->assertSame(1, $ctx['request']['apiVersion']);
        $this->assertTrue($ctx['response']['ok']);
        $this->assertArrayHasKey('duration_ms', $ctx['response']);
        $this->assertSame(200, $ctx['response']['http']);
        $this->assertSame(42, $ctx['response']['id']);
    }

    public function test_logs_error_and_rethrows(): void
    {
        $records = [];
        $inner = $this->fakeCore(function () {
            throw new \RuntimeException('boom');
        });
        $core = new LoggingCore($inner, $this->recordingLogger($records));

        try {
            $core->call('entity.get', []);
            $this->fail('exception expected');
        } catch (\RuntimeException) {
        }

        $this->assertCount(1, $records);
        $this->assertFalse($records[0]['context']['response']['ok']);
        $this->assertSame('boom', $records[0]['context']['response']['error']['description']);
    }
}
