<?php

namespace X3Group\Bitrix24\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use X3Group\Bitrix24\Application\Local\Infrastructure\Database\AppTokenWriter;
use X3Group\Bitrix24\Bitrix24App;
use X3Group\Bitrix24\Models\B24App;
use X3Group\Bitrix24\Models\B24User;

/**
 * Ремонт порталов, у которых app-токен принадлежит не администратору.
 *
 * ЧЕТЫРЕ КОРЗИНЫ. Различать их обязательно, и по той же причине, по которой их различает
 * {@see \X3Group\Bitrix24\Support\AppOwnerBackfill}: is_admin заполняет только
 * B24AppUserMiddleware, то есть заход пользователя на размещение приложения. У приложений,
 * живущих на API-роутах (routes/b24app.php), b24_users не заполняется вообще — и без
 * отдельной корзины «нет данных» весь флот такого приложения получил бы ярлык «нужна
 * переустановка», хотя каждый его портал установлен администратором, прошедшим живую
 * проверку в InstallService.
 *
 *  - ЗДОРОВ: владелец записан и подтверждён админом в b24_users. Не трогаем.
 *  - НА РЕМОНТ: владельца нет (user_id IS NULL) ЛИБО про него точно известно, что он не
 *    админ, — И на портале есть хотя бы один админ, которым можно перепривязаться.
 *    Только эта корзина расходует --limit.
 *  - НУЖНА ПЕРЕУСТАНОВКА: про владельца точно известно, что он не админ, а перепривязаться
 *    не на кого. Это настоящая поломка, которую сами мы починить не можем.
 *  - НЕТ ДАННЫХ: владелец записан, но строки о нём в b24_users нет. Свидетельств против
 *    него нет никаких, поэтому и трогать его нечего, и звать клиента на переустановку не
 *    за что. Только считаем.
 *
 * ПОЧЕМУ ПИШЕТСЯ ТОКЕН, А НЕ ТОЛЬКО КОЛОНКА. Перепривязка одного user_id ничего не чинит:
 * правило 2 донесёт до b24_apps свежий токен нового владельца только тогда, когда тот сам
 * откроет приложение или у него обновится токен. Админ, живущий на API-роутах и во
 * фронтенд не заходящий, не сделает этого никогда — портал остался бы со сломанным токеном
 * навсегда, а в колонке значилось бы, что он починен.
 *
 * ГЕЙТ АДМИНСТВА. Команда пишет в b24_apps в обход {@see AppTokenWriter::saveIfAllowed()},
 * поэтому проверку админства вызывает явно — {@see AppTokenWriter::shouldWrite()} с
 * appExists: true, где она вырождается ровно в «владельцем становится только
 * администратор». Почему не через saveIfAllowed() — см. {@see self::reanchor()}.
 *
 * СЧЁТЧИК ОШИБОК. error_update сбрасывается вместе с токеном: при error_update >= 10
 * {@see Bitrix24App::renewTokens()} исключает портал из ротации обновления токенов, и
 * починенный портал остался бы мёртвым.
 *
 * ВСЕ ПОДЗАПРОСЫ КОРРЕЛИРОВАНЫ ПО member_id. Это не украшение: user_id — маленькое целое,
 * общее для тысяч порталов флота. Потеряв корреляцию в проверке владельца, команда сочла
 * бы здоровым любой портал, чей владелец оказался админом хоть где-нибудь; потеряв её при
 * выборе админа — записала бы в портал токен ЧУЖОГО портала, то есть ровно ту поломку,
 * ради устранения которой всё это и написано.
 */
class ReanchorAppTokenCommand extends Command
{
    protected $signature = 'bitrix24:reanchor-app-token
        {--dry-run : Только показать план, не записывая ничего}
        {--member= : Обработать только этот member_id}
        {--user= : Взять токен именно этого пользователя портала (обязан быть администратором)}
        {--limit=200 : Максимум порталов за прогон; 0 — без ограничения}
        {--max-age=30 : Не брать в новые владельцы админа, чей токен не обновлялся дольше стольких дней; 0 — без ограничения}
        {--skip-verify : Не проверять результат живым вызовом user.admin; только вместе с --member}';

