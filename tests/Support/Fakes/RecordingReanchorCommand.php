<?php

namespace X3Group\Bitrix24\Tests\Support\Fakes;

use X3Group\Bitrix24\Console\Commands\ReanchorAppTokenCommand;

/**
 * Команда ремонта с подменённой живой проверкой.
 *
 * Настоящий verifyAdmin() спрашивает у Битрикса user.admin, поэтому подменяется ровно этот
 * метод — ради него в команде и заведён protected-шов.
 *
 * Счётчик вызовов нужен отдельно от вердикта: dry-run обязан не только ничего не писать, но
 * и не ходить в Битрикс.
 *
 * $onVerify — то, что успевает случиться с БД, пока идёт живой вызов: настоящая проверка
 * идёт по шине appEvents, слушатель которой сохраняет обновлённый токен прямо в b24_apps.
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
