/**
 * R2 owned output pair for the legacy 21 optional-soft-save matrix.
 * Explicit subset: core post/page + native Woo product, not all plugin fields.
 * covers: success|failure|boundary
 */
import { test, expect, type Page } from '@playwright/test'
import { writeFileSync } from 'node:fs'
import {
  assertOwnedContentMatrixLane, CONTENT_MATRIX_TYPES, assertContentMatrixInventory,
  assertContentMatrixReadback, assertContentMatrixRecord, assertContentMatrixCounts,
  writeOwnedContentMatrixEvidence, type ContentMatrixExpected, type ContentMatrixType,
} from '../lib/owned-content-matrix'
import { assertContentIsolation } from '../lib/owned-content-admin'
import { type ManualFields } from '../lib/owned-manual-editor'
import { session, login, wp, scalar, type OwnedSite } from '../owned-wp/helpers'

const lane = assertOwnedContentMatrixLane(process.env)
const [site] = lane.sites
const tag = `cpt-owned-${lane.owner}`

function isolation(target: OwnedSite) {
  return JSON.parse(wp(target, `
$attempts=get_option('wptsall_mi_http_attempts',array());if(!is_array($attempts)){exit(1);}
echo wp_json_encode(array(
 'cron_disabled'=>defined('DISABLE_WP_CRON') && DISABLE_WP_CRON,
 'external_blocked'=>defined('WP_HTTP_BLOCK_EXTERNAL') && WP_HTTP_BLOCK_EXTERNAL,
 'http_guard_installed'=>function_exists('wptsall_mi_block_http'),
 'active_plugins'=>get_option('active_plugins'),
 'forbidden_http_attempts'=>count(array_filter($attempts,function($a){return ($a['severity']??'')==='forbidden_infra';}))
));`))
}

async function rest(page: Page, method: string, route: string, body?: unknown) {
  return page.evaluate(async ({ method, route, body }) => {
    const config = window as unknown as {
      wpApiSettings?: { nonce?: string }; wptsallSiteRelations?: { nonce?: string; restNonce?: string }
      wptsallTranslationEditor?: { restNonce?: string }
    }
    const nonce = config.wpApiSettings?.nonce ?? config.wptsallSiteRelations?.restNonce
      ?? config.wptsallSiteRelations?.nonce ?? config.wptsallTranslationEditor?.restNonce
    if (!nonce) throw new Error('owned CPT admin nonce missing')
    const response = await fetch(route, {
      method, credentials: 'same-origin',
      headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' },
      body: body === undefined ? undefined : JSON.stringify(body),
    })
    return { status: response.status, body: await response.json() }
  }, { method, route, body })
}

function createSource(postType: ContentMatrixType, fields: ManualFields): number {
  const created = JSON.parse(wp(site, `
$type=${JSON.stringify(postType)};$fields=json_decode(${JSON.stringify(JSON.stringify(fields))},true);
if(!post_type_exists($type)){exit(1);}
if($type==='product'){
 if(!class_exists('WC_Product_Simple')){exit(1);}
 $product=new WC_Product_Simple();$product->set_name($fields['post_title']);
 $product->set_description($fields['post_content']);$product->set_short_description($fields['post_excerpt']);
 $product->set_status('publish');$id=$product->save();
}else{
 $id=wp_insert_post(array_merge($fields,array('post_type'=>$type,'post_status'=>'publish')),true);
}
if(is_wp_error($id) || !$id){exit(1);}
echo wp_json_encode(array('id'=>(int)$id));`))
  expect(Number.isSafeInteger(created.id)).toBe(true)
  expect(created.id).toBeGreaterThan(0)
  return created.id
}

