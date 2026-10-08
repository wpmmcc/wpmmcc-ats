import { test } from '../lib/page-errors'
import {
  E2E_PROJECT,
  E2E_SCOPE,
  bootstrapWorkerSetup,
  cleanupWorkerBootstrap,
  ensureLoggedIn,
  runWorkerOnce,
  verifyOfficialClientGateServices,
  type WorkerBootstrapState,
} from './client-translate.shared'

test.describe.configure({ mode: 'serial' })

test.describe(`E2E v2: ${E2E_PROJECT} / client execution / ${E2E_SCOPE}`, () => {
  let bootstrapState: WorkerBootstrapState = {
    componentId: '',
    createdLocalComponentIds: [],
  }

  test.beforeAll(async ({ request }) => {
    await verifyOfficialClientGateServices(request)
  })

  test.afterAll(async ({ request }) => {
    await cleanupWorkerBootstrap(request, bootstrapState)
  })

  test('OAuth 登录：通过 www.wpmm.cc 真实认证', async ({ page, context, request }) => {
    await ensureLoggedIn({ page, context, request })
  })

  test('Worker 准备：域名刷新 → Token 绑定 → 组件发现 → 组件绑定', async ({ page, context, request }) => {
    await ensureLoggedIn({ page, context, request })
    bootstrapState = await bootstrapWorkerSetup(request)
  })

  test('Worker 运行：run-once 执行 core-content relation translation', async ({ page, context, request }) => {
    test.setTimeout(1_200_000)
    await ensureLoggedIn({ page, context, request })
    await runWorkerOnce(request)
  })
})
