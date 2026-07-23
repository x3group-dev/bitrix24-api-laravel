<?php

namespace X3Group\Bitrix24\Tests\Unit\Logging;

use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;
use X3Group\Bitrix24\Logging\ContentTruncatingProcessor;
use X3Group\Bitrix24\Logging\MetadataProcessor;
use X3Group\Bitrix24\Logging\PersonalDataProcessor;
use X3Group\Bitrix24\Logging\SecretMaskingProcessor;

class ProcessorsTest extends TestCase
{
    private function record(array $context): LogRecord
    {
        return new LogRecord(
            datetime: new \DateTimeImmutable('2026-07-23T00:00:00+00:00'),
            channel: 'structured',
            level: Level::Info,
            message: 'test',
            context: $context,
            extra: []
        );
    }

    public function test_secret_masking_removes_tokens_recursively(): void
    {
        $p = new SecretMaskingProcessor(['auth', 'access_token', 'refresh_token']);
        $out = $p($this->record([
            'request' => ['params' => ['ENTITY' => 'BASE_A', 'auth' => 'dd9d616a-secret']],
            'access_token' => 'zzz',
            'keep' => 'visible',
        ]));

        $this->assertSame('***', $out->context['request']['params']['auth']);
        $this->assertSame('BASE_A', $out->context['request']['params']['ENTITY']);
        $this->assertSame('***', $out->context['access_token']);
        $this->assertSame('visible', $out->context['keep']);
    }

    public function test_personal_data_removed_only_for_user_methods(): void
    {
        $p = new PersonalDataProcessor(['user.get'], ['NAME', 'EMAIL']);

        $userRec = $p($this->record([
            'request' => ['method' => 'user.get'],
            'response' => ['NAME' => 'Иван', 'EMAIL' => 'ivan@example.com', 'ID' => 3],
        ]));
        $this->assertSame('***', $userRec->context['response']['NAME']);
        $this->assertSame('***', $userRec->context['response']['EMAIL']);
        $this->assertSame(3, $userRec->context['response']['ID']);

        // Для не-user метода NAME (заголовок статьи) не трогаем.
        $itemRec = $p($this->record([
            'request' => ['method' => 'entity.item.get'],
            'response' => ['NAME' => 'Заголовок статьи'],
        ]));
        $this->assertSame('Заголовок статьи', $itemRec->context['response']['NAME']);
    }

    public function test_content_truncation(): void
    {
        $p = new ContentTruncatingProcessor(20);
        $long = str_repeat('x', 100);
        $out = $p($this->record(['request' => ['params' => ['DETAIL_TEXT' => $long, 'NAME' => 'коротко']]]));

        $this->assertStringStartsWith(str_repeat('x', 20), $out->context['request']['params']['DETAIL_TEXT']);
        $this->assertStringContainsString('всего 100', $out->context['request']['params']['DETAIL_TEXT']);
        $this->assertSame('коротко', $out->context['request']['params']['NAME']);
    }

    public function test_metadata_added(): void
    {
        $p = new MetadataProcessor(
            schemaVersion: '1',
            app: 'base',
            env: 'testing',
            memberIdResolver: fn () => 'tenant-1',
        );
        $out = $p($this->record([]));

        $this->assertSame('1', $out->extra['schema_version']);
        $this->assertSame('base', $out->extra['app']);
        $this->assertSame('testing', $out->extra['env']);
        $this->assertSame('tenant-1', $out->extra['member_id']);
        $this->assertSame('info', $out->extra['level']);
        $this->assertArrayHasKey('request_id', $out->extra);
    }
}
