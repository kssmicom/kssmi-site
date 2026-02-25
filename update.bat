@echo off
setlocal EnableDelayedExpansion
chcp 65001 >nul
cd /d "%~dp0"

echo ========================================
echo KSSMI Website Update Tool
echo ========================================
echo.

REM Generate a commit message automatically based on modified files
for /f "delims=" %%i in ('git diff --name-only HEAD') do (
    set FILELIST=!FILELIST! %%i,
)
if defined FILELIST (
    set MSG=Auto-update: !FILELIST:~0,-1!
) else (
    set MSG=Update website
)

REM [1/3] Stage and commit local changes first
echo [1/3] Adding and committing changes...
git add .
git commit -m "%MSG%" >nul 2>&1
echo Commit message: "%MSG%"
echo Done.

REM [2/3] Pull latest from GitHub (prevents rejection)
echo.
echo [2/3] Pulling latest from GitHub...
git pull origin main --rebase 2>&1
if %errorlevel% neq 0 (
    echo.
    echo [FAILED] Pull failed. Please resolve conflicts manually then run again.
    pause
    exit /b 1
)

REM [3/3] Push to GitHub
echo.
echo [3/3] Pushing to GitHub...
git push origin main 2>&1

if %errorlevel% equ 0 (
    echo.
    echo ========================================
    echo SUCCESS! Website updated.
    echo ========================================
    echo.
    start https://github.com/kssmicom/kssmi-site/actions
) else (
    echo.
    echo [FAILED] Push failed. Check the error above.
)

echo.
pause
