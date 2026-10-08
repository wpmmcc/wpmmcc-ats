/**
 * Mock WPTSALL Server (HTTP :8787)
 *
 * Simulates the real server (www.wpmm.cc) for local E2E testing.
 * Handles OAuth flow, component download, heartbeat, domains, and components.
 *
 * Component templates returned by this server:
 *   ID: mock-bearer-v1   – Auth: Bearer token via "api_key", translates via :9090
 *   ID: mock-editable-v1 – Tests editable_params whitelist (uses /mock/check-overrides)
 */

import * as http from 'http';

// ─── Constants ───────────────────────────────────────────────────────────────

export const MOCK_SESSION_TOKEN = 'mock-sess-wptsall-2026';
export const MOCK_SERVER_PORT   = 8787;

/**
 * RSA-2048 public key used by the mock server's signing-public-key endpoint.
 * The client fetches this before verifying component signatures.  When
 * WPTSALL_SKIP_SIGNATURE_CHECK=true the actual signature check is bypassed,
 * but the key must still be present and correctly shaped.
 */
const MOCK_PUBLIC_KEY_PEM = `-----BEGIN PUBLIC KEY-----
MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAw4vHqyDTXmOWVWNd+/Tb
xY0qoBAifbZ7obl5z+ly2WB0x7PLTlmasfuX6udImgjyIYpv5/zAxdZtQTqzWF4Y
hlIpnx4X8UIv2LWTAUvm1pXxYtQ00kXuBKHMfbGVdQXXrVaQyGBT3TnlR0FpnRFo
DEoRbD93VnZ0LTg/+j83ET2wVZta4C0MiVGxBUvsPTlFXO7URtSkg457WYPCRnLR
alL3FJVVTH71lYz4EAc6mbUte4PIr3S4mAT5c84DERw5/q6Ds4oeYVbTOrZDCCEf
m4urKhYmJTTYa1rilndRsUys82A5jJGE8t044UjdRUoQAxzgI3x1bCeg+hreS9Ja
TwIDAQAB
-----END PUBLIC KEY-----`;

/** The component template served by the mock server. */
export const MOCK_BEARER_TEMPLATE = {
  id:      'mock-bearer-v1',
  name:    'Mock Bearer Token Translator (Local Test)',
  version: '1.0.0',
  type:    'text_translation',
  auth: {
    fields: [{ name: 'api_key', required: true }],
  },
  request: {
    method: 'POST',
    url:    'http://127.0.0.1:9090/api/v1/translate/text',
    headers: {
      'Content-Type':  'application/json',
      'Authorization': 'Bearer {{auth.api_key}}',
    },
    body: {
      text:        '{{input.text}}',
      source_lang: '{{input.source_lang}}',
      target_lang: '{{input.target_lang}}',
    },
  },
  response: {
    translated_text_path: 'translated_text',
  },
};

/** Template used to verify editable_params whitelist behavior in WebUI bindings. */
export const MOCK_EDITABLE_TEMPLATE = {
  id:      'mock-editable-v1',
  name:    'Mock Editable Params Translator (Local Test)',
  version: '1.0.0',
  type:    'text_translation',
  auth: {
    fields: [{ name: 'api_key', required: true }],
  },
  request: {
    method: 'POST',
    url:    'http://127.0.0.1:8787/mock/check-overrides',
    headers: {
      'Content-Type':  'application/json',
      'Authorization': 'Bearer {{auth.api_key}}',
      'X-Allow':       'base-allow',
      'X-Blocked':     'base-blocked',
    },
    body: {
      text:        '{{input.text}}',
      source_lang: '{{input.source_lang}}',
      target_lang: '{{input.target_lang}}',
      note:        'base-note',
    },
  },
  response: {
    translated_text_path: 'translated_text',
  },
  editable_params: [
    { path: 'request.body.text' },
    { path: 'request.headers.X-Allow' },
  ],
};

// ─── Helpers ─────────────────────────────────────────────────────────────────

function parseQueryString(url: string): Record<string, string> {
  const qi = url.indexOf('?');
  if (qi < 0) return {};
  const params: Record<string, string> = {};
  for (const pair of url.slice(qi + 1).split('&')) {
    if (!pair) continue;
    const eq = pair.indexOf('=');
    const k  = eq >= 0 ? pair.slice(0, eq) : pair;
    const v  = eq >= 0 ? pair.slice(eq + 1) : '';
    try {
      params[decodeURIComponent(k)] = decodeURIComponent(v.replace(/\+/g, ' '));
    } catch {
      params[k] = v;
    }
  }
  return params;
}

