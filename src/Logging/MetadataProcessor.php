<?php

namespace X3Group\Bitrix24\Logging;

use Monolog\LogRecord;

class MetadataProcessor
{
    /** @var callable */
    private $memberIdResolver;

    public function __construct(
        private readonly string $schemaVersion,
        private readonly string $app,
        private readonly string $env,
        callable $memberIdResolver,
        private readonly ?string $requestId = null,
    ) {
        $this->memberIdResolver = $memberIdResolver;
    }

    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(extra: array_merge($record->extra, [
            'schema_version' => $this->schemaVersion,
            'app' => $this->app,
            'env' => $this->env,
            'member_id' => ($this->memberIdResolver)(),
            'level' => strtolower($record->level->getName()),
            'request_id' => $this->requestId ?? $this->fallbackRequestId(),
        ]));
    }

    private function fallbackRequestId(): string
    {
        // Стабильный на процесс идентификатор, если не передан внешний X-Request-Id.
        static $id = null;
        if ($id === null) {
            $id = bin2hex(random_bytes(8));
        }

        return $id;
    }
}
