/**
 * 35 — Setup wizard single-source-of-truth (4.5 残留两小真).
 *
 * Coverage gap (3.8flash 4.5 / VERIFIED-REPAIR-PLAN-20260925 第一部分 4.5 行):
 * the 5-step setup wizard carried two small-but-real defects —
 *   ① Step 3 (Target languages) read its checkbox state from the wizard's
 *      PRIVATE option (wptsall_wizard_target_languages) while the REAL
 *      target store is the languages records' status column — two sources
 *      of truth: Languages-page edits never showed up in the wizard, and
 *      the wizard's picks never landed in the real store (the private
 *      option had zero functional consumers).
 *   ② Step 4 (Models) persisted enable-only (`SET status='active' WHERE id
 *      IN (checked)`) — unchecking everything was a silent no-op, so a
 *      model could never be deactivated THROUGH the wizard (asymmetric
 *      with the Models page).
 *
 * The repair (class-setup-wizard.php + Language_Service::set_status()):
 * step-3 render reads the real records (default checked + DISABLED — it is
 *      the fallback base), step-3 submit writes the real statuses via the
 *      new canonical Language_Service::set_status() (the private option is
 *      no longer written), step-5 summary reads the real store, and step-4
 *      submit takes the form as the FULL desired state (unchecked = explicit
 *      deactivate; an untouched submit is identity because checkboxes
 *      pre-populate from the current records).
 *
 * Journey (against the default lab site, container wptsall-wp-lab-wordpress-test-1):
 *   1. Seeded languages (e2e-tg-a active / e2e-tg-b inactive) render their
 *      REAL statuses as checkbox states; the default language's checkbox
 *      is checked + disabled; submitting a flipped set writes the REAL
 *      records (a→inactive, b→active), the default stays active, and the
 *      wizard-private option is NOT written
 *   2. Seeded models (e2e-wiz-a active / e2e-wiz-b inactive) render their
 *      real statuses; a flipped submit lands both states (a→inactive,
 *      b→active); a second submit with EVERYTHING unchecked deactivates
 *      both (the previously-unreachable leg); the step-5 summary lists the
 *      target languages from the REAL store
 *
 * Run (default site):
 *   npx playwright test -c comprehensive/playwright.comprehensive.config.ts --workers=1 35-wizard-single-source
 * Run (slot variant, same pattern as spec 29):
 *   WP_BASE=http://127.0.0.1:9182 LAB_WP_CONTAINER=wptsall-wp-lab-wordpress-slot-b \
 *     bash tests/modules/wpmmcc-ats/e2e/run-playwright-admin-pages.sh 35
 *
 * catalog: WP-CLASS-WPTSALL\Wizard\Setup_Wizard
 * oracle: L1
 */
import { test, expect, type Page } from '@playwright/test'
import { execFileSync } from 'child_process'
import { wpLogin, WP_BASE_URL, findFatalError } from './helpers'

const WIZARD_PAGE = `${WP_BASE_URL}/wp-admin/admin.php?page=wptsall-wizard`
const WPMMCC_ATS_CONTAINER =
  process.env.LAB_WP_CONTAINER ||
  process.env.WPTSALL_LAB_WP_CONTAINER ||
  'wptsall-wp-lab-wordpress-test-1'

// Deterministic fixture rows (idempotent: wiped by code/slug before insert).
const LANG_A = 'e2e-tg-a'
const LANG_B = 'e2e-tg-b'
const MODEL_A = 'e2e-wiz-model-a'
const MODEL_B = 'e2e-wiz-model-b'

/** Run a wp eval snippet inside the lab test container (no shell layer). */
function wpEval(php: string): string {
  try {
    return execFileSync(
      'docker',
      [
        'exec',
        '-e',
        'PAGER=cat',
        WPMMCC_ATS_CONTAINER,
        'wp',
        'eval',
        php,
        '--allow-root',
        '--path=/var/www/html',
      ],
      { encoding: 'utf-8' },
    )
  } catch (e: any) {
    return String((e.stdout ?? '') + (e.stderr ?? ''))
  }
}

/** One scalar via prepared SQL. */
function dbScalar(sql: string, ...args: string[]): string {
  return wpEval(
    `global $wpdb; echo (string) $wpdb->get_var($wpdb->prepare(${JSON.stringify(sql)}, ${args
      .map((a) => JSON.stringify(a))
      .join(', ')}));`,
  ).trim()
}

