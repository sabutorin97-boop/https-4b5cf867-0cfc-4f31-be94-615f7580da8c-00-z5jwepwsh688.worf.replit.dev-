#!/usr/bin/env bash
#
# Первичная настройка облачного сервера под сайт «Окна Профигрупп».
# Запускать на сервере один раз, от root:
#
#   bash setup-server.sh
#
# Что делает:
#   1. ставит nginx, PHP и certbot;
#   2. создаёт каталог сайта /var/www/okna;
#   3. настраивает nginx на домен;
#   4. заводит пользователя deploy и ключ для выкладки с GitHub;
#   5. включает файрвол;
#   6. выпускает бесплатный сертификат Let's Encrypt.
#
# Скрипт можно запускать повторно — он не ломает уже настроенное.

set -euo pipefail

DOMAIN_PUNY="xn-----6kcboc8akc1afejndhfd0clr.xn--p1ai"
DOMAIN_HUMAN="окна-курган-профигруп.рф"
SITE_ROOT="/var/www/okna"
LEADS_DIR="/var/www/okna-leads"
DEPLOY_USER="deploy"
EMAIL="potolok-45@yandex.ru"

say()  { printf "\n\033[1;36m==> %s\033[0m\n" "$1"; }
warn() { printf "\033[1;33m    %s\033[0m\n" "$1"; }
ok()   { printf "\033[1;32m    %s\033[0m\n" "$1"; }

if [ "$(id -u)" -ne 0 ]; then
    echo "Запустите скрипт от root: sudo bash setup-server.sh"
    exit 1
fi

# ---------------------------------------------------------------- пакеты
say "Устанавливаю nginx, PHP и certbot"
export DEBIAN_FRONTEND=noninteractive
apt-get update -qq
# php-curl обязателен: через него send.php отправляет заявки в MAX,
# а max-chat-id.php узнаёт номер диалога. Без него оба падают с ошибкой
# «Call to undefined function curl_init()».
apt-get install -y -qq nginx php-fpm php-curl certbot python3-certbot-nginx rsync ufw curl
ok "Пакеты установлены"

# Определяем версию PHP — путь к сокету зависит от неё
PHP_SOCK="$(find /run/php -name 'php*-fpm.sock' 2>/dev/null | head -n1 || true)"
if [ -z "$PHP_SOCK" ]; then
    systemctl start "php*-fpm" 2>/dev/null || true
    PHP_SOCK="$(find /run/php -name 'php*-fpm.sock' 2>/dev/null | head -n1 || true)"
fi
if [ -z "$PHP_SOCK" ]; then
    warn "Не нашёл сокет PHP-FPM. Форма заявок работать не будет, остальное — да."
    PHP_SOCK="/run/php/php-fpm.sock"
else
    ok "PHP найден: $PHP_SOCK"
fi

# ---------------------------------------------------------------- каталог
say "Готовлю каталог сайта $SITE_ROOT"
mkdir -p "$SITE_ROOT"

# Заглушка, чтобы домен не отдавал пустоту до первой выкладки
if [ ! -f "$SITE_ROOT/index.html" ]; then
    cat > "$SITE_ROOT/index.html" <<'HTML'
<!doctype html>
<html lang="ru"><head><meta charset="utf-8"><title>Окна Профигрупп</title></head>
<body style="font-family:system-ui;background:#161528;color:#fff;display:grid;place-items:center;height:100vh;margin:0">
<p>Сайт скоро появится.</p></body></html>
HTML
    ok "Поставил временную страницу"
fi

# ------------------------------------------------------- журнал заявок
say "Готовлю каталог для заявок $LEADS_DIR"
# Лежит вне папки сайта, чтобы заявки нельзя было скачать из браузера
mkdir -p "$LEADS_DIR"
chown www-data:www-data "$LEADS_DIR"
chmod 750 "$LEADS_DIR"
ok "Каталог создан, доступ только у веб-сервера"

# ---------------------------------------------------------------- nginx
say "Настраиваю nginx на домен $DOMAIN_HUMAN"
CONF_SRC="$(dirname "$0")/nginx-site.conf"
if [ ! -f "$CONF_SRC" ]; then
    echo "Рядом со скриптом нет файла nginx-site.conf — положите его туда же."
    exit 1
fi

sed -e "s|__DOMAIN__|$DOMAIN_PUNY|g" \
    -e "s|unix:/run/php/php-fpm.sock|unix:$PHP_SOCK|g" \
    "$CONF_SRC" > /etc/nginx/sites-available/okna

ln -sf /etc/nginx/sites-available/okna /etc/nginx/sites-enabled/okna
rm -f /etc/nginx/sites-enabled/default

if nginx -t 2>/dev/null; then
    systemctl reload nginx
    ok "nginx настроен и перезапущен"
else
    echo "Ошибка в конфигурации nginx:"
    nginx -t
    exit 1
fi

