/**
 * Tiny OpenAI-ish mock translation provider for SIM auto-translate journeys.
 */
import * as http from 'node:http';
import type { AddressInfo } from 'node:net';

/** Generic mock contract: preserve HTML tags, wrap only text nodes. */
function translatedText(payload: Record<string, unknown>): string {
  const text = String(payload.text ?? '');
  const lang = String(payload.target_lang ?? 'en');
  const wrap = (value: string) => `【${lang}】${value}【/${lang}】`;
  const html = ['rich_html', 'html', 'text/html', 'application/xhtml+xml'].includes(
    String(payload.content_format ?? '').trim().toLowerCase(),
  ) || ['rich_html', 'html'].includes(String(payload.input_type ?? '').trim().toLowerCase())
    || (text.includes('>') && /<[a-zA-Z/!]/.test(text));
  if (!html) return wrap(text);
  const segment = (value: string) => {
    const trimmed = value.trim();
    if (!trimmed) return value;
    const start = value.indexOf(trimmed);
    return value.slice(0, start) + wrap(trimmed) + value.slice(start + trimmed.length);
  };
  let output = '', pending = '', inTag = false, quote = '';
  for (const ch of text) {
    if (inTag) {
      output += ch;
      if (quote && ch === quote) quote = '';
      else if (!quote && (ch === '"' || ch === "'")) quote = ch;
      else if (!quote && ch === '>') inTag = false;
    } else if (ch === '<') {
      output += segment(pending) + ch;
      pending = '';
      inTag = true;
    } else pending += ch;
  }
  return output + segment(pending);
}

export class MockTranslateProvider {
  readonly hits: Array<Record<string, unknown>> = [];
  /** SIM-15: provider calls rejected by an injected HTTP fault. */
  rejectedHits = 0;
  private server: http.Server | null = null;
  port = 0;
  /** SIM-15 fault queue: each translate call consumes one pending fault. */
  private readonly faultQueue: Array<{
    status?: number;
    mode: 'http' | 'empty';
    skip: number;
  }> = [];

  get baseUrl(): string {
    return `http://127.0.0.1:${this.port}`;
  }

  get translateUrl(): string {
    return `${this.baseUrl}/translate`;
  }

  /**
   * SIM-15 lever (doc 24 §四.5 negative journeys): inject N transient
   * faults into the translate endpoint. mode 'http' (default) answers with
   * the given HTTP status; mode 'empty' answers 200 with an empty JSON
   * object (drives the no_translation_output path). `skip` lets the first
   * n calls pass clean so the fault lands on a LATER field (a first-field
   * failure arms the client's component cooldown and blocks the item's
   * remaining fields — the partial path needs the fault on the last one).
   */
  injectFault(
    times: number,
    opts: { status?: number; mode?: 'http' | 'empty'; skip?: number } = {},
  ): void {
    for (let i = 0; i < times; i++) {
      this.faultQueue.push({
        status: opts.status ?? 500,
        mode: opts.mode ?? 'http',
        skip: opts.skip ?? 0,
      });
    }
  }

  async start(): Promise<void> {
    this.server = http.createServer((req, res) => {
      let body = '';
      req.on('data', (chunk) => (body += chunk));
      req.on('end', () => {
        let payload: Record<string, unknown> = {};
        try {
          payload = JSON.parse(body) as Record<string, unknown>;
        } catch {
          /* keep empty */
        }
        // SIM-15 skip lever: while the head fault still has a skip budget,
        // calls pass clean (the fault fires on the (skip+1)-th call).
        const head = this.faultQueue[0] ?? null;
        if (head && head.skip > 0) {
          head.skip -= 1;
          this.hits.push(payload);
          const cleanOut = JSON.stringify({ translated_text: translatedText(payload) });
          res.writeHead(200, {
            'Content-Type': 'application/json',
            'Content-Length': Buffer.byteLength(cleanOut).toString(),
          });
          res.end(cleanOut);
          return;
        }
        const fault = this.faultQueue.shift() ?? null;
        if (fault && fault.mode === 'http') {
          this.rejectedHits += 1;
          this.hits.push(payload);
          const out = JSON.stringify({
            error: { message: 'injected provider fault (sim-15)' },
          });
          res.writeHead(fault.status ?? 500, {
            'Content-Type': 'application/json',
            'Content-Length': Buffer.byteLength(out).toString(),
          });
          res.end(out);
          return;
        }
        this.hits.push(payload);
        const out = JSON.stringify(
          fault && fault.mode === 'empty'
            ? {}
            : { translated_text: translatedText(payload) },
        );
        res.writeHead(200, {
          'Content-Type': 'application/json',
          'Content-Length': Buffer.byteLength(out).toString(),
        });
        res.end(out);
      });
    });
    await new Promise<void>((resolve) => {
      this.server!.listen(0, '127.0.0.1', () => resolve());
    });
    this.port = (this.server.address() as AddressInfo).port;
  }

  async stop(): Promise<void> {
    if (!this.server) return;
    await new Promise<void>((resolve) => this.server!.close(() => resolve()));
    this.server = null;
  }
}

export function translatorTemplate(
  id: string,
  translateUrl: string,
  name = id,
): Record<string, unknown> {
  return {
    id,
    name,
    version: '1.0.0',
    type: 'text_translation',
    auth: { fields: [{ name: 'api_key', required: true }] },
    request: {
      method: 'POST',
      url: translateUrl,
      headers: {
        'Content-Type': 'application/json',
        Authorization: 'Bearer {{auth.api_key}}',
      },
      body: {
        text: '{{input.text}}',
        source_lang: '{{input.source_lang}}',
        target_lang: '{{input.target_lang}}',
      },
    },
    response: { translated_text_path: 'translated_text' },
  };
}
