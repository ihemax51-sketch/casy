#include "LicenseVerifier.h"
#include "PackageBindingVerifier.h"
#include "ProtectionRuntime.h"
#include "TrustedLicenseKey.generated.h"

#include <wincrypt.h>
#include <algorithm>
#include <cctype>
#include <ctime>
#include <cstdlib>
#include <fstream>
#include <iomanip>
#include <map>
#include <sstream>
#include <vector>

#ifndef CALG_SHA_256
#define CALG_SHA_256 (ALG_CLASS_HASH | ALG_TYPE_ANY | ALG_SID_SHA_256)
#endif
#ifndef PROV_RSA_AES
#define PROV_RSA_AES 24
#endif
#ifndef MS_ENH_RSA_AES_PROV_A
#define MS_ENH_RSA_AES_PROV_A "Microsoft Enhanced RSA and AES Cryptographic Provider"
#endif
#ifndef KEY_WOW64_64KEY
#define KEY_WOW64_64KEY 0x0100
#endif
#ifndef KMT_REQUIRE_PACKAGE_BINDING
#define KMT_REQUIRE_PACKAGE_BINDING 0
#endif
#ifndef KMT_DEVELOPMENT_BUILD
#define KMT_DEVELOPMENT_BUILD 0
#endif

namespace
{
    const char* const LICENSE_FILE_NAME = "KMTGuard-License.txt";
    const char* const LICENSE_HEADER = "KMTGUARD-LICENSE-V1";
    KmtLicenseFeature g_monitorFeature = KmtLicenseFilter;
    std::string g_componentName;

    std::string Trim(const std::string& value)
    {
        std::string::size_type first = 0;
        while (first < value.size() && std::isspace(static_cast<unsigned char>(value[first])))
            ++first;
        std::string::size_type last = value.size();
        while (last > first && std::isspace(static_cast<unsigned char>(value[last - 1])))
            --last;
        return value.substr(first, last - first);
    }

    std::string ToUpper(std::string value)
    {
        for (std::string::size_type i = 0; i < value.size(); ++i)
            value[i] = static_cast<char>(std::toupper(static_cast<unsigned char>(value[i])));
        return value;
    }

    std::string GetHostDirectory()
    {
        char path[MAX_PATH] = {0};
        if (GetModuleFileNameA(NULL, path, MAX_PATH) == 0)
            return ".";
        std::string value(path);
        const std::string::size_type separator = value.find_last_of("\\/");
        return separator == std::string::npos ? "." : value.substr(0, separator);
    }

    std::string GetMachineLicensePath()
    {
        char programData[MAX_PATH] = {0};
        const DWORD length = GetEnvironmentVariableA("ProgramData", programData, MAX_PATH);
        if (length == 0 || length >= MAX_PATH)
            return std::string();
        return std::string(programData) + "\\KMTGuard\\" + LICENSE_FILE_NAME;
    }

    bool ReadTextFile(const std::string& path, std::string& content)
    {
        std::ifstream stream(path.c_str(), std::ios::in | std::ios::binary);
        if (!stream)
            return false;
        std::ostringstream output;
        output << stream.rdbuf();
        content = output.str();
        return true;
    }

    bool ReadLicenseToken(const std::string& path, std::string& token, std::string& error)
    {
        std::string content;
        if (!ReadTextFile(path, content))
        {
            error = "License file was not found: " + path;
            return false;
        }

        std::istringstream lines(content);
        std::string line;
        if (!std::getline(lines, line) || Trim(line) != LICENSE_HEADER)
        {
            error = "License file header is invalid.";
            return false;
        }

        while (std::getline(lines, line))
        {
            if (line.compare(0, 6, "Lease=") == 0)
            {
                token = Trim(line.substr(6));
                if (!token.empty())
                    return true;
            }
        }

        error = "License has not been activated. Open KMTGuard.exe first.";
        return false;
    }

    int Base64Value(char value)
    {
        if (value >= 'A' && value <= 'Z') return value - 'A';
        if (value >= 'a' && value <= 'z') return value - 'a' + 26;
        if (value >= '0' && value <= '9') return value - '0' + 52;
        if (value == '-' || value == '+') return 62;
        if (value == '_' || value == '/') return 63;
        return -1;
    }

