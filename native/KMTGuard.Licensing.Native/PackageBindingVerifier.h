#pragma once

#include <windows.h>
#include <string>

struct KmtPackageBinding
{
    std::string LicenseId;
    std::string CustomerId;
    std::string CustomerCode;
    std::string PackageId;
    std::string ServerIp;
    std::string BindingMode;
    std::string Features;
    std::string Watermark;
    __int64 IssuedUtc;

    KmtPackageBinding() : IssuedUtc(0) {}
};

bool KmtLoadPackageBinding(HMODULE module, KmtPackageBinding& binding, std::string& error);
bool KmtPackageBindingHasFeature(const KmtPackageBinding& binding, const char* feature);