/** Idempotent seed: two language records + two model rows + fresh wizard state. */
function seedFixture(): void {
  const php = `
global $wpdb;
$langs = $wpdb->prefix . 'wptsall_languages';
$models = $wpdb->prefix . 'wptsall_models';
$now = current_time('mysql');
foreach (array(${JSON.stringify(LANG_A)}, ${JSON.stringify(LANG_B)}) as $code) {
  $wpdb->query($wpdb->prepare("DELETE FROM {$langs} WHERE code = %s", $code));
}
foreach (array(${JSON.stringify(MODEL_A)}, ${JSON.stringify(MODEL_B)}) as $slug) {
  $wpdb->query($wpdb->prepare("DELETE FROM {$models} WHERE plugin_slug = %s", $slug));
}
$wpdb->insert($langs, array(
  'code' => ${JSON.stringify(LANG_A)}, 'slug' => ${JSON.stringify(LANG_A)}, 'name' => 'E2E Target A',
  'native_name' => 'E2E Target A', 'locale' => 'e2e_TA', 'flag' => '', 'direction' => 'ltr',
  'sort_order' => 970, 'is_default' => 0, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now,
));
$wpdb->insert($langs, array(
  'code' => ${JSON.stringify(LANG_B)}, 'slug' => ${JSON.stringify(LANG_B)}, 'name' => 'E2E Target B',
  'native_name' => 'E2E Target B', 'locale' => 'e2e_TB', 'flag' => '', 'direction' => 'ltr',
  'sort_order' => 971, 'is_default' => 0, 'status' => 'inactive', 'created_at' => $now, 'updated_at' => $now,
));
$wpdb->insert($models, array(
  'plugin_slug' => ${JSON.stringify(MODEL_A)}, 'plugin_name' => 'E2E Wizard Model A', 'status' => 'active',
));
$wpdb->insert($models, array(
  'plugin_slug' => ${JSON.stringify(MODEL_B)}, 'plugin_name' => 'E2E Wizard Model B', 'status' => 'inactive',
));
delete_option('wptsall_wizard_state');
delete_option('wptsall_wizard_target_languages');
echo 'seeded:' . (string) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$langs} WHERE code IN (%s, %s)", ${JSON.stringify(
    LANG_A,
  )}, ${JSON.stringify(LANG_B)}));
`
  const out = wpEval(php.replace(/^\n/, ''))
  if (!out.trim().startsWith('seeded:')) {
    throw new Error(`fixture seed failed: ${out}`)
  }
}

/** Remove every fixture row + wizard state. */
function cleanFixture(): void {
  wpEval(`
global $wpdb;
$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}wptsall_languages WHERE code IN (%s, %s)", ${JSON.stringify(
    LANG_A,
  )}, ${JSON.stringify(LANG_B)}));
$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}wptsall_models WHERE plugin_slug IN (%s, %s)", ${JSON.stringify(
    MODEL_A,
  )}, ${JSON.stringify(MODEL_B)}));
delete_option('wptsall_wizard_state');
delete_option('wptsall_wizard_target_languages');
echo 'clean';
`)
}

/** The wizard's single main step form (action=wptsall_wizard_step). */
async function wizardStepForm(page: Page) {
  const form = page.locator('form[action*="admin-post.php"]').filter({
    has: page.locator('input[name="action"][value="wptsall_wizard_step"]'),
  })
  await expect(form).toHaveCount(1)
  return form
}

