/**
 * Log oracle for the SIM lane — turns the client's JSONL event log into
 * test assertions ("log as oracle").
 *
 * Windowing: the lane-owned client starts with an empty log file per run
 * and specs execute serially, so a journey can delimit its own window.
 * The mark is an ERROR-level probe event posted through the REAL
 * /api/logs/client ingest endpoint (`kind: "oracle_mark"`): error-level
 * writes flush the log writer immediately, so the probe doubles as
 * (a) the window's left edge and (b) a forced flush of any buffered
 * info-level audit events (the writer batches info lines until an 8 KB
 * threshold — without the flush they would sit invisible in the buffer
 * while this oracle reads the file; recorded as Wave-1 finding FL-1).
 * Oracle marks are exempt from the warn/error radar (matched on
 * detail.kind); real webui.client_error reports still fail it.
 */
import { expect } from '@playwright/test';
import { apiPost, type ApiResult } from './sim-client';

export interface LogEvent {
  ts_ms: number;
  level: string;
  event: string;
  detail: Record<string, unknown>;
  raw: string;
}

export interface LogMark {
  ts_ms: number;
  raw: string | null;
}

interface LogsPageData {
  lines?: string[];
  has_more?: boolean;
  next_before_ts_ms?: number | null;
}

const PAGE_LIMIT = 500;
const MAX_PAGES = 40;
const ORACLE_MARK_KIND = 'oracle_mark';

function isWarnOrError(level: string): boolean {
  const l = level.toLowerCase();
  return l === 'warn' || l === 'warning' || l === 'error';
}

export function parseLogEvent(raw: string): LogEvent | null {
  try {
    const v = JSON.parse(raw) as {
      ts_ms?: number;
      level?: string;
      event?: string;
      detail?: Record<string, unknown>;
    };
    if (typeof v.event !== 'string' || typeof v.level !== 'string') {
      return null;
    }
    return {
      ts_ms: Number(v.ts_ms ?? 0),
      level: v.level,
      event: v.event,
      detail: (v.detail ?? {}) as Record<string, unknown>,
      raw,
    };
  } catch {
    return null;
  }
}

async function fetchLogsPage(
  request: Parameters<typeof apiPost>[0],
  opts: { before_ts_ms?: number; event_prefix?: string; min_level?: string; limit?: number },
): Promise<LogsPageData> {
  const res: ApiResult<LogsPageData> = await apiPost(request, '/api/logs/recent', {
    limit: opts.limit ?? PAGE_LIMIT,
    ...(opts.before_ts_ms !== undefined ? { before_ts_ms: opts.before_ts_ms } : {}),
    ...(opts.event_prefix ? { event_prefix: opts.event_prefix } : {}),
    ...(opts.min_level ? { min_level: opts.min_level } : {}),
  });
  expect(res.success, `logs/recent failed: ${JSON.stringify(res.raw)}`).toBe(true);
  return res.data;
}

function isOracleMark(e: LogEvent): boolean {
  return e.event === 'webui.client_error' && e.detail?.kind === ORACLE_MARK_KIND;
}

/**
 * Post an oracle mark (error-level, real ingest endpoint) and return the
 * newest line as the window's left edge. The error-level write flushes
 * every info event buffered before it.
 */
export async function markLogStart(
  request: Parameters<typeof apiPost>[0],
): Promise<LogMark> {
  const probe = await apiPost(request, '/api/logs/client', {
    kind: ORACLE_MARK_KIND,
    message: 'sim-log-oracle window mark',
  });
  expect(probe.success, `logs/client mark failed: ${JSON.stringify(probe.raw)}`).toBe(true);
  const page = await fetchLogsPage(request, { limit: 1 });
  const lines = page.lines ?? [];
  if (lines.length === 0) {
    return { ts_ms: 0, raw: null };
  }
  const raw = lines[lines.length - 1]!;
  const parsed = parseLogEvent(raw);
  return { ts_ms: parsed?.ts_ms ?? 0, raw };
}

