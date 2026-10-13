// @ts-check
const { test, expect } = require('@playwright/test');
const { wpLogin, ensureLoggedIn } = require('./helpers/wp-login');

test.describe('BuddyPress Import', () => {
  test.beforeEach(async ({ page }) => {
    await ensureLoggedIn(page);
  });

  test('install bp-default-data plugin if needed', async ({ page }) => {
    console.log('Checking for BP Default Data plugin...');

    // Go to plugins page
    await page.goto('/wp-admin/plugins.php');
    await page.waitForLoadState('networkidle');

    // Check if bp-default-data is installed
    const bpDefaultData = page.locator('tr[data-slug="developer-helper-for-buddypress"], tr[data-slug="bp-default-data"]');

    if (await bpDefaultData.count() === 0) {
      console.log('BP Default Data not installed. Installing...');

      // Go to add plugins page
      await page.goto('/wp-admin/plugin-install.php?s=bp+default+data&tab=search&type=term');
      await page.waitForLoadState('networkidle');

      // Look for the plugin and install
      const installButton = page.locator('.plugin-card:has-text("Developer Helper for BuddyPress") .install-now, .plugin-card:has-text("BP Default Data") .install-now');

      if (await installButton.count() > 0) {
        await installButton.first().click();

        // Wait for installation
        await page.waitForSelector('.activate-now', { timeout: 60000 });

        // Activate the plugin
        const activateButton = page.locator('.activate-now');
        await activateButton.click();

        await page.waitForLoadState('networkidle');
        console.log('BP Default Data plugin installed and activated');
      }
    } else {
      // Check if active
      const activeRow = page.locator('tr.active[data-slug="developer-helper-for-buddypress"], tr.active[data-slug="bp-default-data"]');
      if (await activeRow.count() === 0) {
        // Activate it
        const activateLink = bpDefaultData.locator('a:has-text("Activate")');
        if (await activateLink.count() > 0) {
          await activateLink.click();
          await page.waitForLoadState('networkidle');
        }
      }
      console.log('BP Default Data plugin is ready');
    }
  });

  test('generate BuddyPress default data', async ({ page }) => {
    console.log('Generating BuddyPress default data...');

    // Try network admin first (multisite), then regular admin
    // Plugin uses 'bpdd-setup' as page slug, and 'settings.php' on multisite, 'tools.php' on single site
    const paths = [
      '/wp-admin/network/settings.php?page=bpdd-setup',
      '/wp-admin/tools.php?page=bpdd-setup',
    ];

    let found = false;
    for (const path of paths) {
      console.log(`Trying path: ${path}`);
      await page.goto(path);
      await page.waitForLoadState('networkidle');

      const title = await page.title();
      const url = page.url();
      console.log(`  Title: ${title}`);
      console.log(`  URL: ${url}`);

      // Take screenshot
      await page.screenshot({ path: 'buddypress-default-data.png', fullPage: true });

      if (!title.includes('Error') && !url.includes('wp-login')) {
        console.log(`Found BP Default Data at: ${path}`);
        found = true;
        break;
      }
    }

    if (!found) {
      console.log('BP Default Data page not accessible on all paths');
    }

    // Check if we're on the right page
    const pageTitle = await page.title();
    console.log(`Page title: ${pageTitle}`);

    // Take screenshot
    await page.screenshot({ path: 'buddypress-default-data.png', fullPage: true });

    // Look for form fields to set data amounts
    const userCount = page.locator('input[name="users_count"], #users_count, input[name="bpdd_users"]');
    if (await userCount.count() > 0) {
      await userCount.fill('20'); // Generate 20 users
    }

    const groupCount = page.locator('input[name="groups_count"], #groups_count, input[name="bpdd_groups"]');
    if (await groupCount.count() > 0) {
      await groupCount.fill('5'); // Generate 5 groups
    }

    const activityCount = page.locator('input[name="activity_count"], #activity_count, input[name="bpdd_activity"]');
    if (await activityCount.count() > 0) {
      await activityCount.fill('50'); // Generate 50 activities
    }

    // Check all checkboxes for data types to import
    const checkboxes = page.locator('input[type="checkbox"][name*="bpdd"], input[type="checkbox"][name*="import"]');
    const checkboxCount = await checkboxes.count();
    for (let i = 0; i < checkboxCount; i++) {
      await checkboxes.nth(i).check();
    }

    // Look for generate/submit button
    const generateButton = page.locator('input[type="submit"], button:has-text("Generate"), button:has-text("Import"), #bpdd_generate');

    if (await generateButton.count() > 0) {
      console.log('Clicking generate button...');
      await generateButton.first().click();

      // Wait for generation to complete
      await page.waitForLoadState('networkidle', { timeout: 120000 });

      // Check for success message
      const successMessage = page.locator('.notice-success, .updated, .success, .bpdd-success');
      if (await successMessage.count() > 0) {
        console.log('BuddyPress data generated successfully!');
      }
    } else {
      console.log('Generate button not found');
    }

    // Verify: check members count
    await page.goto('/wp-admin/users.php');
    await page.waitForLoadState('networkidle');

    const userRows = page.locator('table.wp-list-table tbody tr');
    const count = await userRows.count();
    console.log(`Total users after generation: ${count}`);
  });
});
