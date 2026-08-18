FROM alpine:3.20

RUN apk add --no-cache \
      wireguard-tools \
      iptables \
      tinyproxy \
      curl \
      bind-tools \
      openresolv

COPY tinyproxy.conf /etc/tinyproxy/tinyproxy.conf
COPY entrypoint.sh  /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 8888
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
