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
/* timeout=0 обязателен: без него /updates работает как ожидание новых
   событий и висит до обрыва связи, если писем боту не было. С нулём
   MAX отвечает сразу тем, что накопилось. */
$updates = $base . '/updates?timeout=0&limit=100';

$attempts = [
    'токен в заголовке, без приставки' => [$updates, $token, 'header'],
    'токен в заголовке с Bearer'       => [$updates, 'Bearer ' . $token, 'bearer'],
    'токен в адресе'                   => [$updates . '&access_token=' . rawurlencode($token), null, 'query'],
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

    /* Разбираем сообщения по одному. Боту могли писать разные люди,
       и просто список номеров не подсказывает, какой из них ваш —
       поэтому рядом с номером показываем, кто написал и что именно. */
    $chats = [];
    foreach (($data['updates'] ?? []) as $update) {
        if (!is_array($update)) {
            continue;
        }
        $message = $update['message'] ?? [];
        $chat = $message['recipient']['chat_id'] ?? null;
        if ($chat === null) {
            continue;
        }
        $chat = (string)$chat;
        if (!isset($chats[$chat])) {
            $chats[$chat] = ['who' => '—', 'text' => '', 'when' => 0, 'kind' => ''];
        }
        $stamp = (int)($update['timestamp'] ?? 0);
        if ($stamp >= $chats[$chat]['when']) {
            // dialog — личная переписка, chat — группа. Для рассылки заявок
            // нужна именно группа: она одна, а получателей в ней сколько угодно.
            $type = (string)($message['recipient']['chat_type'] ?? '');
            $chats[$chat] = [
                'who'  => (string)($message['sender']['name'] ?? '—'),
                'text' => (string)($message['body']['text'] ?? ''),
                'when' => $stamp,
                'kind' => $type === 'dialog' ? 'личная переписка' : ($type === '' ? '—' : 'группа'),
            ];
        }
    }

    // Запасной путь: если формат ответа окажется другим, покажем хотя бы номера
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
    if ($chats) {
        echo "Диалоги, в которых бот получал сообщения:\n\n";
        foreach ($chats as $chat => $info) {
            // Время MAX отдаёт в миллисекундах
            $when = $info['when'] > 0 ? date('d.m.Y H:i', (int)($info['when'] / 1000)) : '—';
            echo "    chat_id = $chat\n";
            echo "        что это:   {$info['kind']}\n";
            echo "        от кого:   {$info['who']}\n";
            // Без mbstring обрезаем по байтам: скрипт нужен на голом сервере,
            // где модуль ещё могли не поставить
            $preview = function_exists('mb_substr')
                ? mb_substr($info['text'], 0, 60)
                : substr($info['text'], 0, 120);
            echo "        сообщение: " . $preview . "\n";
            echo "        когда:     $when\n\n";
        }
        echo "Нужную строку узнаете по имени и тексту сообщения.\n";
        echo "Впишите её chat_id в config.php, раздел 'max', вместе с auth = '$authMode'.\n\n";
        echo "Если заявки должны видеть несколько человек — заведите в MAX группу,\n";
        echo "добавьте туда бота, напишите в ней что угодно и запустите скрипт снова:\n";
        echo "в списке появится строка с пометкой «группа», её chat_id и нужен.\n";
    } elseif ($found) {
        echo "Найдено:\n";
        foreach (array_keys($found) as $line) {
            echo "    $line\n";
        }
        echo "\nВпишите нужное значение в config.php, раздел 'max'.\n";
        echo "Если это chat_id — оставьте recipient_field = 'chat_id',\n";
        echo "если только user_id — поставьте recipient_field = 'user_id'.\n";
        echo "И укажите auth = '$authMode' — этот способ подошёл.\n";
    } else {
        echo "Ответ получен, но сообщений в нём нет — очередь пуста.\n";
        echo "MAX отдаёт каждое сообщение только один раз, поэтому попросите\n";
        echo "нужного человека написать боту ещё раз и запустите скрипт снова.\n";
        echo 'Ответ целиком: ' . substr($body, 0, 500) . "\n";
    }
    exit(0);
}

echo "Ни один способ не сработал.\n";
echo "Проверьте токен и попробуйте другой адрес API вторым параметром,\n";
echo "например https://platform-api2.max.ru\n";
exit(1);
