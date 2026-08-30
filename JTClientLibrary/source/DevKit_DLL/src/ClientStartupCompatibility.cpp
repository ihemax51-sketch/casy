#include "ClientStartupCompatibility.h"

#include <windows.h>
#include <stdio.h>
#include <string.h>

namespace
{
    const DWORD kStartupLogMaximumBytes = 1024 * 1024;
    volatile LONG g_clientInitializationState = 0;

    void BuildStartupLogPath(char* path, size_t pathSize)
    {
        if (path == NULL || pathSize == 0)
            return;

        path[0] = '\0';
        char executablePath[MAX_PATH] = {0};
        if (GetModuleFileNameA(NULL, executablePath, MAX_PATH - 1) == 0)
            return;

        char* separator = strrchr(executablePath, '\\');
        if (separator == NULL)
            return;

        *separator = '\0';

        char settingDirectory[MAX_PATH] = {0};
        _snprintf(settingDirectory, MAX_PATH - 1, "%s\\Setting", executablePath);
        settingDirectory[MAX_PATH - 1] = '\0';
        CreateDirectoryA(settingDirectory, NULL);

        _snprintf(path, pathSize - 1, "%s\\KMTGuardKit-startup.log", settingDirectory);
        path[pathSize - 1] = '\0';
    }
}

bool BeginClientInitialization()
{
    return InterlockedCompareExchange(
        &g_clientInitializationState,
        1,
        0) == 0;
}

void MarkClientInitializationSucceeded()
{
    InterlockedExchange(&g_clientInitializationState, 2);
}

void MarkClientInitializationFailed()
{
    InterlockedExchange(&g_clientInitializationState, -1);
}

bool WaitForClientInitialization(DWORD timeoutMilliseconds)
{
    const DWORD startedAt = GetTickCount();

    for (;;) {
        const LONG state = InterlockedCompareExchange(
            &g_clientInitializationState,
            0,
            0);
        if (state == 2)
            return true;
        if (state < 0)
            return false;
        if (GetTickCount() - startedAt >= timeoutMilliseconds)
            return false;

        Sleep(1);
    }
}

void WriteClientStartupDiagnostic(const char* message)
{
    if (message == NULL)
        return;

    char logPath[MAX_PATH] = {0};
    BuildStartupLogPath(logPath, MAX_PATH);
    if (logPath[0] == '\0')
        return;

    DWORD creationDisposition = OPEN_ALWAYS;
    WIN32_FILE_ATTRIBUTE_DATA attributes;
    ZeroMemory(&attributes, sizeof(attributes));
    if (GetFileAttributesExA(logPath, GetFileExInfoStandard, &attributes) &&
        (attributes.nFileSizeHigh != 0 ||
         attributes.nFileSizeLow >= kStartupLogMaximumBytes)) {
        creationDisposition = CREATE_ALWAYS;
    }

    HANDLE file = CreateFileA(
        logPath,
        GENERIC_WRITE,
        FILE_SHARE_READ | FILE_SHARE_WRITE,
        NULL,
        creationDisposition,
        FILE_ATTRIBUTE_NORMAL,
        NULL);
    if (file == INVALID_HANDLE_VALUE)
        return;

    SetFilePointer(file, 0, NULL, FILE_END);

    SYSTEMTIME now;
    GetLocalTime(&now);
    char line[768] = {0};
    _snprintf(
        line,
        sizeof(line) - 1,
        "%04u-%02u-%02u %02u:%02u:%02u.%03u [pid:%lu tid:%lu] %s\r\n",
        now.wYear,
        now.wMonth,
        now.wDay,
        now.wHour,
        now.wMinute,
        now.wSecond,
        now.wMilliseconds,
        GetCurrentProcessId(),
        GetCurrentThreadId(),
        message);
    line[sizeof(line) - 1] = '\0';

    DWORD written = 0;
    WriteFile(file, line, static_cast<DWORD>(strlen(line)), &written, NULL);
    CloseHandle(file);
}
