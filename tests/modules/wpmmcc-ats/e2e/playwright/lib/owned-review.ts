import assert from 'node:assert/strict'
import { lstatSync, realpathSync, writeFileSync } from 'node:fs'
import path from 'node:path'

const ownedPaths = {
  WPTSALL_DB_PATH: 'wptsall.db',
  WPTSALL_DATA_DIR: 'data',
  WPTSALL_LOG_FILE: 'client.log',
  WPTSALL_SESSION_TOKEN_FILE: 'session-token.enc',
  WPTSALL_COMPONENT_BINDINGS_FILE: 'component-bindings.json',
  WPTSALL_DOMAIN_TOKEN_BINDINGS_FILE: 'domain-token-bindings.json',
  WPTSALL_TASK_TYPE_COMPONENT_BINDINGS_FILE: 'task-type-component-bindings.json',
  WPTSALL_RULE_COMPONENT_BINDINGS_FILE: 'rule-component-bindings.json',
  WPTSALL_COMPONENTS_LOCAL_FILE: 'components-local.json',
  WPTSALL_PROVIDER_CATALOG_FILE: 'provider-catalog.json',
  WPTSALL_SYNC_PAIRS_FILE: 'sync-pairs.json',
  WPTSALL_SYNC_STATE_FILE: 'sync-state.json',
  WPTSALL_SYNC_REVIEW_FILE: 'sync-review.json',
  WPTSALL_SYNC_PEER_CREDENTIALS_FILE: 'sync-peer-credentials.json',
} as const

/** Validate before any HTTP request, including teardown after a failed setup. */
export function assertOwnedReviewLane(env: NodeJS.ProcessEnv, clientBase: string): void {
  const base = env.WPTSALL_CLIENT_LOCAL_UI_BASE_URL
  const match = /^http:\/\/127\.0\.0\.1:(\d+)$/.exec(base ?? '')
  assert(match && Number(match[1]) > 0 && Number(match[1]) <= 65535
    && ![8977, 8787, 9090, 9091].includes(Number(match[1])), 'review requires an owned loopback client')
  assert.equal(match[1], String(Number(match[1])), 'review base port must use canonical decimal form')
  assert.equal(clientBase, base, 'review helpers must use the same owned client')
  assert.equal(env.WPTSALL_USE_SERVER_CONTROL_PLANE, '0', 'review requires local-first mode')
  assert.equal(env.WPTSALL_WP_TRANSPORT_ENCRYPT, 'off', 'review ATS double requires signed plaintext mode')
  const root = env.WPTSALL_LANE_STATE_ROOT
  assert(root && path.isAbsolute(root)
    && path.basename(root).startsWith('wptsall-client-local-ui-lane.'), 'review requires the owned runner state root')
  assert.equal(realpathSync(root), root, 'review state root must not be a symlink')
  const info = lstatSync(root)
  assert(info.isDirectory() && info.uid === process.getuid?.() && (info.mode & 0o077) === 0,
    'review state root must be private and owned by this user')
  const state = path.join(root, 'state')
  assert.equal(realpathSync(state), state, 'review state directory must not be a symlink')
  const artifacts = path.join(root, 'artifacts')
  assert.equal(env.WPTSALL_LANE_ARTIFACTS_DIR, artifacts, 'review evidence must stay inside the owned artifacts directory')
  assert.equal(realpathSync(artifacts), artifacts, 'review artifacts directory must not be a symlink')
  for (const [key, name] of Object.entries(ownedPaths)) {
    assert.equal(env[key], path.join(state, name), `${key} must be isolated by the owned runner`)
  }
}

export type ReviewFields = {
  post_title: string
  post_content: string
  post_excerpt: string
}

export type ReviewExpected = {
  itemId: number
  jobId: number
  relationId: number
  objectId: number
  raw: ReviewFields
  translated: ReviewFields
}

function record(value: unknown): Record<string, unknown> {
  assert(value !== null && typeof value === 'object' && !Array.isArray(value), 'review evidence must be an object')
  return value as Record<string, unknown>
}

function assertFields(value: unknown, expected: ReviewFields): void {
  const fields = record(value)
  for (const key of ['post_title', 'post_content', 'post_excerpt'] as const) {
    assert(expected[key].length > 0, `review ${key} oracle must be nonempty`)
    assert.equal(fields[key], expected[key], `review ${key} must match exactly`)
  }
}

function assertPayload(value: unknown, expected: ReviewExpected): Record<string, unknown> {
  const payload = record(value)
  assert.equal(payload.relation_id, expected.relationId, 'review relation must match')
  assert.equal(payload.object_id, expected.objectId, 'review source object must match')
  assert.equal(payload.source_revision, `rev-${expected.objectId}-1`, 'review source revision must survive edits')
  assert.equal(payload.policy_version, 'policy-v1', 'review policy must survive edits')
  assert.equal(payload.source_lang, 'en_US', 'review source language must survive edits')
  assert.equal(payload.target_lang, 'zh_CN', 'review target language must survive edits')
  assertFields(payload.translated_fields, expected.translated)
  return payload
}

/** Independent GET evidence, not a successful PUT response or local form values. */
export function assertReviewContent(value: unknown, expected: ReviewExpected): string {
  assert(Number.isSafeInteger(expected.itemId) && expected.itemId > 0, 'review item must be nonempty')
  assert(Number.isSafeInteger(expected.jobId) && expected.jobId > 0, 'review job must be nonempty')
  const data = record(value)
  const item = record(data.item)
  assert.equal(item.id, expected.itemId, 'review item must match')
  assert.equal(item.job_id, expected.jobId, 'review job must match')
  assert.equal(item.relation_id, expected.relationId, 'review item relation must match')
  assert.equal(item.wp_object_id, expected.objectId, 'review item source object must match')
  assert.equal(item.status, 'pending_review', 'review must hold the item before approval')
  assertFields(data.raw, expected.raw)
  const envelope = record(data.translated)
  const payload = assertPayload(envelope.payload, expected)
  assert(typeof envelope.idempotency_key === 'string' && envelope.idempotency_key.trim().length > 0,
    'review idempotency key must be nonempty')
  assert.equal(payload.client_task_id, envelope.idempotency_key, 'review payload and envelope identity must agree')
  return envelope.idempotency_key
}

export function assertReviewHeld(callbackCount: number): void {
  assert.equal(callbackCount, 0, 'review must not write back before approval')
}

export function assertReviewCallback(value: unknown, expected: ReviewExpected, key: string): void {
  assert(key.trim().length > 0, 'review approved identity must be nonempty')
  const callback = record(value)
  assert.equal(callback.idempotencyKey, key, 'review callback must use the saved identity')
  const payload = assertPayload(callback.payload, expected)
  assert.equal(payload.client_task_id, key, 'review callback task identity must match')
}

export function writeOwnedReviewEvidence(env: NodeJS.ProcessEnv, evidence: unknown): string {
  assertOwnedReviewLane(env, env.WPTSALL_CLIENT_LOCAL_UI_BASE_URL ?? '')
  const file = path.join(env.WPTSALL_LANE_ARTIFACTS_DIR!, 'owned-review-output.evidence')
  writeFileSync(file, JSON.stringify(evidence, null, 2) + '\n', { flag: 'wx', mode: 0o600 })
  return file
}
