<?php

namespace X3Group\Bitrix24\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use X3Group\Bitrix24\Support\TokenOwner;

class TokenOwnerTest extends TestCase
{
    public function test_decodes_user_id_from_token(): void
    {
        // Синтетические токены, имитирующие формат реального Bitrix24 access-токена:
        // 24-символьный заведомо фейковый префикс + 8 hex-цифр владельца (смещение 24) + произвольный хвост.
        self::assertSame(162, TokenOwner::fromAccessToken('deadbeefdeadbeefdeadbeef000000a2cafebabecafebabe'));
        self::assertSame(8, TokenOwner::fromAccessToken('deadbeefdeadbeefdeadbeef00000008cafebabecafebabe'));
        self::assertSame(221, TokenOwner::fromAccessToken('deadbeefdeadbeefdeadbeef000000ddcafebabecafebabe'));
    }

    public function test_returns_null_for_unusable_input(): void
    {
        self::assertNull(TokenOwner::fromAccessToken(null));
        self::assertNull(TokenOwner::fromAccessToken(''));
        self::assertNull(TokenOwner::fromAccessToken('слишком-короткий'));
    }

    public function test_returns_null_when_segment_is_not_hex(): void
    {
        // 32+ символа, но в позиции владельца не hex — формат не наш.
        self::assertNull(TokenOwner::fromAccessToken(str_repeat('z', 40)));
    }

    public function test_returns_null_for_zero_owner(): void
    {
        // Нулевой ID пользователя не существует — считаем нераспознанным.
        // Тот же синтетический формат, что и выше, но owner-сегмент обнулён.
        self::assertNull(TokenOwner::fromAccessToken('deadbeefdeadbeefdeadbeef00000000cafebabecafebabe'));
    }
}
