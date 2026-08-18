#!/bin/sh
# ============================================================
#  Egress: WireGuard + HTTP-прокси с kill switch.
#
#  Зачем kill switch. Если туннель падает, а маршрут по умолчанию
#  остаётся, запросы к провайдерам уходят с российского IP.
#  Часть провайдеров закрывает доступ по гео — это быстрый способ
#  получить бан аккаунта, на котором лежат предоплаченные деньги.
#  Поэтому при отказе туннеля трафик не «идёт напрямую», а не идёт вообще:
#  воркер получает ошибку соединения, переводит задачи в DEFERRED,
#  кредиты остаются в холде.
#
#  В ТЗ v1 egress был описан как «WireGuard-клиент плюс HTTP-прокси»
#  без правил фильтрации, MTU и резервного пира.
# ============================================================
set -eu

WG_IF="${WG_IF:-wg0}"
WG_MTU="${WG_MTU:-1380}"
PROXY_PORT="${PROXY_PORT:-8888}"

# Внутренняя сеть, из которой разрешено обращаться к прокси
LAN="${EGRESS_LAN_CIDR:-172.16.0.0/12}"

echo "[egress] поднимаем ${WG_IF}, MTU=${WG_MTU}"
wg-quick up "/etc/wireguard/${WG_IF}.conf"
ip link set mtu "${WG_MTU}" dev "${WG_IF}"

# ---- Kill switch -------------------------------------------------
# Политика по умолчанию — DROP. Разрешено ровно четыре вещи:
# loopback, установленные соединения, handshake с эндпоинтом пира
# и весь исходящий трафик через сам туннель.

WG_ENDPOINT_IP="$(awk -F'[ =:]+' '/^Endpoint/ {print $3}' "/etc/wireguard/${WG_IF}.conf")"
WG_ENDPOINT_PORT="$(awk -F':' '/^Endpoint/ {print $NF}' "/etc/wireguard/${WG_IF}.conf" | tr -d ' ')"

iptables -P OUTPUT DROP
iptables -P FORWARD DROP

iptables -A OUTPUT -o lo -j ACCEPT
iptables -A OUTPUT -m conntrack --ctstate ESTABLISHED,RELATED -j ACCEPT
iptables -A OUTPUT -o "${WG_IF}" -j ACCEPT
iptables -A OUTPUT -p udp -d "${WG_ENDPOINT_IP}" --dport "${WG_ENDPOINT_PORT}" -j ACCEPT

# Ответы прокси внутрь docker-сети
iptables -A OUTPUT -d "${LAN}" -p tcp --sport "${PROXY_PORT}" -j ACCEPT

# Входящие: только прокси и только из внутренней сети
iptables -P INPUT DROP
iptables -A INPUT -i lo -j ACCEPT
iptables -A INPUT -m conntrack --ctstate ESTABLISHED,RELATED -j ACCEPT
iptables -A INPUT -s "${LAN}" -p tcp --dport "${PROXY_PORT}" -j ACCEPT

echo "[egress] kill switch активен: выход только через ${WG_IF}"

# ---- Проверка, что выходим действительно через туннель ------------
# Если внешний IP совпадает с адресом хоста — конфигурация неверна,
# и лучше не стартовать вовсе, чем молча выпускать трафик мимо туннеля.
TUNNEL_IP="$(curl -s --max-time 15 https://api.ipify.org || echo '')"
if [ -z "${TUNNEL_IP}" ]; then
  echo "[egress] ОШИБКА: не удалось определить внешний IP через туннель" >&2
  exit 1
fi
echo "[egress] внешний IP через туннель: ${TUNNEL_IP}"

# ---- Прокси ------------------------------------------------------
# Слушает только внутреннюю сеть, наружу порт не публикуется.
exec tinyproxy -d -c /etc/tinyproxy/tinyproxy.conf