function sendJson(res: http.ServerResponse, data: unknown, status = 200): void {
  const body = JSON.stringify(data);
  res.writeHead(status, {
    'Content-Type':                'application/json',
    'Content-Length':              Buffer.byteLength(body).toString(),
    'Access-Control-Allow-Origin': '*',
  });
  res.end(body);
}

function readBody(req: http.IncomingMessage): Promise<string> {
  return new Promise((resolve) => {
    const chunks: Buffer[] = [];
    req.on('data', (chunk: Buffer) => chunks.push(chunk));
    req.on('end',  ()              => resolve(Buffer.concat(chunks).toString('utf-8')));
    req.on('error',()              => resolve(''));
  });
}

// ─── Request handler ─────────────────────────────────────────────────────────

async function handleRequest(
  req: http.IncomingMessage,
  res: http.ServerResponse,
): Promise<void> {
  const url      = req.url ?? '/';
  const pathname = url.split('?')[0];
  const query    = parseQueryString(url);

  // Consume request body (prevents ECONNRESET on the Rust client side)
  const rawBody = await readBody(req);

  // CORS preflight
  if (req.method === 'OPTIONS') {
    res.writeHead(204, {
      'Access-Control-Allow-Origin':  '*',
      'Access-Control-Allow-Methods': '*',
      'Access-Control-Allow-Headers': '*',
    });
    res.end();
    return;
  }

  console.log(`[mock-server] ${req.method} ${pathname}`);

  // ── OAuth authorize page ────────────────────────────────────────────────
  if (req.method === 'GET' && pathname === '/oauth/authorize') {
    const redirectUri = query['redirect_uri'] ?? '';
    const state       = query['state']        ?? '';
    const code        = 'mock-auth-code-2026';
    const callbackUrl = `${redirectUri}?code=${encodeURIComponent(code)}&state=${encodeURIComponent(state)}`;

    // Auto-redirect the popup browser back to the client's OAuth callback
    const html = [
      '<!doctype html>',
      '<html><head>',
      `<meta http-equiv="refresh" content="0; url=${callbackUrl}">`,
      '<style>body{font-family:sans-serif;padding:20px}</style>',
      '</head><body>',
      '<p>Mock OAuth: redirecting to callback…</p>',
      `<script>window.location.replace(${JSON.stringify(callbackUrl)});</script>`,
      '</body></html>',
    ].join('\n');

    res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
    res.end(html);
    return;
  }

  // ── OAuth token exchange ────────────────────────────────────────────────
  // The Rust client calls request_json_encrypted<OAuthTokenData> which parses
  // the raw (decrypted) body directly as OAuthTokenData — flat format required.
  if (req.method === 'POST' && pathname === '/api/v1/oauth/token') {
    sendJson(res, { session_token: MOCK_SESSION_TOKEN, expires_in: 86400 });
    return;
  }

  // ── Heartbeat (GET or POST) ─────────────────────────────────────────────
  if (pathname === '/api/v1/client/heartbeat') {
    sendJson(res, { success: true, data: { alive: true, relogin_required: false } });
    return;
  }

  // ── Domains list ───────────────────────────────────────────────────────
  if (req.method === 'GET' && pathname === '/api/v1/client/domains') {
    sendJson(res, { success: true, data: { items: [] } });
    return;
  }

  // ── Components list ────────────────────────────────────────────────────
  if (req.method === 'GET' && pathname === '/api/v1/client/components') {
    sendJson(res, { success: true, data: { items: [] } });
    return;
  }

  // ── Component download: mock-bearer-v1 ─────────────────────────────────
  if (
    req.method === 'GET' &&
    pathname === '/api/v1/client/components/mock-bearer-v1/download'
  ) {
    sendJson(res, {
      success: true,
      data: {
        component_id:  'mock-bearer-v1',
        version:       '1.0.0',
        owner_type:    'user',
        template_json: MOCK_BEARER_TEMPLATE,
        // No signature — WPTSALL_SKIP_SIGNATURE_CHECK=true handles this
      },
    });
    return;
  }

  // ── Component download: mock-editable-v1 ───────────────────────────────
  if (
    req.method === 'GET' &&
    pathname === '/api/v1/client/components/mock-editable-v1/download'
  ) {
    sendJson(res, {
      success: true,
      data: {
        component_id:  'mock-editable-v1',
        version:       '1.0.0',
        owner_type:    'user',
        template_json: MOCK_EDITABLE_TEMPLATE,
      },
    });
    return;
  }

  // ── Local mock endpoint used by editable_params whitelist tests ────────
  if (req.method === 'POST' && pathname === '/mock/check-overrides') {
    let text = '';
    let targetLang = '';
    try {
      const body = JSON.parse(rawBody || '{}') as Record<string, unknown>;
      text = typeof body.text === 'string' ? body.text : '';
      targetLang = typeof body.target_lang === 'string' ? body.target_lang : '';
    } catch {
      // Keep fallback empty fields.
    }

    const xAllow = Array.isArray(req.headers['x-allow'])
      ? req.headers['x-allow'][0]
      : (req.headers['x-allow'] ?? '');
    const xBlocked = Array.isArray(req.headers['x-blocked'])
      ? req.headers['x-blocked'][0]
      : (req.headers['x-blocked'] ?? '');

    sendJson(res, {
      translated_text: `text=${text}|target=${targetLang}|x_allow=${xAllow}|x_blocked=${xBlocked}`,
    });
    return;
  }

  // ── Vendors list ───────────────────────────────────────────────────────
  if (req.method === 'GET' && pathname === '/api/v1/client/vendors') {
    sendJson(res, { success: true, data: { items: [] } });
    return;
  }

  // ── Signing public key ─────────────────────────────────────────────────────
  // The client fetches this before verifying component download signatures.
  // Must return `public_key_pem` (not `public_key`).  Actual signature
  // verification is skipped when WPTSALL_SKIP_SIGNATURE_CHECK=true.
  if (req.method === 'GET' && pathname === '/api/v1/client/signing-public-key') {
    sendJson(res, { success: true, data: { public_key_pem: MOCK_PUBLIC_KEY_PEM, key_id: 'mock-key-2026' } });
    return;
  }

  // ── Component detail (catalog lookup) ─────────────────────────────────────
  // Called by the client when a user creates/edits a component binding in the
  // WebUI.  Returns minimal ComponentManageData for the two mock templates.
  const compDetailMatch = /^\/api\/v1\/components\/(mock-bearer-v1|mock-editable-v1)$/.exec(pathname);
  if (req.method === 'GET' && compDetailMatch) {
    const cid = compDetailMatch[1];
    const isEditable = cid === 'mock-editable-v1';
    sendJson(res, {
      success: true,
      data: {
        component: {
          id:         cid,
          name:       isEditable ? 'Mock Editable Params' : 'Mock Bearer Token Translator',
          version:    '1.0.0',
          type:       'text_translation',
          owner_type: 'user',
        },
      },
    });
    return;
  }

  // ── Logout ──────────────────────────────────────────────────────────────
  if (req.method === 'POST' && pathname === '/api/v1/client/logout') {
    sendJson(res, { success: true, data: {} });
    return;
  }

  // ── Fallback 404 ────────────────────────────────────────────────────────
  console.warn(`[mock-server] 404: ${req.method} ${pathname}`);
  sendJson(res, { success: false, error: 'not_found', path: pathname }, 404);
}

// ─── Public API ───────────────────────────────────────────────────────────────

export async function startMockServer(port = MOCK_SERVER_PORT): Promise<http.Server> {
  const server = http.createServer((req, res) => {
    handleRequest(req, res).catch((err) => {
      console.error('[mock-server] handler error:', err);
      try {
        res.writeHead(500);
        res.end();
      } catch { /* already sent */ }
    });
  });

  return new Promise<http.Server>((resolve, reject) => {
    server.once('error', reject);
    server.listen(port, '127.0.0.1', () => {
      console.log(`[mock-server] listening on http://127.0.0.1:${port}`);
      resolve(server);
    });
  });
}

export async function stopMockServer(server: http.Server): Promise<void> {
  return new Promise<void>((resolve) => {
    server.close(() => resolve());
  });
}
