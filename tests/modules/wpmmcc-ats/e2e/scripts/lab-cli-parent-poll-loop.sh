#!/usr/bin/env bash
# Deprecated: 5min poll merged into lab-cli-event-wake.sh (stdout → monitored shell).
echo "Poll loop lives in lab-cli-event-wake.sh (LAB_PARENT_POLL_SEC=300)." >&2
echo "Restart event-wake: bash lab-cli-ensure-wake.sh" >&2
exec bash "$(cd "$(dirname "$0")" && pwd)/lab-cli-parent-check.sh"
