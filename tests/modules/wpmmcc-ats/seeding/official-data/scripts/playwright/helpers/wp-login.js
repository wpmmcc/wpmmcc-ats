/**
 * WordPress Login Helper
 */

const WP_ADMIN_USER = process.env.WP_USER || 'admin';
const WP_ADMIN_PASS = process.env.WP_PASS || 'password@wptsall';

/**
 * Login to WordPress admin
 * @param {import('@playwright/test').Page} page
 */
async function wpLogin(page) {
  console.log(`Logging in as ${WP_ADMIN_USER}...`);

  await page.goto('/wp-login.php');
  await page.waitForLoadState('networkidle');

  // Fill login form
  await page.locator('#user_login').fill(WP_ADMIN_USER);
  await page.locator('#user_pass').fill(WP_ADMIN_PASS);

  // Click login button and wait for navigation
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'networkidle', timeout: 60000 }),
    page.locator('#wp-submit').click(),
  ]);

  // Check current URL
  const currentUrl = page.url();
  console.log(`After login URL: ${currentUrl}`);

  // Check if login failed (still on login page with error)
  if (currentUrl.includes('wp-login.php')) {
    const errorMessage = await page.locator('#login_error').textContent().catch(() => null);
    if (errorMessage) {
      throw new Error(`Login failed: ${errorMessage}`);
    }
  }

  // If not on wp-admin, navigate there
  if (!currentUrl.includes('wp-admin')) {
    console.log('Navigating to wp-admin...');
    await page.goto('/wp-admin/');
    await page.waitForLoadState('networkidle');

    const adminUrl = page.url();
    console.log(`Admin URL: ${adminUrl}`);

    // If redirected to login again, login failed
    if (adminUrl.includes('wp-login.php')) {
      throw new Error('Login failed - redirected back to login page');
    }
  }

  console.log('Login successful');
}

/**
 * Check if logged in, if not, login
 * @param {import('@playwright/test').Page} page
 */
async function ensureLoggedIn(page) {
  // Go to admin and check if redirected to login
  await page.goto('/wp-admin/');
  await page.waitForLoadState('networkidle');

  const currentUrl = page.url();
  console.log(`ensureLoggedIn - Current URL: ${currentUrl}`);

  if (currentUrl.includes('wp-login.php')) {
    await wpLogin(page);
  } else if (currentUrl.includes('wp-admin')) {
    console.log('Already logged in');
  }
}

/**
 * Navigate to admin menu item
 * @param {import('@playwright/test').Page} page
 * @param {string} menuText - Text of the menu item
 * @param {string} [submenuText] - Text of the submenu item (optional)
 */
async function navigateToMenu(page, menuText, submenuText = null) {
  // Click main menu
  const menuItem = page.locator(`#adminmenu a:has-text("${menuText}")`).first();
  await menuItem.click();

  if (submenuText) {
    // Wait for submenu to be visible and click
    const submenuItem = page.locator(`#adminmenu a:has-text("${submenuText}")`).first();
    await submenuItem.click();
  }

  // Wait for page load
  await page.waitForLoadState('networkidle');
}

module.exports = {
  wpLogin,
  ensureLoggedIn,
  navigateToMenu,
  WP_ADMIN_USER,
  WP_ADMIN_PASS,
};
