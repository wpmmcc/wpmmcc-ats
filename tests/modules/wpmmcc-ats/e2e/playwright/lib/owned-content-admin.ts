import assert from 'node:assert/strict'
import {
  assertOwnedManualLane, writeOwnedManualEvidence, type ManualFields,
} from './owned-manual-editor.ts'

export function assertOwnedContentLane(env: NodeJS.ProcessEnv) {
  for (const key of ['WP_DOCKER_CONTAINER', 'CONTENT_MATRIX_SAVE', 'CONTENT_MATRIX_LIMIT']) {
    assert(!env[key], 'content admin must not inherit shared matrix overrides')
  }
  return assertOwnedManualLane(env, 'content-admin')
}

function record(value: unknown): Record<string, unknown> {
  assert(value !== null && typeof value === 'object' && !Array.isArray(value), 'owned content evidence object required')
  return value as Record<string, unknown>
}

export type ContentProduct = {
  id: number; fields: ManualFields; sku: string; price: string; stock: number
  fieldName: string; fieldKey: string; acfValue: string
}

export type ContentFieldGroup = {
  id: number; key: string; title: string; fieldId: number; fieldKey: string; fieldName: string; label: string
}

function positiveId(id: unknown): void {
  assert(typeof id === 'number' && Number.isSafeInteger(id) && id > 0, 'owned content positive identity required')
}

export function assertContentProduct(value: unknown, expected: ContentProduct): void {
  positiveId(expected.id)
  const data = record(value)
  assert.equal(data.id, expected.id, 'owned product identity must match')
  assert.equal(data.post_type, 'product', 'owned product must retain its type')
  assert.equal(data.post_status, 'publish', 'owned product must be published')
  for (const field of ['post_title', 'post_content', 'post_excerpt'] as const) {
    assert(expected.fields[field].trim().length > 0, 'owned product three-field oracle must be nonempty')
    assert.equal(data[field], expected.fields[field], `owned product ${field} must match`)
  }
  assert(expected.sku.length > 0 && expected.acfValue.length > 0, 'owned SKU and ACF oracle must be nonempty')
  assert(/^\d+\.\d{2}$/.test(expected.price) && Number(expected.price) > 0, 'owned price oracle must be positive')
  assert(Number.isSafeInteger(expected.stock) && expected.stock > 0, 'owned stock oracle must be positive')
  assert.equal(data.sku, expected.sku, 'native Woo SKU must persist')
  assert.equal(data.price, expected.price, 'native Woo price must persist')
  assert.equal(data.stock, expected.stock, 'native Woo stock must persist')
  const meta = record(data.meta)
  assert.equal(meta._sku, expected.sku, 'WP SKU meta must independently match')
  assert.equal(meta._regular_price, expected.price, 'WP price meta must independently match')
  assert.equal(meta._stock, String(expected.stock), 'WP stock meta must independently match')
  assert.equal(meta[expected.fieldName], expected.acfValue, 'WP ACF meta must persist')
  assert.equal(meta[`_${expected.fieldName}`], expected.fieldKey, 'WP ACF reference marker must persist')
  assert.equal(data.acf_value, expected.acfValue, 'native ACF value must independently match')
}

export function assertContentFieldGroup(value: unknown, expected: ContentFieldGroup): void {
  positiveId(expected.id)
  positiveId(expected.fieldId)
  assert(expected.title.length > 0 && expected.label.length > 0 && expected.fieldName.length > 0,
    'owned ACF definition oracle must be nonempty')
  const data = record(value)
  assert.equal(data.ID, expected.id, 'owned ACF group identity must match')
  assert.equal(data.key, expected.key, 'owned ACF group key must match')
  assert.equal(data.title, expected.title, 'owned ACF group title must persist')
  assert.equal(data.active, true, 'owned ACF group must remain active')
  assert.deepEqual(data.location, [[{ param: 'post_type', operator: '==', value: 'product' }]],
    'owned ACF group must target products only')
  assert(Array.isArray(data.fields) && data.fields.length === 1, 'owned ACF field inventory must be singleton')
  const field = record(data.fields[0])
  assert.equal(field.ID, expected.fieldId, 'owned ACF field identity must match')
  assert.equal(field.key, expected.fieldKey, 'owned ACF field key must persist')
  assert.equal(field.name, expected.fieldName, 'owned ACF field name must persist')
  assert.equal(field.label, expected.label, 'owned ACF field label must persist')
  assert.equal(field.type, 'text', 'owned ACF field type must remain text')
}

export function assertContentIsolation(value: unknown): void {
  const data = record(value)
  assert.equal(data.cron_disabled, true)
  assert.equal(data.external_blocked, true)
  assert.equal(data.http_guard_installed, true)
  assert.equal(data.forbidden_http_attempts, 0)
  assert(Array.isArray(data.active_plugins), 'owned content plugin inventory must be complete')
  assert.deepEqual([...data.active_plugins as string[]].sort(), [
    'advanced-custom-fields/acf.php', 'woocommerce/woocommerce.php', 'wpmmcc-ats/wpmmcc-ats.php',
  ])
}

export function writeOwnedContentEvidence(env: NodeJS.ProcessEnv, value: unknown): string {
  assertOwnedContentLane(env)
  return writeOwnedManualEvidence(env, value, 'content-admin')
}
