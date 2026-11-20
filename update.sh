#!/usr/bin/env bash
# Shortcut: ./update.sh [GIT_REF]  ==  bash deploy/update.sh [GIT_REF]  (same args, same behaviour).
exec bash "$(cd "$(dirname "$0")" && pwd)/deploy/update.sh" "$@"
