#!/usr/bin/env python3
from __future__ import annotations

import runpy
import sys
from pathlib import Path


BRIDGE_PATH = Path(__file__).resolve().parent / "cli" / "vanna_bridge.py"

if not BRIDGE_PATH.is_file():
    sys.stdout.write(
        '{"ok": false, "error": "Brakuje pliku cli/vanna_bridge.py.", "details": "Fallback wrapper nie znalazl docelowego mostka."}'
    )
    sys.stdout.flush()
    raise SystemExit(1)

if __name__ == "__main__":
    runpy.run_path(str(BRIDGE_PATH), run_name="__main__")
