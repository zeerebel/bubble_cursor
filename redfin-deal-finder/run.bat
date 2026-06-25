@echo off
REM One-command launcher for Windows. Double-click this file.
cd /d "%~dp0"

if not exist ".venv" (
  echo First run: setting up...
  python -m venv .venv
)
call .venv\Scripts\activate.bat
pip install -q -r requirements.txt

echo.
echo Opening http://127.0.0.1:5000  (close this window to stop)
start "" http://127.0.0.1:5000
python app.py
