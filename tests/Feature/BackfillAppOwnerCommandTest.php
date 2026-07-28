<?php

namespace X3Group\Bitrix24\Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use X3Group\Bitrix24\Models\B24App;
use X3Group\Bitrix24\Models\B24User;
use X3Group\Bitrix24\Tests\TestCase;

/**
 * Команда-обёртка над бэкофиллом владельца app-токена.
 *
 * Правила отбора владельца проверяет {@see AppOwnerBackfillTest}; здесь — только то, за что
 * отвечает сама команда: что она пишет ровно то же, что посчитала, что --dry-run не пишет
 * НИЧЕГО, что повторный прогон безопасен, и что отчёт не приукрашивает картину — оператор
 * решает по нему, звать ли ремонт.
 */
class BackfillAppOwnerCommandTest extends TestCase
{
    public function test_fills_the_owner_for_a_portal_whose_token_belongs_to_a_confirmed_admin(): void
    {
        $this->portal('m-ok', 'ok.bitrix24.ru', self::tokenFor(221));
        $this->user('m-ok', 'ok.bitrix24.ru', 221, isAdmin: true);

        [$exit, $out] = $this->runCommand();

        self::assertSame(0, $exit, 'код возврата прогона');
        self::assertSame(221, $this->ownerOf('m-ok'));
        self::assertStringContainsString('заполнено 1, осталось NULL 0', $out);
    }

    /**
     * Прогон «посмотреть» обязан быть читающим целиком: его запускают на живом флоте
     * именно затем, чтобы решить, запускать ли настоящий.
     */
    public function test_dry_run_writes_nothing(): void
    {
        $this->portal('m-ok', 'ok.bitrix24.ru', self::tokenFor(221));
        $this->user('m-ok', 'ok.bitrix24.ru', 221, isAdmin: true);

        [$exit, $out] = $this->runCommand(['--dry-run' => true]);

        self::assertSame(0, $exit, 'код возврата прогона');
        self::assertNull($this->ownerOf('m-ok'), 'dry-run записал владельца в базу');

        // При этом отчёт показывает не ноль, а то, что было бы сделано.
        self::assertStringContainsString('[DRY-RUN]', $out);
        self::assertStringContainsString('заполнено 1, осталось NULL 0', $out);
    }

    /**
     * dry-run не должен «съедать» работу: после него настоящий прогон обязан сделать ровно
     * то, что было обещано.
     */
    public function test_real_run_after_a_dry_run_still_fills_everything(): void
    {
        $this->portal('m-ok', 'ok.bitrix24.ru', self::tokenFor(221));
        $this->user('m-ok', 'ok.bitrix24.ru', 221, isAdmin: true);

        $this->runCommand(['--dry-run' => true]);
        [$exit, $out] = $this->runCommand();

        self::assertSame(0, $exit, 'код возврата прогона');
        self::assertSame(221, $this->ownerOf('m-ok'));
        self::assertStringContainsString('заполнено 1', $out);
    }

    /**
     * Повторный запуск — штатный режим: знание о правах приезжает в b24_users со временем,
     * поэтому команду гоняют не один раз. Уже проставленного владельца она не пересматривает
     * (в том числе назначенного вручную ремонтом), а заполняет только то, что появилось.
     */
    public function test_second_run_fills_nothing_and_leaves_the_owner_alone(): void
    {
        $this->portal('m-ok', 'ok.bitrix24.ru', self::tokenFor(221));
        $this->user('m-ok', 'ok.bitrix24.ru', 221, isAdmin: true);

        $this->runCommand();
        [$exit, $out] = $this->runCommand();

        self::assertSame(0, $exit, 'код возврата прогона');
        self::assertSame(221, $this->ownerOf('m-ok'));
        self::assertStringContainsString('заполнено 0, осталось NULL 0', $out);
    }

