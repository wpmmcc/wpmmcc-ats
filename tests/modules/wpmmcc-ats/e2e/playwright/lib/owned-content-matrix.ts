import assert from 'node:assert/strict'
import { writeFileSync } from 'node:fs'
import path from 'node:path'
import { assertOwnedContentLane, assertContentIsolation } from './owned-content-admin.ts'
import {
  assertManualEditorReadback, assertManualTargetRecord, type ManualExpected,
} from './owned-manual-editor.ts'

export const CONTENT_MATRIX_TYPES = ['post', 'page', 'product'] as const
export type ContentMatrixType = typeof CONTENT_MATRIX_TYPES[number]
export type ContentMatrixExpected = ManualExpected & { postType: ContentMatrixType }

function record(value: unknown): Record<string, unknown> {
  assert(value !== null && typeof value === 'object' && !Array.isArray(value), 'owned CPT evidence object required')
  return value as Record<string, unknown>
}

function assertType(value: unknown): void {
  assert(CONTENT_MATRIX_TYPES.some((type) => type === value), 'owned CPT must be in the explicit supported subset')
}

export function assertOwnedContentMatrixLane(env: NodeJS.ProcessEnv) {
  assert.equal(env.WPTSALL_CONTENT_MATRIX_OWNED, '1', 'CPT matrix requires its explicit owned selector')
  assert(!env.CONTENT_MATRIX_NO_WPCLI, 'CPT matrix must not inherit legacy fallback controls')
  return assertOwnedContentLane(env)
}

/** Registered core types plus a real, pinned Woo CPT, not a fabricated plugin. */
export function assertContentMatrixInventory(value: unknown): void {
  const data = record(value)
  assert.deepEqual(data.post_types, [...CONTENT_MATRIX_TYPES], 'owned CPT inventory must be complete and ordered')
  assert.equal(data.native_woo_available, true, 'owned product requires the actual native Woo API')
}

export function assertContentMatrixReadback(value: unknown, expected: ContentMatrixExpected): void {
  assertType(expected.postType)
  assertManualEditorReadback(value, expected)
  const data = record(value)
  assert.equal(record(data.source).post_type, expected.postType, 'owned CPT source type must match')
  assert.equal(record(data.target).post_type, expected.postType, 'owned CPT persisted target type must match')
}

export function assertContentMatrixRecord(value: unknown, expected: ContentMatrixExpected): void {
  assertType(expected.postType)
  assertManualTargetRecord(value, expected)
  const data = record(value)
  assert.equal(data.source_post_type, expected.postType, 'independent WP source type must match')
  assert.equal(data.target_post_type, expected.postType, 'independent WP target type must match')
  assert.equal(data.source_status, 'publish', 'owned CPT source must remain published')
  assert.equal(data.target_status, 'publish', 'owned CPT target must remain published')
}

/** SQL failure/null/fraction must not be represented as a healthy zero write. */
export function assertContentMatrixCounts(value: unknown, expected: number): void {
  const data = record(value)
  assert.deepEqual(Object.keys(data).sort(), [...CONTENT_MATRIX_TYPES].sort(), 'owned CPT counts must cover exactly the subset')
  assert(Number.isSafeInteger(expected) && expected >= 0, 'owned CPT expected count must be a nonnegative integer')
  for (const type of CONTENT_MATRIX_TYPES) {
    assert(typeof data[type] === 'number' && Number.isSafeInteger(data[type]) && Number(data[type]) >= 0,
      'owned CPT counts require successful nonnegative integer reads')
    assert.equal(data[type], expected, 'owned CPT independent count must match')
  }
}

/** Refuse incomplete/duplicate cases, response-only evidence, or clobbering. */
export function writeOwnedContentMatrixEvidence(env: NodeJS.ProcessEnv, value: unknown): string {
  assertOwnedContentMatrixLane(env)
  const data = record(value)
  assertContentMatrixInventory(data.inventory)
  assert(Array.isArray(data.cases) && data.cases.length === CONTENT_MATRIX_TYPES.length,
    'owned CPT output must contain every mandatory case')
  const types = data.cases.map((item) => record(record(item).expected).postType)
  assert.deepEqual(types, [...CONTENT_MATRIX_TYPES], 'owned CPT output must not omit or duplicate a type')
  for (const item of data.cases) {
    const entry = record(item)
    const expected = record(entry.expected) as ContentMatrixExpected
    assertContentMatrixReadback(entry.readback, expected)
    assertContentMatrixRecord(entry.persisted, expected)
    assert.equal(entry.post_status, 200, 'owned CPT POST must succeed')
    assert.equal(entry.put_status, 200, 'owned CPT PUT must succeed')
    assert.equal(entry.wrong_target_status, 403, 'owned CPT wrong target must be rejected')
    assert.equal(entry.reloads, 2, 'owned CPT output requires both POST and PUT reloads')
  }
  assertContentMatrixCounts(data.siteCounts, 2)
  assertContentMatrixCounts(data.otherSiteCounts, 0)
  assert.deepEqual(data.nativeAutoDrafts, { post: 1, page: 0, product: 0 }, 'only the native admin auto-draft is allowed')
  assert.deepEqual(data.finalAutoDrafts, data.nativeAutoDrafts, 'native auto-drafts must not grow during CPT saving')
  assertContentMatrixCounts(data.otherSiteAutoDrafts, 0)
  assert.deepEqual(data.browser_forbidden_requests, [], 'owned CPT browser must not use outside infrastructure')
  assert(Array.isArray(data.isolation) && data.isolation.length === 2, 'owned CPT output needs both site isolation checks')
  for (const evidence of data.isolation) assertContentIsolation(evidence)
  const output = path.join(env.WPTSALL_MANUAL_ARTIFACTS_DIR!, 'owned-content-matrix-output.evidence')
  writeFileSync(output, JSON.stringify(value, null, 2) + '\n', { flag: 'wx', mode: 0o600 })
  return output
}