    protected $description = 'Перепривязать app-токен портала на администратора (ремонт после подмены владельца)';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $forcedUserId = $this->option('user') === null ? null : (int) $this->option('user');
        $limit = (int) $this->option('limit');
        $maxAgeDays = (int) $this->option('max-age');
        $member = $this->option('member');

        // --skip-verify снимает единственную защиту от того, чтобы записать в портал
        // мёртвый токен: у сломанного портала токен не-админа обычно живой, а найденный
        // ему на замену админ мог не заходить в приложение месяцами. Обратного прогона
        // нет, поэтому на весь флот эту опцию не даём — только на осознанно выбранный
        // портал.
        if ($this->option('skip-verify') && !$member) {
            $this->error('--skip-verify разрешён только вместе с --member: без живой проверки'
                . ' прогон способен разложить по флоту мёртвые токены, а отменить это нечем.');

            return self::FAILURE;
        }

        $broken = $this->applyScope($this->needingRepair(), $member, $limit)->get();
        $stranded = $this->applyScope($this->needingReinstall(), $member, $limit)->get();
        $unknown = $this->applyScope($this->withoutEvidence(), $member, 0)->count();

        if ($broken->isEmpty() && $stranded->isEmpty() && $unknown === 0) {
            $this->info('Порталов, требующих перепривязки, не найдено.');

            return self::SUCCESS;
        }

        $rows = [];
        $repaired = 0;
        $rolledBack = 0;
        $rollbackSkipped = 0;
        $refused = 0;

        /** @var B24App $b24app */
        foreach ($stranded as $b24app) {
            $rows[] = [$b24app->domain, $this->ownerLabel($b24app), '—', 'нет админов в b24_users: нужна переустановка'];
        }

        /** @var B24App $b24app */
        foreach ($broken as $b24app) {
            $previousOwner = $this->ownerLabel($b24app);
            $admin = $this->pickAdmin($b24app, $forcedUserId, $maxAgeDays);

            if ($admin === null) {
                $rows[] = [$b24app->domain, $previousOwner, '—', $forcedUserId === null
                    ? "нет админа со свежим токеном (--max-age={$maxAgeDays}): нужна переустановка"
                    : "пользователя {$forcedUserId} нет в b24_users этого портала"];

                continue;
            }

            // Ловушка №1: гейт админства НЕ переезжает вместе с колонкой user_id. Прямая
            // запись в b24_apps не наследует никакой проверки, поэтому та же функция, что
            // решает вопрос при установке, вызывается здесь явно.
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

            [$outcome, $message] = $this->reanchor($b24app, $admin);

            match ($outcome) {
                ReanchorOutcome::Repaired => $repaired++,
                ReanchorOutcome::RolledBack => $rolledBack++,
                ReanchorOutcome::RollbackSkipped => $rollbackSkipped++,
            };

            $rows[] = [$b24app->domain, $previousOwner, (string) $admin->user_id, $message];
        }

        if ($rows !== []) {
            $this->table(['портал', 'было', 'стало', 'результат'], $rows);
        }

        $this->info(sprintf(
            '%sНа ремонт: %d, перепривязано: %d, откатов: %d, откат отменён: %d, отказов: %d, на переустановку: %d',
            $dryRun ? '[DRY-RUN] ' : '',
            $broken->count(),
            $repaired,
            $rolledBack,
            $rollbackSkipped,
            $refused,
            $stranded->count(),
        ));

        if ($unknown > 0) {
            $this->line("Порталов без данных о правах владельца: {$unknown}."
                . ' Это не поломка и не повод для переустановки: is_admin заполняется, только когда'
                . ' пользователь открывает фронтенд приложения, поэтому у приложений на API-роутах'
                . ' сюда закономерно попадает весь флот.');
        }

