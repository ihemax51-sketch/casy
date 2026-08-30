#pragma once

#include <windows.h>
#include <string>

enum KmtLicenseFeature
{
    KmtLicenseFilter = 1,
    KmtLicenseGameServer = 2,
    KmtLicenseShardManager = 4
};

struct KmtLicenseResult
{
    bool Valid;
    std::string Message;
    __int64 LeaseExpiresUtc;
    int MaximumPlayers;
    std::string LicenseId;
    std::string CustomerId;
    std::string PackageId;
    std::string ServerIp;
    std::string BindingMode;

    KmtLicenseResult() : Valid(false), LeaseExpiresUtc(0), MaximumPlayers(0) {}
};

bool KmtValidateLicense(KmtLicenseFeature feature, KmtLicenseResult& result);
bool KmtEnforceLicenseAndStartMonitor(KmtLicenseFeature feature, const char* componentName);
