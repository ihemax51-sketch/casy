@echo off
setlocal EnableExtensions EnableDelayedExpansion

title KMTGuard Client DLL Builder - KMTGuardKit
color 0A

rem ================================================================
rem  KMTGuard Client DLL Builder
rem  Builds JTClientLibrary\source\DevKit_DLL as KMTGuardKit.dll.
rem
rem  Usage:
rem    Build_KMTGuardKit_DLL.cmd          Full rebuild
rem    Build_KMTGuardKit_DLL.cmd rebuild  Full rebuild
rem    Build_KMTGuardKit_DLL.cmd build    Incremental build
rem    Build_KMTGuardKit_DLL.cmd clean    Remove build dir and old KMTGuardKit output
rem ================================================================

for %%I in ("%~dp0..") do set "PROJECT_ROOT=%%~fI"
set "CLIENT_ROOT=%PROJECT_ROOT%\JTClientLibrary"
set "BUILD_DIR=%CLIENT_ROOT%\build-client-dll"
set "BUILD_TYPE=Release"
set "TARGET_NAME=DevKit_DLL"
set "OUTPUT_NAME=KMTGuardKit"
set "BIN_DIR=%CLIENT_ROOT%\BinOut\%BUILD_TYPE%"
set "DESKTOP_DIR=%USERPROFILE%\Desktop"
set "PACKAGE_ROOT=%DESKTOP_DIR%\KMTGuardKit_DLL_Build"
set "LOG_DIR=%PACKAGE_ROOT%\logs"
set "STAMP=%DATE:~-4%%DATE:~4,2%%DATE:~7,2%_%TIME:~0,2%%TIME:~3,2%%TIME:~6,2%"
set "STAMP=%STAMP: =0%"
set "PACKAGE_DIR=%PACKAGE_ROOT%\%STAMP%"
set "LOG_FILE=%LOG_DIR%\build_%STAMP%.log"
set "ACTION=%~1"

if "%ACTION%"=="" set "ACTION=rebuild"
if /I "%ACTION%"=="/rebuild" set "ACTION=rebuild"
if /I "%ACTION%"=="rebuild" set "DO_REBUILD=1"
if /I "%ACTION%"=="/build" set "ACTION=build"
if /I "%ACTION%"=="build" set "DO_REBUILD="
if /I "%ACTION%"=="/clean" set "ACTION=clean"
if /I "%ACTION%"=="clean" set "CLEAN_ONLY=1"

call :banner
call :prepare_dirs || goto :fail
call :verify_paths || goto :fail

if /I not "%ACTION%"=="rebuild" if /I not "%ACTION%"=="build" if /I not "%ACTION%"=="clean" (
    echo ERROR: Unknown action "%ACTION%".
    echo Use: build, rebuild, or clean.
    goto :fail
)

if defined CLEAN_ONLY (
    call :section "Cleaning"
    call :safe_rmdir "%BUILD_DIR%" || goto :fail
    if exist "%BIN_DIR%\%OUTPUT_NAME%.*" del /Q "%BIN_DIR%\%OUTPUT_NAME%.*" >> "%LOG_FILE%" 2>&1
    echo Clean completed.
    echo Log: "%LOG_FILE%"
    pause
    exit /B 0
)

call :find_cmake || goto :fail
call :load_vs2005 || goto :fail

if defined DO_REBUILD (
    call :section "Cleaning build directory"
    call :safe_rmdir "%BUILD_DIR%" || goto :fail
)

call :configure || goto :fail
call :build || goto :fail
call :package || goto :fail

call :section "DONE"
echo.
echo Build succeeded.
echo DLL output : "%BIN_DIR%\%OUTPUT_NAME%.dll"
echo Full copy  : "%PACKAGE_DIR%"
echo Build log  : "%LOG_FILE%"
echo.
pause
exit /B 0

:banner
echo ================================================================
echo  KMTGuard Client DLL Builder
echo ================================================================
echo Project : %CLIENT_ROOT%
echo Target  : %TARGET_NAME% ^(%OUTPUT_NAME%.dll^)
echo Config  : %BUILD_TYPE%
echo.
exit /B 0