test.describe('35 Setup wizard single source of truth (4.5 两小真)', () => {
  test.describe.configure({ mode: 'serial' })
  // Multi-leg journey (login + step-2 + step-3 + two step-4 submits).
  test.setTimeout(120_000)

  test.beforeAll(() => {
    cleanFixture()
    seedFixture()
  })

  test.afterAll(() => {
    cleanFixture()
  })

  test('step-3 targets: real-record checkboxes, real-store write, no private option', async ({ page }) => {
    await wpLogin(page)

    // Step-2 leg first (4.5-① family): the wizard previously wrote ONLY the
    // settings store, leaving the languages records' is_default marker
    // stale (the lab carries exactly this divergence: settings=zh_CN while
    // an en_US record holds is_default=1). Submitting step 2 with the
    // CURRENT settings default is an identity settings write that must
    // converge the record marker.
    const settingsDefault = wpEval(
      `echo (string) ( get_option( 'wptsall_settings', array() )['default_language'] ?? '' );`,
    ).trim()
    expect(settingsDefault, 'the lab must carry a settings default_language').toBeTruthy()
    expect(
      dbScalar(`SELECT COUNT(*) FROM {$wpdb->prefix}wptsall_languages WHERE code = %s`, settingsDefault),
      'the settings default must resolve to a language record',
    ).toBe('1')

    await page.goto(`${WIZARD_PAGE}&step=2`, { waitUntil: 'domcontentloaded' })
    await expect(page.getByRole('heading', { name: 'Default language' })).toBeVisible()
    let form = await wizardStepForm(page)
    await form.locator('select[name="default_language"]').selectOption(settingsDefault)
    await Promise.all([
      page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 45_000 }).catch(() => null),
      form.locator("button.button-primary[type=\"submit\"]").click(),
    ])
    expect(
      dbScalar(`SELECT code FROM {$wpdb->prefix}wptsall_languages WHERE is_default = 1 LIMIT 1`),
      'the step-2 pick must converge the record-level is_default marker (both stores agree)',
    ).toBe(settingsDefault)

    await expect(page.getByRole('heading', { name: 'Target languages' })).toBeVisible()

    form = await wizardStepForm(page)

    // Checkbox states render from the REAL records: a=active → checked,
    // b=inactive → unchecked (previously both keyed off the stale private
    // option).
    const cbA = form.locator(`input[name="target_languages[]"][value="${LANG_A}"]`)
    const cbB = form.locator(`input[name="target_languages[]"][value="${LANG_B}"]`)
    await expect(cbA).toBeChecked()
    await expect(cbB).not.toBeChecked()

    // The default language's checkbox (the settings default the wizard
    // just picked) is checked AND disabled (it is the fallback base —
    // untargeting it is meaningless and it must never be unsubmitted).
    const cbDefault = form.locator(`input[name="target_languages[]"][value="${settingsDefault}"]`)
    await expect(cbDefault).toBeChecked()
    await expect(cbDefault).toBeDisabled()

    // Flip the seeded pair: uncheck a, check b.
    await cbA.uncheck()
    await cbB.check()

    await Promise.all([
      page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 45_000 }).catch(() => null),
      form.locator("button.button-primary[type=\"submit\"]").click(),
    ])
    await expect(page.getByRole('heading', { name: 'Models to enable' })).toBeVisible({ timeout: 10_000 })

    // The write landed in the REAL records (languages status column)…
    expect(dbScalar(`SELECT status FROM {$wpdb->prefix}wptsall_languages WHERE code = %s`, LANG_A)).toBe('inactive')
    expect(dbScalar(`SELECT status FROM {$wpdb->prefix}wptsall_languages WHERE code = %s`, LANG_B)).toBe('active')
    // …the default stays active regardless (disabled input, forced active)…
    expect(dbScalar(`SELECT status FROM {$wpdb->prefix}wptsall_languages WHERE code = %s`, settingsDefault)).toBe('active')
    // …and the wizard-private option is NOT written (single source of truth).
    expect(dbScalar(`SELECT COUNT(*) FROM {$wpdb->prefix}options WHERE option_name = 'wptsall_wizard_target_languages'`)).toBe('0')

    expect(findFatalError(await page.content()), 'fatal after step-3 submit').toBeNull()
  })

  test('step-4 models: full-state submit (flip + all-off deactivation leg)', async ({ page }) => {
    await wpLogin(page)

    // Leg 1: flipped submit — a active→unchecked, b inactive→checked.
    await page.goto(`${WIZARD_PAGE}&step=4`, { waitUntil: 'domcontentloaded' })
    await expect(page.getByRole('heading', { name: 'Models to enable' })).toBeVisible()
    let form = await wizardStepForm(page)

    const boxA = form.locator(`input[name="models[]"][value="${modelId(MODEL_A)}"]`)
    const boxB = form.locator(`input[name="models[]"][value="${modelId(MODEL_B)}"]`)
    await expect(boxA).toBeChecked()
    await expect(boxB).not.toBeChecked()

    await boxA.uncheck()
    await boxB.check()
    await Promise.all([
      page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 45_000 }).catch(() => null),
      form.locator("button.button-primary[type=\"submit\"]").click(),
    ])
    await expect(page.getByRole('heading', { name: 'Done' })).toBeVisible({ timeout: 10_000 })

    expect(dbScalar(`SELECT status FROM {$wpdb->prefix}wptsall_models WHERE plugin_slug = %s`, MODEL_A)).toBe('inactive')
    expect(dbScalar(`SELECT status FROM {$wpdb->prefix}wptsall_models WHERE plugin_slug = %s`, MODEL_B)).toBe('active')

    // Step-5 summary reads the REAL store (target languages from the
    // records — LANG_B is active after the step-3 leg; LANG_A is not).
    const summary = await page.locator('.wptsall-wizard').textContent()
    expect(summary ?? '', 'summary must list the active target from the real store').toContain(LANG_B)
    expect(summary ?? '', 'summary must not list the inactive target').not.toContain(LANG_A)

    // Leg 2: ALL unchecked — the previously-unreachable deactivation
    // (enable-only made an all-off submit a silent no-op). The models table
    // is lab-shared (200+ active rows), so snapshot the active set first and
    // restore it in a finally: the leg proves the global all-off semantics
    // WITHOUT leaving the lab degraded.
    const activeSnapshot = wpEval(
      `global $wpdb; echo implode(',', array_map('intval', (array) $wpdb->get_col("SELECT id FROM {$wpdb->prefix}wptsall_models WHERE status = 'active'")));`,
    ).trim()
    expect(activeSnapshot, 'the lab must have active models to deactivate').not.toBe('')
    const snapshotIds = activeSnapshot.split(',').map(Number).filter((n) => Number.isFinite(n) && n > 0)
    expect(snapshotIds.length, 'the snapshot must be a non-empty id set').toBeGreaterThan(0)

    try {
      await page.goto(`${WIZARD_PAGE}&step=4`, { waitUntil: 'domcontentloaded' })
      form = await wizardStepForm(page)
      await expect(form.locator('input[name="models[]"]:checked').first()).toBeChecked()
      // Bulk uncheck (plain inputs, no change listeners; the form submits
      // the live DOM state).
      await page.evaluate(() => {
        document.querySelectorAll<HTMLInputElement>('input[name="models[]"]:checked').forEach((el) => {
          el.checked = false
        })
      })
      await Promise.all([
        page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 45_000 }).catch(() => null),
        form.locator("button.button-primary[type=\"submit\"]").click(),
      ])
      await expect(page.getByRole('heading', { name: 'Done' })).toBeVisible({ timeout: 10_000 })

      // The previously-unreachable leg: EVERY model row went inactive.
      expect(dbScalar(`SELECT COUNT(*) FROM {$wpdb->prefix}wptsall_models WHERE status = 'active'`)).toBe('0')
      expect(dbScalar(`SELECT status FROM {$wpdb->prefix}wptsall_models WHERE plugin_slug = %s`, MODEL_A)).toBe('inactive')
      expect(dbScalar(`SELECT status FROM {$wpdb->prefix}wptsall_models WHERE plugin_slug = %s`, MODEL_B)).toBe('inactive')
      expect(findFatalError(await page.content()), 'fatal after step-4 submits').toBeNull()
    } finally {
      // Exact restore of the pre-leg active set (ids are ints; the IN list
      // is safe by construction).
      wpEval(`
global $wpdb;
$ids = array_map('intval', explode(',', ${JSON.stringify(activeSnapshot)}));
$ids = array_values(array_filter($ids));
if ($ids) {
  $in = implode(',', $ids);
  $wpdb->query("UPDATE {$wpdb->prefix}wptsall_models SET status = 'active' WHERE id IN ($in)");
}
echo 'restored:' . count($ids);
`)
    }
    // The restore landed the exact snapshot set back to active.
    expect(
      dbScalar(`SELECT COUNT(*) FROM {$wpdb->prefix}wptsall_models WHERE status = 'active'`),
      'the lab active set must be restored exactly',
    ).toBe(String(snapshotIds.length))
  })
})

/** Resolve a model row id by plugin_slug. */
function modelId(slug: string): string {
  return dbScalar(`SELECT id FROM {$wpdb->prefix}wptsall_models WHERE plugin_slug = %s`, slug)
}
