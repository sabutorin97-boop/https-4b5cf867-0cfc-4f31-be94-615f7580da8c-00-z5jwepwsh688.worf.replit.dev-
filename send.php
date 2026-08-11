<?php
/**
 * Приём заявок с лендинга и отправка их на почту.
 *
 * Нужен только если хостинг умеет PHP (обычный виртуальный хостинг Timeweb — умеет).
 * После заливки на сервер откройте script.js и укажите:
 *     FORM_ENDPOINT: "/send.php"
 *
 * Заполните две константы ниже.
 */

declare(strict_types=1);

/** Куда приходят заявки. */
const RECIPIENT = 'potolok-45@yandex.ru';

/** От чьего имени уходит письмо. Обязательно адрес на вашем домене,
 *  иначе письмо уйдёт в спам или не отправится вовсе. */
const SENDER = 'noreply@okna-profigrupp.ru';

/** Минимальный интервал между заявками с одного адреса, секунды. */
const THROTTLE_SECONDS = 20;

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Метод не поддерживается'], JSON_UNESCAPED_UNICODE);
    exit;
}

$raw  = file_get_contents('php://input') ?: '';
$data = json_decode($raw, true);
if (!is_array($data)) {
    $data = $_POST;
}

/* Ловушка для ботов: поле скрыто от людей, значит заполнить его мог только робот.
   Отвечаем успехом, чтобы бот не искал обходной путь. */
if (!empty($data['company'])) {
    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

/** Убирает переводы строк — защита от подстановки лишних почтовых заголовков. */
function clean(string $value, int $limit = 200): string
{
    $value = str_replace(["\r", "\n", "\0"], ' ', $value);
    $value = trim($value);
    return mb_substr($value, 0, $limit);
}

$name  = clean((string)($data['name'] ?? ''), 100);
$phone = clean((string)($data['phone'] ?? ''), 30);
$way   = clean((string)($data['way'] ?? ''), 60);
$text  = (string)($data['text'] ?? '');

$digits = preg_replace('/\D/', '', $phone) ?? '';

if (mb_strlen($name) < 2 || mb_strlen($digits) < 10) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Проверьте имя и телефон'], JSON_UNESCAPED_UNICODE);
    exit;
}

/* Простое ограничение частоты — чтобы форму не залили сотней заявок разом. */
$ip   = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
$lock = sys_get_temp_dir() . '/lead-' . md5($ip) . '.lock';
if (is_file($lock) && (time() - (int)filemtime($lock)) < THROTTLE_SECONDS) {
    http_response_code(429);
    echo json_encode(['ok' => false, 'error' => 'Слишком часто, попробуйте через минуту'], JSON_UNESCAPED_UNICODE);
    exit;
}
@touch($lock);

/* Тело письма. Если клиент прислал готовый текст — берём его,
   иначе собираем сами из отдельных полей. */
if ($text === '') {
    $lines = [
        'Заявка с сайта — подбор окон',
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

$subject = '=?UTF-8?B?' . base64_encode('Заявка на замер — ' . $name . ', ' . $phone) . '?=';

$headers = implode("\r\n", [
    'From: =?UTF-8?B?' . base64_encode('Сайт «Окна Профигрупп»') . '?= <' . SENDER . '>',
    'Content-Type: text/plain; charset=UTF-8',
    'Content-Transfer-Encoding: 8bit',
    'MIME-Version: 1.0',
]);

$sent = @mail(RECIPIENT, $subject, $text, $headers, '-f' . SENDER);

if (!$sent) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Почта недоступна'], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
