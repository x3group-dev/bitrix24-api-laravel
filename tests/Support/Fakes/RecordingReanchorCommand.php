<?php

namespace X3Group\Bitrix24\Tests\Support\Fakes;

use X3Group\Bitrix24\Console\Commands\ReanchorAppTokenCommand;

/**
 * Команда ремонта с подменённой живой проверкой.
 *
 * Настоящий verifyAdmin() спрашивает у Битрикса user.admin — в тесте такого вызова быть
 * не может, а без него не проверить ни откат, ни то, что успешная проверка запись
 * оставляет. Подменяется ровно один метод, ради которого в команде и заведён
 * protected-шов (так же устроен probe() в RemoveUninstalledPortals).
 *
 * Счётчик вызовов нужен отдельно от вердикта: dry-run обязан не только ничего не писать,
 * но и не ходить в Битрикс — прогон «посмотреть, что будет» на флоте в 25 тысяч порталов
 * не должен превращаться в 25 тысяч REST-запросов.
 *
 * $onVerify — то, что успевает случиться с БД, пока идёт живой вызов. У настоящей проверки
 * это не выдумка: она идёт через Bitrix24App, то есть по шине appEvents, слушатель которой
 * сохраняет обновлённый токен прямо в b24_apps.
 */
class RecordingReanchorCommand extends ReanchorAppTokenCommand
{
    public int $verifyCalls = 0;

    public function __construct(
        private readonly bool|\Throwable $verdict,
        private readonly ?\Closure $onVerify = null,
    ) {
        parent::__construct();
    }

    protected function verifyAdmin(string $memberId): bool
    {
        $this->verifyCalls++;

        if ($this->onVerify instanceof \Closure) {
            ($this->onVerify)($memberId);
        }

        if ($this->verdict instanceof \Throwable) {
            throw $this->verdict;
        }

        return $this->verdict;
    }
}
