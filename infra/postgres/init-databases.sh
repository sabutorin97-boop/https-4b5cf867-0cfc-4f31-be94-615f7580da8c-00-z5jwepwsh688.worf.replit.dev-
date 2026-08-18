#!/bin/bash
# ============================================================
#  Создаёт две базы И две роли.
#
#  В ТЗ v1 предполагалась одна роль на обе базы — при этом
#  архитектура заявляла независимость контуров. Отдельные роли
#  делают изоляцию реальной: компрометация контура генераций
#  не даёт доступа к таблице Wallet.
# ============================================================
set -euo pipefail

psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname postgres <<-EOSQL
    CREATE ROLE billing_app    WITH LOGIN PASSWORD '${BILLING_DB_PASSWORD}';
    CREATE ROLE generation_app WITH LOGIN PASSWORD '${GENERATION_DB_PASSWORD}';

    CREATE DATABASE billing    OWNER billing_app;
    CREATE DATABASE generation OWNER generation_app;

    -- Роль каждого контура не имеет доступа к чужой базе
    REVOKE ALL ON DATABASE billing    FROM generation_app, PUBLIC;
    REVOKE ALL ON DATABASE generation FROM billing_app, PUBLIC;

    GRANT CONNECT ON DATABASE billing    TO billing_app;
    GRANT CONNECT ON DATABASE generation TO generation_app;
EOSQL

# CHECK-ограничение, которое нельзя выразить в схеме Prisma:
# кошелёк принадлежит либо пользователю, либо организации, но не обоим.
psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname billing <<-'EOSQL'
    -- Выполняется после prisma migrate deploy; здесь оставлено как напоминание
    -- для миграции. Реальное место — файл миграции Prisma:
    --
    -- ALTER TABLE "Wallet" ADD CONSTRAINT wallet_owner_xor
    --   CHECK ((("userId" IS NOT NULL)::int + ("orgId" IS NOT NULL)::int) = 1);
    SELECT 1;
EOSQL

echo "Базы billing и generation созданы, роли разделены."
