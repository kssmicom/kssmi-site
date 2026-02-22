@echo off
cd /d "D:\001 Tools\004 Desk\Desk\Tools\Kssmi\kssmi-site"

echo ========================================
echo KSSMI Website Update Tool
echo ========================================
echo.

set /p MESSAGE="Enter update description: "

echo.
echo Adding files...
git add .

echo.
echo Committing changes...
git commit -m "%MESSAGE%"

echo.
echo Pushing to GitHub...
git remote set-url origin https://kssmicom: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@github.com/kssmicom/kssmi-site.git
git push

if %errorlevel% neq 0 (
    echo.
    echo [ERROR] Push failed. Check your internet connection.
    echo You may need to use VPN or try again later.
)

echo.
echo ========================================
echo Check status: https://github.com/kssmicom/kssmi-site/actions
echo ========================================
echo.
pause
