/**
 * R2: owned ATS admin editor → nonempty save → independent GET/WP → reload.
 * No Client, worker or provider is started. Existing non-owned processes stay.
 * covers: success|failure|boundary
 */
import { test, expect, type Page } from '@playwright/test'
import { execFileSync } from 'node:child_process'
import {
  assertOwnedManualLane, assertOwnedManualContainer, assertManualEditorReadback,
  assertManualTargetRecord, assertManualIsolation, writeOwnedManualEvidence,
  type ManualFields, type ManualExpected,
} from '../lib/owned-manual-editor'
import { session, login, wp, type OwnedSite } from '../owned-wp/helpers'

const lane = assertOwnedManualLane(process.env)
const [site] = lane.sites
const tag = `manual-owned-${lane.owner}`

function verifyContainer(): void {
  const info = JSON.parse(execFileSync('docker', ['inspect', site.name],
    { encoding: 'utf8', stdio: ['pipe', 'pipe', 'pipe'], timeout: 15_000 }))[0]
  assertOwnedManualContainer(info, lane, site)
}

async function rest(page: Page, method: string, route: string, body?: unknown) {
  return page.evaluate(async ({ method, route, body }) => {
    const config = (window as unknown as {
      wpApiSettings?: { nonce?: string }; wptsallSiteRelations?: { nonce?: string; restNonce?: string }
      wptsallTranslationEditor?: { restNonce?: string }
    })
    const nonce = config.wpApiSettings?.nonce ?? config.wptsallSiteRelations?.restNonce
      ?? config.wptsallSiteRelations?.nonce ?? config.wptsallTranslationEditor?.restNonce
    if (!nonce) throw new Error('owned manual admin nonce missing')
    const response = await fetch(route, {
      method, credentials: 'same-origin',
      headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' },
      body: body === undefined ? undefined : JSON.stringify(body),
    })
    return { status: response.status, body: await response.json() }
  }, { method, route, body })
}

function isolation(target: OwnedSite) {
  return JSON.parse(wp(target, `
$attempts=get_option('wptsall_mi_http_attempts',array());
if(!is_array($attempts)){exit(1);}
echo wp_json_encode(array(
 'cron_disabled'=>defined('DISABLE_WP_CRON') && DISABLE_WP_CRON,
 'external_blocked'=>defined('WP_HTTP_BLOCK_EXTERNAL') && WP_HTTP_BLOCK_EXTERNAL,
 'http_guard_installed'=>function_exists('wptsall_mi_block_http'),
 'active_plugins'=>get_option('active_plugins'),
 'forbidden_http_attempts'=>count(array_filter($attempts,function($a){return ($a['severity']??'')==='forbidden_infra';}))
));`))
}

function readTarget(expected: ManualExpected) {
  return JSON.parse(wp(site, `
$source=get_post(${expected.sourceId});$target=get_post(${expected.targetId});
if(!$source || !$target){exit(1);}
$fields=function($post){return array('post_title'=>$post->post_title,'post_content'=>$post->post_content,'post_excerpt'=>$post->post_excerpt);};
global $wpdb;
$ids=$wpdb->get_col($wpdb->prepare("SELECT p.ID FROM {$wpdb->posts} p
 JOIN {$wpdb->postmeta} s ON s.post_id=p.ID AND s.meta_key='_wptsall_source_post_id'
 JOIN {$wpdb->postmeta} r ON r.post_id=p.ID AND r.meta_key='_wptsall_relation_id'
 WHERE s.meta_value=%s AND r.meta_value=%s AND p.post_type='post' AND p.post_status<>'trash' ORDER BY p.ID",
 '${expected.sourceId}','${expected.relationId}'));
if($wpdb->last_error || !is_array($ids)){exit(1);}
echo wp_json_encode(array('source_id'=>(int)$source->ID,'target_id'=>(int)$target->ID,
 'target_ids'=>array_map('intval',$ids),'source'=>$fields($source),'target'=>$fields($target),
 'source_marker'=>get_post_meta($target->ID,'_wptsall_source_post_id',true),
 'relation_marker'=>get_post_meta($target->ID,'_wptsall_relation_id',true),
 'virtual_site_marker'=>get_post_meta($target->ID,'_wptsall_virtual_site_id',true)));
`))
}

async function fillEditor(page: Page, fields: ManualFields): Promise<void> {
  await page.locator('#wptsall-target-post_title').fill(fields.post_title)
  for (const [id, field] of [['content', 'post_content'], ['excerpt', 'post_excerpt']] as const) {
    // Real HTML tabs and editable textareas, not hidden fallback/JS state.
    await page.locator(`#wptsall_tinymce_${id}-html`).click()
    await page.locator(`#wptsall_tinymce_${id}`).fill(fields[field])
    await expect(page.locator(`#wptsall_tinymce_${id}`)).toHaveValue(fields[field])
  }
}

