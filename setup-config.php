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

/** Задаёт вопрос и возвращает ответ. Пустой ответ означает «оставить как есть». */
function ask(string $question, string $default = ''): string
{
    $hint = $default !== '' ? " [$default]" : '';
    echo $question . $hint . ': ';
    $answer = trim((string)fgets(STDIN));
    return $answer === '' ? $default : $answer;
}

/** Вопрос с ответом «да/нет». */
function confirm(string $question, bool $default = true): bool
{
    $hint = $default ? 'Д/н' : 'д/Н';
    echo $question . " ($hint): ";
    $answer = mb_strtolower(trim((string)fgets(STDIN)));
    if ($answer === '') {
        return $default;
    }
    return in_array($answer, ['д', 'да', 'y', 'yes'], true);
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
    $token = ask('Токен бота');

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
    $smtpUser = ask('Почтовый ящик (логин)', $recipient);
    $smtpPass = ask('Пароль приложения');

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
