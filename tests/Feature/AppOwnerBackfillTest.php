<?php

namespace X3Group\Bitrix24\Tests\Feature;

use X3Group\Bitrix24\Models\B24App;
use X3Group\Bitrix24\Models\B24User;
use X3Group\Bitrix24\Support\AppOwnerBackfill;
use X3Group\Bitrix24\Tests\TestCase;

/**
 * Бэкофилл владельца app-токена — единственное место, где владение восстанавливается
 * задним числом, поэтому здесь проверяется не «работает ли заполнение», а правило
 * fail-closed: user_id проставляется ТОЛЬКО подтверждённому администратору того же
 * портала, а любое сомнение оставляет NULL.
 */
class AppOwnerBackfillTest extends TestCase
{
    public function test_admin_owner_is_written_and_counted_as_filled(): void
    {
        $this->seedPortal('m-1', 'p1.bitrix24.ru', self::tokenFor(221));
        $this->seedUser('m-1', 'p1.bitrix24.ru', 221, isAdmin: true);

        $summary = (new AppOwnerBackfill())->run();

        self::assertSame(221, $this->ownerOf('m-1'));
        self::assertSame(1, $summary['filled']);
        self::assertSame([], $summary['notAdmin']);
        self::assertSame([], $summary['unknownOwner']);
        self::assertSame([], $summary['unparseable']);
    }

    public function test_owner_that_is_not_admin_stays_null_and_is_listed_for_repair(): void
    {
        // Ровно тот случай, ради которого всё затевалось: app-токен портала принадлежит
        // рядовому сотруднику. Записать его владельцем значило бы узаконить подмену.
        $this->seedPortal('m-1', 'p1.bitrix24.ru', self::tokenFor(154));
        $this->seedUser('m-1', 'p1.bitrix24.ru', 154, isAdmin: false);

        $summary = (new AppOwnerBackfill())->run();

        self::assertNull($this->ownerOf('m-1'));
        self::assertSame(0, $summary['filled']);
        self::assertSame(['p1.bitrix24.ru'], $summary['notAdmin']);
        self::assertSame([], $summary['unknownOwner']);
        self::assertSame([], $summary['unparseable']);
    }

    public function test_owner_without_b24_users_row_stays_null_and_is_listed_as_unknown_owner(): void
    {
        // Нормальная ситуация для чисто API-приложений: b24_users не заполняется вообще,
        // поэтому админ владелец или нет — неизвестно. Неизвестность != поломка.
        $this->seedPortal('m-1', 'p1.bitrix24.ru', self::tokenFor(221));

        $summary = (new AppOwnerBackfill())->run();

        self::assertNull($this->ownerOf('m-1'));
        self::assertSame(0, $summary['filled']);
        self::assertSame([], $summary['notAdmin']);
        self::assertSame(['p1.bitrix24.ru'], $summary['unknownOwner']);
        self::assertSame([], $summary['unparseable']);
    }

    public function test_admin_row_of_a_different_user_does_not_vouch_for_the_owner(): void
    {
        // На портале есть админ (8), но токен принадлежит другому человеку (221),
        // записи о котором нет. Это unknownOwner, а не notAdmin: чужая строка
        // не должна ни подтверждать владельца, ни обвинять его.
        $this->seedPortal('m-1', 'p1.bitrix24.ru', self::tokenFor(221));
        $this->seedUser('m-1', 'p1.bitrix24.ru', 8, isAdmin: true);

        $summary = (new AppOwnerBackfill())->run();

        self::assertNull($this->ownerOf('m-1'));
        self::assertSame(0, $summary['filled']);
        self::assertSame([], $summary['notAdmin']);
        self::assertSame(['p1.bitrix24.ru'], $summary['unknownOwner']);
        self::assertSame([], $summary['unparseable']);
    }

    public function test_admin_of_another_portal_does_not_vouch_for_the_owner(): void
    {
        // Один и тот же user_id на разных порталах — разные люди. Проверка админства
        // обязана быть привязана к member_id, иначе админ чужого портала «подтвердит»
        // владельца там, где подтверждать нечего.
        $this->seedPortal('m-a', 'a.bitrix24.ru', self::tokenFor(221));
        $this->seedPortal('m-b', 'b.bitrix24.ru', self::tokenFor(221));
        $this->seedUser('m-b', 'b.bitrix24.ru', 221, isAdmin: true);

        $summary = (new AppOwnerBackfill())->run();

        self::assertNull($this->ownerOf('m-a'));
        self::assertSame(221, $this->ownerOf('m-b'));
        self::assertSame(1, $summary['filled']);
        self::assertSame([], $summary['notAdmin']);
        self::assertSame(['a.bitrix24.ru'], $summary['unknownOwner']);
        self::assertSame([], $summary['unparseable']);
    }

