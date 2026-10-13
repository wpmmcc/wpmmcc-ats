#!/usr/bin/env bash
# Ensure a dedicated MySQL database for an E2E slot (ISS T1 / W4).
# Shared MariaDB instance; per-slot DB name = wplab_slot_<slot>.  The matching
# ensure-slot-wordpress.sh provisioner binds one WordPress container to this DB.
set -euo pipefail

SLOT="${1:-${E2E_SLOT:-slot-a}}"
SLOT_SAFE="$(echo "${SLOT}" | tr '[:upper:]' '[:lower:]' | sed 's/[^a-z0-9_-]//g')"
DB_NAME="wplab_${SLOT_SAFE//-/_}"

ROOT_DIR="$(cd "$(dirname "$0")/../../../../.." && pwd)"
LAB_ENV="${ROOT_DIR}/tests/docker-lab/.env"
if [[ -f "${LAB_ENV}" ]]; then
  # shellcheck disable=SC1090
  set -a; source "${LAB_ENV}"; set +a
fi

MYSQL_HOST="${MYSQL_HOST:-127.0.0.1}"
MYSQL_PORT="${MYSQL_PORT:-13306}"
MYSQL_ROOT_PASSWORD="${MYSQL_ROOT_PASSWORD:-wplab_root_2026}"
MYSQL_USER="${MYSQL_USER:-wplab}"
MYSQL_PASSWORD="${MYSQL_PASSWORD:-wplab_pass_2026}"

echo "[slot-db] ensuring database ${DB_NAME} for slot=${SLOT}"

mysql_cmd=(mysql -h"${MYSQL_HOST}" -P"${MYSQL_PORT}" -uroot "-p${MYSQL_ROOT_PASSWORD}")
if ! command -v mysql >/dev/null 2>&1; then
  # Fall back to docker exec into lab db container.
  CID="$(docker ps --filter name=wptsall-wp-lab --format '{{.Names}}' | grep -E 'db|mysql|mariadb' | head -1 || true)"
  if [[ -z "${CID}" ]]; then
    echo "[slot-db] ERROR: mysql client missing and no lab db container found" >&2
    exit 1
  fi
  mysql_cmd=(docker exec -i "${CID}" mysql -uroot "-p${MYSQL_ROOT_PASSWORD}")
fi

"${mysql_cmd[@]}" <<SQL
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${MYSQL_USER}'@'%';
FLUSH PRIVILEGES;
SQL

MANIFEST_DIR="${ROOT_DIR}/tests/modules/wpmmcc-ats/e2e/runtime/${SLOT}"
mkdir -p "${MANIFEST_DIR}"
cat > "${MANIFEST_DIR}/slot-db.json" <<EOF
{
  "slot": "${SLOT}",
  "database": "${DB_NAME}",
  "host": "${MYSQL_HOST}",
  "port": ${MYSQL_PORT},
  "user": "${MYSQL_USER}",
  "created_at": "$(date -Iseconds)"
}
EOF

echo "[slot-db] ok database=${DB_NAME} manifest=${MANIFEST_DIR}/slot-db.json"
echo "${DB_NAME}"
