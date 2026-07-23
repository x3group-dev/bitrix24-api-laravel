<?php

namespace X3Group\Bitrix24\Logging;

use Monolog\LogRecord;

class PersonalDataProcessor
{
    /**
     * @param string[] $personalDataMethods
     * @param string[] $personalDataKeys
     */
    public function __construct(
        private readonly array $personalDataMethods,
        private readonly array $personalDataKeys,
    ) {
    }

    public function __invoke(LogRecord $record): LogRecord
    {
        $method = $record->context['request']['method'] ?? null;
        if (!is_string($method) || !in_array($method, $this->personalDataMethods, true)) {
            return $record;
        }

        return $record->with(context: $this->strip($record->context));
    }

    private function strip(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && in_array($key, $this->personalDataKeys, true)) {
                $data[$key] = '***';
                continue;
            }
            if (is_array($value)) {
                $data[$key] = $this->strip($value);
            }
        }

        return $data;
    }
}
