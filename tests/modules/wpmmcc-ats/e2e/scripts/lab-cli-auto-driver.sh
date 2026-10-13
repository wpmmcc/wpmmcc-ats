#!/usr/bin/env bash
# Thin wrapper: event-wake + legacy pid file for tooling.
exec "$(dirname "$0")/lab-cli-event-wake.sh" "$@"
