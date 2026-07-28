<?php

namespace X3Group\Bitrix24\Support;

use Illuminate\Support\Facades\DB;

/**
 * Заполняет b24_apps.user_id для порталов, установленных до появления этой колонки.
 *
 * Владельца берём из самого токена и принимаем ТОЛЬКО если это подтверждённый
 * администратор: на повреждённых порталах в токене лежит рядовой сотрудник, и записать
 * его владельцем значило бы узаконить подмену. Во всех остальных случаях строка остаётся
 * с NULL (fail-closed) — правило «обновлять токен портала вместе с токеном установщика»
 * для неё просто не сработает.
 *
 * Незаполненные строки разводим по трём причинам, потому что они требуют разной реакции
 * оператора, а общий счётчик смешивал бы диагноз с симптомом:
 *
 * - notAdmin — владелец разобран, запись в b24_users есть, is_admin = false. Вот это и
 *   есть искомая находка: app-токен портала принадлежит не тому человеку. Единственный
 *   список, который стоит печатать целиком, — это кандидаты в ремонт через
 *   bitrix24:reanchor-app-token.
 * - unknownOwner — владелец разобран, но записи о нём в b24_users нет, поэтому admin он
 *   или нет мы не знаем. Это НЕ признак поломки: is_admin пишет только
 *   B24AppUserMiddleware, когда пользователь открывает фронтенд приложения. У
 *   приложений, живущих на одной API-группе роутов, b24_users не заполняется вообще, и
 *   без отдельной корзины сюда попал бы весь флот с ярлыком «на ремонт».
 * - unparseable — владельца не удалось вытащить из токена. Единичные случаи бывают
 *   (пустой или обрезанный токен), но большое число означает не «много сломанных
 *   порталов», а ошибку интеграции: читаем не ту колонку, сменился формат токена,
 *   значения зашифрованы. Это повод разобраться, а не чинить порталы по одному.
 *
 * Обрабатываем только строки с user_id IS NULL, поэтому повторный запуск дозаполняет
 * оставшееся, а не переделывает работу заново. Побочное полезное свойство: то, что
 * bitrix24:reanchor-app-token проставил осознанно, повторный прогон не пересматривает.
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
