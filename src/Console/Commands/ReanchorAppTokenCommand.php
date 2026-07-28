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
 * Ремонт порталов, у которых app-токен принадлежит не администратору: подбирает
 * администратора портала по b24_users и перепривязывает портал на него.
 *
 * Порталы раскладываются по пяти корзинам — здоров, на ремонт, ждёт входа админа, нужна
 * переустановка, нет данных; значение каждой описано на её методе отбора. --limit
 * ограничивает только корзину ремонта: диагностические корзины не делают REST-вызовов и
 * ничего не пишут, поэтому считаются целиком, а обрезаются лишь строки таблицы.
 *
 * Перепривязка пишет ТОКЕН, а не только колонку user_id: смена одной колонки ничего не
 * чинит — правило 2 донесёт токен нового владельца до b24_apps лишь тогда, когда тот сам
 * откроет приложение, а админ, во фронтенд не заходящий, не сделает этого никогда. Вместе
 * с токеном сбрасывается error_update: при error_update >= 10
 * {@see Bitrix24App::renewTokens()} исключает портал из ротации обновления токенов.
 *
 * Запись идёт в обход {@see AppTokenWriter::saveIfAllowed()} (почему — см.
 * {@see self::reanchor()}), поэтому проверка админства вызывается явно:
 * {@see AppTokenWriter::shouldWrite()} с appExists: true.
 *
 * ВСЕ подзапросы коррелированы по member_id: user_id — маленькое целое, общее для тысяч
 * порталов флота, и без корреляции команда записала бы в портал токен ЧУЖОГО портала.
 *
 * Модель владения: b24_apps.user_id — пользователь, чьим токеном приложение ходит в REST
 * от имени всего портала; владельцем вправе быть только администратор, иначе админские
 * методы (userfieldconfig.*) падают «нет прав». Записывают владельца ровно два места:
 * установка админом и этот ремонт.
 */
class ReanchorAppTokenCommand extends Command
{
    protected $signature = 'bitrix24:reanchor-app-token
        {--dry-run : Только показать план, не записывая ничего}
        {--member= : Обработать только этот member_id}
        {--user= : Взять токен именно этого пользователя портала (обязан быть администратором); действует только на порталы из корзины ремонта}
        {--limit=200 : Максимум РЕМОНТИРУЕМЫХ порталов за прогон; 0 — без ограничения. Счётчики диагностических корзин всегда полные, обрезаются только строки таблицы}
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

        // --skip-verify снимает единственную защиту от записи в портал мёртвого токена, а
        // обратного прогона нет, поэтому на весь флот опция не даётся — только на один
        // осознанно выбранный портал.
        if ($this->option('skip-verify') && !$member) {
            $this->error('--skip-verify разрешён только вместе с --member: без живой проверки'
                . ' прогон способен разложить по флоту мёртвые токены, а отменить это нечем.');

            return self::FAILURE;
        }

        $broken = $this->applyScope($this->needingRepair($maxAgeDays), $member, $limit)->get();

        // Диагностические корзины считаются целиком (--limit обрезает только строки
        // таблицы), поэтому у каждой два запроса: показать и посчитать.
        $stranded = $this->applyScope($this->needingReinstall(), $member, $limit)->get();
        $strandedTotal = $this->applyScope($this->needingReinstall(), $member, 0)->count();
        $waiting = $this->applyScope($this->waitingForAdminLogin($maxAgeDays), $member, $limit)->get();
        $waitingTotal = $this->applyScope($this->waitingForAdminLogin($maxAgeDays), $member, 0)->count();
        $unknown = $this->applyScope($this->withoutEvidence(), $member, 0)->count();

        if ($broken->isEmpty() && $strandedTotal === 0 && $waitingTotal === 0 && $unknown === 0) {
            $this->info('Порталов, требующих перепривязки, не найдено.');

            return self::SUCCESS;
        }

        $rows = [];
        $repaired = 0;
        $rolledBack = 0;
        $rollbackSkipped = 0;
        $refused = 0;
        $withoutCandidate = 0;
        $planned = 0;

