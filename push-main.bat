@echo off
setlocal EnableExtensions
title KSSMI - Commit and Push main

rem Always run from the folder containing this file, even when double-clicked.
cd /d "%~dp0"

git rev-parse --is-inside-work-tree >nul 2>nul
if errorlevel 1 (
    echo.
    echo ERROR: This file must stay in the KSSMI Git project folder.
    goto :end
)

echo.
echo Current changes:
git status --short
echo.

set "MESSAGE="
set /p "MESSAGE=Commit message: "
if not defined MESSAGE (
    echo.
    echo Nothing was committed: a commit message is required.
    goto :end
)

choice /C YN /M "Commit and push every listed change to origin/main"
if errorlevel 2 (
    echo.
    echo Cancelled. No files were changed.
    goto :end
)

git add -A
if errorlevel 1 goto :git_error

git commit -m "%MESSAGE%"
if errorlevel 1 goto :git_error

git push origin main
if errorlevel 1 goto :git_error

echo.
echo SUCCESS: main was pushed to GitHub.
goto :end

:git_error
echo.
echo ERROR: Git stopped. Read the message above and send it to Codex if needed.

:end
echo.
pause
endlocal
