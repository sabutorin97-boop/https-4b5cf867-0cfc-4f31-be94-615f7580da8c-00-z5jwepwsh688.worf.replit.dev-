<?php
/**
 * Приём заявок с лендинга.
 *
 * Работает в два шага:
 *   1. записывает заявку в журнал на диске — это происходит всегда;
 *   2. пытается отправить письмо, если настроен SMTP.
 *
 * Порядок именно такой: письмо может не уйти по десятку причин, а заявка
 * клиента теряться не должна. Журнал лежит вне папки сайта, чтобы его
 * нельзя было скачать из браузера.
 *
 * Настройки — в файле config.php рядом (см. config.sample.php).
 * Без него сайт работает, просто письма не отправляются.
 */

declare(strict_types=1);

const THROTTLE_SECONDS = 20;

$config = [
    'recipient'    => 'potolok-45@yandex.ru',
    'leads_dir'    => __DIR__ . '/../okna-leads',
    'source_label' => 'Квиз форма',
    'smtp'         => ['enabled' => false],
    'max'          => ['enabled' => false],
];

if (is_file(__DIR__ . '/config.php')) {
    $loaded = require __DIR__ . '/config.php';
    if (is_array($loaded)) {
        $config = array_replace_recursive($config, $loaded);
    }
}

header('Content-Type: application/json; charset=utf-8');

function respond(bool $ok, string $error = '', int $code = 200): never
{
    http_response_code($code);
    echo json_encode($error === '' ? ['ok' => $ok] : ['ok' => $ok, 'error' => $error], JSON_UNESCAPED_UNICODE);
    exit;
}

/** Убирает переводы строк — защита от подстановки лишних почтовых заголовков. */
function clean(string $value, int $limit = 200): string
{
    $value = str_replace(["\r", "\n", "\0"], ' ', $value);
    return mb_substr(trim($value), 0, $limit);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    respond(false, 'Метод не поддерживается', 405);
}

$raw  = file_get_contents('php://input') ?: '';
$data = json_decode($raw, true);
if (!is_array($data)) {
    $data = $_POST;
}

/* Ловушка для ботов: поле скрыто от людей, заполнить его мог только робот.
   Отвечаем успехом, чтобы бот не искал обходной путь. */
if (!empty($data['company'])) {
    respond(true);
}

$name  = clean((string)($data['name'] ?? ''), 100);
$phone = clean((string)($data['phone'] ?? ''), 30);
$way   = clean((string)($data['way'] ?? ''), 60);
$text  = (string)($data['text'] ?? '');

$digits = preg_replace('/\D/', '', $phone) ?? '';

if (mb_strlen($name) < 2 || mb_strlen($digits) < 10) {
    respond(false, 'Проверьте имя и телефон', 422);
}

/* Ограничение частоты — чтобы форму не залили сотней заявок разом. */
$ip   = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
$lock = sys_get_temp_dir() . '/lead-' . md5($ip) . '.lock';
if (is_file($lock) && (time() - (int)filemtime($lock)) < THROTTLE_SECONDS) {
    respond(false, 'Слишком часто, попробуйте через минуту', 429);
}
@touch($lock);

/* ---------------------------------------------------------------- текст */

// Метка источника: с какого сайта пришла заявка.
// Приходит от формы, иначе берётся из настроек сервера.
$label = clean((string)($data['source_label'] ?? ''), 60);
if ($label === '') {
    $label = (string)$config['source_label'];
}

if ($text === '') {
    $lines = [
        $label . ' — заявка на замер',
        '',
        'Имя: ' . $name,
        'Телефон: ' . $phone,
        'Способ связи: ' . $way,
    ];
    if (!empty($data['solution']) && is_array($data['solution'])) {
        $lines[] = '';
        $lines[] = 'Подобрано:';
        foreach ($data['solution'] as $item) {
            $lines[] = '• ' . clean((string)$item, 300);
        }
    }
    $text = implode("\n", $lines);
}

$text = str_replace("\0", '', $text);
$text .= "\n\nСтраница: " . clean((string)($data['page'] ?? ''), 300);
$text .= "\nИсточник: " . clean((string)($data['source'] ?? '—'), 300);
$text .= "\nIP: " . $ip;
$text .= "\nВремя: " . date('d.m.Y H:i');

/* ------------------------------------------------------- журнал заявок */

$saved = false;
$dir = (string)$config['leads_dir'];

if (!is_dir($dir)) {
    @mkdir($dir, 0770, true);
}

if (is_dir($dir) && is_writable($dir)) {
    $file  = rtrim($dir, '/') . '/' . date('Y-m') . '.txt';
    $entry = str_repeat('=', 60) . "\n" . $text . "\n";
    $saved = (bool)@file_put_contents($file, $entry, FILE_APPEND | LOCK_EX);
}

/* ------------------------------------------------------------- письмо */

/**
 * Отправка через SMTP на голых сокетах: на облачном сервере обычно нет
 * почтовой программы, а функция mail() без неё молча ничего не делает.
 */
