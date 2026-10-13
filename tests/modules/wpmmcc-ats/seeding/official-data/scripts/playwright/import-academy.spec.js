// @ts-check
const { test, expect } = require('@playwright/test');
const { wpLogin, ensureLoggedIn } = require('./helpers/wp-login');

test.describe('Academy LMS Import', () => {
  test.beforeEach(async ({ page }) => {
    await ensureLoggedIn(page);
  });

  test('install academy-starter-templates plugin if needed', async ({ page }) => {
    console.log('Checking for Academy Starter Templates plugin...');

    // Go to plugins page
    await page.goto('/wp-admin/plugins.php');
    await page.waitForLoadState('networkidle');

    // Check if academy-starter-templates is installed
    const starterTemplates = page.locator('tr[data-slug="starter-templates-starter-templates-starter-templates"], tr[data-slug="academy-starter-templates"]');

    if (await starterTemplates.count() === 0) {
      console.log('Academy Starter Templates not installed. Searching...');

      // Go to add plugins page
      await page.goto('/wp-admin/plugin-install.php?s=academy+starter+templates&tab=search&type=term');
      await page.waitForLoadState('networkidle');

      // Look for the plugin and install
      const installButton = page.locator('.plugin-card:has-text("Academy Starter Templates") .install-now');

      if (await installButton.count() > 0) {
        await installButton.first().click();

        // Wait for installation
        await page.waitForSelector('.activate-now', { timeout: 60000 });

        // Activate the plugin
        const activateButton = page.locator('.activate-now');
        await activateButton.click();

        await page.waitForLoadState('networkidle');
        console.log('Academy Starter Templates plugin installed and activated');
      } else {
        console.log('Academy Starter Templates plugin not found in repository');
      }
    } else {
      // Check if active
      const activeRow = page.locator('tr.active[data-slug="academy-starter-templates"]');
      if (await activeRow.count() === 0) {
        // Activate it
        const activateLink = starterTemplates.locator('a:has-text("Activate")');
        if (await activateLink.count() > 0) {
          await activateLink.click();
          await page.waitForLoadState('networkidle');
        }
      }
      console.log('Academy Starter Templates plugin is ready');
    }
  });

  test('import Academy LMS demo data', async ({ page }) => {
    console.log('Importing Academy LMS demo data...');

    // Try different possible menu paths for Academy Starter
    const menuPaths = [
      '/wp-admin/admin.php?page=academy-starter',
      '/wp-admin/admin.php?page=academy-starter-templates',
      '/wp-admin/admin.php?page=academy_lms_starter',
    ];

    let found = false;

    for (const menuPath of menuPaths) {
      await page.goto(menuPath);
      await page.waitForLoadState('networkidle');

      // Check if we found the starter templates page
      if (!page.url().includes('wp-login') && !page.url().includes('error')) {
        found = true;
        console.log(`Found starter templates at: ${menuPath}`);
        break;
      }
    }

    if (!found) {
      // Try finding in admin menu
      console.log('Looking for Academy Starter menu in sidebar...');

      // Look for Academy menu first
      const academyMenu = page.locator('#adminmenu a:has-text("Academy")');
      if (await academyMenu.count() > 0) {
        await academyMenu.first().click();
        await page.waitForLoadState('networkidle');

        // Look for Starter Templates submenu
        const starterMenu = page.locator('#adminmenu a:has-text("Starter"), #adminmenu a:has-text("Demo")');
        if (await starterMenu.count() > 0) {
          await starterMenu.first().click();
          await page.waitForLoadState('networkidle');
          found = true;
        }
      }
    }

    // Take screenshot
    await page.screenshot({ path: 'academy-starter-page.png', fullPage: true });

    // Print page content for debugging
    const pageContent = await page.content();
    console.log('Page title:', await page.title());

    // Look for any buttons or links on the page
    const allButtons = page.locator('button, .button, a.btn, input[type="submit"]');
    const buttonCount = await allButtons.count();
    console.log(`Found ${buttonCount} buttons on page`);

    for (let i = 0; i < Math.min(buttonCount, 10); i++) {
      const text = await allButtons.nth(i).textContent().catch(() => '');
      if (text.trim()) {
        console.log(`  Button ${i}: ${text.trim().substring(0, 50)}`);
      }
    }

    // Look for demo templates to import - expanded selectors
    const demoItems = page.locator('.starter-template, .demo-item, [data-demo], .theme-browser .theme, .starter-site, .starter-templates-list .starter-template-item, .starter-templates__item, .starter-template-card');

    if (await demoItems.count() > 0) {
      console.log(`Found ${await demoItems.count()} demo templates`);

      // Click on first demo item
      await demoItems.first().click();
      await page.waitForTimeout(2000);

      // Look for import button
      const importButton = page.locator('button:has-text("Import"), a:has-text("Import Demo"), .import-btn, .start-import');

      if (await importButton.count() > 0) {
        console.log('Clicking import button...');
        await importButton.first().click();

        // Wait for import to complete (may take several minutes)
        try {
          await page.waitForSelector('.import-complete, .success, .notice-success, .import-done', { timeout: 180000 });
          console.log('Demo import completed!');
        } catch (e) {
          console.log('Import may still be in progress');
          await page.waitForTimeout(60000); // Wait another minute
        }
      }
    } else {
      console.log('No demo templates found');
      console.log('Page URL:', page.url());
    }

    // Verify import results
    await page.goto('/wp-admin/edit.php?post_type=academy_courses');
    await page.waitForLoadState('networkidle');

    // Count courses
    const courseRows = page.locator('table.wp-list-table tbody tr');
    const count = await courseRows.count();
    console.log(`Total Academy courses after import: ${count}`);
  });
});
