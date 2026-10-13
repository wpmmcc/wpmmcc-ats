# ISS-16 Release Stabilization Runbook

This runbook is the release-side checklist for the WPTSALL E2E v2 stabilization gate.

## Required Gate Order

1. Run the core E2E lane on the test host:
   `bash tests/modules/wpmmcc-ats/e2e/run.sh --with-journeys`
2. Run component template baseline:
   `bash tests/modules/wpmmcc-ats/e2e/run-component-template-lane.sh baseline`
3. Run release-required component template checks:
   `bash tests/modules/wpmmcc-ats/e2e/run-component-template-lane.sh release-required`
4. Run the project matrix for plugin coverage:
   `bash tests/modules/wpmmcc-ats/e2e/run-project-matrix.sh --include-core --scope full --with-journeys`
5. Run final release gate:
   `bash tests/modules/wpmmcc-ats/e2e/run-release-gate.sh`

## Blocking Conditions

- ISS-15 coverage has failed checks.
- ISS-16 stability has failed checks.
- MySQL postcheck reports crash or restart markers during the run window.
- Client OAuth, worker run-once, callback write-back, or marker precision fails.
- Release flags show simulation enabled or unsafe marker mode.

## Stabilization Checks

- `tests/modules/wpmmcc-ats/e2e/php/verify-iss15-coverage.php`
- `tests/modules/wpmmcc-ats/e2e/php/snapshot-iss16-release-flags.php`
- `tests/modules/wpmmcc-ats/e2e/php/verify-iss16-stability.php`
- `tests/infra/test-host/mysql-postcheck.sh`

## Rollback

Use `bash tests/modules/wpmmcc-ats/e2e/release-rollback.sh --dry-run` before any real rollback. The script is intentionally conservative and only operates on known release options.
