#include "../KMTGuard.Licensing.Native/LicenseVerifier.h"
#include <iostream>

int main()
{
    KmtLicenseResult gameServer;
    if (!KmtValidateLicense(KmtLicenseGameServer, gameServer))
    {
        std::cerr << "GameServer license failed: " << gameServer.Message << std::endl;
        return 1;
    }

    KmtLicenseResult shardManager;
    if (!KmtValidateLicense(KmtLicenseShardManager, shardManager))
    {
        std::cerr << "ShardManager license failed: " << shardManager.Message << std::endl;
        return 2;
    }

    std::cout << "PASS: native VC80 GameServer and ShardManager license verification" << std::endl;
    return 0;
}
