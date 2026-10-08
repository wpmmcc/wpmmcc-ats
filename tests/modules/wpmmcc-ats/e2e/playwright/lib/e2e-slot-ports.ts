/**
 * Matrix Lab client ports — must stay in sync with e2e_client_port() in config.sh.
 */
export const E2E_SLOT_CLIENT_PORTS: Record<string, string> = {
  'slot-a': '9077',
  'slot-b': '9078',
  'slot-c': '9079',
  'slot-d': '9084',
  'slot-e': '9085',
  'slot-f': '9086',
  'slot-g': '9087',
  'slot-h': '9088',
  'slot-i': '9089',
  'slot-j': '9091',
  'slot-k': '9092',
  'slot-l': '9093',
  'slot-m': '9094',
  'slot-n': '9095',
  // Non-matrix lanes (keep in sync with e2e_client_port() in config.sh):
  // slot-u = pinned unit/integration env; slot-v = journey lane (批 O6 复栈).
  'slot-u': '9097',
  'slot-v': '9096',
}

/** Per-lane OAuth device id (matches Stage 6 WPTSALL_DEVICE_ID). */
export function e2eLabDeviceId(): string {
  const fromEnv = (process.env.WPTSALL_DEVICE_ID ?? '').trim()
  if (fromEnv) return fromEnv
  const slot = (process.env.E2E_SLOT ?? '').trim()
  if (slot && slot !== 'shared') return `wptsall-e2e-${slot}`
  return ''
}

export function resolveSlotClientBase(
  slot: string = process.env.E2E_SLOT ?? 'shared',
  rawBase: string = (process.env.CLIENT_BASE || process.env.CLIENT_URL || '').replace(/\/$/, ''),
): string {
  const slotPort = E2E_SLOT_CLIENT_PORTS[slot]
  if (slotPort) {
    const expected = `http://127.0.0.1:${slotPort}`
    if (!rawBase || /:8977\/?$/.test(rawBase) || !rawBase.includes(`:${slotPort}`)) {
      if (rawBase && rawBase !== expected) {
        console.warn(`[client-slot] refusing CLIENT_BASE=${rawBase} for ${slot}; using ${expected}`)
      }
      return expected
    }
    return rawBase
  }
  return rawBase || 'http://127.0.0.1:8977'
}
