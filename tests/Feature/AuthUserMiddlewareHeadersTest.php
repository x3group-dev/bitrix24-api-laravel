<?php

namespace X3Group\Bitrix24\Tests\Feature;

use X3Group\Bitrix24\Http\Middleware\B24AuthUserMiddleware;
use X3Group\Bitrix24\Tests\TestCase;

/**
 * Контракт отказов B24AuthUserMiddleware.
 *
 * Заголовки фронтового запроса middleware разбирает сам, и обязательных среди
 * них четыре, а не три: `X-b24api-expires-in` идёт в `AuthToken::$expires`,
 * который объявлен как non-nullable `int`. Проверялись же только memberId,
 * domain и accessToken — без expires-in конструктор бросал `TypeError`.
 *
 * Ловушка глубже одного заголовка: `TypeError` — это `\Error`, а не
 * `\Exception`, поэтому задуманный `catch` его не перехватывал и наружу вместо
 * 401 уходила 500. В приложении-потребителе так набралось 1686 пятисоток за
 * три недели: вкладки SPA, открытые до раскатки новой сборки фронтенда,
 * продолжали ходить без этого заголовка.
 */
class AuthUserMiddlewareHeadersTest extends TestCase
{
    private const MEMBER_ID = 'member-1';

    private const ROUTE = '/b24-front-request-probe';

    protected function defineRoutes($router): void
    {
        $router->get(self::ROUTE, fn() => response()->json(['reached' => true]))
            ->middleware(B24AuthUserMiddleware::class);
    }

    /** Полный набор заголовков; отдельные ключи тесты убирают или портят. */
    private function headers(array $overrides = []): array
    {
        return array_filter(
            array_merge([
                'X-b24api-member-id' => self::MEMBER_ID,
                'X-b24api-domain' => 'portal.bitrix24.ru',
                'X-b24api-access-token' => 'access-1',
                'X-b24api-refresh-token' => 'refresh-1',
                'X-b24api-expires-in' => (string) (time() + 3600),
            ], $overrides),
            fn($value) => $value !== null,
        );
    }

    public function test_missing_expires_in_header_is_rejected_with_406(): void
    {
        $response = $this->withHeaders($this->headers(['X-b24api-expires-in' => null]))
            ->getJson(self::ROUTE);

        $response->assertStatus(406);
        $this->assertStringContainsString('X-b24api-expires-in', $response->json('error') ?? '');
    }

    public function test_non_integer_expires_in_header_is_rejected_with_406(): void
    {
        $response = $this->withHeaders($this->headers(['X-b24api-expires-in' => 'not-a-number']))
            ->getJson(self::ROUTE);

        $response->assertStatus(406);
        $this->assertStringContainsString('X-b24api-expires-in', $response->json('error') ?? '');
    }

    /**
     * Отдельно от заголовков: любой \Error внутри блока авторизации обязан
     * стать 401, а не 500. Гейт на expires-in закрывает известный вход, но не
     * класс проблемы — следующий TypeError придёт из другого места.
     *
     * Детали Error наружу не отдаём: в его сообщении лежат пути vendor.
     */
    public function test_unexpected_error_becomes_401_not_500(): void
    {
        $this->app->bind('userEvents', fn() => throw new \TypeError('boom'));

        $response = $this->withHeaders($this->headers())->getJson(self::ROUTE);

        $response->assertStatus(401);
        $this->assertStringNotContainsString('boom', $response->getContent());
    }

    /**
     * Тексты трёх прежних отказов — часть контракта: потребители уже видят
     * именно их. Тест держит их неизменными, пока меняется соседний код.
     */
    public function test_existing_header_rejections_keep_their_messages(): void
    {
        $cases = [
            'X-b24api-member-id' => 'memberId is null',
            'X-b24api-domain' => 'domain is null',
            'X-b24api-access-token' => 'access token is null',
        ];

        foreach ($cases as $header => $message) {
            // withHeaders() домешивает в общий набор, а не заменяет его: без
            // сброса заголовок, убранный на прошлой итерации, вернулся бы.
            $this->flushHeaders();

            $response = $this->withHeaders($this->headers([$header => null]))->getJson(self::ROUTE);

            $response->assertStatus(406);
            $this->assertSame($message, $response->json('error'), "заголовок {$header}");
        }
    }
}