function readTarget(expected: ContentMatrixExpected) {
  return JSON.parse(wp(site, `
$source=get_post(${expected.sourceId});$target=get_post(${expected.targetId});
if(!$source || !$target){exit(1);}
$fields=function($p){return array('post_title'=>$p->post_title,'post_content'=>$p->post_content,'post_excerpt'=>$p->post_excerpt);};
global $wpdb;$ids=$wpdb->get_col($wpdb->prepare(
 "SELECT p.ID FROM {$wpdb->posts} p
 JOIN {$wpdb->postmeta} s ON s.post_id=p.ID AND s.meta_key='_wptsall_source_post_id'
 JOIN {$wpdb->postmeta} r ON r.post_id=p.ID AND r.meta_key='_wptsall_relation_id'
 WHERE s.meta_value=%s AND r.meta_value=%s AND p.post_type=%s AND p.post_status<>'trash' ORDER BY p.ID",
 '${expected.sourceId}','${expected.relationId}',${JSON.stringify(expected.postType)}));
if($wpdb->last_error || !is_array($ids)){exit(1);}
echo wp_json_encode(array('source_id'=>(int)$source->ID,'target_id'=>(int)$target->ID,
 'source_post_type'=>$source->post_type,'target_post_type'=>$target->post_type,
 'source_status'=>$source->post_status,'target_status'=>$target->post_status,
 'target_ids'=>array_map('intval',$ids),'source'=>$fields($source),'target'=>$fields($target),
 'source_marker'=>get_post_meta($target->ID,'_wptsall_source_post_id',true),
 'relation_marker'=>get_post_meta($target->ID,'_wptsall_relation_id',true),
 'virtual_site_marker'=>get_post_meta($target->ID,'_wptsall_virtual_site_id',true)));
`))
}

function counts(target: OwnedSite, autoDrafts = false) {
  return Object.fromEntries(CONTENT_MATRIX_TYPES.map((type) => [
    type, scalar(target, `SELECT COUNT(*) FROM ${target.prefix}posts WHERE post_type='${type}'
      AND ${autoDrafts ? "post_status='auto-draft'" : "post_status NOT IN ('trash','auto-draft')"}`),
  ]))
}

async function fillEditor(page: Page, fields: ManualFields): Promise<void> {
  await expect(page.locator('#wptsall-target-post_title')).toHaveClass(/\bwptsall-editor-field\b/)
  await page.locator('#wptsall-target-post_title').fill(fields.post_title)
  for (const [id, field] of [['content', 'post_content'], ['excerpt', 'post_excerpt']] as const) {
    // Wait for real native initialization, not merely the PHP textarea shell.
    await page.locator(`#wptsall_tinymce_${id}-tmce`).click()
    await expect(page.frameLocator(`#wptsall_tinymce_${id}_ifr`).locator('body'))
      .toHaveAttribute('contenteditable', 'true')
    await page.locator(`#wptsall_tinymce_${id}-html`).click()
    await expect(page.locator(`#wp-wptsall_tinymce_${id}-wrap`)).toHaveClass(/\bhtml-active\b/)
    await expect(page.frameLocator(`#wptsall_tinymce_${id}_ifr`).locator('body')).toBeHidden()
    await page.locator(`#wptsall_tinymce_${id}`).fill(fields[field])
    await expect(page.locator(`#wptsall_tinymce_${id}`)).toHaveValue(fields[field])
  }
}

async function reloadedEditor(page: Page, fields: ManualFields): Promise<void> {
  await expect(page.locator('#wptsall-target-post_title')).toHaveValue(fields.post_title)
  for (const [id, field] of [['content', 'post_content'], ['excerpt', 'post_excerpt']] as const) {
    await page.locator(`#wptsall_tinymce_${id}-html`).click()
    if (field === 'post_content') await expect(page.locator(`#wptsall_tinymce_${id}`)).toHaveValue(fields[field])
    else await expect(page.locator(`#wptsall_tinymce_${id}`)).toHaveValue(new RegExp(fields[field]))
    await page.locator(`#wptsall_tinymce_${id}-tmce`).click()
  }
  await expect(page.frameLocator('#wptsall_tinymce_content_ifr').locator('body'))
    .toHaveText(fields.post_content.replace(/<[^>]*>/g, ''))
  await expect(page.frameLocator('#wptsall_tinymce_excerpt_ifr').locator('body')).toHaveText(fields.post_excerpt)
}

