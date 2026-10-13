import assert from 'node:assert/strict'
import { lstatSync, readFileSync, realpathSync, writeFileSync } from 'node:fs'
import path from 'node:path'
import type { OwnedSite } from '../owned-wp/helpers'

export type ManualLane = { owner: string; plugin: 'wpmmcc-ats'; sites: OwnedSite[]; content_core_image?: string }

function record(value: unknown): Record<string, unknown> {
  assert(value !== null && typeof value === 'object' && !Array.isArray(value), 'manual evidence must be an object')
  return value as Record<string, unknown>
}

function positiveId(value: unknown): number {
  assert(typeof value === 'number' || (typeof value === 'string' && /^[1-9]\d*$/.test(value)),
    'manual identity must be a positive integer')
  const id = Number(value)
  assert(Number.isSafeInteger(id) && id > 0, 'manual identity must be a positive integer')
  return id
}

/** No shared defaults, env file or HTTP before this boundary passes. */
export function assertOwnedManualLane(env: NodeJS.ProcessEnv, profile: 'editor' | 'content-admin' = 'editor'): ManualLane {
  assert(['editor', 'content-admin'].includes(profile), 'known owned manual profile required')
  assert.equal(env.WPTSALL_MANUAL_EDITOR_OWNED, '1', 'manual editor requires the owned WP wrapper')
  const file = env.WPTSALL_OWNED_WP_CONTEXT
  assert(file && path.isAbsolute(file) && path.basename(file) === 'context.json', 'owned manual context required')
  const root = path.dirname(file)
  assert(path.basename(root).startsWith('wptsall-owned-wp-output.'), 'manual context requires the owned WP root')
  assert.equal(realpathSync(root), root, 'manual root must not be a symlink')
  const directory = lstatSync(root)
  assert(directory.isDirectory() && directory.uid === process.getuid?.() && (directory.mode & 0o077) === 0,
    'manual root must be private and user-owned')
  const info = lstatSync(file)
  assert(info.isFile() && info.nlink === 1 && info.uid === process.getuid?.() && (info.mode & 0o777) === 0o600,
    'manual context must be a private single-link regular file')
  const data = record(JSON.parse(readFileSync(file, 'utf8')))
  assert(typeof data.owner === 'string' && /^[a-f0-9]{16}$/.test(data.owner), 'manual owner required')
  assert.equal(data.plugin, 'wpmmcc-ats', 'manual editor must deploy ATS, not the peer plugin')
  if (profile === 'editor') {
    assert.equal(data.content_profile, undefined, 'manual editor must not deploy content plugins')
    assert.equal(data.content_packages, undefined, 'manual editor must not inherit a content package profile')
    assert.equal(data.content_core_image, undefined, 'manual editor must not inherit a content core profile')
  } else {
    assert.equal(env.WPTSALL_CONTENT_ADMIN_OWNED, '1', 'content admin requires its owned wrapper selector')
    assert.equal(data.content_profile, 'woocommerce-acf', 'owned content profile must match')
    assert.equal(data.content_core_image,
      'sha256:29a3af5db27d8c1716367575280bbf449cd231ced707daf8ce411895c042ed76', 'owned compatible core pin must match')
    assert.deepEqual(data.content_packages, {
      'advanced-custom-fields': '307de4ac8842ea96d1b7999618b003acfbe8f602669adf2b0df0d5f9897c46b7',
      woocommerce: 'da189b6616c610d15a2106f93151dab81b78f83e075bcefce221ac0d00b4fa21',
    }, 'owned content package pins must match')
  }
  assert(Array.isArray(data.sites) && data.sites.length === 2, 'manual editor requires two distinct owned sites')
  const names = new Set<string>()
  for (const value of data.sites) {
    const site = record(value)
    assert(typeof site.name === 'string' && new RegExp(`^wptsall-owned-${data.owner}-[ab]$`).test(site.name),
      'manual container must belong to this run')
    assert(!names.has(site.name), 'manual containers must be distinct')
    names.add(site.name)
    assert.equal(site.prefix, `owned${data.owner}${site.name.slice(-1)}_`, 'manual SQL prefix must match')
    assert.equal(site.db_created, true, 'manual SQL claim must be durable')
    assert.notEqual(site.tables_removed, true, 'manual SQL must not have been cleaned already')
    assert.equal(site.home_url, `http://${site.name}.test`, 'manual home must be the owned Docker origin')
    const match = /^http:\/\/127\.0\.0\.1:([1-9]\d*)$/.exec(String(site.base_url))
    assert(match && Number(match[1]) >= 1024 && Number(match[1]) <= 65535
      && ![8787, 8977, 9081, 9082, 9083, 9090, 9091].includes(Number(match[1]))
      && !(Number(match[1]) >= 9181 && Number(match[1]) <= 9196), 'manual base must not reuse shared ports')
    assert(typeof site.admin_user === 'string' && site.admin_user.length > 0
      && typeof site.admin_pass === 'string' && site.admin_pass.length > 0, 'owned manual login required')
  }
  for (const key of ['WP_BASE', 'WP_URL', 'WP_ADMIN_USER', 'WP_ADMIN_PASS']) {
    assert(!env[key], 'manual editor must not inherit shared WP overrides')
  }
  const artifacts = path.join(root, 'artifacts')
  assert.equal(env.WPTSALL_MANUAL_ARTIFACTS_DIR, artifacts, 'manual output must stay in owned artifacts')
  assert.equal(realpathSync(artifacts), artifacts, 'manual artifacts must not be a symlink')
  assert(lstatSync(artifacts).isDirectory(), 'manual artifacts directory required')
  return data as ManualLane
}

