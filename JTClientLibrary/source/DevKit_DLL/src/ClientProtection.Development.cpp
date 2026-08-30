#include "ClientProtection.h"

bool InitializeKmtClientProtection(HINSTANCE)
{
    OutputDebugStringA(
        "[KMTGuardKit] Developer/Test Build - customer package activation is disabled.\n");
    return true;
}
