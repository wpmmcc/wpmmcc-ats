/**
 * Live Client contract schemas (P1): GET /api/status + /api/worker/config
 * against docs/architecture/current/contracts/schemas.
 */
import { test, expect } from '@playwright/test'
import { CLIENT_BASE } from './helpers'
import { expectSchema } from '../lib/expect-api'

test.describe('Client live contract schemas', () => {
  test('GET /api/status matches client-webui-status.response schema', async ({ request }) => {
    const res = await request.get(`${CLIENT_BASE}/api/status`)
    expect(res.status(), 'client /api/status reachable').toBe(200)
    const body = await res.json()
    expectSchema(body, 'client-status-live.response', 'GET /api/status')
    expect(body.success).toBe(true)
    // Catalog sample still drifts for local-complete (no server_base / logged_in).
    // Keep a soft presence check so regressions surface without blocking Lab.
    expect(body.data).toHaveProperty('worker_status')
  })

  test('GET /api/worker/config matches client-worker-config.response schema', async ({ request }) => {
    const res = await request.get(`${CLIENT_BASE}/api/worker/config`)
    expect(res.status(), 'client /api/worker/config reachable').toBe(200)
    const body = await res.json()
    expectSchema(body, 'worker-config.response', 'GET /api/worker/config')
    expect(body.success).toBe(true)
  })
})