/** Bind the context to a live owned container before browser or WP-CLI writes. */
export function assertOwnedManualContainer(value: unknown, lane: ManualLane, site: OwnedSite): void {
  const info = record(value)
  if (lane.content_core_image) assert.equal(info.Image, lane.content_core_image, 'owned content container core pin must match')
  assert.equal(info.Name, `/${site.name}`, 'manual container name must match')
  assert(typeof info.Id === 'string' && /^[a-f0-9]{64}$/.test(info.Id), 'manual immutable container identity required')
  const config = record(info.Config)
  assert.equal(record(config.Labels)['com.wptsall.owned.run'], lane.owner, 'manual container ownership must match')
  assert(Array.isArray(config.Env) && config.Env.includes(`WORDPRESS_TABLE_PREFIX=${site.prefix}`),
    'manual container SQL prefix must match')
  const settings = record(info.NetworkSettings)
  const bindings = record(settings.Ports)['80/tcp']
  assert(Array.isArray(bindings) && bindings.length === 1, 'manual container needs one loopback binding')
  const binding = record(bindings[0])
  assert.equal(binding.HostIp, '127.0.0.1', 'manual WP must not be externally exposed')
  assert.equal(site.base_url, `http://127.0.0.1:${binding.HostPort}`, 'manual live port must match context')
}

export type ManualFields = { post_title: string; post_content: string; post_excerpt: string }
export type ManualExpected = {
  sourceId: number; targetId: number; relationId: number; virtualSiteId: number
  sourceLang: string; targetLang: string; source: ManualFields; target: ManualFields
}

function assertFields(value: unknown, expected: ManualFields): void {
  const data = record(value)
  for (const field of ['post_title', 'post_content', 'post_excerpt'] as const) {
    assert(expected[field].trim().length > 0, `manual ${field} oracle must be nonempty`)
    assert.equal(data[field], expected[field], `manual ${field} must match exactly`)
  }
}

function assertExpected(expected: ManualExpected): void {
  for (const id of [expected.sourceId, expected.targetId, expected.relationId, expected.virtualSiteId]) positiveId(id)
  assert.notEqual(expected.sourceId, expected.targetId, 'manual target must not be the source')
  assert(expected.sourceLang.length > 0 && expected.targetLang.length > 0
    && expected.sourceLang !== expected.targetLang, 'manual languages must be nonempty and distinct')
  for (const field of ['post_title', 'post_content', 'post_excerpt'] as const) {
    assert.notEqual(expected.source[field], expected.target[field], 'manual edited fields must differ from source')
  }
}

/** A separate GET, never a successful save response or in-memory form state. */
export function assertManualEditorReadback(value: unknown, expected: ManualExpected): void {
  assertExpected(expected)
  const data = record(value)
  const source = record(data.source)
  const target = record(data.target)
  const relation = record(data.relation)
  assert.equal(source.ID, expected.sourceId, 'manual source identity must match')
  assert.equal(target.ID, expected.targetId, 'manual persisted target identity must match')
  assert.equal(positiveId(relation.id), expected.relationId, 'manual relation must match')
  assert.equal(relation.target_site_id, `v_${expected.virtualSiteId}`, 'manual virtual site must match')
  assert.equal(relation.target_site_type, 'virtual', 'manual target must be virtual')
  assert.equal(relation.status, 'active', 'manual relation must be active')
  assert.equal(relation.source_lang, expected.sourceLang, 'manual source language must match')
  assert.equal(relation.target_lang, expected.targetLang, 'manual target language must match')
  assert.equal(target.post_status, 'publish', 'manual save must publish the source-status target')
  assertFields(source, expected.source)
  assertFields(target, expected.target)
}

/** Independent WP record + marker inventory, including the unchanged source. */
export function assertManualTargetRecord(value: unknown, expected: ManualExpected): void {
  assertExpected(expected)
  const data = record(value)
  assert.equal(data.source_id, expected.sourceId, 'manual source record must match')
  assert.equal(data.target_id, expected.targetId, 'manual target record must match')
  assert.deepEqual(data.target_ids, [expected.targetId], 'manual translation must have exactly one target')
  assert.equal(positiveId(data.source_marker), expected.sourceId, 'manual source marker must match')
  assert.equal(positiveId(data.relation_marker), expected.relationId, 'manual relation marker must match')
  assert.equal(data.virtual_site_marker, `v_${expected.virtualSiteId}`, 'manual virtual marker must match')
  assertFields(data.source, expected.source)
  assertFields(data.target, expected.target)
}

export function assertManualIsolation(value: unknown): void {
  const data = record(value)
  assert.equal(data.cron_disabled, true, 'manual fixture must disable cron')
  assert.equal(data.http_guard_installed, true, 'manual WP HTTP guard must be installed')
  assert.equal(data.external_blocked, true, 'manual fixture must block external WP HTTP')
  assert.deepEqual(data.active_plugins, ['wpmmcc-ats/wpmmcc-ats.php'], 'manual fixture must activate only ATS')
  assert.equal(data.forbidden_http_attempts, 0, 'manual WP must not attempt Client/provider/control-plane HTTP')
}

export function writeOwnedManualEvidence(env: NodeJS.ProcessEnv, evidence: unknown, profile: 'editor' | 'content-admin' = 'editor'): string {
  assertOwnedManualLane(env, profile)
  const filename = profile === 'editor' ? 'owned-manual-editor-output.evidence' : 'owned-content-admin-output.evidence'
  const file = path.join(env.WPTSALL_MANUAL_ARTIFACTS_DIR!, filename)
  writeFileSync(file, JSON.stringify(evidence, null, 2) + '\n', { flag: 'wx', mode: 0o600 })
  return file
}
