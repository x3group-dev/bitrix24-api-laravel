<?php

namespace X3Group\Bitrix24\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use X3Group\Bitrix24\Application\Local\Infrastructure\Database\AppTokenWriter;
use X3Group\Bitrix24\Bitrix24App;
use X3Group\Bitrix24\Models\B24App;
use X3Group\Bitrix24\Models\B24User;

/**
 * Ремонт порталов, у которых app-токен принадлежит не тому человеку.
 *
 * Кандидаты — строки b24_apps, где владелец не задан (user_id IS NULL, в том числе после
 * бэкофилла, который отказался принимать не-админа) либо записанный владелец не является
 * администратором по данным b24_users. У таких порталов админ-методы Bitrix
 * (userfieldconfig.* и другие) отвечают «нет прав», и снаружи приложение выглядит
 * сломанным.
 *
 * ПОЧЕМУ ПИШЕТСЯ ТОКЕН, А НЕ ТОЛЬКО КОЛОНКА. Перепривязка одного user_id ничего не чинит:
 * правило 2 донесёт до b24_apps свежий токен нового владельца только тогда, когда тот
 * сам откроет приложение или у него обновится токен. Админ, живущий на API-роутах и во
 * фронтенд не заходящий, не сделает этого никогда — портал остался бы со сломанным
 * токеном навсегда, а в колонке значилось бы, что он починен.
 *
 * ГЕЙТ АДМИНСТВА. Команда пишет в b24_apps в обход
 * {@see AppTokenWriter::saveIfAllowed()}, поэтому проверку админства вызывает явно —
 * {@see AppTokenWriter::shouldWrite()} с appExists: true, где она вырождается ровно в
 * «владельцем становится только администратор». Причина не пользоваться saveIfAllowed()
 * описана у {@see self::reanchor()}.
 *
 * СЧЁТЧИК ОШИБОК. error_update сбрасывается вместе с токеном: при error_update >= 10
 * {@see Bitrix24App::renewTokens()} исключает портал из ротации обновления токенов, и
 * починенный портал остался бы мёртвым.
 *
 * Порталы без администраторов в b24_users не трогаются и печатаются как «нужна
 * переустановка». Их может оказаться много и это не обязательно поломка: is_admin
 * заполняется только когда пользователь открывает фронтенд приложения, поэтому у чисто
 * API-приложений b24_users пуст целиком. Ровно ради этого случая --limit ограничивает
 * число КАНДИДАТОВ, а не число ремонтов: иначе один прогон на флоте в 25 тысяч порталов
 * выдал бы таблицу на 25 тысяч строк.
 */
class ReanchorAppTokenCommand extends Command
{
    protected $signature = 'bitrix24:reanchor-app-token
        {--dry-run : Только показать план, не записывая ничего}
        {--member= : Обработать только этот member_id}
        {--user= : Взять токен именно этого пользователя портала (обязан быть администратором)}
        {--limit=200 : Максимум порталов-кандидатов за прогон; 0 — без ограничения}
        {--skip-verify : Не проверять результат живым вызовом user.admin}';

    protected $description = 'Перепривязать app-токен портала на администратора (ремонт после подмены владельца)';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $forcedUserId = $this->option('user') === null ? null : (int) $this->option('user');
        $limit = (int) $this->option('limit');

        $query = $this->candidates();

        if ($member = $this->option('member')) {
            $query->where('member_id', $member);
        }

        if ($limit > 0) {
            $query->limit($limit);
        }

        $candidates = $query->get();

        if ($candidates->isEmpty()) {
            $this->info('Порталов, требующих перепривязки, не найдено.');

            return self::SUCCESS;
        }

        $rows = [];
        $repaired = 0;
        $rolledBack = 0;
        $refused = 0;
        $needReinstall = 0;

        /** @var B24App $b24app */
        foreach ($candidates as $b24app) {
            $previousOwner = $b24app->user_id === null ? 'NULL' : (string) $b24app->user_id;
            $admin = $this->pickAdmin($b24app, $forcedUserId);

            if ($admin === null) {
                $needReinstall++;
                $rows[] = [$b24app->domain, $previousOwner, '—', $forcedUserId === null
                    ? 'нет админов в b24_users: нужна переустановка'
                    : "пользователя {$forcedUserId} нет в b24_users этого портала"];

                continue;
            }

            // Ловушка №1: гейт админства НЕ переезжает вместе с колонкой user_id. Прямая
            // запись в b24_apps не наследует никакой проверки, поэтому та же самая
            // функция, что решает вопрос при установке, вызывается здесь явно.
            if (!AppTokenWriter::shouldWrite(appExists: true, isAdmin: (bool) $admin->is_admin)) {
                $refused++;
                $rows[] = [
                    $b24app->domain,
                    $previousOwner,
                    (string) $admin->user_id,
                    "пользователь {$admin->user_id} не администратор: отказано",
                ];

                continue;
            }

            if ($dryRun) {
                $rows[] = [$b24app->domain, $previousOwner, (string) $admin->user_id, 'dry-run: перепривязал бы'];

                continue;
            }

            $result = $this->reanchor($b24app, $admin);

            if (str_starts_with($result, 'откат')) {
                $rolledBack++;
            } else {
                $repaired++;
            }

            $rows[] = [$b24app->domain, $previousOwner, (string) $admin->user_id, $result];
        }

        $this->table(['портал', 'было', 'стало', 'результат'], $rows);

