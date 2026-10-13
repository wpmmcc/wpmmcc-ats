/**
 * Mock WordPress plugin sites for the SIM lane.
 *
 * Two faithful mock plugins are provided, both speaking the exact wire
 * contracts the client expects:
 *
 *  - `MockWpmmccSite`  — the WPMMCC sync plugin REST surface
 *    (`/wp-json/wpmmcc/v1/...`): signed `/sync/ping` identity, one-time
 *    pairing-code `/sync/handshake`, and the HMAC-authenticated
 *    digest/pull/push/media-chunk/reconcile-digest endpoints with full
 *    `X-WPMMCC-Signature` verification (mirroring
 *    `class-wpmmcc-rest-middleware.php`).
 *
 *  - `MockAtsSite` — the ATS (wptsall) plugin client surface
 *    (`/wp-json/wptsall/v2/{secret}/client/...`): signed identity
 *    ping/validate-token, site-relations, rules, paginated content, and
 *    the translation-callback receiver (records `Idempotency-Key`).
 *
 * All plaintext responses carry `X-WPTSALL-Transport: plaintext` plus
 * `X-WPTSALL-Response-Signature` (HMAC-SHA256 over the body with the
 * HKDF signing key derived from the WP client token) — the client rejects
 * unsigned plaintext on plain HTTP.
 */
import * as crypto from 'node:crypto';
import * as http from 'node:http';

// ---------------------------------------------------------------------------
// Crypto mirrors (must stay byte-identical to the PHP/Rust implementations)
// ---------------------------------------------------------------------------

export function sha256Hex(data: Buffer | string): string {
  return crypto.createHash('sha256').update(data).digest('hex');
}

/**
 * HKDF-SHA256 shared secret from a one-time pairing secret:
 *   ikm  = pairing_secret (raw ASCII bytes of the 32-char hex string)
 *   salt = min(client_uuid, site_uuid) + "|1"
 *   info = "wpmmcc-peer-hmac-v1", len = 32
 * Mirrors PHP `hash_hkdf` in class-wpmmcc-rest-handshake.php §6.
 */
export function derivePeerSharedSecret(
  pairingSecret: string,
  clientUuid: string,
  siteUuid: string,
): Buffer {
  const salt = Buffer.from(`${[clientUuid, siteUuid].sort()[0]}|1`, 'utf8');
  const info = Buffer.from('wpmmcc-peer-hmac-v1', 'utf8');
  return Buffer.from(crypto.hkdfSync('sha256', Buffer.from(pairingSecret, 'utf8'), salt, info, 32));
}

/**
 * Response signing key = HKDF-SHA256(ikm = wp client token,
 * salt = "request-signing", info = "wptsall-signing-v1", len = 32).
 * Mirrors client `crypto::derive_signing_key`.
 */
export function deriveSigningKey(wpClientToken: string): Buffer {
  const salt = Buffer.from('request-signing', 'utf8');
  const info = Buffer.from('wptsall-signing-v1', 'utf8');
  return Buffer.from(
    crypto.hkdfSync('sha256', Buffer.from(wpClientToken, 'utf8'), salt, info, 32),
  );
}

/** `X-WPTSALL-Response-Signature`: base64url(no pad) HMAC-SHA256 over the body. */
export function signPlaintextBody(wpClientToken: string, body: string): string {
  return crypto
    .createHmac('sha256', deriveSigningKey(wpClientToken))
    .update(body, 'utf8')
    .digest('base64url');
}

/** WPMMCC HMAC over the canonical string-to-sign (hex, 64 chars). */
export function computeWpmmccSignature(stringToSign: string, sharedSecret: Buffer): string {
  return crypto.createHmac('sha256', sharedSecret).update(stringToSign, 'utf8').digest('hex');
}

// Self-check against the PHP reference vector (generated with PHP 8
// hash_hkdf on the plugin construction). If this ever fails, the Node
// mirror has drifted from the plugin contract.
{
  const vector = derivePeerSharedSecret(
    '9f1a2b3c4d5e6f708192a3b4c5d6e7f8',
    '11111111-1111-1111-1111-111111111111',
    '22222222-2222-2222-2222-222222222222',
  );
  const expected = '7cd6844ad9f65531518ede47c92422ec411ff6b5cb8bf8a20b8c1ecd8b046b25';
  if (vector.toString('hex') !== expected) {
    throw new Error(
      `mock-wp-site HKDF drift: got ${vector.toString('hex')} want ${expected}`,
    );
  }
}

// ---------------------------------------------------------------------------
// HTTP plumbing
// ---------------------------------------------------------------------------

interface ParsedRequest {
  method: string;
  path: string;
  headers: http.IncomingHttpHeaders;
  body: Buffer;
}

type Handler = (
  req: ParsedRequest,
  res: http.ServerResponse,
) => Promise<void> | void;

function readBody(req: http.IncomingMessage): Promise<Buffer> {
  return new Promise((resolve) => {
    const chunks: Buffer[] = [];
    req.on('data', (chunk) => chunks.push(chunk as Buffer));
    req.on('end', () => resolve(Buffer.concat(chunks)));
    req.on('error', () => resolve(Buffer.concat(chunks)));
  });
}

function parseUrl(rawUrl: string): { pathname: string; query: URLSearchParams } {
  const idx = rawUrl.indexOf('?');
  const pathname = idx >= 0 ? rawUrl.slice(0, idx) : rawUrl;
  const query = new URLSearchParams(idx >= 0 ? rawUrl.slice(idx + 1) : '');
  return { pathname, query };
}

/** Send a client-protocol signed plaintext JSON response. */
function sendSignedJson(
  res: http.ServerResponse,
  wpClientToken: string,
  data: unknown,
  status = 200,
): void {
  const body = JSON.stringify(data);
  res.writeHead(status, {
    'Content-Type': 'application/json',
    'Content-Length': Buffer.byteLength(body).toString(),
    'X-WPTSALL-Transport': 'plaintext',
    'X-WPTSALL-Response-Signature': signPlaintextBody(wpClientToken, body),
  });
  res.end(body);
}

/** WP REST error envelope (what the client matches `rest_no_route` on). */
function restNoRoute(res: http.ServerResponse): void {
  const body = JSON.stringify({
    code: 'rest_no_route',
    message: 'No route was found matching the URL and request method.',
    data: { status: 404 },
  });
  res.writeHead(404, {
    'Content-Type': 'application/json',
    'Content-Length': Buffer.byteLength(body).toString(),
  });
  res.end(body);
}

// ---------------------------------------------------------------------------
// MockWpmmccSite — the WPMMCC sync plugin
// ---------------------------------------------------------------------------

export interface WpmmccPostSeed {
  /** Canonical GUID (the sync identity across sites). */
  guid: string;
  /** Source-site post ID (drives the digest keyset cursor). */
  sourceId: number;
  title: string;
  content: string;
  excerpt?: string;
  postType?: string;
  status?: string;
  fingerprint?: string;
  taxonomies?: Record<string, unknown>;
  metaFields?: Record<string, unknown>;
  /** Optional media manifest entry; `remoteUrl` should point at this site. */
  media?: { remoteUrl: string; filename: string; bytes: Buffer };
}

/** R1: deterministic, append-only scale fixture. Boundary matrix: 100/101/201. */
export function seedPosts(site: MockWpmmccSite, count: number): WpmmccPostSeed[] {
  if (!Number.isSafeInteger(count) || count < 0) {
    throw new Error('seedPosts count must be a nonnegative safe integer');
  }
  if (count === 0) return [];
  const first = Math.max(0, ...site.posts.map((post) => post.sourceId)) + 1;
  if (!Number.isSafeInteger(first) || !Number.isSafeInteger(first + count - 1)) {
    throw new Error('seedPosts source ID overflow');
  }
  const seeds = Array.from({ length: count }, (_, index) => {
    const id = first + index;
    return {
      guid: `${site.siteUuid}-scale-${id}`, sourceId: id,
      title: `Scale post ${id}`, content: `<p>Scale body ${id}</p>`, excerpt: `Scale excerpt ${id}`,
    };
  });
  const existing = new Set(site.posts.map((post) => post.guid));
  if (seeds.some((post) => existing.has(post.guid))) throw new Error('seedPosts duplicate GUID');
  site.posts.push(...seeds);
  return seeds;
}

