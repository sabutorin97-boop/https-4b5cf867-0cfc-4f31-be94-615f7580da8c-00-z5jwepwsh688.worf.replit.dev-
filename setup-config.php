<?php
/**
 * Помощник: записывает config.php, задавая вопросы по одному.
 *
 * Нужен, чтобы не править файл настроек в текстовом редакторе через
 * браузерную консоль — там неудобно двигаться по строкам и легко
 * сломать разметку файла лишним символом.
 *
 * Запуск на сервере:
 *
 *     php /var/www/okna/setup-config.php
 *
 * Существующий config.php без подтверждения не трогается, а перед
 * перезаписью сохраняется рядом с пометкой даты.
 *
 * Запускается только из консоли: через браузер он ничего не выдаст.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$target = __DIR__ . '/config.php';

/**
 * Режим проверки: показывает, что записано в настройках, и пробует
 * достучаться до MAX. Секреты не печатаются целиком — только длина
 * и края, чтобы можно было сверить с кабинетом, не раскрывая токен.
 *
 *     php setup-config.php --check
 */
if (in_array($argv[1] ?? '', ['--check', '-c', 'check'], true)) {
    if (!is_file($target)) {
        echo "Файла настроек нет: $target\n";
        echo "Запустите помощник без параметров, он его создаст.\n";
        exit(1);
    }

    $c = require $target;
    $max = is_array($c['max'] ?? null) ? $c['max'] : [];
    $smtp = is_array($c['smtp'] ?? null) ? $c['smtp'] : [];
    $token = (string)($max['token'] ?? '');

    /** Показывает секрет так, чтобы его можно было опознать, но не прочитать. */
    $peek = static function (string $s): string {
        $len = strlen($s);
        if ($len === 0) {
            return 'пусто';
        }
        // Невидимое показываем точками, иначе «начало» выглядит пустым
        // и непонятно, что в строку затесался мусор
        $show = static fn(string $part): string => preg_replace('/[^\x21-\x7E]/', '·', $part) ?? $part;

        $note = '';
        if (preg_match('/[^\x21-\x7E]/', $s)) {
            $note = ' — есть посторонние знаки, отмечены точками';
        }
        if ($len <= 8) {
            return "длина $len — подозрительно коротко" . $note;
        }
        return "длина $len, начало «" . $show(substr($s, 0, 4))
            . "», конец «" . $show(substr($s, -4)) . '»' . $note;
    };

    echo "\nЧто записано в настройках\n-------------------------\n\n";
    echo '  почта для заявок: ' . ($c['recipient'] ?? '—') . "\n";
    echo '  метка источника:  ' . ($c['source_label'] ?? '—') . "\n";
    echo '  письма:           ' . (!empty($smtp['enabled']) ? 'включены' : 'выключены') . "\n";
    echo '  пароль почты:     ' . $peek((string)($smtp['password'] ?? '')) . "\n";
    echo '  MAX:              ' . (!empty($max['enabled']) ? 'включён' : 'выключен') . "\n";
    echo '  токен бота:       ' . $peek($token) . "\n";
    echo '  чаты:             ' . ((string)($max['chat_id'] ?? '') ?: '—') . "\n\n";

    if ($token === '') {
        echo "Токен пуст — заявки в MAX уходить не будут.\n";
        exit(1);
    }

    echo "Спрашиваю у MAX, кто владелец этого токена...\n";
    $ch = curl_init('https://platform-api.max.ru/me');
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER     => ['Authorization: ' . $token],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
    ]);
    $answer = (string)curl_exec($ch);
    $code   = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code >= 200 && $code < 300) {
        $me = json_decode($answer, true);
        echo "  ответ: HTTP $code — токен рабочий\n";
        if (is_array($me)) {
            echo '  бот:   ' . ($me['name'] ?? '—') . ' (' . ($me['username'] ?? '—') . ")\n";
        }
    } else {
        echo "  ответ: HTTP $code — " . substr($answer, 0, 200) . "\n\n";
        echo "Токен не принят. Скорее всего он изменился в кабинете\n";
        echo "business.max.ru — скопируйте оттуда свежий и запустите\n";
        echo "помощник без параметров, чтобы записать его заново.\n";
    }

    echo "\n";
    exit(0);
}

/**
 * Убирает метки вставки, которые терминал дописывает вокруг текста
 * из буфера обмена: \e[200~ в начале и \e[201~ в конце. Символ ESC
 * консоль часто съедает, а «[200~» остаётся прямо внутри значения —
 * и токен молча становится нерабочим.
 */
