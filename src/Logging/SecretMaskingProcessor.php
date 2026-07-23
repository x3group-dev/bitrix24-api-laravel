<?php

namespace X3Group\Bitrix24\Logging;

use Monolog\LogRecord;

class SecretMaskingProcessor
{
    /** @param string[] $secretKeys */
    public function __construct(private readonly array $secretKeys)
    {
    }

    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(context: $this->mask($record->context));
    }

    private function mask(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && in_array($key, $this->secretKeys, true)) {
                $data[$key] = '***';
                continue;
            }
            if (is_array($value)) {
                $data[$key] = $this->mask($value);
            } elseif (is_string($value) && str_starts_with($value, 'eyJ')) {
                // JWT-подобное значение
                $data[$key] = '***';
            }
        }

        return $data;
    }
}
