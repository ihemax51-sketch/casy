#pragma once

#include <windows.h>

void WriteClientStartupDiagnostic(const char* message);

bool BeginClientInitialization();
void MarkClientInitializationSucceeded();
void MarkClientInitializationFailed();
bool WaitForClientInitialization(DWORD timeoutMilliseconds);