    /**
     * Отчёт — единственный выход команды наружу, и по нему принимают решения: звать ремонт,
     * ждать входа админов или искать ошибку интеграции. Поэтому проверяются все четыре
     * корзины разом: перепутанные местами счётчики поодиночке не видны.
     */
    public function test_report_tells_every_bucket_apart(): void
    {
        $this->portal('m-ok', 'ok.bitrix24.ru', self::tokenFor(221));
        $this->user('m-ok', 'ok.bitrix24.ru', 221, isAdmin: true);

        // Владелец разобран и точно не админ — это и есть поломка.
        $this->portal('m-not-admin', 'not-admin.bitrix24.ru', self::tokenFor(154));
        $this->user('m-not-admin', 'not-admin.bitrix24.ru', 154, isAdmin: false);

        // Про владельца в b24_users нет ни строки: прав мы не знаем, поломкой не считаем.
        $this->portal('m-unknown', 'unknown.bitrix24.ru', self::tokenFor(77));

        // Владелец не читается из токена.
        $this->portal('m-broken', 'broken.bitrix24.ru', 'not-a-token');

        [$exit, $out] = $this->runCommand();

        self::assertSame(0, $exit, 'код возврата прогона');
        self::assertStringContainsString(
            'заполнено 1, осталось NULL 3 (владелец не админ 1, владелец неизвестен 1, токен не разобран 1)',
            $out,
        );

        // Ремонтопригодные порталы названы поимённо — иначе оператору не с чем идти к
        // bitrix24:reanchor-app-token.
        self::assertStringContainsString('порталы на ремонт (bitrix24:reanchor-app-token): not-admin.bitrix24.ru', $out);
        self::assertStringContainsString('владелец не найден в b24_users, таких порталов: 1', $out);
        self::assertStringContainsString('владелец не читается из access_token, таких порталов: 1', $out);
        self::assertStringContainsString('broken.bitrix24.ru', $out);

        // Ни один портал из проблемных корзин не заполнен: сомнение оставляет NULL.
        self::assertNull($this->ownerOf('m-not-admin'));
        self::assertNull($this->ownerOf('m-unknown'));
        self::assertNull($this->ownerOf('m-broken'));
    }

    public function test_report_stays_quiet_when_there_is_nothing_to_report(): void
    {
        [$exit, $out] = $this->runCommand();

        self::assertSame(0, $exit, 'код возврата прогона');
        self::assertStringContainsString('заполнено 0, осталось NULL 0', $out);
        self::assertStringNotContainsString('ВНИМАНИЕ', $out);
        self::assertStringNotContainsString('на ремонт', $out);
    }

    // --- инфраструктура теста ---

    /**
     * @return array{int, string}
     */
    private function runCommand(array $parameters = []): array
    {
        $exit = Artisan::call('bitrix24:backfill-app-owner', $parameters);

        return [$exit, Artisan::output()];
    }

    private function portal(string $memberId, string $domain, string $accessToken): void
    {
        B24App::query()->create([
            'member_id' => $memberId,
            'domain' => $domain,
            'access_token' => $accessToken,
            'refresh_token' => 'refresh-' . $memberId,
            'expires' => time() + 3600,
            'expires_in' => 3600,
        ]);
    }

    private function user(string $memberId, string $domain, int $userId, bool $isAdmin): void
    {
        B24User::query()->create([
            'member_id' => $memberId,
            'domain' => $domain,
            'user_id' => $userId,
            'is_admin' => $isAdmin,
            'access_token' => self::tokenFor($userId),
            'refresh_token' => "refresh-$memberId-$userId",
            'expires' => time() + 3600,
            'expires_in' => 3600,
        ]);
    }

    /** Токен формата Bitrix24: 24 hex-символа префикса, 8 hex-цифр владельца, хвост. */
    private static function tokenFor(int $ownerId): string
    {
        return 'deadbeefdeadbeefdeadbeef'
            . str_pad(dechex($ownerId), 8, '0', STR_PAD_LEFT)
            . 'cafebabecafebabe';
    }

    private function ownerOf(string $memberId): ?int
    {
        return B24App::query()->where('member_id', $memberId)->sole()->user_id;
    }
}