        // Прогон, где ни один портал не выжил, обязан быть заметен вызвавшему скрипту:
        // это либо мёртвые токены админов, либо недоступный портал, но точно не «ремонт
        // прошёл».
        if (!$dryRun && $repaired === 0 && $rolledBack + $rollbackSkipped > 0) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * НА РЕМОНТ: владельца нет либо он точно не админ — И есть на кого перепривязаться.
     *
     * Условие «есть админ» стоит именно здесь, в отборе, а не в триаже после него: иначе
     * непочиняемые строки оставались бы в наборе навсегда, съедали бы --limit на каждом
     * прогоне (порядок по id стабилен) и повторные запуски не двигались бы с места.
     *
     * @return Builder<B24App>
     */
    private function needingRepair(): Builder
    {
        return B24App::query()
            ->orderBy('id')
            ->where(function (Builder $builder): void {
                $builder
                    ->whereNull('user_id')
                    ->orWhereExists(fn(QueryBuilder $sub) => $this->ownerRow($sub, isAdmin: false));
            })
            ->whereExists(fn(QueryBuilder $sub) => $this->anyAdmin($sub));
    }

    /**
     * НУЖНА ПЕРЕУСТАНОВКА: про владельца ТОЧНО известно, что он не админ, и админов на
     * портале нет. Только про такой портал можно честно сказать, что он сломан и что мы
     * его не починим.
     *
     * @return Builder<B24App>
     */
    private function needingReinstall(): Builder
    {
        return B24App::query()
            ->orderBy('id')
            ->whereExists(fn(QueryBuilder $sub) => $this->ownerRow($sub, isAdmin: false))
            ->whereNotExists(fn(QueryBuilder $sub) => $this->anyAdmin($sub));
    }

    /**
     * НЕТ ДАННЫХ: либо владелец записан, но в b24_users про него нет ни строки, либо
     * владельца нет и перепривязываться не на кого. Ни ремонт, ни переустановка: у нас
     * просто нет свидетельств.
     *
     * @return Builder<B24App>
     */
    private function withoutEvidence(): Builder
    {
        return B24App::query()
            ->where(function (Builder $builder): void {
                $builder
                    ->where(function (Builder $nested): void {
                        $nested
                            ->whereNotNull('user_id')
                            ->whereNotExists(fn(QueryBuilder $sub) => $this->ownerRow($sub, isAdmin: null));
                    })
                    ->orWhere(function (Builder $nested): void {
                        $nested
                            ->whereNull('user_id')
                            ->whereNotExists(fn(QueryBuilder $sub) => $this->anyAdmin($sub));
                    });
            });
    }

    /** Строка b24_users про ВЛАДЕЛЬЦА ЭТОГО портала; $isAdmin === null — без фильтра прав. */
    private function ownerRow(QueryBuilder $sub, ?bool $isAdmin): void
    {
        $sub->select(DB::raw(1))
            ->from('b24_users')
            ->whereColumn('b24_users.member_id', 'b24_apps.member_id')
            ->whereColumn('b24_users.user_id', 'b24_apps.user_id');

        if ($isAdmin !== null) {
            $sub->where('b24_users.is_admin', $isAdmin);
        }
    }

    /** Хоть один администратор ЭТОГО портала. */
    private function anyAdmin(QueryBuilder $sub): void
    {
        $sub->select(DB::raw(1))
            ->from('b24_users')
            ->whereColumn('b24_users.member_id', 'b24_apps.member_id')
            ->where('b24_users.is_admin', true);
    }

    /**
     * @param  Builder<B24App>  $query
     * @return Builder<B24App>
     */
    private function applyScope(Builder $query, ?string $member, int $limit): Builder
    {
        if ($member) {
            $query->where('member_id', $member);
        }

        if ($limit > 0) {
            $query->limit($limit);
        }

        return $query;
    }

    private function ownerLabel(B24App $b24app): string
    {
        return $b24app->user_id === null ? 'NULL' : (string) $b24app->user_id;
    }