        /** @var B24App $b24app */
        foreach ($stranded as $b24app) {
            $rows[] = [$b24app->domain, $this->ownerLabel($b24app), '—', 'нет админов в b24_users: нужна переустановка'];
        }

        /** @var B24App $b24app */
        foreach ($waiting as $b24app) {
            $rows[] = [$b24app->domain, $this->ownerLabel($b24app), '—',
                "нет админа со свежим токеном (--max-age={$maxAgeDays}): пусть админ зайдёт в приложение, следующий прогон починит"];
        }

        /** @var B24App $b24app */
        foreach ($broken as $b24app) {
            $previousOwner = $this->ownerLabel($b24app);
            $admin = $this->pickAdmin($b24app, $forcedUserId);

            if ($admin === null) {
                // Автоматический выбор сюда не попадает: отбор уже потребовал наличия
                // админа со свежим токеном. Остаются --user мимо цели и гонка с отбором.
                $withoutCandidate++;
                $rows[] = [$b24app->domain, $previousOwner, '—', $forcedUserId === null
                    ? 'админ со свежим токеном исчез между отбором и выбором'
                    : "пользователя {$forcedUserId} нет в b24_users этого портала"];

                continue;
            }

            // Прямая запись в b24_apps не наследует проверки прав, поэтому та же функция,
            // что решает вопрос при установке, вызывается здесь явно.
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
                $planned++;
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

        $hidden = ($strandedTotal - $stranded->count()) + ($waitingTotal - $waiting->count());

        if ($hidden > 0) {
            $this->line("В таблице показаны не все: строк диагностических корзин скрыто {$hidden}"
                . " (обрезано по --limit={$limit}). Счётчики ниже — полные, за весь флот.");
        }

        // Слагаемые в скобках в сумме дают размер корзины ремонта.
        $this->info(sprintf(
            '%sНа ремонт: %d (перепривязано: %d, откатов: %d, откат отменён: %d, отказов: %d,'
                . ' без кандидата: %d, план: %d), ждут входа админа: %d, на переустановку: %d',
            $dryRun ? '[DRY-RUN] ' : '',
            $broken->count(),
            $repaired,
            $rolledBack,
            $rollbackSkipped,
            $refused,
            $withoutCandidate,
            $planned,
            $waitingTotal,
            $strandedTotal,
        ));

        if ($unknown > 0) {
            $this->line("Порталов без данных о правах владельца: {$unknown}."
                . ' Это не поломка и не повод для переустановки: is_admin заполняется, только когда'
                . ' пользователь открывает фронтенд приложения, поэтому у приложений на API-роутах'
                . ' сюда закономерно попадает весь флот.');
        }

        // Прогон, в котором не выжила ни одна перепривязка, — не «ремонт прошёл».
        if (!$dryRun && $repaired === 0 && $rolledBack + $rollbackSkipped > 0) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * НА РЕМОНТ: владельца нет либо он точно не админ — И есть на кого перепривязаться.
     *
     * Условие «есть админ» стоит в отборе, а не в триаже после него: иначе непочиняемые
     * строки съедали бы --limit на каждом прогоне (порядок по id стабилен) и починяемые
     * порталы за ними не чинились бы никогда.
     *
     * @return Builder<B24App>
     */
    private function needingRepair(int $maxAgeDays): Builder
    {
        return B24App::query()
            ->orderBy('id')
            ->where(fn(Builder $builder) => $this->ownerIsWrong($builder))
            ->whereExists(fn(QueryBuilder $sub) => $this->anyAdmin($sub, $maxAgeDays));
    }

    /**
     * ЖДЁТ ВХОДА АДМИНА: перепривязать есть на кого, но все админы портала протухли.
     *
     * Это НЕ «нужна переустановка»: достаточно, чтобы любой администратор открыл
     * приложение — B24AppUserMiddleware обновит его строку в b24_users, и следующий прогон
     * портал починит. При --max-age=0 корзина пуста: «свежие» и «любые» админы совпадают.
     *
     * @return Builder<B24App>
     */
    private function waitingForAdminLogin(int $maxAgeDays): Builder
    {
        return B24App::query()
            ->orderBy('id')
            ->where(fn(Builder $builder) => $this->ownerIsWrong($builder))
            ->whereExists(fn(QueryBuilder $sub) => $this->anyAdmin($sub))
            ->whereNotExists(fn(QueryBuilder $sub) => $this->anyAdmin($sub, $maxAgeDays));
    }

    /** Владелец либо не задан, либо про него ТОЧНО известно, что он не админ. */
    private function ownerIsWrong(Builder $builder): void
    {
        $builder
            ->whereNull('user_id')
            ->orWhereExists(fn(QueryBuilder $sub) => $this->ownerRow($sub, isAdmin: false));
    }

    /**
     * НУЖНА ПЕРЕУСТАНОВКА: про владельца ТОЧНО известно, что он не админ, и админов на
     * портале нет — сами мы такой портал не починим.
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

    /**
     * Хоть один администратор ЭТОГО портала; $maxAgeDays > 0 — только со свежим токеном.
     *
     * Порог применяется здесь, в отборе, и только здесь: непочиняемый портал не должен
     * попадать в набор ремонта и съедать бюджет прогона.
     */
    private function anyAdmin(QueryBuilder $sub, int $maxAgeDays = 0): void
    {
        $sub->select(DB::raw(1))
            ->from('b24_users')
            ->whereColumn('b24_users.member_id', 'b24_apps.member_id')
            ->where('b24_users.is_admin', true);

        if ($maxAgeDays > 0) {
            $sub->where('b24_users.expires', '>=', time() - $maxAgeDays * 86400);
        }
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
     * Новый владелец: самый свежий администратор ЭТОГО портала. Свежесть держится ровно на
     * orderByDesc('expires'); порог --max-age повторно не проверяется — его уже применил
     * отбор ({@see self::anyAdmin()}), а самый свежий админ заведомо не хуже порога.
     *
     * Под --user строка берётся БЕЗ фильтров: отказ должен приходить от гейта в handle(),
     * иначе «не администратор» и «нет такой строки» слились бы в один ответ.
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
     * вызовом; неудача возвращает строку в прежний вид целиком.
     *
     * Токен переносится из b24_users как есть, включая expires: обе колонки expires —
     * АБСОЛЮТНЫЕ метки времени. Поэтому запись идёт напрямую, а не через
     * {@see AppTokenWriter::saveIfAllowed()}: тот пишет через AppAuthDatabaseStorage::save(),
     * который при пустом expiresIn считает expires сроком жизни и кладёт в колонку
     * now() + expires (та же ловушка — в
     * {@see \X3Group\Bitrix24\Application\Install\InstallTokenProbe::tokenForStorage()}), а
     * заодно переписал бы domain и обнулил application_token. Гейт админства при этом не
     * теряется — он вызван явно в {@see self::handle()}.
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

        // Откат только если с момента записи строку никто не трогал: живая проверка идёт
        // через диспетчер appEvents, слушатель которого сохраняет обновлённый токен прямо
        // в b24_apps, и безусловный откат затёр бы его уже отозванным значением.
        $restored = B24App::query()
            ->whereKey($b24app->getKey())
            ->where('access_token', $admin->access_token)
            ->update($backup);

        if ($restored === 0) {
            // Токен оставляем чужой (свежий), а ВЛАДЕЛЬЦА возвращаем: проверка только что
            // сказала, что выбранный кандидат не админ, и оставить его в user_id значило
            // бы закрепить за порталом исходную аварию. Возврат безопасен именно для
            // user_id: конкурирующие писатели этой колонки не трогают. error_update не
            // восстанавливаем — обнулить его мог законный перенос токена.
            B24App::query()->whereKey($b24app->getKey())->update(['user_id' => $backup['user_id']]);

            logger()->warning('reanchor rollback skipped: row changed under us, ownership restored', [
                'member_id' => $b24app->member_id,
                'domain' => $b24app->domain,
                'user_id' => $admin->user_id,
                'reason' => $reason,
            ]);

            return [ReanchorOutcome::RollbackSkipped, 'откат отменён, владелец возвращён (строку переписали): ' . $reason];
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
     * админ ли мы. Выделено в метод для переопределения в тестах.
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