interface WpmmccPeerRecord {
  originUuid: string;
  sharedSecret: Buffer;
  direction: string;
  pairedAt: number;
}

export class MockWpmmccSite {
  readonly siteUuid: string;
  readonly siteName: string;
  readonly routeSecret: string;
  readonly wpClientToken: string;
  baseUrl = '';

  private server: http.Server | null = null;
  readonly posts: WpmmccPostSeed[];
  private readonly peers = new Map<string, WpmmccPeerRecord>();
  private readonly pairingCodes = new Map<
    string,
    { expiresAt: number; used: boolean }
  >();

  // Wire evidence recorded for spec assertions.
  readonly receivedPackets: Array<Record<string, unknown>> = [];
  receivedChunks = 0;
  /** SIM-16: chunk uploads seen but rejected by an injected fault. */
  rejectedMediaChunks = 0;
  receivedHandshakes: Array<Record<string, unknown>> = [];
  pingCount = 0;
  hmacFailures: Array<{ path: string; sender: string }> = [];
  readonly seenPaths: string[] = [];
  readonly digestRequests: Array<{ lastSeenId: number; limit: number; ids: number[] }> = [];

  // SIM-14 (doc 24 §四.3) data-flow deep-dive levers + evidence.
  /** Packets seen by /sync/push but REJECTED by an injected fault. */
  rejectedPackets = 0;
  /** Completed media assemblies under the REAL chunk-completion contract. */
  readonly mediaAssemblies: Array<{
    filename: string;
    sha256: string;
    sizeBytes: number;
  }> = [];
  /** In-flight chunk buffers keyed by file_uuid (real assembly contract). */
  private readonly chunkBuffers = new Map<
    string,
    { filename: string; total: number; parts: Map<number, Buffer> }
  >();
  /** Fault-injection queue per sync route, consumed one per request. */
  private readonly faultQueue = new Map<
    string,
    Array<{ status: number; code: string; message: string }>
  >();
  /** Post revision counter driving fingerprint bumps on mutation/trash. */
  private readonly postRevisions = new Map<string, number>();

  constructor(opts: {
    siteUuid: string;
    siteName: string;
    routeSecret: string;
    wpClientToken: string;
    posts?: WpmmccPostSeed[];
  }) {
    this.siteUuid = opts.siteUuid;
    this.siteName = opts.siteName;
    this.routeSecret = opts.routeSecret;
    this.wpClientToken = opts.wpClientToken;
    this.posts = opts.posts ?? [];
  }

  /** Generate a one-time pairing code exactly like the site admin page. */
  generatePairingCode(ttlSeconds = 600): string {
    const code = crypto.randomBytes(16).toString('hex');
    this.pairingCodes.set(code, {
      expiresAt: Date.now() + ttlSeconds * 1000,
      used: false,
    });
    return code;
  }

  async start(): Promise<void> {
    const server = http.createServer((req, res) => {
      void this.handle(req, res);
    });
    await new Promise<void>((resolve) => {
      server.listen(0, '127.0.0.1', () => resolve());
    });
    const addr = server.address() as import('node:net').AddressInfo;
    this.baseUrl = `http://127.0.0.1:${addr.port}`;
    this.server = server;
  }

  /**
   * Attach a servable media asset to one post after the site has started
   * (the remote URL must point at this live server). Any `{{MEDIA}}`
   * placeholder in the post content is replaced with the site base URL.
   */
  seedMedia(guid: string, filename: string, bytes: Buffer): void {
    const post = this.posts.find((p) => p.guid === guid);
    if (!post) {
      throw new Error(`seedMedia: unknown post guid ${guid}`);
    }
    const remoteUrl = `${this.baseUrl}/wp-content/uploads/${filename}`;
    post.media = { remoteUrl, filename, bytes };
    post.content = post.content.replaceAll('{{MEDIA}}', this.baseUrl);
  }

  async stop(): Promise<void> {
    const server = this.server;
    if (!server) return;
    await new Promise<void>((resolve) => server.close(() => resolve()));
    this.server = null;
  }

  // -------------------------------------------------------------------------
  // SIM-14 (doc 24 §四.3) data-flow levers — field mutation, trash
  // tombstones, and fault injection for the self-healing journey.
  // -------------------------------------------------------------------------

  private bumpFingerprint(post: WpmmccPostSeed): void {
    const rev = (this.postRevisions.get(post.guid) ?? 0) + 1;
    this.postRevisions.set(post.guid, rev);
    // The real plugin re-hashes content on save; the mock mirrors that by
    // deriving the fingerprint from guid + revision.
    post.fingerprint = `fp-${post.guid}-r${rev}`;
  }

  /**
   * Mutate a post's fields at the source. Bumps the source fingerprint so
   * the next digest reports a change (the change-detection contract).
   */
  mutatePost(
    guid: string,
    patch: { title?: string; content?: string; excerpt?: string },
  ): void {
    const post = this.posts.find((p) => p.guid === guid);
    if (!post) {
      throw new Error(`mutatePost: unknown post guid ${guid}`);
    }
    if (patch.title !== undefined) post.title = patch.title;
    if (patch.content !== undefined) post.content = patch.content;
    if (patch.excerpt !== undefined) post.excerpt = patch.excerpt;
    this.bumpFingerprint(post);
  }

  /**
   * Trash a post at the source (status → trash + fingerprint bump, the
   * real plugin's trash semantics). The next digest reports the trash
   * status and the client relays a tombstone packet to the target.
   */
  trashPost(guid: string): void {
    const post = this.posts.find((p) => p.guid === guid);
    if (!post) {
      throw new Error(`trashPost: unknown post guid ${guid}`);
    }
    post.status = 'trash';
    this.bumpFingerprint(post);
  }

  /**
   * HARD-delete a post at the source: the post vanishes from digest, pull,
   * AND the reconcile-known set. The client's reconcile rotation then
   * reports the entity 'missing' at the source and relays a 'delete'
   * tombstone to the target (distinct from the trash path above).
   */
  deletePost(guid: string): void {
    const idx = this.posts.findIndex((p) => p.guid === guid);
    if (idx < 0) {
      throw new Error(`deletePost: unknown post guid ${guid}`);
    }
    this.posts.splice(idx, 1);
  }

  /**
   * Inject N transient faults for a sync data-plane route. Each matching
   * request consumes one fault and answers with the given HTTP status and
   * error body (e.g. 500 server error, 401 signature rejection). Used to
   * prove the client's next-run self-healing (doc 24 §四.3 故障注入恢复).
   * SIM-16 adds 'media-chunk' — a rejected chunk makes the pair-lane
   * media transfer fail for the item; the safe cursor freezes and the
   * next run re-transfers the whole file (fault consumed) and converges.
   */
  injectFault(
    route: 'digest' | 'push' | 'media-chunk',
    times: number,
    opts: { status?: number; code?: string; message?: string } = {},
  ): void {
    const queue = this.faultQueue.get(route) ?? [];
    for (let i = 0; i < times; i++) {
      queue.push({
        status: opts.status ?? 500,
        code: opts.code ?? 'wpmmcc_error',
        message: opts.message ?? 'injected fault (sim-14)',
      });
    }
    this.faultQueue.set(route, queue);
  }

  /** Consume one injected fault for `route`, if any is pending. */
  private consumeFault(route: string): { status: number; code: string; message: string } | null {
    const queue = this.faultQueue.get(route);
    if (!queue || queue.length === 0) return null;
    return queue.shift() ?? null;
  }

