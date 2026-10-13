import { test, expect } from '@playwright/test'
import * as fs from 'fs'
import * as path from 'path'
import {
  assertPageComplete,
  wpLogin,
  WP_MEMBER_USER,
  WP_MEMBER_PASS,
  type JourneyPayload,
} from './helpers'

const runtimeDir = process.env.E2E_RUNTIME_DIR
  ? path.resolve(process.env.E2E_RUNTIME_DIR)
  : path.resolve(__dirname, '../../runtime')
const targetsFile = path.join(runtimeDir, 'plugin-journey-targets.json')
const reportFile = path.join(runtimeDir, 'plugin-journey-report.json')

function loadPayload(): JourneyPayload {
  expect(fs.existsSync(targetsFile), `missing ${targetsFile} — run Stage 8 prep first`).toBeTruthy()
  return JSON.parse(fs.readFileSync(targetsFile, 'utf-8')) as JourneyPayload
}

test.describe.configure({ mode: 'serial' })

test.describe('Content plugin user journeys (post-translation)', () => {
  const results: Array<{ id: string; role: string; topology: string; url: string; pass: boolean; note: string }> = []

  test.afterAll(async () => {
    fs.mkdirSync(runtimeDir, { recursive: true })
    fs.writeFileSync(
      reportFile,
      JSON.stringify({ timestamp: new Date().toISOString(), results }, null, 2),
    )
    const failed = results.filter((r) => !r.pass)
    if (failed.length) {
      throw new Error(
        `plugin journeys failed: ${failed.map((f) => `${f.id} (${f.note})`).join('; ')}`,
      )
    }
  })

  test('all journey targets render completely', async ({ page, context }) => {
    const payload = loadPayload()
    expect(payload.journeys.length, 'no journeys generated').toBeGreaterThan(0)

    for (const journey of payload.journeys) {
      try {
        if (journey.role === 'member') {
          await context.clearCookies()
          await wpLogin(page, WP_MEMBER_USER, WP_MEMBER_PASS)
        } else if (journey.role === 'admin') {
          await context.clearCookies()
          await wpLogin(page)
        } else {
          await context.clearCookies()
        }

        await assertPageComplete(page, journey)
        results.push({
          id: journey.id,
          role: journey.role,
          topology: journey.topology,
          url: journey.url,
          pass: true,
          note: 'ok',
        })
      } catch (err) {
        const note = err instanceof Error ? err.message : String(err)
        const softLab =
          (process.env.FULL_CHAIN_JOURNEY_SOFT_404 === '1' ||
            process.env.E2E_JOURNEY_SOFT_404 === '1') &&
          (/HTTP 404|404-like content/i.test(note) ||
            /body too short/i.test(note) ||
            (journey.role === 'admin' && /HTTP 5\d\d/i.test(note)))
        if (journey.optional || softLab) {
          results.push({
            id: journey.id,
            role: journey.role,
            topology: journey.topology,
            url: journey.url,
            pass: true,
            note: softLab ? `soft-lab skip: ${note}` : `optional skip: ${note}`,
          })
        } else {
          results.push({
            id: journey.id,
            role: journey.role,
            topology: journey.topology,
            url: journey.url,
            pass: false,
            note,
          })
          throw err
        }
      }
    }
  })
})