:prepare_dirs
if not exist "%PACKAGE_ROOT%" mkdir "%PACKAGE_ROOT%" >NUL 2>&1
if not exist "%LOG_DIR%" mkdir "%LOG_DIR%" >NUL 2>&1
if not exist "%LOG_DIR%" (
    echo ERROR: Could not create log directory "%LOG_DIR%".
    exit /B 1
)
echo ==== Client DLL build started %DATE% %TIME% ==== > "%LOG_FILE%"
exit /B 0

:section
echo.
echo [%~1]
echo ==== %~1 ====>> "%LOG_FILE%"
exit /B 0

:verify_paths
call :section "Checking project"
if not exist "%CLIENT_ROOT%\CMakeLists.txt" (
    echo ERROR: Client root is invalid: "%CLIENT_ROOT%"
    echo ERROR: Client root is invalid: "%CLIENT_ROOT%">> "%LOG_FILE%"
    exit /B 1
)

if not exist "%CLIENT_ROOT%\source\DevKit_DLL\CMakeLists.txt" (
    echo ERROR: DevKit_DLL target folder was not found.
    echo ERROR: DevKit_DLL target folder was not found.>> "%LOG_FILE%"
    exit /B 1
)

if not exist "%CLIENT_ROOT%\source\third-party\dxsdk\Include\d3d9.h" (
    echo ERROR: Internal DirectX SDK headers are missing.
    echo ERROR: Missing "%CLIENT_ROOT%\source\third-party\dxsdk\Include\d3d9.h">> "%LOG_FILE%"
    exit /B 1
)

if not exist "%CLIENT_ROOT%\source\third-party\dxsdk\Lib\d3d9.lib" (
    echo ERROR: Internal DirectX SDK libraries are missing.
    echo ERROR: Missing "%CLIENT_ROOT%\source\third-party\dxsdk\Lib\d3d9.lib">> "%LOG_FILE%"
    exit /B 1
)

echo Project OK.
echo Project OK.>> "%LOG_FILE%"
exit /B 0

:find_cmake
call :section "Finding CMake"
set "CMAKE_EXE="

for %%P in (
    "cmake.exe"
    "C:\Program Files\CMake\bin\cmake.exe"
    "C:\Program Files (x86)\CMake\bin\cmake.exe"
    "C:\Program Files\Microsoft Visual Studio\2022\BuildTools\Common7\IDE\CommonExtensions\Microsoft\CMake\CMake\bin\cmake.exe"
    "C:\Program Files (x86)\Microsoft Visual Studio\2022\BuildTools\Common7\IDE\CommonExtensions\Microsoft\CMake\CMake\bin\cmake.exe"
) do (
    if not defined CMAKE_EXE (
        if "%%~$PATH:P" NEQ "" set "CMAKE_EXE=%%~$PATH:P"
        if exist "%%~P" set "CMAKE_EXE=%%~P"
    )
)

if not defined CMAKE_EXE (
    echo ERROR: CMake was not found.
    echo ERROR: CMake was not found.>> "%LOG_FILE%"
    exit /B 1
)

echo CMake: "%CMAKE_EXE%"
echo CMake: "%CMAKE_EXE%">> "%LOG_FILE%"
exit /B 0

:load_vs2005
call :section "Loading Visual Studio 2005 x86 environment"
set "VS2005_VARS="

if defined VS80COMNTOOLS if exist "%VS80COMNTOOLS%vsvars32.bat" set "VS2005_VARS=%VS80COMNTOOLS%vsvars32.bat"
if not defined VS2005_VARS if exist "C:\Program Files (x86)\Microsoft Visual Studio 8\Common7\Tools\vsvars32.bat" set "VS2005_VARS=C:\Program Files (x86)\Microsoft Visual Studio 8\Common7\Tools\vsvars32.bat"
if not defined VS2005_VARS if exist "C:\Program Files\Microsoft Visual Studio 8\Common7\Tools\vsvars32.bat" set "VS2005_VARS=C:\Program Files\Microsoft Visual Studio 8\Common7\Tools\vsvars32.bat"

if not defined VS2005_VARS (
    echo ERROR: Visual Studio 2005 C++ tools were not found.
    echo ERROR: Visual Studio 2005 C++ tools were not found.>> "%LOG_FILE%"
    exit /B 1
)

call "%VS2005_VARS%" >> "%LOG_FILE%" 2>&1
if errorlevel 1 (
    echo ERROR: Failed to load VS2005 environment.
    exit /B 1
)

where cl.exe >> "%LOG_FILE%" 2>&1
if errorlevel 1 (
    echo ERROR: cl.exe was not found after loading VS2005.
    exit /B 1
)

