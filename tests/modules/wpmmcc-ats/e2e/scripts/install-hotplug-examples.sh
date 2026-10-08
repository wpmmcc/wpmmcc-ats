#!/usr/bin/env bash
# Install tests/docs/e2e/examples/wptsall-field-rules.*.example.json into Lab plugin dirs
# when those plugins are present under WP_PLUGIN_DIR / docker-lab content plugins.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../../../../.." && pwd)"
EXAMPLES="$ROOT/tests/docs/e2e/examples"
# Prefer env, else common Lab mount
PLUGIN_ROOT="${WP_PLUGIN_DIR:-}"
if [[ -z "$PLUGIN_ROOT" ]]; then
  if [[ -d /var/www/html/wp-content/plugins ]]; then
    PLUGIN_ROOT=/var/www/html/wp-content/plugins
  elif [[ -d "$ROOT/tests/docker-lab/wordpress/wp-content/plugins" ]]; then
    PLUGIN_ROOT="$ROOT/tests/docker-lab/wordpress/wp-content/plugins"
  else
    echo "Set WP_PLUGIN_DIR to the target wp-content/plugins path"
    exit 1
  fi
fi

installed=0
skipped=0
for f in "$EXAMPLES"/wptsall-field-rules.*.example.json; do
  [[ -f "$f" ]] || continue
  slug=$(basename "$f" | sed -E 's/^wptsall-field-rules\.(.+)\.example\.json$/\1/')
  dest_dir="$PLUGIN_ROOT/$slug"
  if [[ ! -d "$dest_dir" ]]; then
    echo "SKIP $slug (plugin dir missing)"
    skipped=$((skipped + 1))
    continue
  fi
  cp -f "$f" "$dest_dir/wptsall-field-rules.json"
  echo "OK   $slug -> $dest_dir/wptsall-field-rules.json"
  installed=$((installed + 1))
done
echo "installed=$installed skipped=$skipped"
php "$ROOT/tests/modules/wpmmcc-ats/unit/adapters/validate-field-rules-examples.php"
