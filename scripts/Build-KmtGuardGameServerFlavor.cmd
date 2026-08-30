@echo off
setlocal EnableExtensions

if "%~1"=="" exit /b 2
if "%~2"=="" exit /b 2
if "%~3"=="" exit /b 2

set "SERVER_ROOT=%~f1"
set "BUILD_DIR=%~f2"
set "OUTPUT_DIR=%~f3"
set "DEVELOPMENT_BUILD=%~4"
if "%DEVELOPMENT_BUILD%"=="" set "DEVELOPMENT_BUILD=OFF"

set "CMAKE_EXE=C:\Program Files\CMake\bin\cmake.exe"
if not exist "%CMAKE_EXE%" set "CMAKE_EXE=C:\Program Files (x86)\CMake\bin\cmake.exe"
if not exist "%CMAKE_EXE%" set "CMAKE_EXE=C:\Program Files\Microsoft Visual Studio\2022\BuildTools\Common7\IDE\CommonExtensions\Microsoft\CMake\CMake\bin\cmake.exe"
if not exist "%CMAKE_EXE%" set "CMAKE_EXE=C:\Program Files (x86)\Microsoft Visual Studio\2022\BuildTools\Common7\IDE\CommonExtensions\Microsoft\CMake\CMake\bin\cmake.exe"
if not exist "%CMAKE_EXE%" (
    echo CMake was not found.
    exit /b 5
)

set "NINJA_EXE="
for %%I in (ninja.exe) do set "NINJA_EXE=%%~$PATH:I"
if not exist "%NINJA_EXE%" set "NINJA_EXE=C:\Program Files\Microsoft Visual Studio\2022\BuildTools\Common7\IDE\CommonExtensions\Microsoft\CMake\Ninja\ninja.exe"
if not exist "%NINJA_EXE%" set "NINJA_EXE=C:\Program Files (x86)\Microsoft Visual Studio\2022\BuildTools\Common7\IDE\CommonExtensions\Microsoft\CMake\Ninja\ninja.exe"
if not exist "%NINJA_EXE%" (
    echo Ninja was not found.
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
    -S "%SERVER_ROOT%" ^
    -B "%BUILD_DIR%" ^
    -G Ninja ^
    -DCMAKE_MAKE_PROGRAM:FILEPATH="%NINJA_EXE%" ^
    -DCMAKE_BUILD_TYPE=RelWithDebInfo ^
    -DKMT_DEVELOPMENT_BUILD=%DEVELOPMENT_BUILD% ^
    -DKMT_GAMESERVER_OUTPUT_DIRECTORY="%OUTPUT_DIR%"
if errorlevel 1 goto :configure_failed

"%CMAKE_EXE%" --build "%BUILD_DIR%" --target OutPutGS
set "BUILD_RESULT=%errorlevel%"

if not "%BUILD_RESULT%"=="0" goto :restore_production
if not exist "%OUTPUT_DIR%\KMTGuard_GameServer.dll" (
    echo KMTGuard_GameServer.dll was not produced.
    set "BUILD_RESULT=4"
)
goto :restore_production

:configure_failed
set "BUILD_RESULT=%errorlevel%"

:restore_production
"%CMAKE_EXE%" ^
    -S "%SERVER_ROOT%" ^
    -B "%BUILD_DIR%" ^
    -G Ninja ^
    -DCMAKE_MAKE_PROGRAM:FILEPATH="%NINJA_EXE%" ^
    -DCMAKE_BUILD_TYPE=RelWithDebInfo ^
    -DKMT_DEVELOPMENT_BUILD=OFF ^
    -DKMT_GAMESERVER_OUTPUT_DIRECTORY="C:/Gs"
if errorlevel 1 (
    echo Failed to restore the GameServer Production build configuration.
    exit /b 6
)

exit /b %BUILD_RESULT%
