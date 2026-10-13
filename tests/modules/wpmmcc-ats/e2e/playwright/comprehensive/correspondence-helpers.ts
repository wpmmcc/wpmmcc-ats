/**
 * Shared helpers for source ↔ translated public correspondence specs (22/23).
 */
import type { Page, APIRequestContext } from '@playwright/test'
import { execFileSync } from 'child_process'
import { WP_BASE_URL } from './helpers'

export const WP_DOCKER =
  process.env.WP_DOCKER_CONTAINER || 'wptsall-wp-lab-wordpress-test-1'
export const WP_CLI_DISABLED = /^(1|true|yes)$/i.test(
  String(process.env.CORRESPONDENCE_NO_WPCLI || ''),
)

export type HtmlSeo = {
  title: string
  canonical: string
  metaDescription: string
  ogTitle: string
  ogUrl: string
  ogLocale: string
  hreflangs: Array<{ lang: string; href: string }>
  vsMarker: boolean
  htmlLang: string
  bodyClass: string
}

export type ChosenPair = {
  relationId: number
  targetLang: string
  pathPrefix: string
  virtualSiteId: string
  sourceLang: string
}

export function wpCli(args: string[]): string | null {
  if (WP_CLI_DISABLED) return null
  try {
    return execFileSync('docker', ['exec', WP_DOCKER, 'wp', ...args, '--allow-root'], {
      encoding: 'utf8',
      timeout: 90_000,
      maxBuffer: 2 * 1024 * 1024,
    }).trim()
  } catch {
    return null
  }
}

export async function restNonce(page: Page): Promise<string> {
  return page.evaluate(() => {
    const w = window as unknown as { wpApiSettings?: { nonce?: string } }
    return String(w?.wpApiSettings?.nonce || '')
  })
}

export async function browserJson(
  page: Page,
  url: string,
  init: RequestInit = {},
): Promise<{ status: number; body: unknown }> {
  return page.evaluate(
    async ({ u, opts }) => {
      const res = await fetch(u, opts as RequestInit)
      const text = await res.text()
      let body: unknown = text
      try {
        body = JSON.parse(text)
      } catch {
        /* keep */
      }
      return { status: res.status, body }
    },
    { u: url, opts: init },
  )
}