  packetForSeed(post: WpmmccPostSeed): Record<string, unknown> {
    const vectorClock: Record<string, number> = { [this.siteUuid]: 1 };
    const manifest = post.media
      ? [
          {
            asset_ref: 'asset_1',
            type: 'image',
            remote_url: post.media.remoteUrl,
            sha256: sha256Hex(post.media.bytes),
            size_bytes: post.media.bytes.length,
          },
        ]
      : [];
    return {
      schema_version: 'wpmmcc-sync-v1.0',
      packet_id: `pkt_${post.guid}`,
      origin_context: {
        origin_site_uuid: this.siteUuid,
        origin_site_url: this.baseUrl,
        origin_blog_id: 1,
        origin_lang: 'en_US',
        vector_clock: vectorClock,
        hop_count: 1,
        dispatch_timestamp: 100,
      },
      action: 'upsert',
      sync_mode: 'sync_only',
      target_lang: 'zh_CN',
      source_fingerprint: post.fingerprint ?? `fp-${post.guid}`,
      entity: {
        guid: post.guid,
        object_type: 'post',
        subtype: post.postType ?? 'post',
        source_id: post.sourceId,
        slug: `slug-${post.sourceId}`,
        status: post.status ?? 'publish',
        author_hint: { display_name: 'Sim Editor' },
        core_fields: {
          post_title: post.title,
          post_content: post.content,
          post_excerpt: post.excerpt ?? '',
          post_date_gmt: '2026-01-01 00:00:00',
          comment_status: 'open',
          ping_status: 'open',
          menu_order: 0,
        },
        taxonomies: post.taxonomies ?? { category: [] },
        meta_fields: post.metaFields ?? {},
        plugin_specific: {},
      },
      multimodal_manifest: manifest,
    };
  }

  private async handle(req: http.IncomingMessage, res: http.ServerResponse) {
    const body = await readBody(req);
    const { pathname, query } = parseUrl(req.url ?? '/');
    const request: ParsedRequest = {
      method: (req.method ?? 'GET').toUpperCase(),
      path: pathname,
      headers: req.headers,
      body,
    };
    this.seenPaths.push(`${request.method} ${pathname}`);

    // Public media download (attachment URLs inside packets are plain GETs).
    if (request.method === 'GET' && pathname.startsWith('/wp-content/uploads/')) {
      const filename = pathname.split('/').pop() ?? '';
      const asset = this.posts
        .map((p) => p.media)
        .find((m) => m && m.filename === filename);
      if (asset) {
        res.writeHead(200, {
          'Content-Type': 'image/png',
          'Content-Length': asset.bytes.length.toString(),
        });
        res.end(asset.bytes);
        return;
      }
      res.writeHead(404, { 'Content-Type': 'text/plain' });
      res.end('not found');
      return;
    }

    // Identity ping: /wp-json/wpmmcc/v1/{secret}/sync/ping (signed response).
    const pingMatch = pathname.match(/^\/wp-json\/wpmmcc\/v1\/([^/]+)\/sync\/ping$/);
    if (request.method === 'GET' && pingMatch) {
      if (pingMatch[1] !== this.routeSecret) {
        restNoRoute(res);
        return;
      }
      this.pingCount += 1;
      sendSignedJson(res, this.wpClientToken, {
        success: true,
        data: {
          plugin_identity: 'wpmmcc',
          plugin_version: '1.0.0',
          site_platform: 'wp',
        },
      });
      return;
    }

    // One-time pairing handshake (public — no HMAC yet).
    if (request.method === 'POST' && pathname === '/wp-json/wpmmcc/v1/sync/handshake') {
      this.handleHandshake(request, res);
      return;
    }

    // HMAC-authenticated sync surface.
    const syncMatch = pathname.match(/^\/wp-json(\/wpmmcc\/v1\/sync\/[^/]+)$/);
    if (request.method === 'POST' && syncMatch) {
      await this.handleSync(request, res, syncMatch[1]);
      return;
    }

    restNoRoute(res);
  }

  private handleHandshake(req: ParsedRequest, res: http.ServerResponse): void {
    let payload: Record<string, unknown> = {};
    try {
      payload = JSON.parse(req.body.toString('utf8')) as Record<string, unknown>;
    } catch {
      /* handled below via field checks */
    }
    const pairingCode = String(payload.pairing_code ?? '');
    const originUuid = String(payload.origin_uuid ?? '');
    const originName = String(payload.origin_name ?? 'WPMMCC ATS Client');
    const originUrl = String(payload.origin_url ?? '');
    const requestedDirection = String(payload.requested_direction ?? 'bidirectional');
    this.receivedHandshakes.push(payload);

    const record = this.pairingCodes.get(pairingCode);
    const expired = record ? record.expiresAt < Date.now() : true;
    const alreadyUsed = record ? record.used : true;
    if (!record || expired || alreadyUsed) {
      sendSignedJson(
        res,
        this.wpClientToken,
        {
          code: 'wpmmcc_invalid_pairing_code',
          message: 'Pairing code is invalid, expired, or already used.',
          data: { status: 400 },
        },
        400,
      );
      return;
    }
    record.used = true;
    if (!originUuid) {
      sendSignedJson(
        res,
        this.wpClientToken,
        {
          code: 'wpmmcc_missing_origin_uuid',
          message: 'origin_uuid is required.',
          data: { status: 400 },
        },
        400,
      );
      return;
    }

    // Direction negotiation mirrors the plugin (doc 14 §4.3).
    let negotiated = 'bidirectional';
    if (requestedDirection === 'push_only') negotiated = 'pull_only';
    else if (requestedDirection === 'pull_only') negotiated = 'push_only';

    const pairingSecret = crypto.randomBytes(16).toString('hex');
    const sharedSecret = derivePeerSharedSecret(pairingSecret, originUuid, this.siteUuid);
    this.peers.set(originUuid, {
      originUuid,
      sharedSecret,
      direction: negotiated,
      pairedAt: Math.floor(Date.now() / 1000),
    });

    sendSignedJson(res, this.wpClientToken, {
      success: true,
      data: {
        peer_uuid: this.siteUuid,
        peer_name: this.siteName,
        status: 'paired',
        pairing_secret: pairingSecret,
        key_scheme: 'hmac_v1',
        negotiated_direction: negotiated,
        install_signature: `sim-install-${this.siteUuid}`,
      },
    });
  }