# ---------------------------------------------------------------- пользователь для выкладки
say "Готовлю пользователя $DEPLOY_USER для автоматической выкладки"
if ! id "$DEPLOY_USER" >/dev/null 2>&1; then
    adduser --system --group --shell /bin/bash --home "/home/$DEPLOY_USER" "$DEPLOY_USER"
    ok "Пользователь создан"
else
    ok "Пользователь уже есть"
fi

chown -R "$DEPLOY_USER":"$DEPLOY_USER" "$SITE_ROOT"
chmod -R 755 "$SITE_ROOT"

# nginx должен читать файлы сайта
usermod -aG "$DEPLOY_USER" www-data 2>/dev/null || true

mkdir -p "/home/$DEPLOY_USER/.ssh"
chmod 700 "/home/$DEPLOY_USER/.ssh"

KEY="/home/$DEPLOY_USER/.ssh/github_deploy"
NEW_KEY=0
if [ ! -f "$KEY" ]; then
    ssh-keygen -t ed25519 -N "" -C "github-deploy" -f "$KEY" >/dev/null
    NEW_KEY=1
    ok "Создан ключ для выкладки"
else
    ok "Ключ для выкладки уже был"
fi

touch "/home/$DEPLOY_USER/.ssh/authorized_keys"
if ! grep -qFf "$KEY.pub" "/home/$DEPLOY_USER/.ssh/authorized_keys" 2>/dev/null; then
    cat "$KEY.pub" >> "/home/$DEPLOY_USER/.ssh/authorized_keys"
fi
chmod 600 "/home/$DEPLOY_USER/.ssh/authorized_keys"
chown -R "$DEPLOY_USER":"$DEPLOY_USER" "/home/$DEPLOY_USER/.ssh"

# ---------------------------------------------------------------- файрвол
say "Включаю файрвол"
ufw allow OpenSSH >/dev/null 2>&1 || true
ufw allow 'Nginx Full' >/dev/null 2>&1 || true
ufw --force enable >/dev/null 2>&1 || true
ok "Открыты порты 22, 80 и 443"

# ---------------------------------------------------------------- сертификат
say "Проверяю, указывает ли домен на этот сервер"
SERVER_IP="$(curl -s --max-time 10 https://api.ipify.org || echo '')"
DOMAIN_IP="$(getent hosts "$DOMAIN_PUNY" | awk '{print $1}' | head -n1 || echo '')"

echo "    IP сервера: ${SERVER_IP:-неизвестен}"
echo "    IP домена:  ${DOMAIN_IP:-не определился}"

if [ -n "$SERVER_IP" ] && [ "$SERVER_IP" = "$DOMAIN_IP" ]; then
    say "Выпускаю сертификат Let's Encrypt"
    certbot --nginx \
        -d "$DOMAIN_PUNY" -d "www.$DOMAIN_PUNY" \
        --non-interactive --agree-tos -m "$EMAIL" --redirect || {
            warn "Сертификат выпустить не удалось. Сайт работает по http."
            warn "Повторить можно командой: certbot --nginx -d $DOMAIN_PUNY -d www.$DOMAIN_PUNY"
        }
    systemctl reload nginx
else
    warn "Домен пока смотрит на другой адрес — сертификат не выпускаю."
    warn "Смените A-запись домена на IP этого сервера, подождите обновления DNS"
    warn "и выполните: certbot --nginx -d $DOMAIN_PUNY -d www.$DOMAIN_PUNY --redirect"
fi

# ---------------------------------------------------------------- итог
say "Готово"
echo
echo "  Каталог сайта:  $SITE_ROOT"
echo "  Заявки:         $LEADS_DIR (по одному файлу на месяц)"
echo "  Пользователь:   $DEPLOY_USER"
echo "  Адрес сайта:    https://$DOMAIN_HUMAN"
echo
echo "  Заявки записываются на диск всегда. Чтобы они приходили ещё и на"
echo "  почту, после первой выкладки выполните на сервере:"
echo "      cd $SITE_ROOT && cp config.sample.php config.php && nano config.php"
echo "  и впишите пароль приложения от почты (см. комментарии в файле)."
echo

if [ "$NEW_KEY" = "1" ]; then
    echo "──────────────────────────────────────────────────────────────"
    echo " Секреты для GitHub: Settings → Secrets and variables → Actions"
    echo "──────────────────────────────────────────────────────────────"
    echo
    echo " SSH_HOST = ${SERVER_IP:-IP этого сервера}"
    echo " SSH_USER = $DEPLOY_USER"
    echo " SSH_PATH = $SITE_ROOT"
    echo
    echo " SSH_KEY  = всё, что ниже, вместе со строками BEGIN и END:"
    echo
    cat "$KEY"
    echo
    echo "──────────────────────────────────────────────────────────────"
    echo " Скопируйте ключ сейчас — больше он показан не будет."
    echo " Посмотреть позже: cat $KEY"
    echo "──────────────────────────────────────────────────────────────"
fi
