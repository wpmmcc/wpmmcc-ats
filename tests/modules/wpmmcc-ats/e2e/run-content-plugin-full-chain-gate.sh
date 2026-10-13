#!/usr/bin/env bash
# Thin alias — canonical: content-plugin-full-chain/run-gate.sh
exec bash "$(cd "$(dirname "$0")" && pwd)/content-plugin-full-chain/run-gate.sh" "$@"
