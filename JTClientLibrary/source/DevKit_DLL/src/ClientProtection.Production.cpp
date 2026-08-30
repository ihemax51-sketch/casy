#include "ClientProtection.h"
#include "PackageBindingVerifier.h"

bool InitializeKmtClientProtection(HINSTANCE module)
{
#if KMT_DEVELOPMENT_BUILD
    (void)module;
    return true;
#else
    KmtPackageBinding binding;
    std::string error;
    if (!KmtLoadPackageBinding(static_cast<HMODULE>(module), binding, error) ||
        !KmtPackageBindingHasFeature(binding, "CLIENTDLL"))
    {
        OutputDebugStringA(("[KMTGuardKit] Customer package validation failed: " + error + "\n").c_str());
        return false;
    }
    return true;
#endif
}
