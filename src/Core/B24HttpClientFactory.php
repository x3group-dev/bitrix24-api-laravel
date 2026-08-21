<?php

namespace X3Group\Bitrix24\Core;

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * HTTP-клиент для вызовов REST с жёстким потолком длительности.
 *
 * Ловушка, ради которой класс и заведён: у Symfony HttpClient `timeout` — это
 * таймаут ПРОСТОЯ (сколько ждать очередную порцию данных), а не общее время
 * запроса. Общее ограничивает только `max_duration`, и по умолчанию она равна
 * 0 — «без ограничения». Поэтому портал, который принял соединение и отвечает
 * по капле, держит php-fpm воркер сколько угодно: `timeout` не срабатывает,
 * потому что данные идут, `max_execution_time` не считает время в I/O, а
 * `request_terminate_timeout` в пуле обычно не задан.
 *
 * Так 2026-08-20 легло приложение dependent-fields: 18 из 20 воркеров сидели
 * по 2–5 часов в одном исходящем вызове, пул исчерпался и nginx сутки отдавал
 * 502. Ни SDK-шный `CoreBuilder` (у него только `'timeout' => 120`), ни голый
 * `HttpClient::create()` в биндингах обёртки потолка не ставили.
 *
 * Значения переопределяются через `config('bitrix24.http.*')`. Ноль и
 * отрицательные значения игнорируются: в Symfony ноль и есть «без
 * ограничения», то есть ровно то состояние, из-за которого всё и висло.
 */
final class B24HttpClientFactory
{
    /** Сколько ждать очередную порцию данных, секунд. */
    public const DEFAULT_TIMEOUT = 60.0;

    /**
     * Потолок всего запроса, секунд. Взят с запасом к SDK-шному `timeout: 120`,
     * чтобы не обрубать легитимно долгие вызовы: смысл не в том, чтобы запрос
     * был быстрым, а в том, чтобы он гарантированно закончился.
     */
    public const DEFAULT_MAX_DURATION = 120.0;

    public static function make(): HttpClientInterface
    {
        return HttpClient::create(self::options());
    }

    /**
     * @return array{http_version: string, timeout: float, max_duration: float}
     */
    public static function options(): array
    {
        return [
            'http_version' => '2.0',
            'timeout' => self::positiveOr('bitrix24.http.timeout', self::DEFAULT_TIMEOUT),
            'max_duration' => self::positiveOr('bitrix24.http.max_duration', self::DEFAULT_MAX_DURATION),
        ];
    }

    private static function positiveOr(string $configKey, float $default): float
    {
        $configured = function_exists('config') ? config($configKey) : null;

        if (!is_numeric($configured) || (float) $configured <= 0) {
            return $default;
        }

        return (float) $configured;
    }
}
