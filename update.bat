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
git push

echo.
echo ========================================
echo Website is updating!
echo Check: https://github.com/kssmicom/kssmi-site/actions
echo ========================================
echo.
pause