        $this->info(sprintf(
            '%sКандидатов: %d, перепривязано: %d, откатов: %d, отказов: %d, нужна переустановка: %d',
            $dryRun ? '[DRY-RUN] ' : '',
            $candidates->count(),
            $repaired,
            $rolledBack,
            $refused,
            $needReinstall,
        ));

        return self::SUCCESS;
    }

    /**
     * Кандидаты одним запросом: владелец не задан ЛИБО по нему нет строки b24_users с
     * is_admin = true (её нет вовсе или в ней false). Отдельный SELECT на каждый портал
     * здесь стоил бы 25 тысяч запросов на прогон.
     *
     * @return Builder<B24App>
     */
    private function candidates(): Builder
    {
        return B24App::query()
            ->orderBy('id')
            ->where(function (Builder $builder): void {
                $builder
                    ->whereNull('user_id')
                    ->orWhereNotExists(function ($sub): void {
                        $sub->select(DB::raw(1))
                            ->from('b24_users')
                            ->whereColumn('b24_users.member_id', 'b24_apps.member_id')
                            ->whereColumn('b24_users.user_id', 'b24_apps.user_id')
                            ->where('b24_users.is_admin', true);
                    });
            });
    }

    /**
     * Под --user строка берётся БЕЗ фильтра is_admin — намеренно. Отказ обязан приходить
     * от гейта в handle(), иначе «не администратор» и «нет такой строки» слились бы в
     * один невнятный ответ, а сам гейт остался бы без единственного сценария, который
     * его проверяет.
     */
    private function pickAdmin(B24App $b24app, ?int $forcedUserId): ?B24User
    {
        $query = B24User::query()->where('member_id', $b24app->member_id);

        if ($forcedUserId !== null) {
            return $query->where('user_id', $forcedUserId)->first();
        }

        return $query->where('is_admin', true)->orderByDesc('expires')->first();
    }

    /**
     * Записывает токен администратора вместе с владельцем и проверяет результат живым
     * вызовом. Неудача возвращает строку РОВНО в прежний вид: полуоткат оставил бы портал
     * в состоянии, которого не было ни до ремонта, ни после.
     *
     * Токен переносится из b24_users как есть, включая expires. Это не небрежность:
     * b24_users.expires и b24_apps.expires — обе АБСОЛЮТНЫЕ метки времени (b24_users
     * пишет B24AppUserMiddleware как time() + AUTH_EXPIRES - 600, b24_apps так же читают
     * {@see RemoveUninstalledPortals} и {@see Bitrix24App::renewTokens}). Поэтому запись
     * идёт напрямую, а не через {@see AppTokenWriter::saveIfAllowed()}: тот пишет через
     * AppAuthDatabaseStorage::save(), который при пустом expiresIn считает expires СРОКОМ
     * ЖИЗНИ и кладёт в колонку now() + expires — сложение двух timestamp-ов припарковало
     * бы портал в 2083 году (та же ловушка описана в {@see \X3Group\Bitrix24\Application\Install\InstallTokenProbe::tokenForStorage()}).
     * Он же переписал бы domain значением с протоколом, а application_token — тем, что
     * лежит в LocalAppAuth. Гейт админства при этом не теряется: он вызван явно выше.
     */
    private function reanchor(B24App $b24app, B24User $admin): string
    {
        $backup = [
            'access_token' => $b24app->access_token,
            'refresh_token' => $b24app->refresh_token,
            'expires' => $b24app->expires,
            'expires_in' => $b24app->expires_in,
            'user_id' => $b24app->user_id,
            'error_update' => $b24app->error_update,
        ];

        B24App::query()->whereKey($b24app->getKey())->update([
            'access_token' => $admin->access_token,
            'refresh_token' => $admin->refresh_token,
            'expires' => $admin->expires,
            'expires_in' => $admin->expires_in ?? 3600,
            'user_id' => $admin->user_id,
            'error_update' => 0,
        ]);

        logger()->info('reanchored app token', [
            'member_id' => $b24app->member_id,
            'domain' => $b24app->domain,
            'previous_user_id' => $backup['user_id'],
            'user_id' => $admin->user_id,
        ]);

        if ($this->option('skip-verify')) {
            return 'перепривязан (проверка пропущена)';
        }

        try {
            if ($this->verifyAdmin($b24app->member_id)) {
                return 'перепривязан, user.admin=true';
            }

            $reason = 'user.admin=false';
        } catch (\Throwable $e) {
            $reason = $e->getMessage();
        }

        B24App::query()->whereKey($b24app->getKey())->update($backup);

        logger()->warning('reanchor rolled back', [
            'member_id' => $b24app->member_id,
            'domain' => $b24app->domain,
            'user_id' => $admin->user_id,
            'reason' => $reason,
        ]);

        return 'откат: ' . $reason;
    }

    /**
     * Живая проверка: тем самым токеном, который только что записан, спрашиваем у портала,
     * админ ли мы. Выделено в метод для переопределения в тестах — по той же причине и
     * тем же способом, что probe() в {@see RemoveUninstalledPortals}.
     *
     * Скалярный result Битрикса SDK заворачивает в массив из одного элемента, отсюда [0].
     */
    protected function verifyAdmin(string $memberId): bool
    {
        $result = (new Bitrix24App($memberId))->api->core
            ->call('user.admin', [])
            ->getResponseData()
            ->getResult();

        return ($result[0] ?? false) === true;
    }
}