export function parseSeo(html: string): HtmlSeo {
  const pick = (re: RegExp): string => {
    const m = html.match(re)
    return m?.[1] ? String(m[1]).replace(/\s+/g, ' ').trim() : ''
  }
  const title = pick(/<title[^>]*>([\s\S]*?)<\/title>/i)
  const canonical =
    pick(/<link[^>]+rel=["']canonical["'][^>]+href=["']([^"']+)["']/i) ||
    pick(/<link[^>]+href=["']([^"']+)["'][^>]+rel=["']canonical["']/i)
  const metaDescription =
    pick(/<meta[^>]+name=["']description["'][^>]+content=["']([^"']*)["']/i) ||
    pick(/<meta[^>]+content=["']([^"']*)["'][^>]+name=["']description["']/i)
  const ogTitle =
    pick(/<meta[^>]+property=["']og:title["'][^>]+content=["']([^"']*)["']/i) ||
    pick(/<meta[^>]+content=["']([^"']*)["'][^>]+property=["']og:title["']/i)
  const ogUrl =
    pick(/<meta[^>]+property=["']og:url["'][^>]+content=["']([^"']*)["']/i) ||
    pick(/<meta[^>]+content=["']([^"']*)["'][^>]+property=["']og:url["']/i)
  const ogLocale =
    pick(/<meta[^>]+property=["']og:locale["'][^>]+content=["']([^"']*)["']/i) ||
    pick(/<meta[^>]+content=["']([^"']*)["'][^>]+property=["']og:locale["']/i)
  const htmlLang = pick(/<html[^>]+lang=["']([^"']+)["']/i)
  const bodyClass =
    pick(/<body[^>]+class=["']([^"']*)["']/i) || pick(/<body[^>]+class=([^\s>]+)/i)
  const hreflangs: Array<{ lang: string; href: string }> = []
  const seen = new Set<string>()
  // Match any <link rel=alternate … hreflang=…> regardless of attr order.
  for (const m of html.matchAll(/<link\b([^>]*rel=["']alternate["'][^>]*)>/gi)) {
    const attrs = m[1]
    if (!/hreflang=/i.test(attrs)) continue
    const lang = (attrs.match(/hreflang=["']([^"']+)["']/i) || [])[1]
    const href = (attrs.match(/href=["']([^"']+)["']/i) || [])[1]
    if (!lang || !href) continue
    const key = `${lang.toLowerCase()}|${href}`
    if (seen.has(key)) continue
    seen.add(key)
    hreflangs.push({ lang, href })
  }
  return {
    title,
    canonical,
    metaDescription,
    ogTitle,
    ogUrl,
    ogLocale,
    hreflangs,
    vsMarker: /<!--\s*wptsall virtual site/i.test(html),
    htmlLang,
    bodyClass,
  }
}

export function normalizeUrl(u: string): string {
  try {
    const x = new URL(u)
    x.hash = ''
    const path = x.pathname.replace(/\/+$/, '') || '/'
    return `${x.origin}${path}/`.toLowerCase()
  } catch {
    return u.replace(/\/+$/, '').toLowerCase() + '/'
  }
}

export function urlHasPrefix(url: string, prefix: string): boolean {
  const p = prefix.replace(/^\/+|\/+$/g, '')
  if (!p) return false
  try {
    return new URL(url).pathname.toLowerCase().includes(`/${p.toLowerCase()}/`)
  } catch {
    return url.toLowerCase().includes(`/${p.toLowerCase()}/`)
  }
}

export function countDoublePrefix(html: string, prefix: string): number {
  const p = prefix.replace(/^\/+|\/+$/g, '')
  if (!p) return 0
  const re = new RegExp(`/${p}/${p}/`, 'gi')
  return (html.match(re) || []).length
}

export function langToken(code: string): string {
  return String(code || '')
    .replace(/_/g, '-')
    .toLowerCase()
    .split('-')[0]
}

export async function chooseActiveVirtualRelation(
  page: Page,
  nonce: string,
): Promise<ChosenPair | null> {
  const relRes = await browserJson(
    page,
    `${WP_BASE_URL}/wp-json/wptsall/v2/site-relations?status=active&per_page=50`,
    { headers: { 'X-WP-Nonce': nonce }, credentials: 'include' },
  )
  const vsRes = await browserJson(
    page,
    `${WP_BASE_URL}/wp-json/wptsall/v2/virtual-sites?per_page=80&status=active`,
    { headers: { 'X-WP-Nonce': nonce }, credentials: 'include' },
  )
  const rels = Array.isArray(relRes.body) ? (relRes.body as Array<Record<string, unknown>>) : []
  const vsList = Array.isArray(vsRes.body) ? (vsRes.body as Array<Record<string, unknown>>) : []
  const vsById = new Map(vsList.map((v) => [String(v.id), v]))

  const rank = (lang: string) => {
    if (lang.startsWith('fr')) return 0
    if (lang.startsWith('en')) return 2
    return 1
  }

  const candidates: ChosenPair[] = []
  for (const rel of rels) {
    const targetSiteId = String(rel.target_site_id || '')
    if (!targetSiteId.startsWith('v_')) continue
    const vsId = targetSiteId.replace(/^v_/, '')
    const vs = vsById.get(vsId)
    if (!vs) continue
    const prefix = String(vs.path_prefix || vs.prefix || '')
      .replace(/^\/+|\/+$/g, '')
      .trim()
    if (!prefix) continue
    const targetLang = String(rel.target_lang || vs.lang || vs.language || '')
    const sourceLang = String(rel.source_lang || 'en_US')
    candidates.push({
      relationId: Number(rel.id || 0),
      targetLang,
      pathPrefix: prefix,
      virtualSiteId: vsId,
      sourceLang,
    })
  }
  candidates.sort((a, b) => rank(a.targetLang) - rank(b.targetLang))
  return candidates.find((c) => c.relationId > 0) || null
}

export function ensureHreflangEmitter(): void {
  const php =
    'if (class_exists("\\WPTSALL\\Settings\\Services\\Settings_Service")) {' +
    '\\WPTSALL\\Settings\\Services\\Settings_Service::update(array(' +
    '"hreflang_emitter"=>"wpmmcc-ats","prefer_sitemap_hreflang"=>false)); echo "ok";' +
    '} else { echo "skip"; }'
  wpCli(['eval', php])
}

export async function fetchPublic(
  request: APIRequestContext,
  url: string,
): Promise<{ status: number; html: string; finalUrl: string }> {
  const res = await request.get(url, { maxRedirects: 5 })
  return {
    status: res.status(),
    html: await res.text(),
    finalUrl: res.url(),
  }
}

export async function resolveRestBase(
  page: Page,
  nonce: string,
  postType: string,
): Promise<string | null> {
  const types = await browserJson(page, `${WP_BASE_URL}/wp-json/wp/v2/types?context=edit`, {
    headers: { 'X-WP-Nonce': nonce },
    credentials: 'include',
  })
  if (!types.body || typeof types.body !== 'object') return null
  const entry = (types.body as Record<string, { rest_base?: string }>)[postType]
  if (!entry) return null
  return String(entry.rest_base || postType)
}

/** CPT public path segment from rewrite slug (empty for post/page %postname%). */
export function resolveRewriteSlug(postType: string): string {
  if (postType === 'post' || postType === 'page') return ''
  try {
    const php = `\$o=get_post_type_object(${JSON.stringify(postType)}); echo (\$o && is_array(\$o->rewrite) && !empty(\$o->rewrite['slug'])) ? \$o->rewrite['slug'] : '';`
    return (wpCli(['eval', php]) || '').trim()
  } catch {
    return ''
  }
}

export function publicPathUnderPrefix(postType: string, slug: string): string {
  const rewrite = resolveRewriteSlug(postType)
  return rewrite ? `${rewrite}/${slug}` : slug
}

export async function createAndTranslate(
  page: Page,
  nonce: string,
  pair: ChosenPair,
  opts: {
    postType?: string
    stamp: string
    srcTitle: string
    srcBody: string
    tgtTitle: string
    tgtBody: string
    tgtSlug: string
    srcMeta?: string
    tgtMeta?: string
    /** Raw post_content (blocks); defaults to paragraph wrapping srcBody/tgtBody. */
    srcContent?: string
    tgtContent?: string
  },
): Promise<{ sourceId: number; targetId: number; sourceUrl: string; targetUrl: string }> {
  const postType = opts.postType || 'post'
  const restBase =
    postType === 'post'
      ? 'posts'
      : postType === 'page'
        ? 'pages'
        : (await resolveRestBase(page, nonce, postType)) || postType

  const created = await browserJson(page, `${WP_BASE_URL}/wp-json/wp/v2/${encodeURIComponent(restBase)}`, {
    method: 'POST',
    headers: {
      'X-WP-Nonce': nonce,
      'Content-Type': 'application/json',
    },
    credentials: 'include',
    body: JSON.stringify({
      title: opts.srcTitle,
      content: opts.srcContent ?? `<p>${opts.srcBody}</p>`,
      status: 'publish',
      excerpt: opts.srcBody,
    }),
  })
  if (created.status < 200 || created.status >= 300) {
    throw new Error(`create ${restBase} failed: ${created.status} ${JSON.stringify(created.body)}`)
  }
  const source = created.body as { id?: number; link?: string }
  const sourceId = Number(source.id || 0)
  const sourceUrl = String(source.link || '')

  if (opts.srcMeta) {
    wpCli(['post', 'meta', 'update', String(sourceId), '_yoast_wpseo_title', `Yoast SRC ${opts.stamp}`])
    wpCli(['post', 'meta', 'update', String(sourceId), '_yoast_wpseo_metadesc', opts.srcMeta])
    wpCli([
      'post',
      'meta',
      'update',
      String(sourceId),
      '_yoast_wpseo_opengraph-title',
      `OG SRC ${opts.stamp}`,
    ])
  }

  const translated_data: Record<string, string> = {
    post_title: opts.tgtTitle,
    post_name: opts.tgtSlug,
    post_content: opts.tgtContent ?? `<p>${opts.tgtBody}</p>`,
    post_excerpt: opts.tgtBody,
  }
  if (opts.tgtMeta) {
    translated_data._yoast_wpseo_title = `Yoast TGT ${opts.stamp}`
    translated_data._yoast_wpseo_metadesc = opts.tgtMeta
    translated_data['_yoast_wpseo_opengraph-title'] = `OG TGT ${opts.stamp}`
  }

  const save = await browserJson(page, `${WP_BASE_URL}/wp-json/wptsall/v2/manual-translations`, {
    method: 'POST',
    headers: {
      'X-WP-Nonce': nonce,
      'Content-Type': 'application/json',
    },
    credentials: 'include',
    body: JSON.stringify({
      source_post_id: sourceId,
      relation_id: pair.relationId,
      translated_data,
    }),
  })
  if (![200, 201].includes(save.status)) {
    throw new Error(`manual-translations failed: ${save.status} ${JSON.stringify(save.body)}`)
  }
  const targetId = Number(
    (save.body as { target_id?: number; target_post_id?: number })?.target_id ||
      (save.body as { target_post_id?: number })?.target_post_id ||
      0,
  )
  const targetPost = await browserJson(
    page,
    `${WP_BASE_URL}/wp-json/wp/v2/${encodeURIComponent(restBase)}/${targetId}?context=edit`,
    { headers: { 'X-WP-Nonce': nonce }, credentials: 'include' },
  )
  let targetUrl = String((targetPost.body as { link?: string })?.link || '')
  if (!targetUrl && targetId > 0) {
    const slug = wpCli(['post', 'get', String(targetId), '--field=post_name']) || opts.tgtSlug
    targetUrl = `${WP_BASE_URL}/${pair.pathPrefix}/${publicPathUnderPrefix(postType, slug)}/`
  }
  return { sourceId, targetId, sourceUrl, targetUrl }
}
