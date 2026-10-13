/**
 * R2 output pair for 20's legacy shared admin-save probes.
 * Native Woo/ACF admin → independent WP/native API read → reload.
 * covers: success|failure|boundary
 */
import { test, expect, type Page } from '@playwright/test'
import {
  assertOwnedContentLane, assertContentProduct, assertContentFieldGroup,
  assertContentIsolation, writeOwnedContentEvidence, type ContentProduct, type ContentFieldGroup,
} from '../lib/owned-content-admin'
import { session, login, wp, type OwnedSite } from '../owned-wp/helpers'

const lane = assertOwnedContentLane(process.env)
const [site] = lane.sites
const tag = `content-owned-${lane.owner}`

function isolation(target: OwnedSite) {
  return JSON.parse(wp(target, `
$attempts=get_option('wptsall_mi_http_attempts',array()); if(!is_array($attempts)){exit(1);}
echo wp_json_encode(array(
 'cron_disabled'=>defined('DISABLE_WP_CRON') && DISABLE_WP_CRON,
 'external_blocked'=>defined('WP_HTTP_BLOCK_EXTERNAL') && WP_HTTP_BLOCK_EXTERNAL,
 'http_guard_installed'=>function_exists('wptsall_mi_block_http'),
 'active_plugins'=>get_option('active_plugins'),
 'forbidden_http_attempts'=>count(array_filter($attempts,function($a){return ($a['severity']??'')==='forbidden_infra';}))
));`))
}

function readGroup(expected: ContentFieldGroup) {
  return JSON.parse(wp(site, `
$g=acf_get_field_group(${expected.id}); $f=acf_get_fields(${JSON.stringify(expected.key)});
if(!$g || !is_array($f)){exit(1);}
echo wp_json_encode(array('ID'=>(int)$g['ID'],'key'=>$g['key'],'title'=>$g['title'],
 'active'=>(bool)$g['active'],'location'=>$g['location'],'fields'=>array_map(function($x){
 return array('ID'=>(int)$x['ID'],'key'=>$x['key'],'name'=>$x['name'],'label'=>$x['label'],'type'=>$x['type']);
},$f)));`))
}

function readProduct(expected: ContentProduct) {
  return JSON.parse(wp(site, `
$p=get_post(${expected.id});$wc=wc_get_product(${expected.id});if(!$p || !$wc){exit(1);}
$name=${JSON.stringify(expected.fieldName)};$meta=array();
foreach(array('_sku','_regular_price','_stock',$name,'_'.$name) as $key){$meta[$key]=get_post_meta($p->ID,$key,true);}
echo wp_json_encode(array('id'=>(int)$p->ID,'post_type'=>$p->post_type,'post_status'=>$p->post_status,
 'post_title'=>$p->post_title,'post_content'=>$p->post_content,'post_excerpt'=>$p->post_excerpt,
 'sku'=>$wc->get_sku(),'price'=>$wc->get_regular_price(),'stock'=>$wc->get_stock_quantity(),
 'acf_value'=>get_field($name,$p->ID,false),'meta'=>$meta));`))
}

async function saveClassic(page: Page, id: number, selector: '#publish' | 'button.acf-publish' = '#publish'): Promise<void> {
  await expect(page.locator('#post_ID')).toHaveValue(String(id))
  await expect(page.locator(selector)).toBeVisible()
  const saved = page.waitForResponse((r) => new URL(r.url()).pathname === '/wp-admin/post.php'
    && r.request().method() === 'POST')
  const navigated = page.waitForURL((url) => url.pathname === '/wp-admin/post.php'
    && url.searchParams.get('post') === String(id) && url.searchParams.has('message'))
  const [response] = await Promise.all([saved, navigated, page.locator(selector).click()])
  expect(response.status()).toBe(302)
  await page.waitForLoadState('domcontentloaded')
}

async function productForm(page: Page, expected: ContentProduct, fill: boolean): Promise<void> {
  if (fill) await page.locator('#title').fill(expected.fields.post_title)
  else await expect(page.locator('#title')).toHaveValue(expected.fields.post_title)
  for (const [id, field] of [['content', 'post_content'], ['excerpt', 'post_excerpt']] as const) {
    await page.locator(`#${id}-html`).click()
    if (fill) await page.locator(`#${id}`).fill(expected.fields[field])
    else await expect(page.locator(`#${id}`)).toHaveValue(expected.fields[field])
  }
  await page.locator('.general_tab a').click()
  if (fill) await page.locator('#_regular_price').fill(expected.price)
  else await expect(page.locator('#_regular_price')).toHaveValue(expected.price)
  await page.locator('.inventory_tab a').click()
  if (fill) {
    await page.locator('#_sku').fill(expected.sku)
    await page.locator('#_manage_stock').check()
    await page.locator('#_stock').fill(String(expected.stock))
  } else {
    await expect(page.locator('#_sku')).toHaveValue(expected.sku)
    await expect(page.locator('#_manage_stock')).toBeChecked()
    await expect(page.locator('#_stock')).toHaveValue(String(expected.stock))
  }
  const acf = page.locator(`.acf-field[data-key="${expected.fieldKey}"] input[type="text"]`)
  if (fill) await acf.fill(expected.acfValue)
  else await expect(acf).toHaveValue(expected.acfValue)
}

