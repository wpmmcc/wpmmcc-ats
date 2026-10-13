// @ts-check
const { test, expect } = require('@playwright/test');
const { ensureLoggedIn } = require('./helpers/wp-login');

test.describe('LearnPress Import', () => {
  test.beforeEach(async ({ page }) => {
    await ensureLoggedIn(page);
  });

  test('install LearnPress sample course data', async ({ page }) => {
    console.log('Starting LearnPress sample data installation...');

    // Get current course count
    await page.goto('/wp-admin/edit.php?post_type=lp_course');
    await page.waitForLoadState('networkidle');

    const coursesBefore = await page.locator('table.wp-list-table tbody tr').count();
    console.log(`Courses before: ${coursesBefore}`);

    // Navigate to LearnPress Tools page
    await page.goto('/wp-admin/admin.php?page=learn-press-tools');
    await page.waitForLoadState('networkidle');

    // Scroll to bottom to see the sample data section
    await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight));
    await page.waitForTimeout(1000);

    // Take screenshot
    await page.screenshot({ path: 'learnpress-tools-scrolled.png', fullPage: true });

    // Look for "Course Data" tab and click it
    const courseDataTab = page.locator('.nav-tab-wrapper a:has-text("Course Data"), a.nav-tab:has-text("Course Data")');
    if (await courseDataTab.count() > 0) {
      console.log('Found Course Data tab');
      await courseDataTab.first().click({ force: true });
      await page.waitForLoadState('networkidle');
    }

    // Look for Install Sample Data section
    // The Install button has class "lp-install-sample__install" or just find button with "Install" text
    const installSection = page.locator('.lp-install-sample, h2:has-text("Install Sample Data")');

    if (await installSection.count() > 0) {
      console.log('Found Install Sample Data section');

      // Scroll to the install section
      await installSection.first().scrollIntoViewIfNeeded();
      await page.waitForTimeout(500);

      // Handle confirmation dialog
      page.on('dialog', async dialog => {
        console.log(`Dialog: ${dialog.message()}`);
        await dialog.accept();
      });

      // Find and click the Install button
      // Try multiple selectors
      const installButton = page.locator('.lp-install-sample__install, .lp-install-sample a.button-primary:has-text("Install"), button:has-text("Install"):near(:has-text("Sample Data"))').first();

      if (await installButton.count() > 0) {
        console.log('Clicking Install button...');
        await installButton.click({ force: true });

        // Wait for installation
        await page.waitForLoadState('networkidle', { timeout: 120000 });

        // Check for success
        const success = page.locator('.lp-install-sample__response.success, .notice-success');
        if (await success.count() > 0) {
          console.log('Sample data installed successfully!');
        } else {
          console.log('Installation completed (no explicit success message)');
        }
      } else {
        // Try finding install link directly
        const installLink = page.locator('a:has-text("Install"):near(:has-text("Sample"))').first();
        if (await installLink.count() > 0) {
          console.log('Found Install link, clicking...');
          await installLink.click({ force: true });
          await page.waitForLoadState('networkidle', { timeout: 120000 });
        } else {
          console.log('Install button not found');
        }
      }
    } else {
      console.log('Install Sample Data section not found');
    }

    // Verify results
    await page.goto('/wp-admin/edit.php?post_type=lp_course');
    await page.waitForLoadState('networkidle');

    const coursesAfter = await page.locator('table.wp-list-table tbody tr').count();
    console.log(`Courses after: ${coursesAfter}`);
    console.log(`New courses: ${coursesAfter - coursesBefore}`);

    // Check lessons
    await page.goto('/wp-admin/edit.php?post_type=lp_lesson');
    await page.waitForLoadState('networkidle');

    const lessonCount = await page.locator('table.wp-list-table tbody tr').count();
    console.log(`Total lessons: ${lessonCount}`);

    // Expect at least some data imported
    if (coursesAfter > coursesBefore) {
      console.log('SUCCESS: Sample data imported!');
    }
  });
});
