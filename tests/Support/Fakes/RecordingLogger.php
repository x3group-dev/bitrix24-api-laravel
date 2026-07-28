<?php

namespace X3Group\Bitrix24\Tests\Support\Fakes;

use Psr\Log\AbstractLogger;

/**
 * PSR-логгер, который вместо записи копит вызовы, чтобы тест мог проверить,
 * что событие вообще было залогировано и с какими полями.
 */
class RecordingLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    public array $records = [];

    public function log($level, \Stringable|string $message, array $context = []): void
    {
        $this->records[] = [
            'level' => (string) $level,
            'message' => (string) $message,
            'context' => $context,
        ];
    }

    /**
     * @return list<array{level: string, message: string, context: array<string, mixed>}>
     */
    public function ofLevel(string $level): array
    {
        return array_values(array_filter($this->records, fn(array $r): bool => $r['level'] === $level));
    }
}
