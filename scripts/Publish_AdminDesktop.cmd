@echo off
setlocal
cd /d "%~dp0.."
set "NO_PAUSE=1"
call "04_BUILD_FILTER_FULL_REBUILD.cmd"
exit /b %errorlevel%
