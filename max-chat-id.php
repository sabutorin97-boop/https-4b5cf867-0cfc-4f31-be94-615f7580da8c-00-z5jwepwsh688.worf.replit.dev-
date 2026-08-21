<?php
/**
 * Помощник: показывает chat_id, куда бот MAX должен слать заявки.
 *
 * Порядок:
 *   1. создайте бота в кабинете business.max.ru («Чат-боты» → «Создать»)
 *      и возьмите там токен;
 *   2. напишите своему боту любое сообщение — иначе ему некуда отвечать;
 *   3. запустите на сервере:
 *
 *          php /var/www/okna/max-chat-id.php ВАШ_ТОКЕН
 *
 * Скрипт покажет найденные чаты. Нужный chat_id впишите в config.php.
 *
 * Запускается только из консоли: через браузер он ничего не выдаст.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$token = $argv[1] ?? '';
$base  = rtrim($argv[2] ?? 'https://platform-api.max.ru', '/');

if ($token === '') {
    echo "Укажите токен бота:\n";
    echo "    php max-chat-id.php ВАШ_ТОКЕН\n\n";
    echo "Если MAX сменил адрес API, его можно передать вторым параметром:\n";
    echo "    php max-chat-id.php ВАШ_ТОКЕН https://platform-api2.max.ru\n";
    exit(1);
}

/**
 * Пробуем разные способы передачи токена.
 *
 * $auth — что положить в заголовок Authorization, либо null,
 * если токен уже вписан в адрес.
 */
function ask(string $url, ?string $auth): array
{
    $ch = curl_init($url);
    $headers = ['Accept: application/json'];
    if ($auth !== null) {
        $headers[] = 'Authorization: ' . $auth;
    }
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
    ]);
    $body = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    return [$code, $body, $err];
}

/* Порядок не случаен: MAX отвечает «Malformed access token» на приставку
   Bearer, а на токен в адресе — «use Authorization header». Поэтому первым
   идёт голый токен в заголовке, остальные оставлены на случай смены формата.
   Третий элемент — значение auth для config.php, если способ подойдёт. */
$attempts = [
    'токен в заголовке, без приставки' => [$base . '/updates', $token, 'header'],
    'токен в заголовке с Bearer'       => [$base . '/updates', 'Bearer ' . $token, 'bearer'],
    'токен в адресе'                   => [$base . '/updates?access_token=' . rawurlencode($token), null, 'query'],
];

foreach ($attempts as $how => [$url, $auth, $authMode]) {
    echo "Пробую: $how\n";
    [$code, $body, $err] = ask($url, $auth);

    if ($err !== '') {
        echo "   не удалось соединиться: $err\n\n";
        continue;
    }

    echo "   ответ: HTTP $code\n";

    if ($code < 200 || $code >= 300) {
        echo '   ' . substr($body, 0, 300) . "\n\n";
        continue;
    }

    $data = json_decode($body, true);
    if (!is_array($data)) {
        echo "   ответ не разобрался как JSON\n\n";
        continue;
    }

    // Ищем в ответе все числовые идентификаторы чатов и пользователей
    $found = [];
    $walk = function ($node) use (&$walk, &$found) {
        if (!is_array($node)) {
            return;
        }
        foreach ($node as $key => $value) {
            if (is_array($value)) {
                $walk($value);
            } elseif (in_array((string)$key, ['chat_id', 'chatId', 'user_id', 'userId'], true)) {
                $found[(string)$key . ' = ' . $value] = true;
            }
        }
    };
    $walk($data);

    echo "\n";
    if ($found) {
        echo "Найдено:\n";
        foreach (array_keys($found) as $line) {
            echo "    $line\n";
        }
        echo "\nВпишите нужное значение в config.php, раздел 'max'.\n";
        echo "Если это chat_id — оставьте recipient_field = 'chat_id',\n";
        echo "если только user_id — поставьте recipient_field = 'user_id'.\n";
        echo "И укажите auth = '$authMode' — этот способ подошёл.\n";
    } else {
        echo "Ответ получен, но идентификаторов в нём нет.\n";
        echo "Напишите боту сообщение в MAX и запустите скрипт ещё раз.\n";
        echo 'Ответ целиком: ' . substr($body, 0, 500) . "\n";
    }
    exit(0);
}

echo "Ни один способ не сработал.\n";
echo "Проверьте токен и попробуйте другой адрес API вторым параметром,\n";
echo "например https://platform-api2.max.ru\n";
exit(1);
