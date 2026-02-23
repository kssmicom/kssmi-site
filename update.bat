@echo off
chcp 65001 >nul
cd /d "D:\001 Tools\004 Desk\Desk\Tools\Kssmi\kssmi-site"

echo ========================================
echo KSSMI Website Update Tool
echo ========================================
echo.

REM Set git credentials
git remote set-url origin https://kssmicom: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@github.com/kssmicom/kssmi-site.git

:COMMIT
echo [1/2] Adding and committing changes...
git add .
git commit -m "Update website" >nul 2>&1

:PUSH
echo [2/2] Pushing to GitHub...
git push 2>&1

if %errorlevel% equ 0 (
    echo.
    echo ========================================
    echo SUCCESS! Website updated.
    echo ========================================
    echo.
    start https://github.com/kssmicom/kssmi-site/actions
    goto END
)

echo.
echo [FAILED] Push failed. Retrying in 3 seconds...
timeout /t 3 /nobreak >nul
goto PUSH

:END
echo.
pause
