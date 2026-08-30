@echo off
setlocal EnableExtensions

if "%~1"=="" exit /b 2
if "%~2"=="" exit /b 2
if "%~3"=="" exit /b 2

set "CLIENT_ROOT=%~f1"
set "BUILD_DIR=%~f2"
set "OUTPUT_DIR=%~f3"
set "DEVELOPMENT_BUILD=%~4"
if "%DEVELOPMENT_BUILD%"=="" set "DEVELOPMENT_BUILD=OFF"
set "DIAGNOSTIC_SKIP_SETUP=%~5"
if "%DIAGNOSTIC_SKIP_SETUP%"=="" set "DIAGNOSTIC_SKIP_SETUP=OFF"

set "CMAKE_EXE=C:\Program Files\CMake\bin\cmake.exe"
if not exist "%CMAKE_EXE%" set "CMAKE_EXE=C:\Program Files (x86)\CMake\bin\cmake.exe"
if not exist "%CMAKE_EXE%" set "CMAKE_EXE=C:\Program Files\Microsoft Visual Studio\2022\BuildTools\Common7\IDE\CommonExtensions\Microsoft\CMake\CMake\bin\cmake.exe"
if not exist "%CMAKE_EXE%" set "CMAKE_EXE=C:\Program Files (x86)\Microsoft Visual Studio\2022\BuildTools\Common7\IDE\CommonExtensions\Microsoft\CMake\CMake\bin\cmake.exe"
if not exist "%CMAKE_EXE%" (
    echo CMake was not found.
    exit /b 5
)

set "VS2005_VARS=C:\Program Files (x86)\Microsoft Visual Studio 8\Common7\Tools\vsvars32.bat"
if not exist "%VS2005_VARS%" (
    echo Visual Studio 2005 x86 tools were not found.
    exit /b 3
)

call "%VS2005_VARS%"
if errorlevel 1 exit /b %errorlevel%

"%CMAKE_EXE%" ^
    -G "NMake Makefiles" ^
    -DCMAKE_BUILD_TYPE=Release ^
    -DPUT_LOGLEVEL=PUT_WARNING ^
    -DKMT_DEVELOPMENT_BUILD=%DEVELOPMENT_BUILD% ^
    -DKMT_DIAGNOSTIC_SKIP_SETUP=%DIAGNOSTIC_SKIP_SETUP% ^
    -DKMT_CLIENT_OUTPUT_DIRECTORY="%OUTPUT_DIR%" ^
    -S "%CLIENT_ROOT%" ^
    -B "%BUILD_DIR%"
if errorlevel 1 exit /b %errorlevel%

"%CMAKE_EXE%" --build "%BUILD_DIR%" --target DevKit_DLL
if errorlevel 1 exit /b %errorlevel%

if not exist "%OUTPUT_DIR%\KMTGuardKit.dll" (
    echo KMTGuardKit.dll was not produced.
    exit /b 4
)

exit /b 0