function strip_paste(string $s): string
{
    return preg_replace('/\x1B?\[20[01]~/', '', $s) ?? $s;
}

/** Задаёт вопрос и возвращает ответ. Пустой ответ означает «оставить как есть». */
function ask(string $question, string $default = ''): string
{
    $hint = $default !== '' ? " [$default]" : '';
    echo $question . $hint . ': ';
    $answer = trim(strip_paste((string)fgets(STDIN)));
    return $answer === '' ? $default : $answer;
}

/**
 * Спрашивает секрет — токен или пароль.
 *
 * Браузерная консоль при вставке иногда добавляет управляющие символы
 * или теряет начало строки. Здесь всё, кроме обычных печатных знаков,
 * вырезается, а о находке говорится вслух: иначе токен выглядит целым,
 * но сервер отвечает «Invalid access_token», и причина неочевидна.
 */
function ask_secret(string $question): string
{
    echo $question . ': ';
    $raw = trim(strip_paste((string)fgets(STDIN)));
    $clean = preg_replace('/[^\x21-\x7E]/', '', $raw) ?? $raw;

    if ($clean !== $raw) {
        $cut = strlen($raw) - strlen($clean);
        echo "    убрал посторонние знаки: $cut шт.\n";
    }
    if ($clean !== '') {
        echo '    принято: длина ' . strlen($clean)
            . ', начало «' . substr($clean, 0, 4)
            . '», конец «' . substr($clean, -4) . "»\n";
        echo "    сверьте края с тем, что в кабинете\n";
    }

    return $clean;
}

/** Вопрос с ответом «да/нет». */
function confirm(string $question, bool $default = true): bool
{
    $hint = $default ? 'Д/н' : 'д/Н';
    echo $question . " ($hint): ";
    $answer = trim((string)fgets(STDIN));
    if ($answer === '') {
        return $default;
    }
    /* Заглавные буквы перечислены прямо здесь: strtolower не понижает
       кириллицу, а mb_strtolower требует модуль mbstring, которого
       на голом сервере может не быть — а помощник нужен именно там. */
    return in_array($answer, ['д', 'Д', 'да', 'Да', 'ДА', 'y', 'Y', 'yes', 'Yes', 'YES'], true);
}

echo "\n";
echo "Настройка приёма заявок\n";
echo "-----------------------\n\n";

if (is_file($target)) {
    echo "Файл настроек уже существует: $target\n";
    if (!confirm('Перезаписать его?', false)) {
        echo "Ничего не меняю.\n";
        exit(0);
    }
    $backup = $target . '.' . date('Y-m-d_His') . '.bak';
    if (!@copy($target, $backup)) {
        echo "Не удалось сохранить копию старого файла. Останавливаюсь.\n";
        exit(1);
    }
    echo "Старый файл сохранён: $backup\n\n";
}

/* ------------------------------------------------------------- почта */

$recipient = ask('Почта, куда слать заявки', 'potolok-45@yandex.ru');
$label     = ask('Метка источника (видно в заявке)', 'Квиз форма');

echo "\n";

/* --------------------------------------------------------------- MAX */

$maxOn   = confirm('Дублировать заявки в мессенджер MAX?');
$token   = '';
$chatIds = '';

if ($maxOn) {
    echo "\nТокен бота — длинная строка из кабинета business.max.ru.\n";
    echo "Вставка в консоли: Ctrl+Shift+V или правая кнопка мыши.\n";
    $token = ask_secret('Токен бота');

    echo "\nНомера чатов, куда слать заявки. Их показывает max-chat-id.php.\n";
    echo "Если получателей несколько — перечислите через запятую.\n";
    $chatIds = ask('chat_id');

    if ($token === '' || $chatIds === '') {
        echo "\nТокен или chat_id не заданы — отправку в MAX пока отключаю.\n";
        $maxOn = false;
    }
}

echo "\n";

/* -------------------------------------------------------------- SMTP */

$smtpOn = confirm('Отправлять заявки ещё и письмом на почту?', false);
$smtpUser = $smtpPass = '';

