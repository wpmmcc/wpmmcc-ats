import { test } from '@playwright/test'
import {
  bypassTurnstile,
  clearWebSession,
  loginWebUser,
  readUserPasswordHash,
  requestWebPasswordReset,
  resetWebPassword,
  resolvePasswordResetToken,
  restoreUserPasswordHash,
} from './helpers'

test.describe.configure({ mode: 'serial' })

test.describe('Journey: Web Password Recovery', () => {
  const email = process.env.JOURNEY_RECOVERY_EMAIL ?? 'demo@wptsall.dev'
  const originalPassword = process.env.JOURNEY_RECOVERY_ORIGINAL_PASSWORD ?? 'demo'
  const newPassword = process.env.JOURNEY_RECOVERY_NEW_PASSWORD ?? 'Journey-Recover-Password-2026-B'
  const restoreHashSourceEmail = process.env.JOURNEY_RECOVERY_RESTORE_HASH_SOURCE_EMAIL ?? 'admin@wptsall.dev'

  test('官网忘记密码 -> 程序内取 reset token -> 重置密码 -> 新密码登录 -> 恢复原账号哈希', async ({ page, context }) => {
    const restoreHash = readUserPasswordHash(restoreHashSourceEmail)

    try {
      await bypassTurnstile(context)
      await clearWebSession(page)

      await requestWebPasswordReset(page, { email })
      const resetToken = await resolvePasswordResetToken(email)
      await resetWebPassword(page, { token: resetToken, password: newPassword })

      await clearWebSession(page)
      await loginWebUser(page, { email, password: newPassword })
    } finally {
      restoreUserPasswordHash(email, restoreHash)
      await clearWebSession(page).catch(() => {})
      await loginWebUser(page, { email, password: originalPassword }).catch(() => {})
    }
  })
})