    public function test_token_shorter_than_the_owner_segment_is_unparseable(): void
    {
        // 30 символов вместо 32: сегмента владельца просто нет. Токен целиком hex и
        // обрывается «правдоподобно» — без проверки длины substr() вернул бы хвост
        // 'abcdef', то есть владельца 11259375, и такой портал молча заполнился бы.
        $this->seedPortal('m-1', 'p1.bitrix24.ru', 'deadbeefdeadbeefdeadbeefabcdef');
        $this->seedUser('m-1', 'p1.bitrix24.ru', 0xabcdef, isAdmin: true);

        $summary = (new AppOwnerBackfill())->run();

        self::assertNull($this->ownerOf('m-1'));
        self::assertSame(0, $summary['filled']);
        self::assertSame(['p1.bitrix24.ru'], $summary['unparseable']);
        self::assertSame([], $summary['notAdmin']);
        self::assertSame([], $summary['unknownOwner']);
    }

    public function test_token_with_non_hex_owner_segment_is_unparseable(): void
    {
        // Длина нужная, но в позиции владельца не hex. hexdec() на таком мусоре молча
        // вернул бы 0x1234 = 4660, то есть «владельца», которого не существует.
        $this->seedPortal('m-1', 'p1.bitrix24.ru', 'deadbeefdeadbeefdeadbeef1z2z3z4zcafebabecafebabe');
        $this->seedUser('m-1', 'p1.bitrix24.ru', 4660, isAdmin: true);

        $summary = (new AppOwnerBackfill())->run();

        self::assertNull($this->ownerOf('m-1'));
        self::assertSame(0, $summary['filled']);
        self::assertSame(['p1.bitrix24.ru'], $summary['unparseable']);
        self::assertSame([], $summary['notAdmin']);
        self::assertSame([], $summary['unknownOwner']);
    }

    public function test_token_with_zero_owner_segment_is_unparseable(): void
    {
        // Пользователя с ID 0 не бывает — значит формат не распознан.
        $this->seedPortal('m-1', 'p1.bitrix24.ru', 'deadbeefdeadbeefdeadbeef00000000cafebabecafebabe');

        $summary = (new AppOwnerBackfill())->run();

        self::assertNull($this->ownerOf('m-1'));
        self::assertSame(0, $summary['filled']);
        self::assertSame(['p1.bitrix24.ru'], $summary['unparseable']);
        self::assertSame([], $summary['notAdmin']);
        self::assertSame([], $summary['unknownOwner']);
    }

    public function test_every_non_filled_case_leaves_user_id_null(): void
    {
        $this->seedPortal('m-ok', 'ok.bitrix24.ru', self::tokenFor(221));
        $this->seedUser('m-ok', 'ok.bitrix24.ru', 221, isAdmin: true);

        $this->seedPortal('m-not-admin', 'not-admin.bitrix24.ru', self::tokenFor(154));
        $this->seedUser('m-not-admin', 'not-admin.bitrix24.ru', 154, isAdmin: false);

        $this->seedPortal('m-unknown', 'unknown.bitrix24.ru', self::tokenFor(77));

        $this->seedPortal('m-broken', 'broken.bitrix24.ru', 'not-a-token');

        $summary = (new AppOwnerBackfill())->run();

        self::assertSame(1, $summary['filled']);
        self::assertSame(221, $this->ownerOf('m-ok'));

        self::assertNull($this->ownerOf('m-not-admin'));
        self::assertNull($this->ownerOf('m-unknown'));
        self::assertNull($this->ownerOf('m-broken'));

        self::assertSame(['not-admin.bitrix24.ru'], $summary['notAdmin']);
        self::assertSame(['unknown.bitrix24.ru'], $summary['unknownOwner']);
        self::assertSame(['broken.bitrix24.ru'], $summary['unparseable']);
    }