    bool DecodeBase64Url(const std::string& input, std::vector<unsigned char>& output)
    {
        output.clear();
        unsigned long accumulator = 0;
        int bits = 0;
        for (std::string::size_type i = 0; i < input.size(); ++i)
        {
            if (input[i] == '=')
                break;
            const int decoded = Base64Value(input[i]);
            if (decoded < 0)
                return false;
            accumulator = (accumulator << 6) | static_cast<unsigned long>(decoded);
            bits += 6;
            if (bits >= 8)
            {
                bits -= 8;
                output.push_back(static_cast<unsigned char>((accumulator >> bits) & 0xFF));
            }
        }
        return true;
    }

    bool HashSha256(const unsigned char* data, DWORD length, std::vector<unsigned char>& hash)
    {
        HCRYPTPROV provider = 0;
        HCRYPTHASH hashHandle = 0;
        bool success = false;
        if (CryptAcquireContextA(&provider, NULL, MS_ENH_RSA_AES_PROV_A, PROV_RSA_AES, CRYPT_VERIFYCONTEXT) &&
            CryptCreateHash(provider, CALG_SHA_256, 0, 0, &hashHandle) &&
            CryptHashData(hashHandle, data, length, 0))
        {
            DWORD size = 32;
            hash.resize(size);
            success = CryptGetHashParam(hashHandle, HP_HASHVAL, &hash[0], &size, 0) == TRUE;
            hash.resize(success ? size : 0);
        }
        if (hashHandle) CryptDestroyHash(hashHandle);
        if (provider) CryptReleaseContext(provider, 0);
        return success;
    }

    bool VerifySignature(const std::vector<unsigned char>& payload, const std::vector<unsigned char>& signature)
    {
        if (signature.size() != KMT_LICENSE_RSA_MODULUS_SIZE)
            return false;

        const DWORD blobSize = sizeof(BLOBHEADER) + sizeof(RSAPUBKEY) + KMT_LICENSE_RSA_MODULUS_SIZE;
        std::vector<unsigned char> blob(blobSize, 0);
        BLOBHEADER* header = reinterpret_cast<BLOBHEADER*>(&blob[0]);
        header->bType = PUBLICKEYBLOB;
        header->bVersion = CUR_BLOB_VERSION;
        header->reserved = 0;
        header->aiKeyAlg = CALG_RSA_SIGN;
        RSAPUBKEY* publicKey = reinterpret_cast<RSAPUBKEY*>(&blob[sizeof(BLOBHEADER)]);
        publicKey->magic = 0x31415352;
        publicKey->bitlen = KMT_LICENSE_RSA_MODULUS_SIZE * 8;
        publicKey->pubexp = KMT_LICENSE_RSA_EXPONENT;
        unsigned char* modulus = &blob[sizeof(BLOBHEADER) + sizeof(RSAPUBKEY)];
        for (DWORD i = 0; i < KMT_LICENSE_RSA_MODULUS_SIZE; ++i)
            modulus[i] = KMT_LICENSE_RSA_MODULUS[KMT_LICENSE_RSA_MODULUS_SIZE - 1 - i];

        HCRYPTPROV provider = 0;
        HCRYPTKEY key = 0;
        HCRYPTHASH hash = 0;
        bool valid = false;
        if (CryptAcquireContextA(&provider, NULL, MS_ENH_RSA_AES_PROV_A, PROV_RSA_AES, CRYPT_VERIFYCONTEXT) &&
            CryptImportKey(provider, &blob[0], blobSize, 0, 0, &key) &&
            CryptCreateHash(provider, CALG_SHA_256, 0, 0, &hash) &&
            CryptHashData(hash, &payload[0], static_cast<DWORD>(payload.size()), 0))
        {
            std::vector<unsigned char> littleEndianSignature(signature.rbegin(), signature.rend());
            valid = CryptVerifySignatureA(hash, &littleEndianSignature[0], static_cast<DWORD>(littleEndianSignature.size()), key, NULL, 0) == TRUE;
        }

        if (hash) CryptDestroyHash(hash);
        if (key) CryptDestroyKey(key);
        if (provider) CryptReleaseContext(provider, 0);
        return valid;
    }