if ($smtpOn) {
    echo "\nДля Яндекса нужен пароль приложения, а не пароль от почты:\n";
    echo "id.yandex.ru → Безопасность → Пароли приложений → Почта.\n";
    /* Поля логина и пароля идут подряд, и пароль легко вписать не туда.
       Молча записывать такой логин нельзя: письма просто не будут уходить,
       а причина обнаружится только по пустой почте. */
    do {
        $smtpUser = ask('Почтовый ящик (логин)', $recipient);
        $looksLikeMail = str_contains($smtpUser, '@');
        if (!$looksLikeMail) {
            echo "    это не похоже на адрес почты — в нём нет знака @\n";
            echo "    здесь нужен ящик вида potolok-45@yandex.ru,\n";
            echo "    пароль спрошу следующим вопросом\n";
        }
    } while (!$looksLikeMail);

    $smtpPass = ask_secret('Пароль приложения');

    if ($smtpPass === '') {
        echo "\nПароль не задан — отправку писем пока отключаю.\n";
        $smtpOn = false;
    }
}

/* ------------------------------------------------------------- запись */

/** Оборачивает значение в кавычки для вставки в PHP-файл. */
function q(string $value): string
{
    return "'" . str_replace(["\\", "'"], ["\\\\", "\\'"], $value) . "'";
}

// Кавычки вокруг PHP делают вставку «глухой»: {$q1} останется текстом,
// а не превратится в значение переменной — подставим их ниже сами.
$content = <<<'PHP'
<?php
/**
 * Настройки приёма заявок. Записано помощником setup-config.php.
 *
 * Файл лежит только на сервере: в репозиторий он не попадает
 * и при выкладке сайта не перезаписывается.
 */

return [
    'recipient'    => {$q1},
    'leads_dir'    => __DIR__ . '/../okna-leads',
    'source_label' => {$q2},

    'smtp' => [
        'enabled'   => {$smtpFlag},
        'host'      => 'smtp.yandex.ru',
        'port'      => 465,
        'user'      => {$q3},
        'password'  => {$q4},
        'from'      => {$q3},
        'from_name' => 'Сайт «Окна Профигрупп»',
    ],

    'max' => [
        'enabled'         => {$maxFlag},
        'token'           => {$q5},
        'chat_id'         => {$q6},
        'base_url'        => 'https://platform-api.max.ru',
        'auth'            => 'header',
        'recipient_field' => 'chat_id',
    ],
];
PHP;

// Подстановки готовим заранее: в heredoc нельзя вызывать функции
$content = strtr($content, [
    '{$q1}'        => q($recipient),
    '{$q2}'        => q($label),
    '{$q3}'        => q($smtpUser !== '' ? $smtpUser : $recipient),
    '{$q4}'        => q($smtpPass),
    '{$q5}'        => q($token),
    '{$q6}'        => q($chatIds),
    '{$smtpFlag}'  => $smtpOn ? 'true' : 'false',
    '{$maxFlag}'   => $maxOn ? 'true' : 'false',
]);

if (@file_put_contents($target, $content) === false) {
    echo "\nНе удалось записать $target. Проверьте права на папку.\n";
    exit(1);
}

// В файле пароль и токен — читать его должен только сервер
@chmod($target, 0640);

echo "\n";
echo "Готово. Настройки записаны: $target\n";
echo '  почта для заявок: ' . $recipient . "\n";
echo '  метка источника:  ' . $label . "\n";
echo '  письма:           ' . ($smtpOn ? 'включены' : 'выключены') . "\n";
echo '  MAX:              ' . ($maxOn ? 'включён, чатов ' . count(array_filter(array_map('trim', explode(',', $chatIds)))) : 'выключен') . "\n";

if ($maxOn) {
    echo "\n";
    if (confirm('Отправить проверочное сообщение в MAX прямо сейчас?')) {
        echo "\n";
        foreach (array_filter(array_map('trim', explode(',', $chatIds))) as $chat) {
            $ch = curl_init('https://platform-api.max.ru/messages?chat_id=' . rawurlencode($chat));
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => json_encode([
                    'text' => 'Проверка связи с сайтом «Окна Профигрупп». Если вы видите это сообщение — заявки будут приходить сюда.',
                ], JSON_UNESCAPED_UNICODE),
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: ' . $token],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 15,
            ]);
            $answer = (string)curl_exec($ch);
            $code   = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($code >= 200 && $code < 300) {
                echo "    чат $chat — сообщение ушло\n";
            } else {
                echo "    чат $chat — не получилось (HTTP $code): " . substr($answer, 0, 200) . "\n";
            }
        }
        echo "\nПосмотрите в MAX, куда пришло сообщение. Лишние номера можно\n";
        echo "убрать, запустив этот помощник ещё раз.\n";
    }
}

echo "\n";
