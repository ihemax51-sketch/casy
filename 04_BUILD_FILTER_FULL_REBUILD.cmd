@echo off
setlocal EnableExtensions

title KMTGuard Complete Professional Release
for %%I in ("%~dp0.") do set "ROOT=%%~fI"
set "SCRIPT=%ROOT%\scripts\Publish-KmtGuardRelease.ps1"

echo ============================================================
echo  KMTGuard Complete Professional Release
echo ============================================================
echo.
echo Building and validating the release before deployment...
echo Runtime settings, license and logs will be preserved.
echo.

powershell -NoProfile -ExecutionPolicy Bypass -File "%SCRIPT%" -Configuration Release -BuildRoot "D:\KMTGuard-build"
set "RESULT=%ERRORLEVEL%"

echo.
if "%RESULT%"=="0" (
    echo ============================================================
    echo  SUCCESS - D:\KMTGuard-build is ready
    echo ============================================================
) else (
    echo ============================================================
    echo  FAILED - existing runtime files were kept or restored
    echo ============================================================
)
echo.
if not defined NO_PAUSE pause
endlocal
exit /b %RESULT%