test('21 owned post/page/Woo product each require POST/PUT, independent GET/WP and two reloads', async ({ browser }, testInfo) => {
  for (const target of lane.sites) assertContentIsolation(isolation(target))
  const inventory = JSON.parse(wp(site, `
$types=array('post','page','product');
foreach($types as $type){if(!post_type_exists($type)){exit(1);}}
echo wp_json_encode(array('post_types'=>$types,'native_woo_available'=>class_exists('WC_Product_Simple')));`))
  assertContentMatrixInventory(inventory)
  assertContentMatrixCounts(counts(site), 0)
  assertContentMatrixCounts(counts(lane.sites[1]), 0)
  assertContentMatrixCounts(counts(site, true), 0)
  assertContentMatrixCounts(counts(lane.sites[1], true), 0)
  const ownedSession = await session(browser)
  const page = await ownedSession.context.newPage()
  const diagnostics: Record<string, unknown>[] = []
  page.on('request', (request) => {
    if (request.url().includes('manual-translations')) diagnostics.push({
      event: 'request', method: request.method(), path: new URL(request.url()).pathname,
    })
  })
  page.on('response', (response) => {
    if (response.url().includes('manual-translations')) diagnostics.push({
      event: 'response', status: response.status(), path: new URL(response.url()).pathname,
    })
    if (response.request().resourceType() === 'script' && response.status() >= 400) diagnostics.push({
      event: 'script-response', status: response.status(), path: new URL(response.url()).pathname,
    })
  })
  page.on('requestfailed', (request) => diagnostics.push({
    event: 'requestfailed', path: new URL(request.url()).pathname, failure: request.failure()?.errorText,
  }))
  page.on('pageerror', (error) => diagnostics.push({ event: 'pageerror', message: error.message, stack: error.stack }))
  const rejectedRequests: string[] = []
  const allowed = new Set(lane.sites.map((target) => new URL(target.home_url).origin))
  await ownedSession.context.route('**/*', async (route) => {
    const url = new URL(route.request().url())
    if (!allowed.has(url.origin)) {
      rejectedRequests.push(`${url.origin}${url.pathname}`)
      await route.abort()
    } else await route.continue()
  })
  try {
    await login(page, site)
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
    // Core admin creates one unsaved media-editor auto-draft before our sources.
    const nativeAutoDrafts = counts(site, true)
    expect(nativeAutoDrafts).toEqual({ post: 1, page: 0, product: 0 })
    const cases = []
    for (const postType of CONTENT_MATRIX_TYPES) {
      diagnostics.push({ event: 'case', post_type: postType })
      const sourceFields: ManualFields = {
        post_title: `Source ${postType} ${tag}`, post_content: `<p>Source ${postType} body ${tag}</p>`,
        post_excerpt: `Source ${postType} excerpt ${tag}`,
      }
      const sourceId = createSource(postType, sourceFields)
      const editorPath = `/wp-admin/admin.php?page=wptsall-translate&source_post_id=${sourceId}&relation_id=${relationId}`
      const loading = page.waitForResponse((r) => r.url().includes('/manual-translations/editor-data')
        && r.request().method() === 'GET')
      await page.goto(site.home_url + editorPath)
      const initial = await loading
      expect(initial.status()).toBe(200)
      const initialData = await initial.json()
      const targetId = Number(initialData.target.ID)
      expect(Number.isSafeInteger(targetId)).toBe(true)
      expect(targetId).toBeGreaterThan(0)
      expect(targetId).not.toBe(sourceId)
      expect(initialData.source.post_type).toBe(postType)
      expect(initialData.target.post_type).toBe(postType)
      diagnostics.push({ event: 'case-identity', post_type: postType, source_id: sourceId, target_id: targetId })
      const fields: ManualFields = {
        post_title: `Titre ${postType} ${tag}`, post_content: `<p>Corps ${postType} <strong>${tag}</strong></p>`,
        post_excerpt: `Résumé ${postType} ${tag}`,
      }
      const expected: ContentMatrixExpected = {
        postType, sourceId, targetId, relationId, virtualSiteId, sourceLang: 'en_US', targetLang: 'fr_FR',
        source: sourceFields, target: fields,
      }
      await fillEditor(page, fields)
      const saving = page.waitForResponse((r) => new URL(r.url()).pathname.endsWith('/manual-translations')
        && r.request().method() === 'POST')
      await page.locator('#wptsall-save-translation').click()
      const saved = await saving
      expect(saved.status()).toBe(200)
      expect(saved.request().postDataJSON()).toMatchObject({
        source_post_id: String(sourceId), relation_id: String(relationId), translated_data: fields,
      })
      await expect(page.locator('#wptsall-save-translation')).toBeEnabled()
      const getPath = `/wp-json/wptsall/v2/manual-translations/editor-data?source_post_id=${sourceId}&relation_id=${relationId}`
      const readback = await rest(page, 'GET', getPath)
      expect(readback.status).toBe(200)
      assertContentMatrixReadback(readback.body, expected)
      assertContentMatrixRecord(readTarget(expected), expected)
      const firstReload = page.waitForResponse((r) => r.url().includes('/manual-translations/editor-data')
        && r.request().method() === 'GET')
      await page.reload()
      const reloaded = await firstReload
      expect(reloaded.status()).toBe(200)
      assertContentMatrixReadback(await reloaded.json(), expected)
      await reloadedEditor(page, fields)
      const edited: ManualFields = {
        post_title: `Titre édité ${postType} ${tag}`, post_content: `<p>Corps édité ${postType} <strong>${tag}</strong></p>`,
        post_excerpt: `Résumé édité ${postType} ${tag}`,
      }
      await page.locator('#wptsall-mode-edit').click()
      await fillEditor(page, edited)
      const updating = page.waitForResponse((r) => new URL(r.url()).pathname.endsWith(`/manual-translations/${targetId}`)
        && r.request().method() === 'PUT')
      await page.locator('#wptsall-save-translation').click()
      const updated = await updating
      expect(updated.status()).toBe(200)
      expect(updated.request().postDataJSON()).toMatchObject({ relation_id: String(relationId), fields: edited })
      await expect(page.locator('#wptsall-save-translation')).toBeEnabled()
      const editedExpected = { ...expected, target: edited }
      const finalReadback = await rest(page, 'GET', getPath)
      expect(finalReadback.status).toBe(200)
      assertContentMatrixReadback(finalReadback.body, editedExpected)
      const persisted = readTarget(editedExpected)
      assertContentMatrixRecord(persisted, editedExpected)
      const invalid = await rest(page, 'PUT', `/wp-json/wptsall/v2/manual-translations/${sourceId}`, {
        relation_id: relationId, fields: { post_title: `Wrong target ${tag}` },
      })
      expect(invalid.status).toBe(403)
      expect(invalid.body.code).toBe('rest_object_forbidden')
      assertContentMatrixRecord(readTarget(editedExpected), editedExpected)
      diagnostics.push({ event: 'final-reload', post_type: postType })
      const finalReload = page.waitForResponse((r) => r.url().includes('/manual-translations/editor-data')
        && r.request().method() === 'GET')
      await page.reload()
      const finalResponse = await finalReload
      expect(finalResponse.status()).toBe(200)
      assertContentMatrixReadback(await finalResponse.json(), editedExpected)
      await reloadedEditor(page, edited)
      cases.push({
        expected: editedExpected, readback: finalReadback.body, persisted,
        post_status: saved.status(), put_status: updated.status(), wrong_target_status: invalid.status, reloads: 2,
      })
    }
    const siteCounts = counts(site), otherSiteCounts = counts(lane.sites[1])
    const finalAutoDrafts = counts(site, true), otherSiteAutoDrafts = counts(lane.sites[1], true)
    diagnostics.push({ event: 'counts', siteCounts, otherSiteCounts, finalAutoDrafts, otherSiteAutoDrafts })
    assertContentMatrixCounts(siteCounts, 2)
    assertContentMatrixCounts(otherSiteCounts, 0)
    expect(finalAutoDrafts).toEqual(nativeAutoDrafts)
    assertContentMatrixCounts(otherSiteAutoDrafts, 0)
    expect(rejectedRequests).toEqual([])
    const evidence = lane.sites.map((target) => isolation(target))
    for (const item of evidence) assertContentIsolation(item)
    const output = writeOwnedContentMatrixEvidence(process.env, {
      evidence_level: 'deployed_owned_wp_manual_cpt_subset', inventory, cases, siteCounts, otherSiteCounts,
      nativeAutoDrafts, finalAutoDrafts, otherSiteAutoDrafts,
      browser_forbidden_requests: rejectedRequests, isolation: evidence,
      existing_nonowned_processes: 'untouched_not_globally_absent',
    })
    await testInfo.attach('owned-content-matrix-output', { path: output, contentType: 'application/json' })
  } catch (error) {
    const state = await page.evaluate(() => ({
      pathname: window.location.pathname,
      scripts: Array.from(document.scripts).map((script) => script.src ? new URL(script.src).pathname : 'inline'),
      editor_config_present: Boolean((window as any).wptsallTranslationEditor),
      editors: ['content', 'excerpt'].map((key) => {
        const editorId = `wptsall_tinymce_${key}`
        const tiny = (window as any).tinymce?.get(editorId)
        return { id: editorId, exists: Boolean(tiny), initialized: tiny?.initialized,
          hidden: tiny?.isHidden(), editable: tiny?.getBody()?.getAttribute('contenteditable') }
      }),
    })).catch(() => ({ page_state_unavailable: true }))
    const output = testInfo.outputPath('owned-content-matrix-failure.evidence')
    writeFileSync(output, JSON.stringify({ diagnostics, state }, null, 2) + '\n', { flag: 'wx', mode: 0o600 })
    await testInfo.attach('owned-content-matrix-failure-diagnostics', { path: output, contentType: 'application/json' })
    throw error
  } finally {
    await ownedSession.close()
  }
})