async function assertReloadedEditor(page: Page, fields: ManualFields): Promise<void> {
  await expect(page.locator('#wptsall-target-post_title')).toHaveValue(fields.post_title)
  for (const [id, field] of [['content', 'post_content'], ['excerpt', 'post_excerpt']] as const) {
    await expect(page.locator(`#wptsall_tinymce_${id}-html`)).toHaveCount(1)
    await page.locator(`#wptsall_tinymce_${id}-html`).click()
    // wp_editor may apply wpautop to plain excerpts. Assert visible text mode,
    // then the visible rich editor below, while REST/WP assert exact bytes.
    if (field === 'post_content') {
      await expect(page.locator(`#wptsall_tinymce_${id}`)).toHaveValue(fields[field])
    } else {
      await expect(page.locator(`#wptsall_tinymce_${id}`)).toHaveValue(new RegExp(fields[field]))
    }
    await page.locator(`#wptsall_tinymce_${id}-tmce`).click()
  }
  const body = page.frameLocator('#wptsall_tinymce_content_ifr').locator('body')
  await expect(body).toHaveText(fields.post_content.replace(/<[^>]*>/g, ''))
  await expect(body.locator('strong')).toHaveText(tag)
  await expect(page.frameLocator('#wptsall_tinymce_excerpt_ifr').locator('body')).toHaveText(fields.post_excerpt)
}