where nmake.exe >> "%LOG_FILE%" 2>&1
if errorlevel 1 (
    echo ERROR: nmake.exe was not found after loading VS2005.
    exit /B 1
)

echo VS2005 environment loaded.
exit /B 0

:configure
call :section "Configuring CMake"
if not exist "%BUILD_DIR%" mkdir "%BUILD_DIR%" >> "%LOG_FILE%" 2>&1

"%CMAKE_EXE%" ^
    -G "NMake Makefiles" ^
    -DCMAKE_BUILD_TYPE=%BUILD_TYPE% ^
    -DPUT_LOGLEVEL=PUT_WARNING ^
    -S "%CLIENT_ROOT%" ^
    -B "%BUILD_DIR%" >> "%LOG_FILE%" 2>&1

if not errorlevel 1 (
    echo Configure completed.
    exit /B 0
)

echo Configure failed once. Recreating build directory and retrying...
echo Configure failed once. Recreating build directory and retrying...>> "%LOG_FILE%"
call :safe_rmdir "%BUILD_DIR%" || exit /B 1
mkdir "%BUILD_DIR%" >> "%LOG_FILE%" 2>&1

"%CMAKE_EXE%" ^
    -G "NMake Makefiles" ^
    -DCMAKE_BUILD_TYPE=%BUILD_TYPE% ^
    -DPUT_LOGLEVEL=PUT_WARNING ^
    -S "%CLIENT_ROOT%" ^
    -B "%BUILD_DIR%" >> "%LOG_FILE%" 2>&1

if errorlevel 1 (
    echo ERROR: CMake configure failed. See log:
    echo "%LOG_FILE%"
    exit /B 1
)

echo Configure completed.
exit /B 0

:build
call :section "Building %TARGET_NAME%"
"%CMAKE_EXE%" --build "%BUILD_DIR%" --target "%TARGET_NAME%" >> "%LOG_FILE%" 2>&1

if errorlevel 1 (
    echo ERROR: Build failed. See log:
    echo "%LOG_FILE%"
    exit /B 1
)

if not exist "%BIN_DIR%\%OUTPUT_NAME%.dll" (
    echo ERROR: Build finished but DLL was not found:
    echo "%BIN_DIR%\%OUTPUT_NAME%.dll"
    echo ERROR: DLL not found after build.>> "%LOG_FILE%"
    exit /B 1
)

echo Build completed.
exit /B 0

:package
call :section "Copying full DLL output"
if not exist "%PACKAGE_DIR%" mkdir "%PACKAGE_DIR%" >> "%LOG_FILE%" 2>&1
if not exist "%PACKAGE_DIR%" (
    echo ERROR: Could not create package directory "%PACKAGE_DIR%".
    exit /B 1
)

for %%F in ("%BIN_DIR%\*") do (
    if exist "%%~fF" copy /Y "%%~fF" "%PACKAGE_DIR%\" >> "%LOG_FILE%" 2>&1
)

copy /Y "%LOG_FILE%" "%PACKAGE_DIR%\" >NUL 2>&1

echo Copied to: "%PACKAGE_DIR%"
echo Copied to: "%PACKAGE_DIR%">> "%LOG_FILE%"
exit /B 0

:safe_rmdir
set "TARGET_DIR=%~1"
if "%TARGET_DIR%"=="" exit /B 0
if not exist "%TARGET_DIR%" exit /B 0

echo %TARGET_DIR% | findstr /I /B /C:"%CLIENT_ROOT%" >NUL
if errorlevel 1 (
    echo ERROR: Refusing to delete outside client root: "%TARGET_DIR%"
    echo ERROR: Refusing to delete outside client root: "%TARGET_DIR%">> "%LOG_FILE%"
    exit /B 1
)

rmdir /S /Q "%TARGET_DIR%" >> "%LOG_FILE%" 2>&1
exit /B %ERRORLEVEL%

:fail
echo.
echo ================================================================
echo  BUILD FAILED
echo ================================================================
echo Log: "%LOG_FILE%"
echo.
if exist "%LOG_FILE%" (
    echo Last log lines:
    powershell -NoProfile -ExecutionPolicy Bypass -Command "Get-Content -LiteralPath '%LOG_FILE%' -Tail 45" 2>NUL
)
echo.
pause
exit /B 1
