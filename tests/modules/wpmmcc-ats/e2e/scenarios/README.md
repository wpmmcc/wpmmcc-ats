# Scenario Pack Standard

本目录用于维护“独立场景脚本包”，统一开发与测试的场景入口协议。

## 目录规范

- `tests/modules/wpmmcc-ats/e2e/scenarios/<product>/<scenario_id>/`
  - `spec.json`
  - `run.sh`
  - `README.md`

## 必填字段（spec.json）

- `scenario_id`
- `requirement_id`
- `owner`
- `layer`
- `product`
- `surface`
- `execution.command`

## 统一输出字段

每次执行会输出 `tests/modules/wpmmcc-ats/e2e/runtime/scenario-runs/*.json`，包含：

- `status`
- `category`
- `surface`
- `message`
- `next_action`
- `evidence`

## 统一编排

- 单场景执行：`bash <scenario_dir>/run.sh [--dry-run]`
- 场景包执行：`bash tests/modules/wpmmcc-ats/e2e/scenarios/run-pack.sh [--product ...] [--layer ...] [--dry-run]`