test('17 owned manual editor saves three fields, rejects a wrong target and independently reloads', async ({ browser }, testInfo) => {
  verifyContainer()
  for (const target of lane.sites) assertManualIsolation(isolation(target))
  const ownedSession = await session(browser)
  const page = await ownedSession.context.newPage()
  const rejectedRequests: string[] = []
  const allowed = new Set(lane.sites.map((target) => new URL(target.home_url).origin))
  await ownedSession.context.route('**/*', async (route) => {
    const url = new URL(route.request().url())
    if (!allowed.has(url.origin)) {
      rejectedRequests.push(`${url.origin}${url.pathname}`)
      await route.abort()
    } else {
      await route.continue()
    }
  })
  try {
    await login(page, site)
    // Language form is a product admin surface, on an empty owned ATS site.
    await page.goto(`${site.home_url}/wp-admin/admin.php?page=wptsall-languages&action=add`)
    const form = page.locator('form').filter({ has: page.locator('input[name="action"][value="wptsall_language_save"]') })
    await form.locator('#code').fill('fr_FR')
    await form.locator('#name').fill(`Français ${tag}`)
    await form.locator('#native_name').fill('Français')
    await form.locator('#slug').fill('fr')
    await form.locator('#locale').fill('fr_FR')
    await Promise.all([page.waitForURL('**/*updated=1*'), form.locator('input[type="submit"], button[type="submit"]').click()])

    await page.goto(`${site.home_url}/wp-admin/admin.php?page=wptsall-sites&tab=add`)
    const defaults = await page.evaluate(() => ({
      sourceLang: (document.querySelector('#source_lang') as HTMLSelectElement).value,
      template: (document.querySelector('#template') as HTMLSelectElement).value,
    }))
    expect(defaults.sourceLang).toBe('en_US')
    expect(defaults.template.length).toBeGreaterThan(0)
    const virtual = await rest(page, 'POST', '/wp-json/wptsall/v2/virtual-sites', {
      name: tag, path_prefix: tag, lang: 'fr_FR',
    })
    expect(virtual.status).toBe(200)
    const virtualSiteId = Number(virtual.body.site_id)
    expect(virtualSiteId).toBeGreaterThan(0)
    const relation = await rest(page, 'POST', '/wp-json/wptsall/v2/site-relations', {
      source_site_id: 1, source_lang: defaults.sourceLang, template: defaults.template,
      target_sites: [{ id: String(virtualSiteId), type: 'virtual', lang: 'fr_FR' }], media_handling: 'copy',
    })
    expect(relation.status).toBe(200)
    expect(relation.body.relation_ids).toHaveLength(1)
    const relationId = Number(relation.body.relation_ids[0])
    expect(relationId).toBeGreaterThan(0)
    const sourceFields: ManualFields = {
      post_title: `Source ${tag}`, post_content: `<p>Source body ${tag}</p>`, post_excerpt: `Source excerpt ${tag}`,
    }
    const created = await rest(page, 'POST', '/wp-json/wp/v2/posts', {
      title: sourceFields.post_title, content: sourceFields.post_content, excerpt: sourceFields.post_excerpt, status: 'publish',
    })
    expect(created.status).toBe(201)
    const sourceId = Number(created.body.id)
    expect(sourceId).toBeGreaterThan(0)

    const editorPath = `/wp-admin/admin.php?page=wptsall-translate&source_post_id=${sourceId}&relation_id=${relationId}`
    const loading = page.waitForResponse((response) => response.url().includes('/manual-translations/editor-data')
      && response.request().method() === 'GET')
    await page.goto(site.home_url + editorPath)
    const initial = await loading
    expect(initial.status()).toBe(200)
    const initialData = await initial.json()
    const targetId = Number(initialData.target.ID)
    expect(targetId).toBeGreaterThan(0)
    expect(targetId).not.toBe(sourceId)
    expect(initialData.target.post_status).toBe('draft')
    const fields: ManualFields = {
      post_title: `Titre ${tag}`, post_content: `<p>Corps <strong>${tag}</strong></p>`, post_excerpt: `Résumé ${tag}`,
    }
    const expected: ManualExpected = {
      sourceId, targetId, relationId, virtualSiteId, sourceLang: 'en_US', targetLang: 'fr_FR',
      source: sourceFields, target: fields,
    }
    await fillEditor(page, fields)
    const saving = page.waitForResponse((response) => new URL(response.url()).pathname.endsWith('/manual-translations')
      && response.request().method() === 'POST')
    await page.locator('#wptsall-save-translation').click()
    const saved = await saving
    expect(saved.status()).toBe(200)
    expect(saved.request().postDataJSON()).toMatchObject({
      source_post_id: String(sourceId), relation_id: String(relationId), translated_data: fields,
    })
    expect(await saved.json()).toMatchObject({ target_id: targetId, relation_id: relationId, is_update: true })
    await expect(page.locator('#wptsall-save-translation')).toBeEnabled()
    const getPath = `/wp-json/wptsall/v2/manual-translations/editor-data?source_post_id=${sourceId}&relation_id=${relationId}`
    const readback = await rest(page, 'GET', getPath)
    expect(readback.status).toBe(200)
    assertManualEditorReadback(readback.body, expected)
    assertManualTargetRecord(readTarget(expected), expected)

    // The source is not a translation target: PUT must reject, not overwrite it.
    const invalid = await rest(page, 'PUT', `/wp-json/wptsall/v2/manual-translations/${sourceId}`, {
      relation_id: relationId, fields: { post_title: `Unowned target ${tag}` },
    })
    expect(invalid.status).toBe(403)
    expect(invalid.body.code).toBe('rest_object_forbidden')
    assertManualTargetRecord(readTarget(expected), expected)
    const reloading = page.waitForResponse((response) => response.url().includes('/manual-translations/editor-data')
      && response.request().method() === 'GET')
    await page.reload()
    const reloaded = await reloading
    expect(reloaded.status()).toBe(200)
    assertManualEditorReadback(await reloaded.json(), expected)
    await assertReloadedEditor(page, fields)

    // Edit-mode PUT uses the same owned target and must persist new values.
    const edited: ManualFields = {
      post_title: `Titre édité ${tag}`,
      post_content: `<p>Corps édité <strong>${tag}</strong></p>`,
      post_excerpt: `Résumé édité ${tag}`,
    }
    await page.locator('#wptsall-mode-edit').click()
    await fillEditor(page, edited)
    const updating = page.waitForResponse((response) => new URL(response.url()).pathname.endsWith(`/manual-translations/${targetId}`)
      && response.request().method() === 'PUT')
    await page.locator('#wptsall-save-translation').click()
    const updated = await updating
    expect(updated.status()).toBe(200)
    expect(updated.request().postDataJSON()).toMatchObject({ relation_id: String(relationId), fields: edited })
    const editedExpected = { ...expected, target: edited }
    const editedReadback = await rest(page, 'GET', getPath)
    expect(editedReadback.status).toBe(200)
    assertManualEditorReadback(editedReadback.body, editedExpected)
    const persisted = readTarget(editedExpected)
    assertManualTargetRecord(persisted, editedExpected)
    const finalReload = page.waitForResponse((response) => response.url().includes('/manual-translations/editor-data')
      && response.request().method() === 'GET')
    await page.reload()
    expect((await finalReload).status()).toBe(200)
    await assertReloadedEditor(page, edited)
    expect(rejectedRequests).toEqual([])
    const isolationEvidence = lane.sites.map((target) => isolation(target))
    for (const evidence of isolationEvidence) assertManualIsolation(evidence)
    const output = writeOwnedManualEvidence(process.env, {
      evidence_level: 'deployed_owned_wp', existing_nonowned_processes: 'untouched_not_globally_absent',
      started_infrastructure: ['two_owned_wp_containers'], expected: editedExpected,
      readback: editedReadback.body, persisted, wrong_target_status: invalid.status,
      browser_forbidden_requests: rejectedRequests, isolation: isolationEvidence,
    })
    await testInfo.attach('owned-manual-editor-output', { path: output, contentType: 'application/json' })
  } finally {
    await ownedSession.close()
  }
})