function smtp_send(array $s, string $to, string $subject, string $body): bool
{
    $host = (string)($s['host'] ?? '');
    $port = (int)($s['port'] ?? 465);
    if ($host === '') {
        return false;
    }

    $address = $port === 465 ? 'ssl://' . $host : $host;
    $socket = @fsockopen($address, $port, $errno, $errstr, 15);
    if (!$socket) {
        error_log("Заявка: SMTP недоступен ($errno $errstr)");
        return false;
    }
    stream_set_timeout($socket, 15);

    $read = static function () use ($socket): string {
        $out = '';
        while (($line = fgets($socket, 515)) !== false) {
            $out .= $line;
            if (strlen($line) < 4 || $line[3] !== '-') {
                break;
            }
        }
        return $out;
    };

    $say = static function (string $cmd, string $expect) use ($socket, $read): bool {
        if ($cmd !== '') {
            fwrite($socket, $cmd . "\r\n");
        }
        $answer = $read();
        if (str_starts_with($answer, $expect)) {
            return true;
        }
        error_log('Заявка: SMTP ответил «' . trim($answer) . '» на «' . explode(' ', $cmd)[0] . '»');
        return false;
    };

    $ok = $say('', '220')
        && $say('EHLO okna', '250');

    // На порту 587 шифрование включается отдельной командой
    if ($ok && $port !== 465) {
        $ok = $say('STARTTLS', '220')
            && @stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)
            && $say('EHLO okna', '250');
    }

    $ok = $ok
        && $say('AUTH LOGIN', '334')
        && $say(base64_encode((string)($s['user'] ?? '')), '334')
        && $say(base64_encode((string)($s['password'] ?? '')), '235')
        && $say('MAIL FROM:<' . ($s['from'] ?? $s['user']) . '>', '250')
        && $say('RCPT TO:<' . $to . '>', '250')
        && $say('DATA', '354');

    if ($ok) {
        $from = (string)($s['from'] ?? $s['user']);
        $fromName = '=?UTF-8?B?' . base64_encode((string)($s['from_name'] ?? 'Сайт')) . '?=';

        $headers = [
            'From: ' . $fromName . ' <' . $from . '>',
            'To: <' . $to . '>',
            'Subject: ' . $subject,
            'Date: ' . date('r'),
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
        ];

        $letter = implode("\r\n", $headers) . "\r\n\r\n"
            . chunk_split(base64_encode($body), 76, "\r\n");

        fwrite($socket, $letter . "\r\n.\r\n");
        $ok = $say('', '250');
    }

    $say('QUIT', '221');
    fclose($socket);

    return $ok;
}

$mailed = false;
$smtp = is_array($config['smtp'] ?? null) ? $config['smtp'] : [];
$subject = '=?UTF-8?B?' . base64_encode($label . ': ' . $name . ', ' . $phone) . '?=';

if (!empty($smtp['enabled'])) {
    $mailed = smtp_send($smtp, (string)$config['recipient'], $subject, $text);
} elseif (function_exists('mail')) {
    // Запасной путь для обычного хостинга, где почтовая программа уже есть
    $headers = implode("\r\n", [
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
        'MIME-Version: 1.0',
    ]);
    $mailed = @mail((string)$config['recipient'], $subject, $text, $headers);
}

/* --------------------------------------------------- мессенджер MAX */

/**
 * Отправка заявки боту в MAX.
 *
 * Адрес и способ передачи токена вынесены в настройки: документация MAX
 * ещё меняется, и если формат окажется другим, поправить можно одной
 * строкой в config.php, не трогая код.
 */
function max_send(array $m, string $body): bool
{
    $base  = rtrim((string)($m['base_url'] ?? 'https://platform-api.max.ru'), '/');
    $token = (string)($m['token'] ?? '');
    $chat  = (string)($m['chat_id'] ?? '');

    if ($token === '' || $chat === '') {
        error_log('Заявка: для MAX не задан токен или chat_id');
        return false;
    }

    $url = $base . '/messages';
    $headers = ['Content-Type: application/json'];

    // Токен передаётся либо заголовком, либо параметром в адресе
    if (($m['auth'] ?? 'header') === 'query') {
        $url .= '?access_token=' . rawurlencode($token);
    } else {
        $headers[] = 'Authorization: Bearer ' . $token;
    }

    // Получатель: числовой chat_id либо user_id — зависит от того, что выдал бот
    $key = ($m['recipient_field'] ?? 'chat_id');
    $payload = json_encode([
        $key   => ctype_digit($chat) ? (int)$chat : $chat,
        'text' => $body,
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
    ]);

    $answer = curl_exec($ch);
    $code   = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err    = curl_error($ch);
    curl_close($ch);

    if ($code >= 200 && $code < 300) {
        return true;
    }

    // Ответ пишем в журнал ошибок целиком: по нему сразу видно,
    // что именно не понравилось — токен, адрес или имя поля.
    error_log('Заявка: MAX ответил ' . $code . ' ' . $err . ' ' . substr((string)$answer, 0, 300));
    return false;
}

$maxed = false;
$max = is_array($config['max'] ?? null) ? $config['max'] : [];

if (!empty($max['enabled']) && function_exists('curl_init')) {
    $maxed = max_send($max, $text);
}

/* ------------------------------------------------------------- ответ */

// Заявка принята, если она хотя бы записана в журнал: письмо и сообщение
// можно отправить позже, а вот потерять обращение клиента нельзя.
if ($saved || $mailed || $maxed) {
    respond(true);
}

error_log('Заявка: не удалось ни записать в журнал, ни отправить письмо, ни доставить в MAX');
respond(false, 'Не получилось сохранить заявку', 500);
