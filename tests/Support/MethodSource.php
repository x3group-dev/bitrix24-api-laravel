<?php

namespace X3Group\Bitrix24\Tests\Support;

use PhpToken;
use ReflectionMethod;

/**
 * Исходник тела метода без комментариев.
 *
 * Нужен структурным проверкам: {@see \X3Group\Bitrix24\Application\Install\InstallService}
 * сам строит себе ServiceBuilder, поэтому прогнать его методы целиком нельзя — они упираются
 * в живые REST-вызовы к Битриксу. Инвариант «на КАЖДОМ пути установки профиль запрашивается,
 * админство проверяется до записи и владелец пишется» иначе не удержать: поведенческий тест
 * одного пути молча сойдёт за оба, а расхождение путей и было тем, что поймал ревью.
 *
 * Это страховка от расхождения, а не замена поведенческим тестам. Комментарии вырезаются,
 * чтобы проверка ловила код, а не пояснения к нему.
 */
final class MethodSource
{
    public static function of(string $class, string $method): string
    {
        $reflectionMethod = new ReflectionMethod($class, $method);

        $lines = file($reflectionMethod->getFileName());
        $body = implode('', array_slice(
            $lines,
            $reflectionMethod->getStartLine() - 1,
            $reflectionMethod->getEndLine() - $reflectionMethod->getStartLine() + 1,
        ));

        $source = '';
        foreach (PhpToken::tokenize('<?php ' . $body) as $phpToken) {
            if ($phpToken->is([T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG])) {
                continue;
            }

            $source .= $phpToken->text;
        }

        return $source;
    }
}
