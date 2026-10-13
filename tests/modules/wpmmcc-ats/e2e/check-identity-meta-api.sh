#!/usr/bin/env bash
# Gate: internal WPTSALL meta keys must not use WP get/update/delete_post_meta
# (or get/update/delete_term_meta for field_owners) in product code except allowlisted paths.
#
# Covers identity keys + field_owners / last_synced / translation_meta / source_attachment_id.
#
#   bash tests/modules/wpmmcc-ats/e2e/check-identity-meta-api.sh
#
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
ROOT="$(cd "${SCRIPT_DIR}/../../../.." && pwd)"
SRC="${ROOT}/wpmmcc-ats/source/includes"

KEYS='_wptsall_virtual_site_id|_wptsall_source_post_id|_wptsall_relation_id|_wptsall_source_blog_id|_wptsall_field_owners|_wptsall_last_synced|_wptsall_translation_meta|_wptsall_source_attachment_id'
ALLOW_FILES=(
  'sites/services/class-translation-identity.php'
  'tasks/services/class-direct-db-service.php'
  'hooks/class-virtual-site-query-switch.php'
  'sync/services/class-field-ownership-service.php'
  'sites/services/class-manual-content-service.php'
)

tmp="$(mktemp)"
rg -n --glob '*.php' -e "(get|update|delete)_post_meta\s*\([^;]*('|\")(${KEYS})('|\")" "${SRC}" \
  | while IFS= read -r line; do
      file="${line%%:*}"
      rel="${file#${SRC}/}"
      skip=0
      for a in "${ALLOW_FILES[@]}"; do
        if [[ "${rel}" == "${a}" ]]; then
          skip=1
          break
        fi
      done
      if [[ "${line}" == *'Direct_DB_Service::'* ]]; then
        skip=1
      fi
      if [[ "${line}" == *'Translation_Identity::'* ]]; then
        skip=1
      fi
      # persist_internal_post_meta is the approved internal write helper.
      if [[ "${rel}" == 'sites/services/class-manual-content-service.php' && "${line}" == *'persist_internal'* ]]; then
        skip=1
      fi
      if [[ ${skip} -eq 0 ]]; then
        echo "${line}"
      fi
    done > "${tmp}"

# Also flag get/update_term_meta on field_owners / last_synced outside allowlist.
rg -n --glob '*.php' -e "(get|update|delete)_term_meta\s*\([^;]*('|\")(_wptsall_field_owners|_wptsall_last_synced|_wptsall_translation_meta)('|\")" "${SRC}" \
  | while IFS= read -r line; do
      file="${line%%:*}"
      rel="${file#${SRC}/}"
      skip=0
      for a in "${ALLOW_FILES[@]}"; do
        if [[ "${rel}" == "${a}" ]]; then
          skip=1
          break
        fi
      done
      if [[ "${line}" == *'Direct_DB_Service::'* ]]; then
        skip=1
      fi
      if [[ ${skip} -eq 0 ]]; then
        echo "${line}"
      fi
    done >> "${tmp}"

count="$(wc -l < "${tmp}" | tr -d ' ')"
if [[ "${count}" != "0" ]]; then
  echo "[identity-meta-api] FAIL: ${count} forbidden WP meta API call(s) on internal keys:" >&2
  cat "${tmp}" >&2
  rm -f "${tmp}"
  exit 1
fi
rm -f "${tmp}"
echo "[identity-meta-api] OK — no forbidden WP meta API on identity/internal keys"