test('20 owned Woo product and ACF definition/value survive real Update and reload', async ({ browser }, testInfo) => {
  for (const target of lane.sites) assertContentIsolation(isolation(target))
  const fixture = JSON.parse(wp(site, `
if(!class_exists('WC_Product_Simple') || !function_exists('acf_update_field_group')){exit(1);}
$key='group_${lane.owner}';$field_key='field_${lane.owner}';
$g=acf_update_field_group(array('key'=>$key,'title'=>'Initial ${tag}','active'=>1,
 'location'=>array(array(array('param'=>'post_type','operator'=>'==','value'=>'product')))));
$f=acf_update_field(array('key'=>$field_key,'parent'=>$g['ID'],'name'=>'owned_tagline',
 'label'=>'Initial field ${tag}','type'=>'text'));
$p=new WC_Product_Simple();$p->set_name('Initial product ${tag}');$p->set_description('Initial body ${tag}');
$p->set_short_description('Initial excerpt ${tag}');$p->set_status('publish');$id=$p->save();
if(!$id || !$g || !$f){exit(1);}
echo wp_json_encode(array('product_id'=>(int)$id,'group_id'=>(int)$g['ID'],'field_id'=>(int)$f['ID']));`))
  for (const id of [fixture.product_id, fixture.group_id, fixture.field_id]) {
    expect(Number.isSafeInteger(id)).toBe(true)
    expect(id).toBeGreaterThan(0)
  }
  const group: ContentFieldGroup = {
    id: fixture.group_id, key: `group_${lane.owner}`, title: `Saved group ${tag}`,
    fieldId: fixture.field_id, fieldKey: `field_${lane.owner}`, fieldName: 'owned_tagline',
    label: `Saved field label ${tag}`,
  }
  const product: ContentProduct = {
    id: fixture.product_id,
    fields: { post_title: `Saved product ${tag}`, post_content: `<p>Saved body ${tag}</p>`, post_excerpt: `Saved excerpt ${tag}` },
    sku: `SKU-${lane.owner}`, price: '17.25', stock: 9,
    fieldName: group.fieldName, fieldKey: group.fieldKey, acfValue: `Saved ACF value ${tag}`,
  }
  const ownedSession = await session(browser)
  const page = await ownedSession.context.newPage()
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
    await page.goto(`${site.home_url}/wp-admin/post.php?post=${group.id}&action=edit`)
    await page.locator('#title').fill(group.title)
    const field = page.locator(`.acf-field-object[data-key="${group.fieldKey}"]`)
    await field.locator('strong a.edit-field').click()
    await field.locator('input[name$="[label]"]').fill(group.label)
    await saveClassic(page, group.id, 'button.acf-publish')
    const groupReadback = readGroup(group)
    assertContentFieldGroup(groupReadback, group)
    await page.reload()
    await expect(page.locator('#title')).toHaveValue(group.title)
    const reloadedField = page.locator(`.acf-field-object[data-key="${group.fieldKey}"]`)
    await expect(reloadedField.locator('input[name$="[label]"]')).toHaveValue(group.label)

    await page.goto(`${site.home_url}/wp-admin/post.php?post=${product.id}&action=edit`)
    await productForm(page, product, true)
    await saveClassic(page, product.id)
    const productReadback = readProduct(product)
    assertContentProduct(productReadback, product)
    await page.reload()
    await productForm(page, product, false)
    const identities = JSON.parse(wp(site, `
global $wpdb;echo wp_json_encode(array(
 'product_ids'=>array_map('intval',$wpdb->get_col($wpdb->prepare(
 "SELECT ID FROM {$wpdb->posts} WHERE post_type='product' AND post_title=%s",${JSON.stringify(product.fields.post_title)}))),
 'group_ids'=>array_map('intval',$wpdb->get_col($wpdb->prepare(
 "SELECT ID FROM {$wpdb->posts} WHERE post_type='acf-field-group' AND post_name=%s",${JSON.stringify(group.key)})))
));`))
    expect(identities).toEqual({ product_ids: [product.id], group_ids: [group.id] })
    const otherSite = JSON.parse(wp(lane.sites[1], `
global $wpdb;$counts=array();
foreach(array('products'=>'product','groups'=>'acf-field-group') as $key=>$type){
 $count=$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type=%s",$type));
 if($wpdb->last_error || !is_scalar($count) || !preg_match('/^[0-9]+$/',(string)$count)){exit(1);}
 $counts[$key]=(int)$count;
}
echo wp_json_encode($counts);`))
    expect(otherSite).toEqual({ products: 0, groups: 0 })
    expect(rejectedRequests).toEqual([])
    const evidence = lane.sites.map((target) => isolation(target))
    for (const value of evidence) assertContentIsolation(value)
    const output = writeOwnedContentEvidence(process.env, {
      evidence_level: 'deployed_owned_wp_native_content_plugins', product, productReadback,
      group, groupReadback, identities, otherSite, browser_forbidden_requests: rejectedRequests, isolation: evidence,
      existing_nonowned_processes: 'untouched_not_globally_absent',
    })
    await testInfo.attach('owned-content-admin-output', { path: output, contentType: 'application/json' })
  } finally {
    await ownedSession.close()
  }
})