    bool ParsePayload(const std::vector<unsigned char>& bytes, std::map<std::string, std::string>& claims)
    {
        const std::string payload(bytes.begin(), bytes.end());
        std::string::size_type start = 0;
        while (start <= payload.size())
        {
            const std::string::size_type end = payload.find('&', start);
            const std::string pair = payload.substr(start, end == std::string::npos ? std::string::npos : end - start);
            const std::string::size_type separator = pair.find('=');
            if (separator != std::string::npos)
                claims[pair.substr(0, separator)] = pair.substr(separator + 1);
            if (end == std::string::npos)
                break;
            start = end + 1;
        }
        return claims["v"] == "2";
    }

    std::string FeatureName(KmtLicenseFeature feature)
    {
        switch (feature)
        {
        case KmtLicenseGameServer: return "GAMESERVER";
        case KmtLicenseShardManager: return "SHARDMANAGER";
        default: return "FILTER";
        }
    }

    bool HasFeature(const std::string& claim, const std::string& feature)
    {
        const std::string padded = "," + ToUpper(claim) + ",";
        return padded.find("," + feature + ",") != std::string::npos;
    }

    bool IpListContains(const std::string& list, const std::string& candidate)
    {
        std::string::size_type start = 0;
        while (start <= list.size())
        {
            const std::string::size_type end = list.find(',', start);
            if (ToUpper(Trim(list.substr(
                    start,
                    end == std::string::npos ? std::string::npos : end - start))) ==
                ToUpper(Trim(candidate)))
                return true;
            if (end == std::string::npos)
                break;
            start = end + 1;
        }
        return false;
    }

    bool ReadMachineGuid(std::string& value)
    {
        HKEY key = NULL;
        LONG status = RegOpenKeyExA(HKEY_LOCAL_MACHINE, "SOFTWARE\\Microsoft\\Cryptography", 0,
                                    KEY_QUERY_VALUE | KEY_WOW64_64KEY, &key);
        if (status != ERROR_SUCCESS)
            status = RegOpenKeyExA(HKEY_LOCAL_MACHINE, "SOFTWARE\\Microsoft\\Cryptography", 0, KEY_QUERY_VALUE, &key);
        if (status != ERROR_SUCCESS)
            return false;

        char buffer[256] = {0};
        DWORD size = sizeof(buffer);
        DWORD type = 0;
        status = RegQueryValueExA(key, "MachineGuid", NULL, &type, reinterpret_cast<BYTE*>(buffer), &size);
        RegCloseKey(key);
        if (status != ERROR_SUCCESS || (type != REG_SZ && type != REG_EXPAND_SZ))
            return false;
        value = buffer;
        return !value.empty();
    }

    bool GetMachineHash(std::string& output)
    {
        std::string machineGuid;
        if (!ReadMachineGuid(machineGuid))
            return false;
        char windowsDirectory[MAX_PATH] = {0};
        if (GetWindowsDirectoryA(windowsDirectory, MAX_PATH) == 0)
            return false;
        char root[4] = { windowsDirectory[0], ':', '\\', 0 };
        DWORD volumeSerial = 0;
        if (!GetVolumeInformationA(root, NULL, 0, &volumeSerial, NULL, NULL, NULL, 0))
            return false;

        std::ostringstream canonical;
        canonical << ToUpper(Trim(machineGuid)) << "|" << std::uppercase << std::hex
                  << std::setfill('0') << std::setw(8) << volumeSerial;
        const std::string value = canonical.str();
        std::vector<unsigned char> hash;
        if (!HashSha256(reinterpret_cast<const unsigned char*>(value.data()), static_cast<DWORD>(value.size()), hash))
            return false;
        std::ostringstream hex;
        for (std::vector<unsigned char>::size_type i = 0; i < hash.size(); ++i)
            hex << std::uppercase << std::hex << std::setfill('0') << std::setw(2) << static_cast<unsigned int>(hash[i]);
        output = hex.str();
        return true;
    }

