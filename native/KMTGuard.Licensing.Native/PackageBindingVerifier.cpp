#include "PackageBindingVerifier.h"
#include "TrustedLicenseKey.generated.h"

#include <wincrypt.h>
#include <algorithm>
#include <cctype>
#include <map>
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

namespace
{
    const WORD PACKAGE_RESOURCE_ID = 60101;
    const unsigned long PACKAGE_FORMAT_VERSION = 1;
    const size_t PACKAGE_HEADER_SIZE = 28;
    const unsigned char PACKAGE_MAGIC[4] = { 'K', 'M', 'B', '1' };
    const unsigned char PACKAGE_MASK[16] = {
        0x6D, 0x13, 0xA7, 0xC2, 0x59, 0xE1, 0x34, 0x8B,
        0xF0, 0x27, 0x95, 0x4E, 0xB8, 0x62, 0x0C, 0xD5
    };

    unsigned long ReadUInt32(const unsigned char* value)
    {
        return static_cast<unsigned long>(value[0]) |
               (static_cast<unsigned long>(value[1]) << 8) |
               (static_cast<unsigned long>(value[2]) << 16) |
               (static_cast<unsigned long>(value[3]) << 24);
    }

    bool DecodeResource(const unsigned char* data, size_t size, std::string& token)
    {
        if (!data || size < PACKAGE_HEADER_SIZE ||
            !std::equal(PACKAGE_MAGIC, PACKAGE_MAGIC + 4, data) ||
            ReadUInt32(data + 4) != PACKAGE_FORMAT_VERSION)
            return false;

        const unsigned long length = ReadUInt32(data + 8);
        if (length == 0 || length > size - PACKAGE_HEADER_SIZE)
            return false;

        unsigned long state = 0xA341316C;
        size_t index;
        for (index = 0; index < 16; ++index)
            state = (state ^ data[12 + index]) * 16777619UL;

        token.resize(length);
        for (index = 0; index < length; ++index)
        {
            state ^= state << 13;
            state ^= state >> 17;
            state ^= state << 5;
            token[index] = static_cast<char>(
                data[PACKAGE_HEADER_SIZE + index] ^
                static_cast<unsigned char>(state) ^
                PACKAGE_MASK[index & 15]);
        }
        return true;
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
        for (std::string::size_type index = 0; index < input.size(); ++index)
        {
            if (input[index] == '=')
                break;
            const int decoded = Base64Value(input[index]);
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

    bool VerifySignature(const std::vector<unsigned char>& payload, const std::vector<unsigned char>& signature)
    {
        if (payload.empty() || signature.size() != KMT_LICENSE_RSA_MODULUS_SIZE)
            return false;

        const DWORD blobSize = sizeof(BLOBHEADER) + sizeof(RSAPUBKEY) + KMT_LICENSE_RSA_MODULUS_SIZE;
        std::vector<unsigned char> blob(blobSize, 0);
        BLOBHEADER* header = reinterpret_cast<BLOBHEADER*>(&blob[0]);
        header->bType = PUBLICKEYBLOB;
        header->bVersion = CUR_BLOB_VERSION;
        header->aiKeyAlg = CALG_RSA_SIGN;
        RSAPUBKEY* publicKey = reinterpret_cast<RSAPUBKEY*>(&blob[sizeof(BLOBHEADER)]);
        publicKey->magic = 0x31415352;
        publicKey->bitlen = KMT_LICENSE_RSA_MODULUS_SIZE * 8;
        publicKey->pubexp = KMT_LICENSE_RSA_EXPONENT;
        unsigned char* modulus = &blob[sizeof(BLOBHEADER) + sizeof(RSAPUBKEY)];
        for (DWORD index = 0; index < KMT_LICENSE_RSA_MODULUS_SIZE; ++index)
            modulus[index] = KMT_LICENSE_RSA_MODULUS[KMT_LICENSE_RSA_MODULUS_SIZE - 1 - index];

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
            valid = CryptVerifySignatureA(
                hash,
                &littleEndianSignature[0],
                static_cast<DWORD>(littleEndianSignature.size()),
                key,
                NULL,
                0) == TRUE;
        }
        if (hash) CryptDestroyHash(hash);
        if (key) CryptDestroyKey(key);
        if (provider) CryptReleaseContext(provider, 0);
        return valid;
    }

    bool ParsePayload(const std::vector<unsigned char>& payload, std::map<std::string, std::string>& values)
    {
        const std::string text(payload.begin(), payload.end());
        std::string::size_type start = 0;
        while (start <= text.size())
        {
            const std::string::size_type end = text.find('&', start);
            const std::string pair = text.substr(start, end == std::string::npos ? std::string::npos : end - start);
            const std::string::size_type separator = pair.find('=');
            if (separator != std::string::npos)
                values[pair.substr(0, separator)] = pair.substr(separator + 1);
            if (end == std::string::npos)
                break;
            start = end + 1;
        }
        return values["v"] == "1";
    }

    bool DecodeTextClaim(const std::string& value, std::string& output)
    {
        std::vector<unsigned char> bytes;
        if (!DecodeBase64Url(value, bytes))
            return false;
        output.assign(bytes.begin(), bytes.end());
        return true;
    }

    std::string ToUpper(std::string value)
    {
        for (std::string::size_type index = 0; index < value.size(); ++index)
            value[index] = static_cast<char>(std::toupper(static_cast<unsigned char>(value[index])));
        return value;
    }
}

bool KmtLoadPackageBinding(HMODULE module, KmtPackageBinding& binding, std::string& error)
{
    if (!module)
    {
        error = "Package module is unavailable.";
        return false;
    }

    HRSRC resource = FindResourceA(module, MAKEINTRESOURCEA(PACKAGE_RESOURCE_ID), MAKEINTRESOURCEA(10));
    if (!resource)
    {
        error = "Package binding resource is missing.";
        return false;
    }
    const DWORD size = SizeofResource(module, resource);
    HGLOBAL loaded = LoadResource(module, resource);
    const unsigned char* data = loaded ? static_cast<const unsigned char*>(LockResource(loaded)) : NULL;
    std::string token;
    if (!DecodeResource(data, size, token))
    {
        error = "Package binding resource is invalid.";
        return false;
    }

    const std::string::size_type first = token.find('.');
    const std::string::size_type second = first == std::string::npos ? std::string::npos : token.find('.', first + 1);
    if (first == std::string::npos || second == std::string::npos || token.substr(0, first) != "KMTP1")
    {
        error = "Package binding token format is invalid.";
        return false;
    }

    std::vector<unsigned char> payload;
    std::vector<unsigned char> signature;
    if (!DecodeBase64Url(token.substr(first + 1, second - first - 1), payload) ||
        !DecodeBase64Url(token.substr(second + 1), signature) ||
        !VerifySignature(payload, signature))
    {
        error = "Package binding signature is invalid.";
        return false;
    }

    std::map<std::string, std::string> claims;
    if (!ParsePayload(payload, claims) || claims["kid"] != KMT_LICENSE_KEY_ID)
    {
        error = "Package binding signing key is not trusted.";
        return false;
    }
    binding.BindingMode = claims["bind"].empty() ? "IP" : ToUpper(claims["bind"]);
    if (claims["lid"].empty() || claims["cid"].empty() || claims["ccode"].empty() ||
        claims["pkg"].empty() || claims["features"].empty() || claims["mark"].empty() ||
        !DecodeTextClaim(claims["ip"], binding.ServerIp) ||
        (binding.BindingMode != "IP" && binding.BindingMode != "LIMIT") ||
        (binding.BindingMode == "IP" && binding.ServerIp.empty()) ||
        (binding.BindingMode == "LIMIT" && !binding.ServerIp.empty()))
    {
        error = "Package binding claims are incomplete.";
        return false;
    }

    binding.LicenseId = claims["lid"];
    binding.CustomerId = claims["cid"];
    binding.CustomerCode = claims["ccode"];
    binding.PackageId = claims["pkg"];
    binding.Features = claims["features"];
    binding.Watermark = claims["mark"];
    binding.IssuedUtc = _strtoi64(claims["issued"].c_str(), NULL, 10);
    return binding.IssuedUtc > 0;
}

bool KmtPackageBindingHasFeature(const KmtPackageBinding& binding, const char* feature)
{
    if (!feature || !*feature)
        return false;
    const std::string values = "," + ToUpper(binding.Features) + ",";
    return values.find("," + ToUpper(feature) + ",") != std::string::npos;
}
