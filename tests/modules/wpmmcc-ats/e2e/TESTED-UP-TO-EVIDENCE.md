# Tested up to — evidence gate (B6)

`wpmmcc-ats/source/readme.txt` field **Tested up to** must match the latest WordPress core version that passed the Lab plugin matrix (or documented smoke) for this release candidate.

## Policy

- Do **not** bump `Tested up to` without an evidence pointer below.
- CI / release gate should fail if readme claims a core version with no row here.

## Evidence log

| Date | WP core | Evidence | Notes |
|------|---------|----------|-------|
| 2026-08-28 | 7.0 | Lab `wordpress-test` container `wp core version` → **7.0.4** | Matches readme `Tested up to: 7.0`. Full matrix deep proof still recommended before WP.org submit; core version confirmed on live Lab. |
| 2026-09-28 | 7.1 | Lab slot-e (rebuilt 2026-09-27) `wp core version` → **7.1**; wpmmcc-ats 2.1.4 `active`; `wptsall_table()` loads (bootstrap live); HTTP smoke `/` 200 + `/wp-login.php` 200, zero fatal markers | Re-aligns the half-done main-header bump that had left readme at 7.0 (plugin-check `mismatched_tested_up_to_header`). Full wp-unit (2194/0) still runs on slots a-d at 6.7.2; matrix on 7.1 remains recommended. |

## Hook

- Script: `tests/modules/wpmmcc-ats/e2e/scripts/assert-tested-up-to.sh`
- Until automated hard-fail, owners update this file in the same PR that changes `Tested up to`.