    /**
     * Новый владелец: самый свежий администратор ЭТОГО портала, чей токен не протух
     * окончательно.
     *
     * Порог по свежести не косметика. У сломанного портала токен не-админа обычно живой, а
     * админ, найденный ему на замену, мог не заходить в приложение месяцами: refresh-токен
     * Битрикса столько не живёт, и перепривязка сделала бы портал хуже, чем он был. Порог
     * считается по b24_users.expires — абсолютной метке, которую пишет B24AppUserMiddleware.
     *
     * Под --user строка берётся БЕЗ фильтров: оператор назвал человека явно, отказ обязан
     * приходить от гейта в handle() (иначе «не администратор» и «нет такой строки» слились
     * бы в один невнятный ответ, а сам гейт остался бы без сценария, который его
     * проверяет), а мёртвый токен поймает живая проверка.
     */
    private function pickAdmin(B24App $b24app, ?int $forcedUserId, int $maxAgeDays): ?B24User
    {
        $query = B24User::query()->where('member_id', $b24app->member_id);

        if ($forcedUserId !== null) {
            return $query->where('user_id', $forcedUserId)->first();
        }

        $query->where('is_admin', true);

        if ($maxAgeDays > 0) {
            $query->where('expires', '>=', time() - $maxAgeDays * 86400);
        }

        return $query->orderByDesc('expires')->first();
    }

    /**
     * Записывает токен администратора вместе с владельцем и проверяет результат живым
     * вызовом. Неудача возвращает строку РОВНО в прежний вид: полуоткат оставил бы портал
     * в состоянии, которого не было ни до ремонта, ни после.
     *
     * Токен переносится из b24_users как есть, включая expires. Это не небрежность:
     * b24_users.expires и b24_apps.expires — обе АБСОЛЮТНЫЕ метки времени (b24_users пишет
     * B24AppUserMiddleware как time() + AUTH_EXPIRES - 600, b24_apps так же читают
     * {@see RemoveUninstalledPortals} и {@see Bitrix24App::renewTokens}). Поэтому запись
     * идёт напрямую, а не через {@see AppTokenWriter::saveIfAllowed()}: тот пишет через
     * AppAuthDatabaseStorage::save(), который при пустом expiresIn считает expires СРОКОМ
     * ЖИЗНИ и кладёт в колонку now() + expires — сложение двух timestamp-ов припарковало бы
     * портал в 2083 году (та же ловушка описана в
     * {@see \X3Group\Bitrix24\Application\Install\InstallTokenProbe::tokenForStorage()}).
     * Он же разложил бы expires и expires_in по разным колонкам, переписал бы domain
     * значением с протоколом и обнулил бы application_token, на котором держится проверка
     * подлинности входящих событий. Гейт админства при этом не теряется: он вызван явно.
     *
     * @return array{ReanchorOutcome, string}
     */
    private function reanchor(B24App $b24app, B24User $admin): array
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
            return [ReanchorOutcome::Repaired, 'перепривязан (проверка пропущена)'];
        }

        try {
            if ($this->verifyAdmin($b24app->member_id)) {
                return [ReanchorOutcome::Repaired, 'перепривязан, user.admin=true'];
            }

            $reason = 'user.admin=false';
        } catch (\Throwable $e) {
            $reason = $e->getMessage();
        }

        // Откат только если с момента записи строку никто не трогал. Живая проверка идёт
        // через Bitrix24App, то есть по шине appEvents, слушатель которой сохраняет
        // обновлённый токен прямо в b24_apps — безусловный откат затёр бы настоящий свежий
        // токен значением, которое Битрикс уже отозвал, и сломал бы портал насмерть.
        $restored = B24App::query()
            ->whereKey($b24app->getKey())
            ->where('access_token', $admin->access_token)
            ->update($backup);

        if ($restored === 0) {
            logger()->warning('reanchor rollback skipped: row changed under us', [
                'member_id' => $b24app->member_id,
                'domain' => $b24app->domain,
                'user_id' => $admin->user_id,
                'reason' => $reason,
            ]);

            return [ReanchorOutcome::RollbackSkipped, 'откат отменён (строку переписали): ' . $reason];
        }

        logger()->warning('reanchor rolled back', [
            'member_id' => $b24app->member_id,
            'domain' => $b24app->domain,
            'user_id' => $admin->user_id,
            'reason' => $reason,
        ]);

        return [ReanchorOutcome::RolledBack, 'откат: ' . $reason];
    }

    /**
     * Живая проверка: тем самым токеном, который только что записан, спрашиваем у портала,
     * админ ли мы. Выделено в метод для переопределения в тестах — по той же причине и тем
     * же способом, что probe() в {@see RemoveUninstalledPortals}.
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
