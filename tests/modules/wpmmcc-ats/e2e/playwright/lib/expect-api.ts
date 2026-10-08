/**
 * Playwright helper: wait for an API call triggered by UI, then AJV-validate
 * request body and/or response JSON against schemas under ./schemas or contracts/.
 */
import { expect, type Page, type Response, type Request } from '@playwright/test'
import Ajv, { type ErrorObject, type ValidateFunction } from 'ajv'
import addFormats from 'ajv-formats'
import fs from 'node:fs'
import path from 'node:path'

const SCHEMA_DIRS = [
  path.resolve(__dirname, '../schemas'),
  path.resolve(__dirname, '../../../../../docs/architecture/current/contracts/schemas'),
]

const ajv = new Ajv({ allErrors: true, strict: false, validateSchema: false })
addFormats(ajv)

const validatorCache = new Map<string, ValidateFunction>()

export type ExpectApiOptions = {
  /** Substring or RegExp matched against request URL */
  path: string | RegExp
  method?: string | RegExp
  /** Optional AJV schema id (filename without path) or absolute/relative path */
  requestSchema?: string
  responseSchema?: string
  status?: number | number[]
  timeoutMs?: number
  /** When true, parse request body as JSON and validate */
  validateRequestBody?: boolean
}

export type ExpectApiResult = {
  response: Response
  request: Request
  status: number
  url: string
  requestJson: unknown | null
  responseJson: unknown | null
}

function resolveSchemaPath(schemaRef: string): string {
  if (path.isAbsolute(schemaRef) && fs.existsSync(schemaRef)) return schemaRef
  const asRel = path.resolve(__dirname, schemaRef)
  if (fs.existsSync(asRel)) return asRel
  for (const dir of SCHEMA_DIRS) {
    const candidates = [
      path.join(dir, schemaRef),
      path.join(dir, `${schemaRef}.json`),
      path.join(dir, `${schemaRef}.schema.json`),
    ]
    for (const c of candidates) {
      if (fs.existsSync(c)) return c
    }
  }
  throw new Error(`Schema not found: ${schemaRef} (searched ${SCHEMA_DIRS.join(', ')})`)
}

export function loadSchema(schemaRef: string): object {
  const file = resolveSchemaPath(schemaRef)
  return JSON.parse(fs.readFileSync(file, 'utf8')) as object
}

export function assertJsonSchema(data: unknown, schemaRef: string, label = 'payload'): void {
  const file = resolveSchemaPath(schemaRef)
  let validate = validatorCache.get(file)
  if (!validate) {
    const schema = loadSchema(schemaRef) as Record<string, unknown>
    // Avoid Ajv "$id already exists" when multiple specs compile the same file.
    const { $id: _id, ...schemaBody } = schema
    validate = ajv.compile(schemaBody)
    validatorCache.set(file, validate)
  }
  const ok = validate(data)
  if (!ok) {
    const errs = (validate.errors || [])
      .map((e: ErrorObject) => `${e.instancePath || '/'} ${e.message}`)
      .join('; ')
    throw new Error(`${label} failed schema ${path.basename(file)}: ${errs}`)
  }
}

function urlMatches(url: string, pattern: string | RegExp): boolean {
  if (typeof pattern === 'string') return url.includes(pattern)
  return pattern.test(url)
}

function parseMaybeJson(raw: string | null): unknown | null {
  if (raw == null || raw === '') return null
  try {
    return JSON.parse(raw)
  } catch {
    return null
  }
}

/**
 * Start waiting for a matching API response *before* the UI action that triggers it.
 *
 * @example
 * const pending = expectApi(page, { path: '/api/domain-tokens/upsert', responseSchema: 'domain-tokens-upsert.response' })
 * await page.getByTestId('sites-modal-save').click()
 * const { responseJson } = await pending
 */
export function expectApi(page: Page, opts: ExpectApiOptions): Promise<ExpectApiResult> {
  const methodOpt = opts.method || 'POST'
  const timeout = opts.timeoutMs ?? 60_000
  const statuses = opts.status == null ? null : Array.isArray(opts.status) ? opts.status : [opts.status]

  return page
    .waitForResponse(
      (res) => {
        if (!urlMatches(res.url(), opts.path)) return false
        const reqMethod = res.request().method().toUpperCase()
        if (methodOpt instanceof RegExp) {
          if (!methodOpt.test(reqMethod)) return false
        } else if (reqMethod !== String(methodOpt).toUpperCase()) {
          return false
        }
        if (statuses && !statuses.includes(res.status())) return false
        return true
      },
      { timeout },
    )
    .then(async (response) => {
      const request = response.request()
      const status = response.status()
      const url = response.url()
      const requestJson = parseMaybeJson(request.postData())
      let responseJson: unknown | null = null
      try {
        responseJson = await response.json()
      } catch {
        responseJson = parseMaybeJson(await response.text().catch(() => null))
      }

      if (opts.validateRequestBody !== false && opts.requestSchema) {
        expect(requestJson, `request body for ${url}`).not.toBeNull()
        assertJsonSchema(
          requestJson,
          opts.requestSchema,
          `request ${request.method()} ${opts.path}`,
        )
      }
      if (opts.responseSchema) {
        expect(responseJson, `response body for ${url}`).not.toBeNull()
        assertJsonSchema(
          responseJson,
          opts.responseSchema,
          `response ${request.method()} ${opts.path}`,
        )
      }

      return { response, request, status, url, requestJson, responseJson }
    })
}

/** Convenience: assert a bare object against a schema (e.g. browserFetch results). */
export function expectSchema(data: unknown, schemaRef: string, label?: string): void {
  assertJsonSchema(data, schemaRef, label)
}
