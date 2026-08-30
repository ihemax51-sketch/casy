#include "ProtectionRuntime.h"

#include <windows.h>
#include <tlhelp32.h>
#include <string>

namespace
{
    typedef LONG (NTAPI* NtQueryInformationProcessFunction)(HANDLE, ULONG, PVOID, ULONG, PULONG);
    typedef BOOL (WINAPI* CheckRemoteDebuggerPresentFunction)(HANDLE, PBOOL);

    struct EncodedName
    {
        const unsigned char* Data;
        size_t Length;
    };

    const unsigned char NAME_OLLYDBG[] = { 0x35, 0x36, 0x36, 0x23, 0x3E, 0x38, 0x3D, 0x74, 0x3F, 0x22, 0x3F };
    const unsigned char NAME_X32DBG[] = { 0x22, 0x69, 0x68, 0x3E, 0x38, 0x3D, 0x74, 0x3F, 0x22, 0x3F };
    const unsigned char NAME_X64DBG[] = { 0x22, 0x6C, 0x6E, 0x3E, 0x38, 0x3D, 0x74, 0x3F, 0x22, 0x3F };
    const unsigned char NAME_IDA[] = { 0x33, 0x3E, 0x3B, 0x74, 0x3F, 0x22, 0x3F };
    const unsigned char NAME_IDA64[] = { 0x33, 0x3E, 0x3B, 0x6C, 0x6E, 0x74, 0x3F, 0x22, 0x3F };
    const unsigned char NAME_CHEATENGINE[] = { 0x39, 0x32, 0x3F, 0x3B, 0x2E, 0x3F, 0x34, 0x3D, 0x33, 0x34, 0x3F, 0x74, 0x3F, 0x22, 0x3F };
    const unsigned char NAME_SCYLLA[] = { 0x29, 0x39, 0x23, 0x36, 0x36, 0x3B, 0x74, 0x3F, 0x22, 0x3F };
    const unsigned char NAME_DNSPY[] = { 0x3E, 0x34, 0x29, 0x2A, 0x23, 0x74, 0x3F, 0x22, 0x3F };
    const unsigned char NAME_ILSPY[] = { 0x33, 0x36, 0x29, 0x2A, 0x23, 0x74, 0x3F, 0x22, 0x3F };
    const unsigned char NAME_GHIDRA[] = { 0x3D, 0x32, 0x33, 0x3E, 0x28, 0x3B, 0x74, 0x3F, 0x22, 0x3F };

    const EncodedName BLOCKED_NAMES[] = {
        { NAME_OLLYDBG, sizeof(NAME_OLLYDBG) },
        { NAME_X32DBG, sizeof(NAME_X32DBG) },
        { NAME_X64DBG, sizeof(NAME_X64DBG) },
        { NAME_IDA, sizeof(NAME_IDA) },
        { NAME_IDA64, sizeof(NAME_IDA64) },
        { NAME_CHEATENGINE, sizeof(NAME_CHEATENGINE) },
        { NAME_SCYLLA, sizeof(NAME_SCYLLA) },
        { NAME_DNSPY, sizeof(NAME_DNSPY) },
        { NAME_ILSPY, sizeof(NAME_ILSPY) },
        { NAME_GHIDRA, sizeof(NAME_GHIDRA) }
    };

    std::wstring DecodeName(const EncodedName& value)
    {
        std::wstring output(value.Length, L'\0');
        for (size_t index = 0; index < value.Length; ++index)
            output[index] = static_cast<wchar_t>(value.Data[index] ^ 0x5A);
        return output;
    }

    bool HasDebugObject()
    {
        HMODULE ntdll = GetModuleHandleA("ntdll.dll");
        NtQueryInformationProcessFunction query = ntdll
            ? reinterpret_cast<NtQueryInformationProcessFunction>(GetProcAddress(ntdll, "NtQueryInformationProcess"))
            : NULL;
        if (!query)
            return false;

        ULONG_PTR debugPort = 0;
        if (query(GetCurrentProcess(), 7, &debugPort, sizeof(debugPort), NULL) == 0 && debugPort != 0)
            return true;

        HANDLE debugObject = NULL;
        if (query(GetCurrentProcess(), 30, &debugObject, sizeof(debugObject), NULL) == 0 && debugObject != NULL)
            return true;

        ULONG debugFlags = 1;
        return query(GetCurrentProcess(), 31, &debugFlags, sizeof(debugFlags), NULL) == 0 && debugFlags == 0;
    }

    bool HasBlockedProcess()
    {
        HANDLE snapshot = CreateToolhelp32Snapshot(TH32CS_SNAPPROCESS, 0);
        if (snapshot == INVALID_HANDLE_VALUE)
            return false;

        PROCESSENTRY32W entry = {0};
        entry.dwSize = sizeof(entry);
        bool found = false;
        if (Process32FirstW(snapshot, &entry))
        {
            do
            {
                for (size_t index = 0; index < sizeof(BLOCKED_NAMES) / sizeof(BLOCKED_NAMES[0]); ++index)
                {
                    const std::wstring blocked = DecodeName(BLOCKED_NAMES[index]);
                    if (_wcsicmp(entry.szExeFile, blocked.c_str()) == 0)
                    {
                        found = true;
                        break;
                    }
                }
                if (found)
                    break;
            }
            while (Process32NextW(snapshot, &entry));
        }
        CloseHandle(snapshot);
        return found;
    }
}

bool KmtIsAnalysisEnvironment()
{
    if (IsDebuggerPresent())
        return true;

    HMODULE kernel = GetModuleHandleA("kernel32.dll");
    CheckRemoteDebuggerPresentFunction checkRemote = kernel
        ? reinterpret_cast<CheckRemoteDebuggerPresentFunction>(GetProcAddress(kernel, "CheckRemoteDebuggerPresent"))
        : NULL;
    if (checkRemote)
    {
        BOOL remoteDebugger = FALSE;
        if (checkRemote(GetCurrentProcess(), &remoteDebugger) && remoteDebugger)
            return true;
    }

    return HasDebugObject() || HasBlockedProcess();
}
