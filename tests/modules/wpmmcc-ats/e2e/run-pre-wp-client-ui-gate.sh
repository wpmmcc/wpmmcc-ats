#!/usr/bin/env bash
# Thin alias — canonical path: client-ui-setup/pre-wp-bind/run-gate.sh
exec bash "$(cd "$(dirname "$0")" && pwd)/client-ui-setup/pre-wp-bind/run-gate.sh" "$@"