    bool ValidateToken(const std::string& token, KmtLicenseFeature feature, KmtLicenseResult& result)
    {
        const std::string::size_type first = token.find('.');
        const std::string::size_type second = first == std::string::npos ? std::string::npos : token.find('.', first + 1);
        if (first == std::string::npos || second == std::string::npos || token.substr(0, first) != "KMT1")
        {
            result.Message = "License token format is invalid.";
            return false;
        }

        std::vector<unsigned char> payload;
        std::vector<unsigned char> signature;
        if (!DecodeBase64Url(token.substr(first + 1, second - first - 1), payload) || payload.empty() ||
            !DecodeBase64Url(token.substr(second + 1), signature) || signature.empty())
        {
            result.Message = "License token encoding is invalid.";
            return false;
        }
        if (!VerifySignature(payload, signature))
        {
            result.Message = "License signature is invalid.";
            return false;
        }

        std::map<std::string, std::string> claims;
        if (!ParsePayload(payload, claims) || claims["kid"] != KMT_LICENSE_KEY_ID)
        {
            result.Message = "License signing key is not trusted.";
            return false;
        }
        if (!HasFeature(claims["features"], FeatureName(feature)))
        {
            result.Message = "This component is not included in the subscription.";
            return false;
        }

        char* maximumPlayersEnd = NULL;
        const long maximumPlayers = strtol(claims["maxplayers"].c_str(), &maximumPlayersEnd, 10);
        if (claims["maxplayers"].empty() || maximumPlayersEnd == NULL || *maximumPlayersEnd != '\0' ||
            maximumPlayers < 1 || maximumPlayers > 10000)
        {
            result.Message = "Licensed player limit is missing or invalid.";
            return false;
        }

        const std::string bindingMode = claims["bind"].empty() ? "IP" : ToUpper(claims["bind"]);
        if (bindingMode != "IP" && bindingMode != "LIMIT")
        {
            result.Message = "License binding mode is invalid.";
            return false;
        }
        std::string machineHash;
        if (!GetMachineHash(machineHash) || ToUpper(claims["machine"]) != machineHash)
        {
            result.Message = "License lease belongs to another Windows server.";
            return false;
        }

        const __int64 now = _time64(NULL);
        const __int64 notBefore = _strtoi64(claims["nbf"].c_str(), NULL, 10);
        const __int64 subscriptionExpires = _strtoi64(claims["subexp"].c_str(), NULL, 10);
        const __int64 leaseExpires = _strtoi64(claims["leaseexp"].c_str(), NULL, 10);
        if (notBefore > now + 300)
        {
            result.Message = "License is not active yet.";
            return false;
        }
        if (subscriptionExpires <= now)
        {
            result.Message = "KMTGuard subscription has expired.";
            return false;
        }
        if (leaseExpires <= now)
        {
            result.Message = "Online license lease has expired. Start KMTGuard Filter to refresh it.";
            return false;
        }

        result.Valid = true;
        result.LeaseExpiresUtc = leaseExpires;
        result.MaximumPlayers = static_cast<int>(maximumPlayers);
        result.LicenseId = claims["lid"];
        result.CustomerId = claims["cid"];
        result.PackageId = claims["pkg"];
        std::vector<unsigned char> serverIp;
        if (!DecodeBase64Url(claims["ip"], serverIp))
        {
            result.Valid = false;
            result.Message = "License server IP claim is invalid.";
            return false;
        }
        result.ServerIp.assign(serverIp.begin(), serverIp.end());
        result.BindingMode = bindingMode;
        if (
            (result.BindingMode == "IP" && result.ServerIp.empty()) ||
            (result.BindingMode == "LIMIT" && !result.ServerIp.empty()))
        {
            result.Valid = false;
            result.Message = "License binding mode is invalid.";
            return false;
        }
        result.Message = "License is valid.";
        return true;
    }

    HMODULE GetVerifierModule()
    {
        MEMORY_BASIC_INFORMATION memory = {0};
        if (VirtualQuery(reinterpret_cast<LPCVOID>(&KmtValidateLicense), &memory, sizeof(memory)) == 0)
            return NULL;
        return static_cast<HMODULE>(memory.AllocationBase);
    }

    bool ValidatePackageBinding(KmtLicenseFeature feature, KmtLicenseResult& result)
    {
        KmtPackageBinding binding;
        std::string error;
        if (!KmtLoadPackageBinding(GetVerifierModule(), binding, error))
        {
            result.Valid = false;
            result.Message = error;
            return false;
        }
        const std::string featureName = FeatureName(feature);
        if (!KmtPackageBindingHasFeature(binding, featureName.c_str()))
        {
            result.Valid = false;
            result.Message = "This customer package does not include this component.";
            return false;
        }
        if (binding.LicenseId != result.LicenseId ||
            binding.CustomerId != result.CustomerId ||
            binding.PackageId != result.PackageId ||
            binding.BindingMode != result.BindingMode ||
            (binding.BindingMode == "IP" &&
             !IpListContains(binding.ServerIp, result.ServerIp)))
        {
            result.Valid = false;
            result.Message = "License lease does not match this customer package.";
            return false;
        }
        return true;
    }

