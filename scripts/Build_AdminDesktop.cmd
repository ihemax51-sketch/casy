@echo off
setlocal
cd /d "%~dp0.."
dotnet build "KMTGuard.AdminDesktop\KMTGuard.AdminDesktop.csproj" -c Release