  private async handleSync(
    req: ParsedRequest,
    res: http.ServerResponse,
    canonicalUri: string,
  ): Promise<void> {
    // Full HMAC verification (mirror of the plugin middleware).
    const sig = String(req.headers['x-wpmmcc-signature'] ?? '');
    const ts = String(req.headers['x-wpmmcc-timestamp'] ?? '');
    const nonce = String(req.headers['x-wpmmcc-nonce'] ?? '');
    const sender = String(req.headers['x-wpmmcc-site-uuid'] ?? '');
    const peer = this.peers.get(sender);
    const bodyHash = sha256Hex(req.body);
    const stringToSign = `${req.method}\n${canonicalUri}\n${ts}\n${nonce}\n${sender}\n${bodyHash}`;
    const expected = peer
      ? computeWpmmccSignature(stringToSign, peer.sharedSecret)
      : '';

    if (!peer || !sig || !ts || !nonce || sig !== expected) {
      this.hmacFailures.push({ path: canonicalUri, sender });
      sendSignedJson(
        res,
        this.wpClientToken,
        {
          code: 'wpmmcc_signature_mismatch',
          message: 'HMAC verification failed',
          data: { status: 401 },
        },
        401,
      );
      return;
    }

    const route = canonicalUri.replace('/wpmmcc/v1', '');
    let payload: Record<string, unknown> = {};
    try {
      payload = JSON.parse(req.body.toString('utf8')) as Record<string, unknown>;
    } catch {
      /* empty body endpoints still work */
    }

    if (route === '/sync/digest') {
      // SIM-14 fault injection: transient server errors must surface as
      // digest rejections the client can retry on the next run.
      const fault = this.consumeFault('digest');
      if (fault) {
        sendSignedJson(res, this.wpClientToken, {
          success: false,
          code: fault.code,
          message: fault.message,
          data: { status: fault.status },
        }, fault.status);
        return;
      }
      const lastSeenId = Number(payload.last_seen_id ?? 0);
      const limit = Math.max(1, Number(payload.limit ?? 200));
      const postTypes = Array.isArray(payload.post_types)
        ? (payload.post_types as string[])
        : ['post'];
      const items = this.posts
        .filter((p) => (p.postType ?? 'post') === 'post' || postTypes.includes(p.postType ?? 'post'))
        .filter((p) => p.sourceId > lastSeenId)
        .sort((a, b) => a.sourceId - b.sourceId)
        .slice(0, limit)
        .map((p) => ({
          canonical_uuid: p.guid,
          source_fingerprint: p.fingerprint ?? `fp-${p.guid}`,
          vector_clock: 1,
          local_id: p.sourceId,
          post_status: p.status ?? 'publish',
          post_type: p.postType ?? 'post',
          last_modified: '2026-01-01 00:00:00',
        }));
      this.digestRequests.push({ lastSeenId, limit, ids: items.map((item) => item.local_id) });
      sendSignedJson(res, this.wpClientToken, {
        success: true,
        data: { items, server_time: Math.floor(Date.now() / 1000) },
      });
      return;
    }

    if (route === '/sync/pull') {
      const wanted = Array.isArray(payload.canonical_uuids)
        ? (payload.canonical_uuids as string[])
        : [];
      const packets = this.posts
        .filter((p) => wanted.includes(p.guid))
        .map((p) => this.packetForSeed(p));
      sendSignedJson(res, this.wpClientToken, {
        success: true,
        data: { packets },
      });
      return;
    }

    if (route === '/sync/push') {
      // SIM-14 fault injection: the packet is SEEN but rejected; keep it
      // out of receivedPackets (accepted-only wire evidence) and count it
      // separately so self-healing assertions stay exact.
      const fault = this.consumeFault('push');
      if (fault) {
        this.rejectedPackets += 1;
        sendSignedJson(res, this.wpClientToken, {
          success: false,
          code: fault.code,
          message: fault.message,
          data: { status: fault.status },
        }, fault.status);
        return;
      }
      const packet = payload;
      this.receivedPackets.push(packet);
      const guid = String(
        (packet.entity as Record<string, unknown> | undefined)?.guid ?? '',
      );
      const targetId = 1000 + this.receivedPackets.length;
      sendSignedJson(res, this.wpClientToken, {
        success: true,
        data: {
          status: 'success',
          packet_id: packet.packet_id,
          target_id: targetId,
          target_url: `${this.baseUrl}/?p=${targetId}`,
          fingerprint_ack: packet.source_fingerprint,
          mapped_guid: guid,
        },
      });
      return;
    }

    if (route === '/sync/media-chunk') {
      // SIM-16 fault injection: a rejected chunk (e.g. a transient 500 on
      // chunk 2 of 3) makes transfer_media fail for the asset; the pair
      // run records the item error and the safe cursor keeps the entity
      // rescannable, so the next run re-transfers the file cleanly.
      const chunkFault = this.consumeFault('media-chunk');
      if (chunkFault) {
        this.rejectedMediaChunks += 1;
        sendSignedJson(res, this.wpClientToken, {
          success: false,
          code: chunkFault.code,
          message: chunkFault.message,
          data: { status: chunkFault.status },
        }, chunkFault.status);
        return;
      }
      // REAL plugin chunk-completion contract (class-wpmmcc-rest-media.php):
      // intermediate chunks answer completed:false with no attachment URL;
      // the final chunk assembles the parts, answers completed:true with
      // the assembled sha256 + attachment URL. (The pre-SIM-14 mock always
      // answered completed:true — a protocol-fidelity gap for multi-chunk
      // transfers, since the client's completion check only fires on the
      // last chunk and could never catch a premature completion lie.)
      this.receivedChunks += 1;
      const fileUuid = String(payload.file_uuid ?? '');
      const filename = String(payload.filename ?? 'wpmmcc-asset.bin');
      const index = Number(payload.chunk_index ?? 0);
      const total = Math.max(1, Number(payload.total ?? 1));
      const part = Buffer.from(String(payload.bytes ?? ''), 'base64');
      let buf = this.chunkBuffers.get(fileUuid);
      if (!buf) {
        buf = { filename, total, parts: new Map() };
        this.chunkBuffers.set(fileUuid, buf);
      }
      buf.parts.set(index, part);
      const completed = index + 1 >= total && buf.parts.size >= total;
      if (!completed) {
        sendSignedJson(res, this.wpClientToken, {
          success: true,
          data: {
            chunk_acked: true,
            completed: false,
            assembled_sha256: '',
            attachment_id: 0,
            attachment_url: '',
            reused: false,
          },
        });
        return;
      }
      const assembled = Buffer.concat(
        Array.from({ length: total }, (_, i) => buf!.parts.get(i) ?? Buffer.alloc(0)),
      );
      const sha256 = sha256Hex(assembled);
      const attachmentUrl = `${this.baseUrl}/wp-content/uploads/${filename}`;
      this.mediaAssemblies.push({
        filename,
        sha256,
        sizeBytes: assembled.length,
      });
      this.chunkBuffers.delete(fileUuid);
      sendSignedJson(res, this.wpClientToken, {
        success: true,
        data: {
          chunk_acked: true,
          completed: true,
          assembled_sha256: sha256,
          attachment_id: 55,
          attachment_url: attachmentUrl,
          reused: false,
        },
      });
      return;
    }

    if (route === '/sync/reconcile-digest') {
      // REAL verdict fidelity (class-wpmmcc-reconciler.php): the submitted
      // fingerprint is compared against the SOURCE's current one —
      // equal → in_sync, unknown uuid → missing, drifted → source_newer
      // (the only update-propagation verdict: the digest's keyset cursor
      // never re-reports already-scanned posts).
      const fingerprints = Array.isArray(payload.fingerprints)
        ? (payload.fingerprints as Array<Record<string, unknown>>)
        : [];
      const current = new Map(
        this.posts.map((p) => [
          p.guid,
          p.fingerprint ?? `fp-${p.guid}`,
        ]),
      );
      const verdicts: Record<string, string> = {};
      for (const item of fingerprints) {
        const uuid = String(item.canonical_uuid ?? '');
        const remoteFp = String(item.source_fingerprint ?? '');
        const cur = current.get(uuid);
        verdicts[uuid] =
          cur === undefined
            ? 'missing'
            : cur === remoteFp
              ? 'in_sync'
              : 'source_newer';
      }
      sendSignedJson(res, this.wpClientToken, {
        success: true,
        data: { verdicts, server_time: Math.floor(Date.now() / 1000) },
      });
      return;
    }

    restNoRoute(res);
  }
}

// ---------------------------------------------------------------------------
// MockAtsSite — the ATS (wptsall) translation plugin client surface
// ---------------------------------------------------------------------------

export interface AtsRelation {
  id: number;
  sourceLang: string;
  targetLang: string;
}

export interface AtsContentItem {
  objectId: number;
  postType: string;
  title: string;
  content: string;
  excerpt?: string;
  /** SIM-15 T4: seed the item WITHOUT the job snapshot (broken source data). */
  omitJobSnapshot?: boolean;
  /**
   * SIM-16: for subtype `attachment` items — the source attachment URL the
   * client's no-provider attachment-copy lane downloads from. Point it at
   * this mock's public `/wp-content/uploads/...` route (a seeded file) or
   * a foreign origin to drive `media.source_copy_origin_rejected`.
   */
  attachmentUrl?: string;
  /**
   * SIM-16: extra complete_data fields merged verbatim (e.g. a media_ref
   * field with a garbage value to drive `discovery.media_ref_source_missing`).
   */
  extraData?: Record<string, unknown>;
}

/**
 * SIM-16: one untranslated language-pack entry, mirroring the real
 * plugin's template_entries row served by GET /content?data_type=
 * language_pack (class-client-data-rest-controller.php
 * get_untranslated_language_pack_entries: entry_id/msgid/msgid_plural/
 * msgctxt/reference + the template's text_domain/source_type/source_name).
 */
export interface AtsLanguagePackItem {
  entryId: number;
  msgid: string;
  /** 'plugin' | 'theme' | 'config' — the fetch is per-subtype. */
  subtype: 'plugin' | 'theme' | 'config';
  textDomain?: string;
  msgidPlural?: string;
  msgctxt?: string;
}

export class MockAtsSite {
  readonly siteName: string;
  readonly routeSecret: string;
  readonly wpClientToken: string;
  baseUrl = '';

  private server: http.Server | null = null;
  private readonly relation: AtsRelation;
  private readonly contentItems: AtsContentItem[];

  // Lifecycle outbox: one pending row per seeded content item. Rows are
  // leased on GET and removed on a `completed` ack (retry keeps them
  // pending) — mirroring the plugin's outbox lease contract that actually
  // drives client discovery (an empty outbox means "nothing to do").
  private outboxSeq = 7000;
  private readonly outboxPending: Array<{
    outboxId: number;
    taskId: number;
    clientTaskId: string;
    objectId: number;
    item: Record<string, unknown>;
  }> = [];

