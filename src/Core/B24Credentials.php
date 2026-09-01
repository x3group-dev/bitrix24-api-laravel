<?php

namespace X3Group\Bitrix24\Core;

use Bitrix24\SDK\Core\Credentials\Credentials;

/**
 * Credentials, у которых смена домена портала действительно применяется.
 *
 * Ловушка, ради которой класс и заведён. Когда портал переименован, Битрикс
 * отвечает на каждый REST-вызов редиректом 302 на новый адрес.
 * {@see \Bitrix24\SDK\Core\Core::call()} это обрабатывает: читает новый домен,
 * зовёт `Credentials::changeDomainUrl()` и рекурсивно повторяет вызов. Но
 * SDK-шный `changeDomainUrl()` делает
 *
 *     $this->endpoints->changeClientUrl($domainUrl);
 *
 * и **выбрасывает результат**, а `Endpoints::$clientUrl` объявлен `readonly` —
 * то есть `changeClientUrl()` не мутирует объект, а возвращает новый. Домен не
 * меняется, повтор уходит на тот же старый адрес, снова прилетает 302 — и так
 * до бесконечности.
 *
 * Потолок `max_duration` тут бессилен: он ограничивает длительность одного
 * вызова, а бесконечен запрос, состоящий из быстрых (~100 мс) вызовов.
 * Так 2026-09-01 лёг dependent-fields: воркеры php-fpm сидели в этой рекурсии
 * часами (сигнатура — ровно одна побудка в секунду и растущий от стека RSS),
 * пул исчерпался, nginx сутки отдавал 502. У одного портала за сутки набралось
 * 39 363 повтора — то есть ни одного полезного вызова.
 *
 * Дефект присутствует и в свежем b24phpsdk 3.5.0. Когда его починят апстримом,
 * канареечный тест `vendor_credentials_still_ignore_domain_change` покраснеет —
 * это сигнал, что класс можно убрать.
 *
 * После починки всё остальное работает само: `Core::call()` повторяет вызов уже
 * на новый домен и отправляет `PortalDomainUrlChangedEvent`, а
 * {@see \X3Group\Bitrix24\Listeners\PortalDomainUrlChangedListener} пишет новый
 * домен в базу — портал лечится сам.
 */
final class B24Credentials extends Credentials
{
    public function changeDomainUrl(string $domainUrl): void
    {
        // Родитель проверяет URL и запрещает смену домена в webhook-контексте.
        parent::changeDomainUrl($domainUrl);

        $this->endpoints = $this->endpoints?->changeClientUrl($domainUrl);
    }
}
