# Plugin coverage matrix

Nearly 20 content categories and 100 plugins. Each plugin is isolate-activated (only that plugin + `wpmmcc-ats`), then exercised on:

- WPTSALL mapping / listen / virtual + real subsite
- The **target plugin** frontend and wp-admin editor
- Content management (create → map → edit source dirty-mark)
- URL open (source, virtual, subsite)
- Language-following links (hreflang / virtual switcher marker)

## Layout

| Path | Role |
|---|---|
| `catalog.json` | Plugin list + categories |
| `PROGRESS.md` | Live status |
| `records/<slug>.md` | Human record per plugin |
| `results/<slug>.json` | Machine result |
| `harness/run-one.php` | One-plugin lab runner (`wp eval-file`) |
| `harness/install-batch.sh` | Download WP.org zips into the lab |
| `harness/run-batch.sh` | Install + isolate + test + write records |

## Run

```bash
# Download as many catalog plugins as WP.org serves
bash tests/modules/wpmmcc-ats/plugin-coverage/harness/install-batch.sh

# One plugin
docker exec wptsall-wp-lab-wordpress-blog-1 wp eval-file \
  /var/www/html/wp-content/plugins/wpmmcc-ats/../..  # use copied harness
# Preferred:
docker cp tests/modules/wpmmcc-ats/plugin-coverage/harness/run-one.php \
  wptsall-wp-lab-wordpress-blog-1:/tmp/run-one.php
docker exec wptsall-wp-lab-wordpress-blog-1 wp eval-file /tmp/run-one.php woocommerce --allow-root
```

Or `bash tests/modules/wpmmcc-ats/plugin-coverage/harness/run-batch.sh [slug ...]`