  // Wire evidence.
  receivedCallbacks: Array<{
    payload: Record<string, unknown>;
    idempotencyKey: string | null;
  }> = [];
  readonly receivedAcks: Array<{ outboxId: number; outcome: string }> = [];
  readonly receivedClaims: Array<{ data_type: string; object_ids: Array<unknown> }> = [];
  pingCount = 0;
  seenPaths: string[] = [];

  // FL-2a claim lock state: (data_type|object_id) → epoch ms of the claim.
  // 30-minute expiry mirrors the real plugin's get_claim_timeout_seconds().
  private readonly claimLocks = new Map<string, number>();

  // SIM-16 (doc 24 §四.5) language_pack + media family state + evidence:
  /** Relation i18n_config (null → the relation omits the field entirely). */
  private i18nConfig: { translate_plugin_i18n?: boolean } | null;
  /** Untranslated language-pack entries (the unclaimed pool per subtype). */
  private languagePackItems: AtsLanguagePackItem[] = [];
  /**
   * SIM-16: entry ids whose msgstr an APPLIED i18n callback wrote. The
   * real plugin's translation_callback (Path B) writes each msgstr into
   * template_entries, so get_untranslated_language_pack_entries never
   * serves them again — the claim lock is a 30-minute hold, NOT the
   * translation record. Without this set, expireClaims would re-expose
   * the whole history instead of only the failed-batch entries.
   */
  private readonly translatedEntryIds = new Set<number>();
  /** Public media files served at /wp-content/uploads/{filename}. */
  private readonly mediaFiles = new Map<string, Buffer>();
  /** SIM-16: runtime-registered translation rules (media_ref fields etc.). */
  private readonly extraRules: Array<Record<string, unknown>> = [];
  /** Next attachment id handed out by /media-upload. */
  private attachmentSeq = 5000;
  /** Language-pack claims seen (entry ids, deduped by the claim locks). */
  readonly receivedLanguagePackClaims: number[][] = [];
  /** Language-pack content pages served (fetch arm evidence). */
  lpPagesServed = 0;
  /** Public media downloads completed (attachment-copy download arm). */
  mediaDownloads = 0;
  /** Public media downloads rejected by an injected fault. */
  rejectedDownloads = 0;
  /** Accepted /media-upload calls (authenticated binary upload arm). */
  readonly mediaUploads: Array<{
    filename: string;
    sourceId: number;
    relationId: number;
    sizeBytes: number;
    attachmentId: number;
  }> = [];
  /** /media-upload calls seen but rejected by an injected fault. */
  rejectedMediaUploads = 0;

  // SIM-15 (doc 24 §四.5) negative-journey fault levers + evidence:
  // transient failures on the ATS data-plane routes (claim / callback /
  // outbox ack) so the client's failure paths are driven end to end.
  /** Fault queue per ATS route, consumed one per matching request. */
  private readonly atsFaultQueue = new Map<
    string,
    Array<{ status: number; code: string; message: string }>
  >();
  /** Claim requests seen but rejected by an injected fault. */
  rejectedClaims = 0;
  /** Callback requests seen but rejected; keys recorded for idempotency proof. */
  rejectedCallbacks = 0;
  readonly rejectedCallbackKeys: string[] = [];
  /** Outbox ack requests seen but rejected by an injected fault. */
  rejectedAcks = 0;
  /** SIM-15 evidence: rows still pending (a completed ack removes them). */
  get pendingOutboxCount(): number {
    return this.outboxPending.length;
  }
  /** Callbacks actually APPLIED (deduped by Idempotency-Key, real contract). */
  appliedCallbacks = 0;
  /** Callbacks answered from the idempotency cache (same key + same body). */
  idempotentReplays = 0;
  /** Idempotency-Key → applied attempt (body hash + result id), real contract. */
  private readonly callbackAttempts = new Map<
    string,
    { bodyHash: string; resultId: number }
  >();

  /** Claim lock timeout, mirroring the plugin's 30-minute claim window. */
  private static readonly CLAIM_TIMEOUT_MS = 30 * 60 * 1000;

  /** True while the (data_type, object) hold an unexpired claim lock. */
  private hasActiveClaim(dataType: string, objectId: number): boolean {
    const claimedAt = this.claimLocks.get(`${dataType}|${objectId}`);
    return (
      claimedAt !== undefined && Date.now() - claimedAt < MockAtsSite.CLAIM_TIMEOUT_MS
    );
  }

  /** Claim (data_type, object) unless already actively claimed; true when newly claimed. */
  private claimIfNew(dataType: string, objectId: number): boolean {
    if (this.hasActiveClaim(dataType, objectId)) return false;
    this.claimLocks.set(`${dataType}|${objectId}`, Date.now());
    return true;
  }

  /**
   * SIM-15 lever: inject N transient faults for an ATS data-plane route.
   * Each matching request consumes one fault and answers with the given
   * HTTP status (claim → POST /content/claim, callback → POST
   * /translation-callback, ack → POST /content-changes/{id}/ack).
   *
   * SIM-16 additions (doc 24 §四.5 language_pack + media families):
   *   'lp-content'     → GET /content?data_type=language_pack (fetch arm)
   *   'media-download' → GET /wp-content/uploads/{name} (public asset)
   *   'media-upload'   → POST /media-upload (authenticated binary upload)
   */
  injectFault(
    route: 'claim' | 'callback' | 'ack' | 'lp-content' | 'media-download' | 'media-upload',
    times: number,
    opts: { status?: number; code?: string; message?: string } = {},
  ): void {
    const queue = this.atsFaultQueue.get(route) ?? [];
    for (let i = 0; i < times; i++) {
      queue.push({
        status: opts.status ?? 500,
        code: opts.code ?? 'wpmmcc_error',
        message: opts.message ?? 'injected fault (sim-15)',
      });
    }
    this.atsFaultQueue.set(route, queue);
  }

  /** Consume one injected ATS fault for `route`, if any is pending. */
  private consumeAtsFault(
    route: string,
  ): { status: number; code: string; message: string } | null {
    const queue = this.atsFaultQueue.get(route);
    if (!queue || queue.length === 0) return null;
    return queue.shift() ?? null;
  }

  constructor(opts: {
    siteName: string;
    routeSecret: string;
    wpClientToken: string;
    relation: AtsRelation;
    contentItems: AtsContentItem[];
    /**
     * SIM-16: relation i18n_config — the client only runs the language-
     * pack lanes when the relation carries these flags (Relation.i18n_config
     * in types/wp.rs; the real plugin emits them from
     * class-relation-config-service.php). Omitted → the relation carries no
     * i18n_config (existing wires unchanged; the client defaults all false).
     */
    i18nConfig?: { translate_plugin_i18n?: boolean };
    /** SIM-16: untranslated language-pack entries served per subtype. */
    languagePackItems?: AtsLanguagePackItem[];
  }) {
    this.siteName = opts.siteName;
    this.routeSecret = opts.routeSecret;
    this.wpClientToken = opts.wpClientToken;
    this.relation = opts.relation;
    this.contentItems = opts.contentItems;
    this.i18nConfig = opts.i18nConfig ?? null;
    this.languagePackItems = opts.languagePackItems ?? [];
    for (const item of this.contentItems) {
      const outboxId = ++this.outboxSeq;
      this.outboxPending.push({
        outboxId,
        taskId: outboxId,
        clientTaskId: `sim-outbox-${outboxId}`,
        objectId: item.objectId,
        item: this.contentItemFor(item),
      });
    }
  }

  /**
   * SIM-16: seed one more untranslated language-pack entry at runtime
   * (fresh entry id per journey — claimed entries disappear from the
   * unclaimed pool exactly like claimed posts).
   */
  addLanguagePackItem(item: AtsLanguagePackItem): void {
    this.languagePackItems.push(item);
  }

