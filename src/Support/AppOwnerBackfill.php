<?php

namespace X3Group\Bitrix24\Support;

use Illuminate\Support\Facades\DB;

/**
 * Заполняет b24_apps.user_id для порталов, установленных до появления этой колонки.
 *
 * Владелец декодируется из самого access-токена и принимается ТОЛЬКО если это
 * подтверждённый по b24_users администратор: на повреждённых порталах в токене лежит
 * рядовой сотрудник, и записать его владельцем значило бы узаконить подмену. Иначе строка
 * остаётся с NULL (fail-closed).
 *
 * Незаполненные строки разводятся по трём корзинам — они требуют разной реакции:
 *
 * - notAdmin — владелец разобран, is_admin = false. Это и есть искомая поломка; такие
 *   порталы — вход для bitrix24:reanchor-app-token.
 * - unknownOwner — владелец разобран, но записи о нём в b24_users нет, прав мы не знаем.
 *   НЕ признак поломки: is_admin пишет только B24AppUserMiddleware, поэтому у приложений
 *   на API-роутах сюда закономерно попадает весь флот.
 * - unparseable — владельца не удалось вытащить из токена. Единичные случаи нормальны,
 *   массовые означают ошибку интеграции (не та колонка, сменился формат, шифрование).
 *
 * Обрабатываются только строки с user_id IS NULL, поэтому повторный запуск дозаполняет
 * оставшееся и не пересматривает то, что проставил ремонт.
 */
class AppOwnerBackfill
{
    /**
     * @return array{filled: int, notAdmin: list<string>, unknownOwner: list<string>, unparseable: list<string>}
     */
    public function run(): array
    {
        $filled = 0;
        $notAdmin = [];
        $unknownOwner = [];
        $unparseable = [];

        DB::table('b24_apps')
            ->whereNull('user_id')
            ->orderBy('id')
            ->chunkById(200, function ($rows) use (&$filled, &$notAdmin, &$unknownOwner, &$unparseable) {
                foreach ($rows as $row) {
                    $owner = TokenOwner::fromAccessToken($row->access_token);

                    if ($owner === null) {
                        $unparseable[] = $row->domain;

                        continue;
                    }

                    $isAdmin = $this->isAdmin($row->member_id, $owner);

                    if ($isAdmin === null) {
                        $unknownOwner[] = $row->domain;

                        continue;
                    }

                    if ($isAdmin === false) {
                        $notAdmin[] = $row->domain;

                        continue;
                    }

                    DB::table('b24_apps')->where('id', $row->id)->update(['user_id' => $owner]);
                    $filled++;
                }
            });

        return [
            'filled' => $filled,
            'notAdmin' => $notAdmin,
            'unknownOwner' => $unknownOwner,
            'unparseable' => $unparseable,
        ];
    }

    /**
     * @return bool|null true/false — что записано в b24_users; null — записи о таком
     *                   пользователе нет, то есть ответа у нас просто нет
     */
    private function isAdmin(string $memberId, int $userId): ?bool
    {
        $user = DB::table('b24_users')
            ->where('member_id', $memberId)
            ->where('user_id', $userId)
            ->first(['is_admin']);

        return $user === null ? null : (bool) $user->is_admin;
    }
}
