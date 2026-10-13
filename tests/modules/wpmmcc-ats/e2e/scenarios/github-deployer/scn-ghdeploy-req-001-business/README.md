# SCN-GHDEPLOY-REQ-001-BUSINESS

- requirement_id: `REQ-GHDEPLOY-001`
- owner: `qa`
- layer: `business`
- product: `github-deployer`

用途：

- 作为 GitHub Deployer 核心业务场景入口，验证模板读取与部署计划业务链路。

执行：

```bash
bash tests/modules/wpmmcc-ats/e2e/scenarios/github-deployer/scn-ghdeploy-req-001-business/run.sh
```

快速验证：

```bash
bash tests/modules/wpmmcc-ats/e2e/scenarios/github-deployer/scn-ghdeploy-req-001-business/run.sh --dry-run
```