    public function test_second_run_fills_nothing_but_still_reports_the_remaining_problems(): void
    {
        $this->seedPortal('m-ok', 'ok.bitrix24.ru', self::tokenFor(221));
        $this->seedUser('m-ok', 'ok.bitrix24.ru', 221, isAdmin: true);

        $this->seedPortal('m-not-admin', 'not-admin.bitrix24.ru', self::tokenFor(154));
        $this->seedUser('m-not-admin', 'not-admin.bitrix24.ru', 154, isAdmin: false);

        $this->seedPortal('m-unknown', 'unknown.bitrix24.ru', self::tokenFor(77));
        $this->seedPortal('m-broken', 'broken.bitrix24.ru', 'not-a-token');

        (new AppOwnerBackfill())->run();
        $second = (new AppOwnerBackfill())->run();

        // Повторный прогон не переделывает уже сделанное…
        self::assertSame(0, $second['filled']);
        self::assertSame(221, $this->ownerOf('m-ok'));

        // …но и не «излечивает» отчёт: проблемные порталы никуда не делись,
        // и молчание вместо них было бы враньём оператору.
        self::assertSame(['not-admin.bitrix24.ru'], $second['notAdmin']);
        self::assertSame(['unknown.bitrix24.ru'], $second['unknownOwner']);
        self::assertSame(['broken.bitrix24.ru'], $second['unparseable']);
    }

    public function test_deliberately_set_user_id_survives_and_is_not_a_repair_candidate(): void
    {
        // Так выглядит портал, починенный вручную через bitrix24:reanchor-app-token:
        // владельцем осознанно назначен админ (8), хотя в токене всё ещё лежит
        // сотрудник (154), который админом не является. Бэкофилл не должен ни
        // переписывать это решение, ни снова звать портал в ремонт.
        $this->seedPortal('m-1', 'p1.bitrix24.ru', self::tokenFor(154), userId: 8);
        $this->seedUser('m-1', 'p1.bitrix24.ru', 154, isAdmin: false);
        $this->seedUser('m-1', 'p1.bitrix24.ru', 8, isAdmin: true);

        $summary = (new AppOwnerBackfill())->run();

        self::assertSame(8, $this->ownerOf('m-1'));
        self::assertSame(0, $summary['filled']);
        self::assertSame([], $summary['notAdmin']);
        self::assertSame([], $summary['unknownOwner']);
        self::assertSame([], $summary['unparseable']);
    }

    public function test_processes_every_null_row_across_a_chunk_boundary(): void
    {
        // run() ходит по базе страницами по 200. Порталы с NULL чередуются с уже
        // заполненными по всему диапазону id, поэтому 250 обрабатываемых строк
        // действительно распределены на две страницы (200 + 50), а не лежат
        // сплошным блоком в начале, где границу можно и не пересечь.
        $total = 500;

        for ($i = 1; $i <= $total; $i++) {
            $memberId = "m-$i";
            $domain = "p$i.bitrix24.ru";
            $owner = 1000 + $i;

            if ($i % 2 === 1) {
                // уже заполнено и намеренно НЕ совпадает с владельцем из токена:
                // если такую строку тронут, это будет видно
                $this->seedPortal($memberId, $domain, self::tokenFor($owner), userId: 9000 + $i);

                continue;
            }

            $this->seedPortal($memberId, $domain, self::tokenFor($owner));
            $this->seedUser($memberId, $domain, $owner, isAdmin: true);
        }

        $summary = (new AppOwnerBackfill())->run();

        self::assertSame(250, $summary['filled']);
        self::assertSame([], $summary['notAdmin']);
        self::assertSame([], $summary['unknownOwner']);
        self::assertSame([], $summary['unparseable']);

        $owners = B24App::query()->orderBy('id')->pluck('user_id', 'member_id')->all();

        $expected = [];
        for ($i = 1; $i <= $total; $i++) {
            $expected["m-$i"] = $i % 2 === 1 ? 9000 + $i : 1000 + $i;
        }

        // Поимённо: каждая строка получила своего владельца, никого не пропустили
        // и ничьё значение не затёрли соседним.
        self::assertSame($expected, $owners);
    }

    private function seedPortal(string $memberId, string $domain, string $accessToken, ?int $userId = null): void
    {
        B24App::query()->create([
            'member_id' => $memberId,
            'domain' => $domain,
            'user_id' => $userId,
            'access_token' => $accessToken,
            'refresh_token' => 'refresh-' . $memberId,
            'expires' => time() + 3600,
            'expires_in' => 3600,
        ]);
    }

    private function seedUser(string $memberId, string $domain, int $userId, bool $isAdmin): void
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

    /**
     * Синтетический токен формата Bitrix24: 24 hex-символа заведомо фейкового префикса,
     * затем 8 hex-цифр владельца, затем произвольный хвост.
     */
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