/** Everything appended to the client log after `mark`, in file order. */
export async function collectLogWindow(
  request: Parameters<typeof apiPost>[0],
  mark: LogMark,
): Promise<LogEvent[]> {
  // Flush probe: the writer batches info-level events (8 KB threshold);
  // an error-level mark through the real ingest endpoint flushes the
  // buffer so the journey's audit events are on disk before we read.
  const flush = await apiPost(request, '/api/logs/client', {
    kind: ORACLE_MARK_KIND,
    message: 'sim-log-oracle window flush',
  });
  expect(flush.success, `logs/client flush failed: ${JSON.stringify(flush.raw)}`).toBe(true);
  const collected: string[] = [];
  let cursor: number | undefined;
  for (let i = 0; i < MAX_PAGES; i++) {
    const page = await fetchLogsPage(request, {
      ...(cursor !== undefined ? { before_ts_ms: cursor } : {}),
    });
    const lines = page.lines ?? [];
    collected.unshift(...lines);
    const next = page.next_before_ts_ms;
    if (!page.has_more || next == null || (mark.ts_ms > 0 && next <= mark.ts_ms)) {
      break;
    }
    cursor = next;
  }
  return collected
    .map(parseLogEvent)
    .filter((e): e is LogEvent => e !== null)
    .filter((e) =>
      mark.raw === null
        ? true
        : e.ts_ms > mark.ts_ms || (e.ts_ms === mark.ts_ms && e.raw !== mark.raw),
    );
}

export interface ExpectedStep {
  event: string | RegExp;
  /** Subset match on detail fields (deep equality per key). */
  detail?: Record<string, unknown>;
}

function detailMatches(detail: Record<string, unknown>, want: Record<string, unknown>): boolean {
  return Object.entries(want).every(
    ([k, v]) => JSON.stringify(detail[k]) === JSON.stringify(v),
  );
}

function describeEvents(events: LogEvent[], around: number): string {
  const slice = events.slice(Math.max(0, around - 3), around + 4);
  return slice.map((e) => `${e.level} ${e.event}`).join(' | ') || '(empty window)';
}

/** In-order presence check: every expected step must appear, after the previous one. */
export function expectEventSequence(
  events: LogEvent[],
  expected: ExpectedStep[],
  label: string,
): void {
  let cursor = 0;
  for (const step of expected) {
    const idx = events.findIndex(
      (e, i) =>
        i >= cursor &&
        (typeof step.event === 'string' ? e.event === step.event : step.event.test(e.event)) &&
        (!step.detail || detailMatches(e.detail, step.detail)),
    );
    expect(
      idx,
      `[${label}] expected event ${String(step.event)} (at/after #${cursor}) in window; around cursor: ${describeEvents(events, cursor)}`,
    ).toBeGreaterThanOrEqual(0);
    cursor = idx + 1;
  }
}

export function eventsNamed(events: LogEvent[], event: string): LogEvent[] {
  return events.filter((e) => e.event === event);
}

export function eventsWithPrefix(events: LogEvent[], prefix: string): LogEvent[] {
  return events.filter((e) => e.event.startsWith(prefix));
}

/**
 * The radar: fail on any warn/error event the journey did not explicitly
 * allow. Oracle marks (this module's own probes) are always exempt; real
 * webui.client_error reports are NOT — they fail the radar.
 */
export function expectNoUnexpected(
  events: LogEvent[],
  label: string,
  allowEventNames: string[] = [],
): LogEvent[] {
  const allowed = new Set(allowEventNames);
  const offenders = events.filter(
    (e) => isWarnOrError(e.level) && !allowed.has(e.event) && !isOracleMark(e),
  );
  expect(
    offenders.map((e) => `${e.level} ${e.event} ${JSON.stringify(e.detail)}`),
    `[${label}] unexpected warn/error events in journey window`,
  ).toEqual([]);
  return offenders;
}

/** Whole-window dump for triage reports (never an assertion). */
export function summarizeWindow(events: LogEvent[]): string {
  return events.map((e) => `${e.level}\t${e.event}\t${JSON.stringify(e.detail)}`).join('\n');
}
