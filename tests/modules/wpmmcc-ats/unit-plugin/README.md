# unit-plugin/ — migration / opt-in track (NOT the default track)

> Execution plan 2-5 (ISS A-2) track-identity annotation. This directory is a
> **migration-era, opt-in** test track. Its results never override the
> default track's conclusions.

## Identity

| 事实 | 依据 |
|------|------|
| 默认轨道是 `tests/modules/wpmmcc-ats/unit/` | `bash scripts/wptsall.sh test wp-unit` 打包并扫描 `unit/`(AGENTS.md §15.1);本目录**不在**扫描范围 |
| 本目录的 `run.php` 只是转发 shim | 它 `require` 兄弟 `unit/run.php`,而该 runner 只 glob `unit/{module}/test-*.php` |
| 历史来源 | tests/ SSOT 迁移前的旧位置;文件内仍引用旧插件 slug 路径(`wptsall-pro`) |
| 运行身份 | opt-in:需要手工把文件挂进容器 runner 才会执行;不进任何门禁 lane |

## 与默认轨道的关系(铁律)

1. **权威结论只认默认轨道** `tests/modules/wpmmcc-ats/unit/`。本目录任何
   通过/失败都只是迁移轨信号,不得覆盖、顶替或"平反"默认轨道结论。
2. 行为契约漂移时,先修默认轨道的权威测试,再把本目录同名测试同步为
   同行为副本(或删除该副本)。
3. 本目录测试允许落后(stale),但必须在下表登记,不得静默。

## 当前状态(2026-09-22 P2 批次 F-T6 处置后)

按铁律逐文件处置(证据:09-22 opt-in 实测,全部 15 文件挂载进 slot-u 经默认
runner 跑出真实 verdict):

| 文件 | 09-22 实测 | 处置 |
|------|-----------|------|
| `core/test-protocol-v2-security-unit.php` | 15/0 | **字节级同步**自默认轨(漂移 471 行已消) |
| `core/test-transport-middleware.php` | 2/0 | **字节级同步**(漂移 359 行) |
| `sites/test-translation-editor-unit.php` | 4/0 | **字节级同步**(漂移 18 行) |
| `tasks/test-automation-cron.php` | 4/0 | **字节级同步**(漂移 243 行) |
| `tasks/test-client-data-rest-controller.php` | 15/0 | **字节级同步**(09-12 同步后默认轨又前进,再漂 289 行,已消) |
| `tasks/test-sync-executor.php` | 1/4 | **字节级同步**(漂移 1160 行;默认轨版本为权威绿测) |
| `tasks/test-task-job-planner.php` | 5/0 | **字节级同步**(漂移 616 行) |
| `models/test-compatibility-declaration-service.php` | 2/0 | **已移植**至默认轨 `unit/models/`(钩子 `wptsall_model_compatibility_field_declarations` 的唯一覆盖;catalog 4 处证据已改指默认轨;默认轨复验 2/0) |
| `core/test-site-verification.php` | 3/0 | 保留;主体默认轨另有 `unit/core/test-site-verification-identity.php` 覆盖 |
| `sites/test-manual-translation-service-unit.php` | 3/0 | 保留;主体默认轨另有 4 文件覆盖(manual-translation-core 等) |
| `core/test-client-api-request-ip.php` | 0/6 | **stale 登记**:主体已变(老断言全败);catalog 引用 1 处、非唯一证据 |
| `models/test-model-object-service.php` | 6/3 | **stale 登记**:部分断言过期;catalog 6 处、均非唯一证据 |
| `tasks/test-client-tasks-rest-controller.php` | 7/5 | **stale 登记**;catalog 5 处、均非唯一证据 |
| `tasks/test-client-token-auth-unit.php` | 4/5 | **stale 登记**;catalog 4 处、均非唯一证据 |
| `tasks/test-plugin-activation-schema-unit.php` | 0/9 | **stale 登记**:激活 schema 断言已被默认轨/integration 迁移流取代;catalog 5 处、均非唯一证据 |

stale 五件的共性:断言写死迁移期行为,产品现行行为已由默认轨(2174/0)权威
覆盖。它们继续作为 catalog **非唯一**证据存在;若日后要清,须连同 catalog
证据行一并处置(92→处置后 88 处引用仍指本目录),不得直接删除文件。

- 历史(2026-09-12):`tasks/test-client-data-rest-controller.php` 曾字节级同步
  (15/0 opt-in 实测)——教训:**默认轨每前进都可能再造成漂移**,故本 README
  09-22 起按文件登记而非按一次性声明。
- 历史(2026-09-07):20/10/30;原 10 条 `translation_callback_*` stale 已随
  09-12 同步消失。

## 复现命令

```bash
# 默认轨(权威)
bash scripts/wptsall.sh test wp-unit
# 本轨单文件(opt-in,手工挂载;slot-u 即 env-unit,勿打主容器)
tar -C tests/modules/wpmmcc-ats -cf /tmp/wptsall-unit.tar unit
docker cp /tmp/wptsall-unit.tar wptsall-wp-lab-wordpress-slot-u:/tmp/
docker exec wptsall-wp-lab-wordpress-slot-u sh -c \
  'rm -rf /tmp/tests && mkdir -p /tmp/tests && tar -xf /tmp/wptsall-unit.tar -C /tmp/tests'
docker cp tests/modules/wpmmcc-ats/unit-plugin/tasks/test-client-data-rest-controller.php \
  wptsall-wp-lab-wordpress-slot-u:/tmp/tests/unit/tasks/test-client-data-rest-controller-plugin-track.php
docker exec -e WPTSALL_WP_ROOT=/var/www/html wptsall-wp-lab-wordpress-slot-u sh -c \
  'cd /tmp/tests/unit && php run.php --file=tasks/test-client-data-rest-controller-plugin-track.php'
```
