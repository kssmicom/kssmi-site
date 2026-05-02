@echo off
setlocal
set DIR=%~dp0

echo Cleaning up log and temp files from kssmi-site root...
echo.

del /f /q "%DIR%action.log"
del /f /q "%DIR%astro-check.log"
del /f /q "%DIR%build.log"
del /f /q "%DIR%build2.log"
del /f /q "%DIR%build3.log"
del /f /q "%DIR%build4.log"
del /f /q "%DIR%build_error.log"
del /f /q "%DIR%build_trace.log"
del /f /q "%DIR%build_output.log"
del /f /q "%DIR%check.log"
del /f /q "%DIR%check2.log"
del /f /q "%DIR%check3.log"
del /f /q "%DIR%check4.log"
del /f /q "%DIR%check5.log"
del /f /q "%DIR%check_final.log"
del /f /q "%DIR%check_final_2.log"
del /f /q "%DIR%check_final_3.log"
del /f /q "%DIR%check_final_4.log"
del /f /q "%DIR%dev_error4.log"
del /f /q "%DIR%error_log.txt"
del /f /q "%DIR%server.log"
del /f /q "%DIR%tsc.log"
del /f /q "%DIR%validation.log"
del /f /q "%DIR%verbose_build_log.txt"

del /f /q "%DIR%backslashes.txt"
del /f /q "%DIR%build2.txt"
del /f /q "%DIR%build_full.txt"
del /f /q "%DIR%build_log.txt"
del /f /q "%DIR%build_output.txt"
del /f /q "%DIR%check_out.txt"
del /f /q "%DIR%check_output.txt"
del /f /q "%DIR%check_result.txt"
del /f /q "%DIR%dev_log2.txt"
del /f /q "%DIR%git_log.txt"
del /f /q "%DIR%git_log_dates.txt"
del /f /q "%DIR%utf8.txt"
del /f /q "%DIR%yaml_errors.txt"

del /f /q "%DIR%check.json"
del /f /q "%DIR%check_err.json"
del /f /q "%DIR%email-logs.json"
del /f /q "%DIR%github_runs.json"
del /f /q "%DIR%jobs.json"
del /f /q "%DIR%run.json"
del /f /q "%DIR%runs.json"
del /f /q "%DIR%translation_output.json"

del /f /q "%DIR%debug-counts.js"
del /f /q "%DIR%debug-ids.mjs"
del /f /q "%DIR%test-paths.mjs"

echo.
echo Done! All log/temp files deleted.
echo You can delete this Cleanup-Logs.bat file too.
pause