  /**
   * SIM-16 lever: model the 30-minute claim timeout ELAPSING (the real
   * plugin's get_claim_timeout_seconds window). Clears the claim locks so
   * previously claimed entries/items reappear in the unclaimed pool — the
   * recovery path for journeys whose callback failed AFTER a successful
   * claim (a fresh claim is the only re-entry for the same entry).
   */
  expireClaims(dataType?: 'post' | 'term' | 'language_pack'): void {
    if (dataType === undefined) {
      this.claimLocks.clear();
      return;
    }
    for (const key of Array.from(this.claimLocks.keys())) {
      if (key.startsWith(`${dataType}|`)) this.claimLocks.delete(key);
    }
  }

  /**
   * SIM-16: seed a public media file the attachment-copy lane downloads
   * from this site (served at /wp-content/uploads/{filename}).
   */
  seedMediaFile(filename: string, bytes: Buffer): void {
    this.mediaFiles.set(filename, bytes);
  }

  /**
   * SIM-15: seed one more content item at runtime (with its lifecycle
   * outbox row), so each negative journey runs against a fresh, unclaimed
   * object instead of burning the constructor-seeded set in test 1.
   *
   * SIM-16: `outbox: false` seeds the item SCAN-LANE ONLY — attachment
   * items (the no-provider attachment-copy lane) must not also enter the
   * outbox lane, which would translate them like ordinary posts and
   * confound the media journey's evidence.
   */
  addContentItem(item: AtsContentItem, opts: { outbox?: boolean } = {}): void {
    this.contentItems.push(item);
    if (opts.outbox === false) return;
    const outboxId = ++this.outboxSeq;
    this.outboxPending.push({
      outboxId,
      taskId: outboxId,
      clientTaskId: `sim-outbox-${outboxId}`,
      objectId: item.objectId,
      item: this.contentItemFor(item),
    });
  }

  /**
   * SIM-16: register one more translation rule at runtime (the rules wire
   * drives field_content_formats — a media_ref field rule reaches the
   * pipeline's FieldTranslationKind::MediaAsset arm).
   */
  addRule(rule: Record<string, unknown>): void {
    this.extraRules.push(rule);
  }

  /**
   * SIM-15: heal a previously broken item (e.g. one seeded without the job
   * snapshot) — flips the flag and rebuilds its pending outbox row so the
   * next client pass sees repaired source data ("the site fixed it").
   */
  repairContentItem(objectId: number): void {
    const item = this.contentItems.find((i) => i.objectId === objectId);
    if (!item) return;
    item.omitJobSnapshot = false;
    for (const row of this.outboxPending) {
      if (row.objectId === objectId) {
        row.item = this.contentItemFor(item);
      }
    }
  }

  /** The ContentItem wire shape shared by /content and /content-changes. */
  private contentItemFor(item: AtsContentItem): Record<string, unknown> {
    return {
      object_type: 'post_type',
      subtype: item.postType,
      object_id: item.objectId,
      needs_resync: false,
      mapping_id: null,
      complete_data: {
        ID: String(item.objectId),
        object_id: item.objectId,
        object_type: 'post_type',
        subtype: item.postType,
        post_title: item.title,
        post_content: item.content,
        post_excerpt: item.excerpt ?? '',
        post_status: 'publish',
        post_type: item.postType,
        post_name: `sim-post-${item.objectId}`,
        post_date: '2026-01-01 00:00:00',
        post_modified: '2026-01-01 00:00:00',
        comment_status: 'closed',
        ping_status: 'closed',
        menu_order: '0',
        post_author: '1',
        meta: [],
        // The job snapshot the client requires on every claimable content
        // payload — without a source_revision the pipeline rejects the
        // item ("missing source_revision for object N"). SIM-15 T4 seeds
        // a broken item by omitting it, then repairs it mid-journey.
        ...(item.omitJobSnapshot
          ? {}
          : {
              __wptsall_job_snapshot: {
                source_revision: `rev-${item.objectId}-1`,
                policy_version: 'policy-v1',
              },
            }),
        // SIM-16: the no-provider attachment-copy lane (pipeline.rs
        // build_attachment_copy_trace) reads complete_data.attachment_url
        // for subtype 'attachment' items; extraData merges any further
        // fields verbatim (e.g. a garbage media_ref field value).
        ...(item.attachmentUrl ? { attachment_url: item.attachmentUrl } : {}),
        ...(item.extraData ?? {}),
      },
    };
  }

  async start(): Promise<void> {
    const server = http.createServer((req, res) => {
      void this.handle(req, res);
    });
    await new Promise<void>((resolve) => {
      server.listen(0, '127.0.0.1', () => resolve());
    });
    const addr = server.address() as import('node:net').AddressInfo;
    this.baseUrl = `http://127.0.0.1:${addr.port}`;
    this.server = server;
  }

  async stop(): Promise<void> {
    const server = this.server;
    if (!server) return;
    await new Promise<void>((resolve) => server.close(() => resolve()));
    this.server = null;
  }

