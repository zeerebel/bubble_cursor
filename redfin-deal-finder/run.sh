#!/usr/bin/env bash
# One-command launcher for macOS / Linux.
# Double-click won't work everywhere; if needed run:  bash run.sh
set -e
cd "$(dirname "$0")"

# Create an isolated environment the first time, then reuse it.
if [ ! -d ".venv" ]; then
  echo "First run: setting up..."
  python3 -m venv .venv
fi
# shellcheck disable=SC1091
source .venv/bin/activate
pip install -q -r requirements.txt

echo ""
echo "Opening http://127.0.0.1:5000  (press Ctrl+C to stop)"
# Try to open the browser automatically (mac: open, linux: xdg-open).
( sleep 1.5; (open http://127.0.0.1:5000 2>/dev/null || xdg-open http://127.0.0.1:5000 2>/dev/null) ) &
python app.py
