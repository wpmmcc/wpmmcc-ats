import { execSync } from 'child_process'

/**
 * Lab-aware WP-CLI helper for comprehensive Playwright specs.
 *
 * Prefer docker exec into the Lab wordpress-test container; fall back to a
 * host `wp` binary when present (historical blog.wpmm.cc runners).
 */

const CONTAINER =
  process.env.LAB_WP_CONTAINER ||
  process.env.WPTSALL_LAB_WP_CONTAINER ||
  'wptsall-wp-lab-wordpress-test-1'

const WP_PATH = process.env.WP_PATH || '/var/www/html'

function hasDockerWp(): boolean {
  try {
    execSync(`docker inspect -f '{{.State.Running}}' ${CONTAINER}`, {
      encoding: 'utf-8',
      stdio: ['ignore', 'pipe', 'pipe'],
    })
    return true
  } catch {
    return false
  }
}

export function wpCli(args: string, opts?: { head?: number }): string {
  const head = opts?.head
  const pipe = head != null ? ` | head -${head}` : ''
  const hostCmd = `wp --allow-root --path=${process.env.WP_HOST_PATH || '/var/www/wordpress'} ${args} 2>&1${pipe}`
  const dockerCmd = `docker exec -e PAGER=cat ${CONTAINER} wp --allow-root --path=${WP_PATH} ${args} 2>&1${pipe}`
  const cmd = hasDockerWp() ? dockerCmd : hostCmd
  try {
    return execSync(cmd, { encoding: 'utf-8' })
  } catch (e: any) {
    return String((e.stdout ?? '') + (e.stderr ?? ''))
  }
}
