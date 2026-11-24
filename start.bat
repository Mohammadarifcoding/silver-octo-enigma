@echo off
setlocal
cd /d "%~dp0"
where php >nul 2>&1
if errorlevel 1 (
  echo PHP was not found. Install PHP 8.2 or newer with pdo_sqlite enabled.
  pause
  exit /b 1
)
php run.php %*
if errorlevel 1 (
  echo If port 8080 is busy, try: php run.php 8081
  pause
)
