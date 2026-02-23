@echo off
cd /d "D:\001 Tools\004 Desk\Desk\Tools\Kssmi\kssmi-site"

echo ========================================
echo KSSMI Website Update Tool
echo ========================================
echo.

REM Set git credentials
git remote set-url origin https://kssmicom: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@github.com/kssmicom/kssmi-site.git

echo Adding files...
git add .

echo.
echo Committing changes (if any)...
git commit -m "Update website" 2>nul

echo.
echo Pushing to GitHub...
git push

if %errorlevel% neq 0 (
    echo.
    echo ========================================
    echo [ERROR] Push failed!
    echo ========================================
    echo.
    echo Possible causes:
    echo - No internet connection
    echo - GitHub is blocked (try VPN)
    echo - No changes to push
    echo.
    echo Run this script again to retry.
) else (
    echo.
    echo ========================================
    echo [SUCCESS] Pushed to GitHub!
    echo ========================================
)

echo.
echo Check deployment: https://github.com/kssmicom/kssmi-site/actions
echo.
pause
