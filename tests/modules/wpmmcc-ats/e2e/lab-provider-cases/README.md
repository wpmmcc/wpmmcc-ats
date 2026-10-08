# Lab provider UI cases (generated)

Do **not** edit by hand. Regenerate:

```bash
python3 scripts/sync-lab-provider-ui-cases.py
```

- Cases: **129** (from mock `http://127.0.0.1:9090/api/v1/lab-provider-cases`)
- Each `*.json` is one provider/auth-profile script: auth keys, mock URL, response path, UI fill order.
- Playwright / Tauri drivers load `manifest.json` + these files (or the live mock endpoint).