  private async handle(req: http.IncomingMessage, res: http.ServerResponse) {
    const body = await readBody(req);
    const { pathname, query } = parseUrl(req.url ?? '/');
    const method = (req.method ?? 'GET').toUpperCase();
    this.seenPaths.push(`${method} ${pathname}`);

    // SIM-16: public media files (plain GETs — the client's media download
    // arm fetches attachment URLs without the client-token envelope, the
    // same as the WPMMCC mock's public uploads route).
    const mediaMatch = pathname.match(/^\/wp-content\/uploads\/([^/]+)$/);
    if (mediaMatch && method === 'GET') {
      const downloadFault = this.consumeAtsFault('media-download');
      if (downloadFault) {
        this.rejectedDownloads += 1;
        const out = JSON.stringify({
          error: downloadFault.code,
          message: downloadFault.message,
        });
        res.writeHead(downloadFault.status, {
          'Content-Type': 'application/json',
          'Content-Length': Buffer.byteLength(out).toString(),
        });
        res.end(out);
        return;
      }
      const file = this.mediaFiles.get(mediaMatch[1] ?? '');
      if (!file) {
        const out = JSON.stringify({ error: 'not_found' });
        res.writeHead(404, {
          'Content-Type': 'application/json',
          'Content-Length': Buffer.byteLength(out).toString(),
        });
        res.end(out);
        return;
      }
      this.mediaDownloads += 1;
      res.writeHead(200, {
        'Content-Type': 'application/octet-stream',
        'Content-Length': file.length.toString(),
      });
      res.end(file);
      return;
    }

    const clientBase = `/wp-json/wptsall/v2/${this.routeSecret}/client`;
    if (!pathname.startsWith(clientBase)) {
      restNoRoute(res);
      return;
    }
    const sub = pathname.slice(clientBase.length).replace(/^\//, '');

    if ((sub === 'ping' || sub === 'validate-token') && method === 'GET') {
      this.pingCount += 1;
      sendSignedJson(res, this.wpClientToken, {
        success: true,
        data: {
          plugin_identity: 'wpmmcc_ats',
          plugin_version: '1.0.0',
          site_platform: 'wp',
        },
      });
      return;
    }

    if (sub === 'site-relations' && method === 'GET') {
      // Typed endpoints carry a bare payload (no success/data envelope) —
      // the client deserializes the response body directly into
      // RelationsResponse { relations }.
      sendSignedJson(res, this.wpClientToken, {
        relations: [
          {
            id: this.relation.id,
            source_site_id: 's1',
            source_lang: this.relation.sourceLang,
            target_site_id: 'v1',
            target_site_type: 'virtual',
            target_lang: this.relation.targetLang,
            sync_mode: 'auto',
            template: 'default',
            models: [
              {
                model_id: 1,
                plugin_slug: 'wptsall',
                plugin_name: 'WPTSALL ATS',
                post_types: ['post'],
                taxonomies: [],
              },
            ],
            preflight_policy: 'warn',
            missing_component_behavior: 'block',
            // SIM-16: i18n_config gates the client's language-pack lanes
            // (Relation.i18n_config → translate_plugin_i18n etc.). Only
            // included when the site was constructed with flags, so every
            // existing spec's site-relations wire stays byte-identical.
            ...(this.i18nConfig
              ? {
                  i18n_config: {
                    translate_plugin_i18n: this.i18nConfig.translate_plugin_i18n ?? false,
                    translate_theme_i18n: false,
                    translate_config_i18n: false,
                    translate_site_strings: false,
                    translate_menu_strings: false,
                    translate_widget_strings: false,
                  },
                }
              : {}),
          },
        ],
      });
      return;
    }

    if (sub === 'rules' && method === 'GET') {
      sendSignedJson(res, this.wpClientToken, {
        rules: [
          {
            id: 1,
            model_id: 1,
            name: 'sim-post-fields',
            data_type: 'post',
            object_name: 'post',
            field_capabilities: {
              translate_fields: ['post_title', 'post_content', 'post_excerpt'],
            },
            translate_fields: ['post_title', 'post_content', 'post_excerpt'],
            related_taxonomies: [],
            field_content_formats: {
              post_title: 'plain_text',
              post_content: 'rich_html',
              post_excerpt: 'plain_text',
            },
            field_storage_map: {
              post_title: 'post_column',
              post_content: 'post_column',
              post_excerpt: 'post_column',
            },
            source_group: 'content_objects',
            routing_profile: 'standard',
            delivery_target: 'post_column',
            required_component_slots: ['text_translation'],
            required_content_formats: ['plain_text', 'rich_html'],
          },
          ...this.extraRules,
        ],
      });
      return;
    }

    if (sub === 'content' && method === 'GET') {
      // SIM-16: the language-pack fetch arm (GET /content?data_type=
      // language_pack&subtype=plugin). REAL contract (class-client-data-
      // rest-controller.php get_untranslated_language_pack_entries): a
      // bare {items,total,page,per_page} payload of template_entries rows
      // for the relation's source_type, with actively-claimed entries
      // excluded (the same 30-minute claim pool as posts).
      if (String(query.get('data_type') ?? '') === 'language_pack') {
        const lpFault = this.consumeAtsFault('lp-content');
        if (lpFault) {
          sendSignedJson(res, this.wpClientToken, {
            success: false,
            error: lpFault.code,
            message: lpFault.message,
            data: { status: lpFault.status },
          }, lpFault.status);
          return;
        }
        const subtype = String(query.get('subtype') ?? 'plugin');
        const page = Math.max(1, Number(query.get('page') ?? 1));
        const perPage = Number(query.get('per_page') ?? 50);
        const items = this.languagePackItems
          .filter((e) => e.subtype === subtype)
          .filter((e) => !this.translatedEntryIds.has(e.entryId))
          .filter((e) => !this.hasActiveClaim('language_pack', e.entryId))
          .map((e) => ({
            object_type: 'language_pack',
            subtype: e.subtype,
            object_id: e.entryId,
            template_id: 8000 + e.entryId,
            text_domain: e.textDomain ?? `sim16-${e.subtype}`,
            source_name: `sim16-${e.subtype}-pack`,
            complete_data: {
              entry_id: e.entryId,
              msgid: e.msgid,
              msgid_plural: e.msgidPlural ?? '',
              msgctxt: e.msgctxt ?? '',
              reference: `sim16-${e.subtype}.po:${e.entryId}`,
              text_domain: e.textDomain ?? `sim16-${e.subtype}`,
            },
          }));
        this.lpPagesServed += 1;
        const start = (page - 1) * perPage;
        sendSignedJson(res, this.wpClientToken, {
          items: items.slice(start, start + perPage),
          total: items.length,
          page,
          per_page: perPage,
        });
        return;
      }
      const page = Number(query.get('page') ?? 1);
      const perPage = Number(query.get('per_page') ?? 50);
      // FL-2a fidelity: the real plugin's content discovery never re-serves
      // items with an active claim (claims expire after 30 minutes and the
      // items then reappear). Mirror that here so a claimed post stops
      // coming back every iteration — the pre-FL-2a mock re-served
      // everything forever, which is what turned the missing claim route
      // into an endless scan loop.
      const items = this.contentItems
        .filter((item) => !this.hasActiveClaim('post', item.objectId))
        .map((item) => this.contentItemFor(item));
      const start = (page - 1) * perPage;
      const paged = items.slice(start, start + perPage);
      sendSignedJson(res, this.wpClientToken, {
        items: paged,
        total: items.length,
        page,
        per_page: perPage,
      });
      return;
    }

    if (sub === 'content/claim' && method === 'POST') {
      // SIM-15 fault injection: a rejected claim means the client must NOT
      // submit those items (fail-closed scan lane).
      const claimFault = this.consumeAtsFault('claim');
      if (claimFault) {
        this.rejectedClaims += 1;
        sendSignedJson(res, this.wpClientToken, {
          success: false,
          error: claimFault.code,
          message: claimFault.message,
          data: { status: claimFault.status },
        }, claimFault.status);
        return;
      }
      // FL-2a: the real plugin's POST /client/content/claim contract
      // (trait-client-data-rest-controller-claim.php): a 30-minute time
      // lock per (data_type, object); only NEWLY claimable items are
      // counted and echoed in claimed_items (post: object_id+post_type,
      // term: object_id+taxonomy, language_pack: entry_id). Unknown
      // relation → 404 relation_not_found. Items already claimed inside
      // the window are silently NOT re-claimed (claimed_count excludes
      // them), exactly like the plugin's claimed_at cutoff UPDATE.
      let payload: Record<string, unknown> = {};
      try {
        payload = JSON.parse(body.toString('utf8')) as Record<string, unknown>;
      } catch {
        sendSignedJson(res, this.wpClientToken, {
          success: false,
          error: 'missing_items',
          message: 'items array is required.',
        }, 400);
        return;
      }
      const relationId = Number(payload.relation_id ?? 0);
      const dataType = String(payload.data_type ?? 'post');
      const rawItems = Array.isArray(payload.items) ? (payload.items as Array<Record<string, unknown>>) : [];
      if (relationId !== this.relation.id) {
        sendSignedJson(res, this.wpClientToken, {
          success: false,
          error: 'relation_not_found',
          message: 'Site relation not found.',
        }, 404);
        return;
      }
      const claimedItems: Array<Record<string, unknown>> = [];
      for (const entry of rawItems) {
        const objectId = Number(entry.object_id ?? 0);
        if (dataType === 'language_pack') {
          const entryId = Number(entry.entry_id ?? entry.object_id ?? 0);
          if (entryId > 0 && this.claimIfNew('language_pack', entryId)) {
            claimedItems.push({ entry_id: entryId });
          }
          continue;
        }
        if (objectId <= 0) continue;
        if (dataType === 'term') {
          const taxonomy = String(entry.taxonomy ?? entry.post_type ?? entry.subtype ?? '');
          if (taxonomy === '') continue;
          if (this.claimIfNew('term', objectId)) {
            claimedItems.push({ object_id: objectId, taxonomy });
          }
          continue;
        }
        const postType = String(entry.post_type ?? 'post');
        if (this.claimIfNew('post', objectId)) {
          claimedItems.push({ object_id: objectId, post_type: postType });
        }
      }
      this.receivedClaims.push({ data_type: dataType, object_ids: claimedItems.map((c) => c.object_id ?? c.entry_id) });
      if (dataType === 'language_pack') {
        this.receivedLanguagePackClaims.push(
          claimedItems.map((c) => Number(c.entry_id ?? c.object_id ?? 0)),
        );
      }
      sendSignedJson(res, this.wpClientToken, {
        success: true,
        claimed_count: claimedItems.length,
        claimed_items: claimedItems,
      });
      return;
    }

    if (sub === 'content-changes' && method === 'GET') {
      // The lifecycle outbox — the entry point that actually drives client
      // discovery. Rows stay pending until a `completed` ack removes them
      // (retry outcomes re-offer the row after the lease).
      const relationFilter = query.get('relation_id');
      const rows = this.outboxPending
        .filter(
          (row) =>
            relationFilter == null ||
            Number(relationFilter) === this.relation.id,
        )
        .map((row) => ({
          relation_id: this.relation.id,
          outbox_id: row.outboxId,
          task_id: row.taskId,
          client_task_id: row.clientTaskId,
          item: row.item,
        }));
      sendSignedJson(res, this.wpClientToken, {
        success: true,
        data: { items: rows },
      });
      return;
    }

    const ackMatch = sub.match(/^content-changes\/(\d+)\/ack$/);
    if (ackMatch && method === 'POST') {
      // SIM-15 fault injection: a rejected ack leaves the row leased at the
      // site (the lease-timeout path is the fallback in production).
      const ackFault = this.consumeAtsFault('ack');
      if (ackFault) {
        this.rejectedAcks += 1;
        sendSignedJson(res, this.wpClientToken, {
          success: false,
          error: ackFault.code,
          message: ackFault.message,
          data: { status: ackFault.status },
        }, ackFault.status);
        return;
      }
      let payload: Record<string, unknown> = {};
      try {
        payload = JSON.parse(body.toString('utf8')) as Record<string, unknown>;
      } catch {
        /* outcome defaults below */
      }
      const outboxId = Number(ackMatch[1]);
      const outcome = String(payload.outcome ?? 'completed');
      this.receivedAcks.push({ outboxId, outcome });
      if (outcome === 'completed') {
        const idx = this.outboxPending.findIndex((r) => r.outboxId === outboxId);
        if (idx >= 0) this.outboxPending.splice(idx, 1);
      }
      sendSignedJson(res, this.wpClientToken, {
        success: true,
        data: { acked: true, outbox_id: outboxId },
      });
      return;
    }

    if (sub === 'translation-callback' && method === 'POST') {
      // SIM-15 fault injection: the callback is SEEN but rejected; the
      // idempotency key is recorded so the retry-equality can be asserted
      // (the client derives it deterministically per (worker, relation,
      // object) — a recovery re-send must carry the SAME key).
      const cbFault = this.consumeAtsFault('callback');
      if (cbFault) {
        this.rejectedCallbacks += 1;
        const rejectedKey = req.headers['idempotency-key'];
        this.rejectedCallbackKeys.push(
          rejectedKey == null ? '' : String(rejectedKey),
        );
        sendSignedJson(res, this.wpClientToken, {
          success: false,
          error: cbFault.code,
          message: cbFault.message,
          data: { status: cbFault.status },
        }, cbFault.status);
        return;
      }
      let payload: Record<string, unknown> = {};
      try {
        payload = JSON.parse(body.toString('utf8')) as Record<string, unknown>;
      } catch {
        sendSignedJson(
          res,
          this.wpClientToken,
          { success: false, message: 'invalid json' },
          400,
        );
        return;
      }
      const idempotencyKey = req.headers['idempotency-key'] ?? null;
      const key = idempotencyKey == null ? null : String(idempotencyKey);
      // Real ATS contract (class-client-data-rest-controller.php
      // translation_callback): Idempotency-Key is cached against the request
      // body hash. A replay with the same body returns the CACHED response
      // without re-applying; the same key with a different body answers
      // 409 idempotency_conflict.
      const bodyHash = crypto.createHash('sha256').update(body).digest('hex');
      if (key != null) {
        const prior = this.callbackAttempts.get(key);
        if (prior != null) {
          if (prior.bodyHash !== bodyHash) {
            sendSignedJson(res, this.wpClientToken, {
              success: false,
              error: 'idempotency_conflict',
              message: 'Same Idempotency-Key with different body.',
            }, 409);
            return;
          }
          this.idempotentReplays += 1;
          this.receivedCallbacks.push({
            payload,
            idempotencyKey: key,
          });
          sendSignedJson(res, this.wpClientToken, {
            success: true,
            idempotent: true,
            queued: false,
            result_id: prior.resultId,
            protocol: 'v2',
          });
          return;
        }
        this.callbackAttempts.set(key, { bodyHash, resultId: 9000 + this.appliedCallbacks + 1 });
        this.appliedCallbacks += 1;
        // Real plugin contract (translation_callback → the i18n Path-B
        // arm writes each msgstr to template_entries): an APPLIED i18n
        // batch makes its entries translated — the untranslated pool
        // shrinks permanently (see translatedEntryIds).
        const appliedEntries = payload.entries;
        if (
          Array.isArray(appliedEntries)
          && ['plugin_i18n', 'theme_i18n', 'config_i18n'].includes(
            String(payload.business_line ?? ''),
          )
        ) {
          for (const entry of appliedEntries) {
            if (entry != null && typeof entry === 'object') {
              const entryId = Number((entry as Record<string, unknown>).entry_id ?? 0);
              if (entryId > 0) this.translatedEntryIds.add(entryId);
            }
          }
        }
        // Real plugin contract (translation_callback →
        // Content_Change_Dispatcher::complete_outbox): a 2xx callback
        // COMPLETES the row server-side — the client's explicit
        // completed-ack exists only for no-callback outcomes
        // (NoChanges/PendingReview). When the callback carries no
        // outbox_id (scan-lane payload), the plugin resolves the row by
        // relation+source instead; mirror that with an objectId match.
        // Pre-fix the row lingered forever.
        let callbackOutboxId = Number(payload.outbox_id ?? 0);
        if (callbackOutboxId <= 0) {
          const objectId = Number(payload.object_id ?? 0);
          const row = objectId > 0
            ? this.outboxPending.find((r) => r.objectId === objectId)
            : undefined;
          callbackOutboxId = row?.outboxId ?? 0;
        }
        if (callbackOutboxId > 0) {
          const idx = this.outboxPending.findIndex(
            (r) => r.outboxId === callbackOutboxId,
          );
          if (idx >= 0) this.outboxPending.splice(idx, 1);
        }
      }
      this.receivedCallbacks.push({
        payload,
        idempotencyKey: key,
      });
      // Ack shape (submitter's validator): top-level success + result_id +
      // protocol; queued=false means the write already applied.
      sendSignedJson(res, this.wpClientToken, {
        success: true,
        queued: false,
        result_id: 9000 + this.appliedCallbacks,
        protocol: 'v2',
      });
      return;
    }

    if (sub === 'media-upload' && method === 'POST') {
      // SIM-16: the authenticated binary upload arm (submitter.rs
      // upload_media_to_wp). REAL contract (class-client-data-rest-
      // controller.php media_upload): X-WPTSALL-Filename/Relation-ID/
      // Source-ID headers, a blocked-extension deny list, and a top-level
      // {success, attachment_id, attachment_url, url} ack — with the
      // response signature the client verifies on plaintext transports.
      const uploadFault = this.consumeAtsFault('media-upload');
      if (uploadFault) {
        this.rejectedMediaUploads += 1;
        sendSignedJson(res, this.wpClientToken, {
          success: false,
          error: uploadFault.code,
          message: uploadFault.message,
          data: { status: uploadFault.status },
        }, uploadFault.status);
        return;
      }
      const filename = String(req.headers['x-wptsall-filename'] ?? '');
      const relationId = Number(req.headers['x-wptsall-relation-id'] ?? 0);
      const sourceId = Number(req.headers['x-wptsall-source-id'] ?? 0);
      if (filename === '') {
        sendSignedJson(res, this.wpClientToken, {
          success: false,
          error: 'missing_filename',
          message: 'X-WPTSALL-Filename header is required.',
        }, 400);
        return;
      }
      const ext = (filename.split('.').pop() ?? '').toLowerCase();
      const blocked = new Set([
        'php', 'phtml', 'phps', 'exe', 'sh', 'bat', 'js', 'mjs', 'svg',
        'htm', 'html', 'py', 'pl', 'rb', 'cgi', 'asp', 'jsp',
      ]);
      if (blocked.has(ext)) {
        sendSignedJson(res, this.wpClientToken, {
          success: false,
          error: 'blocked_file_type',
          message: `File type is not allowed: .${ext}`,
        }, 400);
        return;
      }
      if (relationId !== this.relation.id || sourceId <= 0) {
        sendSignedJson(res, this.wpClientToken, {
          success: false,
          error: 'missing_relation_or_source',
          message: 'X-WPTSALL-Relation-ID and X-WPTSALL-Source-ID are required.',
        }, 400);
        return;
      }
      const attachmentId = ++this.attachmentSeq;
      const attachmentUrl = `${this.baseUrl}/wp-content/uploads/${filename}`;
      this.mediaUploads.push({
        filename,
        sourceId,
        relationId,
        sizeBytes: body.length,
        attachmentId,
      });
      sendSignedJson(res, this.wpClientToken, {
        success: true,
        attachment_id: attachmentId,
        attachment_url: attachmentUrl,
        url: attachmentUrl,
      });
      return;
    }

    restNoRoute(res);
  }
}
