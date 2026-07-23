<?php

namespace X3Group\Bitrix24\Logging;

use Monolog\LogRecord;

class ContentTruncatingProcessor
{
    public function __construct(private readonly int $limit)
    {
    }

    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(context: $this->truncate($record->context));
    }

    private function truncate(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->truncate($value);
            } elseif (is_string($value) && mb_strlen($value) > $this->limit) {
                $total = mb_strlen($value);
                $data[$key] = mb_substr($value, 0, $this->limit) . "… (всего {$total} символов)";
            }
        }

        return $data;
    }
}
