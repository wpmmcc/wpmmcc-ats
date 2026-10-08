import { test, expect } from '@playwright/test'
import { wpCli } from './wp-cli'

/**
 * WP-CLI — verify key plugin CLI commands work (P6-1, P6-2, P6-3).
 * Runs via Lab docker exec (or host wp), not in browser.
 *
 * catalog: WP-CLASS-Tasks_Command
 * oracle: L1
 */

test.describe('08 WP-CLI Commands', () => {
  test('wp wptsall audit list shows log entries', () => {
    const out = wpCli('wptsall audit list', { head: 10 })
    expect(out.length).toBeGreaterThan(0)
  })

  test('wp wptsall translate progress runs', () => {
    const out = wpCli('wptsall translate progress', { head: 5 })
    expect(out.length).toBeGreaterThan(0)
  })

  test('wp wptsall translate pending runs', () => {
    const out = wpCli('wptsall translate pending', { head: 5 })
    expect(out.length).toBeGreaterThan(0)
  })

  test('wp wptsall tm counts works', () => {
    const out = wpCli('wptsall tm counts', { head: 5 })
    expect(out.length).toBeGreaterThan(0)
  })

  test('wp wptsall strings counts works', () => {
    const out = wpCli('wptsall strings counts', { head: 5 })
    expect(out.length).toBeGreaterThan(0)
  })

  test('wp wptsall tasks status works', () => {
    const out = wpCli('wptsall tasks status', { head: 5 })
    expect(out.length).toBeGreaterThan(0)
  })

  test('wp wptsall help is available', () => {
    const out = wpCli('help wptsall', { head: 20 })
    expect(out).toMatch(/wptsall/i)
  })
})