    bool ValidatePath(const std::string& path, KmtLicenseFeature feature, KmtLicenseResult& result)
    {
        std::string token;
        if (!ReadLicenseToken(path, token, result.Message))
            return false;
        return ValidateToken(token, feature, result);
    }

    void AppendLog(const std::string& message)
    {
        std::ofstream log((GetHostDirectory() + "\\KMTGuard-License.log").c_str(), std::ios::out | std::ios::app);
        if (!log)
            return;
        std::time_t now = std::time(NULL);
        std::tm localTime;
        localtime_s(&localTime, &now);
        char timestamp[32] = {0};
        std::strftime(timestamp, sizeof(timestamp), "%Y-%m-%d %H:%M:%S", &localTime);
        log << timestamp << " [" << g_componentName << "] " << message << std::endl;
    }

    DWORD WINAPI MonitorLicense(LPVOID)
    {
        for (;;)
        {
            Sleep(60 * 1000);
            if (KmtIsAnalysisEnvironment())
            {
                AppendLog("Runtime integrity check failed.");
                ExitProcess(ERROR_ACCESS_DENIED);
            }
            KmtLicenseResult result;
            if (!KmtValidateLicense(g_monitorFeature, result))
            {
                AppendLog("Runtime license check failed: " + result.Message);
                ExitProcess(ERROR_ACCESS_DENIED);
            }
        }
    }
}

bool KmtValidateLicense(KmtLicenseFeature feature, KmtLicenseResult& result)
{
#if KMT_DEVELOPMENT_BUILD
    (void)feature;
    result.Valid = true;
    result.Message = "KMTGuard internal development flavor - activation is disabled.";
    result.LeaseExpiresUtc = 0x7FFFFFFF;
    result.MaximumPlayers = 10000;
    result.LicenseId = "DEVELOPMENT-BUILD";
    result.CustomerId = "KMT-INTERNAL";
    result.PackageId = "DEVELOPMENT-BUILD";
    result.ServerIp.clear();
    result.BindingMode.clear();
    return true;
#else
    const std::string localPath = GetHostDirectory() + "\\" + LICENSE_FILE_NAME;
    const std::string machinePath = GetMachineLicensePath();
    KmtLicenseResult local;
    KmtLicenseResult machine;
    const bool localValid = ValidatePath(localPath, feature, local);
    const bool machineValid = !machinePath.empty() && ValidatePath(machinePath, feature, machine);
    if (localValid || machineValid)
    {
        result = !localValid || (machineValid && machine.LeaseExpiresUtc > local.LeaseExpiresUtc) ? machine : local;
#if KMT_REQUIRE_PACKAGE_BINDING
        if (!ValidatePackageBinding(feature, result))
            return false;
#endif
        return true;
    }

    result = !machine.Message.empty() ? machine : local;
    return false;
#endif
}

bool KmtEnforceLicenseAndStartMonitor(KmtLicenseFeature feature, const char* componentName)
{
#if KMT_DEVELOPMENT_BUILD
    (void)feature;
    g_componentName = componentName ? componentName : "KMTGuard";
    AppendLog("KMTGuard internal development flavor accepted without activation.");
    return true;
#else
    g_monitorFeature = feature;
    g_componentName = componentName ? componentName : "KMTGuard";
    if (KmtIsAnalysisEnvironment())
    {
        AppendLog("Startup integrity check failed.");
        ExitProcess(ERROR_ACCESS_DENIED);
        return false;
    }
    KmtLicenseResult result;
    if (!KmtValidateLicense(feature, result))
    {
        const std::string message = g_componentName + " license check failed:\r\n\r\n" + result.Message;
        AppendLog(result.Message);
        MessageBoxA(NULL, message.c_str(), "KMTGuard License", MB_OK | MB_ICONERROR | MB_TOPMOST);
        ExitProcess(ERROR_ACCESS_DENIED);
        return false;
    }

    AppendLog("License accepted.");
    HANDLE thread = CreateThread(NULL, 0, MonitorLicense, NULL, 0, NULL);
    if (thread)
        CloseHandle(thread);
    return true;
#endif
}
