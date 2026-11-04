#!/usr/bin/env bash
# Scout Site Standard check for a theme: bash tools/theme-standard-check.sh <theme-dir> [--warn]
# Details and the check list: tools/theme-standard-check.py (this wrapper keeps the name the standard uses).
exec python3 "$(dirname "$0")/theme-standard-check.py" "$@"
