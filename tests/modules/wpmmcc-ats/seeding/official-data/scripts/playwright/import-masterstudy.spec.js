// @ts-check
const { test, expect } = require('@playwright/test');
const { ensureLoggedIn } = require('./helpers/wp-login');

test.describe('MasterStudy LMS Import', () => {
  test.beforeEach(async ({ page }) => {
    await ensureLoggedIn(page);
  });

  test('import demo data via MasterStudy', async ({ page }) => {
    console.log('Starting MasterStudy LMS demo import...');

    // Get current course count
    await page.goto('/wp-admin/edit.php?post_type=stm-courses');
    await page.waitForLoadState('networkidle');

    const coursesBefore = await page.locator('table.wp-list-table tbody tr').count();
    console.log(`Courses before: ${coursesBefore}`);

    // Navigate to MasterStudy settings
    await page.goto('/wp-admin/admin.php?page=stm-lms-settings');
    await page.waitForLoadState('networkidle');

    // Take screenshot
    await page.screenshot({ path: 'masterstudy-settings.png', fullPage: true });

    // Look for "Install" button for MasterStudy Templates
    const installTemplatesBtn = page.locator('a:has-text("Install"):near(:has-text("MasterStudy Templates")), button:has-text("Install"):near(:has-text("Templates"))');

    if (await installTemplatesBtn.count() > 0) {
      console.log('Found MasterStudy Templates Install button');
      await installTemplatesBtn.first().click();
      await page.waitForLoadState('networkidle', { timeout: 60000 });

      // After installation, might need to navigate to the templates page
      await page.waitForTimeout(3000);
    }

    // Try IMPORT/EXPORT section
    const importExportMenu = page.locator('a:has-text("IMPORT/EXPORT"), button:has-text("IMPORT/EXPORT"), .stm-lms-settings-menu a:has-text("Import")');

    if (await importExportMenu.count() > 0) {
      console.log('Found IMPORT/EXPORT menu');
      await importExportMenu.first().click();
      await page.waitForLoadState('networkidle');

      await page.screenshot({ path: 'masterstudy-import-export.png', fullPage: true });

      // Look for import button or file input
      const importBtn = page.locator('button:has-text("Import"), input[type="file"], a:has-text("Import Demo")');
      if (await importBtn.count() > 0) {
        console.log('Found import option');
      }
    }

    // Try looking for Starter Templates or Demo Import page
    const starterPaths = [
      '/wp-admin/admin.php?page=starter-templates',
      '/wp-admin/admin.php?page=ms-lms-starter-templates',
      '/wp-admin/admin.php?page=stm-admin-demo-import',
    ];

    for (const path of starterPaths) {
      await page.goto(path);
      await page.waitForLoadState('networkidle');

      if (!page.url().includes('error') && !page.url().includes('wp-login')) {
        console.log(`Found starter templates at: ${path}`);
        await page.screenshot({ path: 'masterstudy-starter.png', fullPage: true });

        // Look for demo items
        const demoItems = page.locator('.starter-template, .demo-item, .theme-browser .theme, [data-demo]');
        if (await demoItems.count() > 0) {
          console.log(`Found ${await demoItems.count()} demo items`);

          // Click first demo
          await demoItems.first().click();
          await page.waitForTimeout(2000);

          // Look for import button
          const importButton = page.locator('button:has-text("Import"), a:has-text("Import")');
          if (await importButton.count() > 0) {
            await importButton.first().click();
            await page.waitForLoadState('networkidle', { timeout: 180000 });
          }
        }
        break;
      }
    }

    // Check LMS Starter Templates plugin
    await page.goto('/wp-admin/plugins.php');
    await page.waitForLoadState('networkidle');

    const starterPlugin = page.locator('tr[data-slug*="starter"], tr:has-text("MasterStudy Starter")');
    if (await starterPlugin.count() > 0) {
      console.log('Found MasterStudy Starter plugin');
    }

    // Verify results
    await page.goto('/wp-admin/edit.php?post_type=stm-courses');
    await page.waitForLoadState('networkidle');

    const coursesAfter = await page.locator('table.wp-list-table tbody tr').count();
    console.log(`Courses after: ${coursesAfter}`);
    console.log(`New courses: ${coursesAfter - coursesBefore}`);

    if (coursesAfter > coursesBefore) {
      console.log('SUCCESS: Demo data imported!');
    } else {
      console.log('No new courses imported - may need manual demo import');
    }
  });
});
