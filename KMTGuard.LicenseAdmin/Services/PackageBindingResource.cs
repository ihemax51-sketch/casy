using System.Buffers.Binary;
using System.ComponentModel;
using System.Runtime.InteropServices;
using System.Security.Cryptography;
using System.Text;

namespace KMTGuard.LicenseAdmin.Services;

internal static class PackageBindingResource
{
    internal const int ResourceId = 60101;
    private const int ResourceTypeRcData = 10;
    private const int HeaderSize = 28;
    private const uint FormatVersion = 1;
    private const uint LoadLibraryAsDataFile = 0x00000002;
    private static readonly byte[] Magic = "KMB1"u8.ToArray();
    private static readonly byte[] Mask =
    {
        0x6D, 0x13, 0xA7, 0xC2, 0x59, 0xE1, 0x34, 0x8B,
        0xF0, 0x27, 0x95, 0x4E, 0xB8, 0x62, 0x0C, 0xD5
    };

    public static void Write(string binaryPath, string token)
    {
        if (!File.Exists(binaryPath))
            throw new FileNotFoundException("A required customer binary is missing.", binaryPath);
        if (string.IsNullOrWhiteSpace(token))
            throw new InvalidDataException("Package binding token is empty.");

        var payload = Encode(token.Trim());
        var update = BeginUpdateResource(binaryPath, false);
        if (update == IntPtr.Zero)
            throw new Win32Exception(Marshal.GetLastWin32Error(), $"Could not open {Path.GetFileName(binaryPath)} for personalization.");

        var committed = false;
        try
        {
            if (!UpdateResource(update, (IntPtr)ResourceTypeRcData, (IntPtr)ResourceId, 0, payload, (uint)payload.Length))
                throw new Win32Exception(Marshal.GetLastWin32Error(), $"Could not personalize {Path.GetFileName(binaryPath)}.");
            if (!EndUpdateResource(update, false))
                throw new Win32Exception(Marshal.GetLastWin32Error(), $"Could not save {Path.GetFileName(binaryPath)} personalization.");
            committed = true;
        }
        finally
        {
            if (!committed)
                _ = EndUpdateResource(update, true);
        }

        var written = Read(binaryPath);
        if (!CryptographicEquals(written, token.Trim()))
            throw new InvalidDataException($"Personalization verification failed for {Path.GetFileName(binaryPath)}.");
    }

    public static string Read(string binaryPath)
    {
        var module = LoadLibraryEx(binaryPath, IntPtr.Zero, LoadLibraryAsDataFile);
        if (module == IntPtr.Zero)
            throw new Win32Exception(Marshal.GetLastWin32Error(), $"Could not inspect {Path.GetFileName(binaryPath)}.");
        try
        {
            var resource = FindResource(module, (IntPtr)ResourceId, (IntPtr)ResourceTypeRcData);
            if (resource == IntPtr.Zero)
                throw new InvalidDataException($"{Path.GetFileName(binaryPath)} does not contain a package binding.");
            var size = SizeofResource(module, resource);
            var loaded = LoadResource(module, resource);
            var pointer = LockResource(loaded);
            if (size < HeaderSize || pointer == IntPtr.Zero)
                throw new InvalidDataException($"{Path.GetFileName(binaryPath)} contains an invalid package binding.");

            var data = new byte[size];
            Marshal.Copy(pointer, data, 0, data.Length);
            return Decode(data);
        }
        finally
        {
            _ = FreeLibrary(module);
        }
    }

    private static byte[] Encode(string token)
    {
        var plain = Encoding.UTF8.GetBytes(token);
        var nonce = RandomNumberGenerator.GetBytes(16);
        var output = new byte[HeaderSize + plain.Length];
        Magic.CopyTo(output, 0);
        BinaryPrimitives.WriteUInt32LittleEndian(output.AsSpan(4, 4), FormatVersion);
        BinaryPrimitives.WriteUInt32LittleEndian(output.AsSpan(8, 4), checked((uint)plain.Length));
        nonce.CopyTo(output, 12);
        Transform(plain, output.AsSpan(HeaderSize), nonce);
        CryptographicOperations.ZeroMemory(plain);
        return output;
    }

    private static string Decode(ReadOnlySpan<byte> data)
    {
        if (data.Length < HeaderSize || !data[..4].SequenceEqual(Magic))
            throw new InvalidDataException("Package binding header is invalid.");
        if (BinaryPrimitives.ReadUInt32LittleEndian(data.Slice(4, 4)) != FormatVersion)
            throw new InvalidDataException("Package binding version is not supported.");
        var length = checked((int)BinaryPrimitives.ReadUInt32LittleEndian(data.Slice(8, 4)));
        if (length <= 0 || length > data.Length - HeaderSize)
            throw new InvalidDataException("Package binding length is invalid.");

        var plain = new byte[length];
        Transform(data.Slice(HeaderSize, length), plain, data.Slice(12, 16));
        try
        {
            return Encoding.UTF8.GetString(plain);
        }
        finally
        {
            CryptographicOperations.ZeroMemory(plain);
        }
    }

    private static void Transform(ReadOnlySpan<byte> input, Span<byte> output, ReadOnlySpan<byte> nonce)
    {
        uint state = 0xA341316C;
        foreach (var value in nonce)
            state = unchecked((state ^ value) * 16777619);

        for (var index = 0; index < input.Length; index++)
        {
            state ^= state << 13;
            state ^= state >> 17;
            state ^= state << 5;
            output[index] = (byte)(input[index] ^ (byte)state ^ Mask[index & 15]);
        }
    }

    private static bool CryptographicEquals(string left, string right)
    {
        var leftBytes = Encoding.UTF8.GetBytes(left);
        var rightBytes = Encoding.UTF8.GetBytes(right);
        try
        {
            return leftBytes.Length == rightBytes.Length && CryptographicOperations.FixedTimeEquals(leftBytes, rightBytes);
        }
        finally
        {
            CryptographicOperations.ZeroMemory(leftBytes);
            CryptographicOperations.ZeroMemory(rightBytes);
        }
    }

    [DllImport("kernel32.dll", EntryPoint = "BeginUpdateResourceW", CharSet = CharSet.Unicode, SetLastError = true)]
    private static extern IntPtr BeginUpdateResource(string fileName, [MarshalAs(UnmanagedType.Bool)] bool deleteExistingResources);

    [DllImport("kernel32.dll", EntryPoint = "UpdateResourceW", SetLastError = true)]
    [return: MarshalAs(UnmanagedType.Bool)]
    private static extern bool UpdateResource(IntPtr update, IntPtr type, IntPtr name, ushort language, byte[] data, uint dataSize);

    [DllImport("kernel32.dll", EntryPoint = "EndUpdateResourceW", SetLastError = true)]
    [return: MarshalAs(UnmanagedType.Bool)]
    private static extern bool EndUpdateResource(IntPtr update, [MarshalAs(UnmanagedType.Bool)] bool discard);

    [DllImport("kernel32.dll", EntryPoint = "LoadLibraryExW", CharSet = CharSet.Unicode, SetLastError = true)]
    private static extern IntPtr LoadLibraryEx(string fileName, IntPtr file, uint flags);

    [DllImport("kernel32.dll", EntryPoint = "FindResourceW", SetLastError = true)]
    private static extern IntPtr FindResource(IntPtr module, IntPtr name, IntPtr type);

    [DllImport("kernel32.dll", SetLastError = true)]
    private static extern IntPtr LoadResource(IntPtr module, IntPtr resource);

    [DllImport("kernel32.dll", SetLastError = true)]
    private static extern IntPtr LockResource(IntPtr resourceData);

    [DllImport("kernel32.dll", SetLastError = true)]
    private static extern int SizeofResource(IntPtr module, IntPtr resource);

    [DllImport("kernel32.dll", SetLastError = true)]
    [return: MarshalAs(UnmanagedType.Bool)]
    private static extern bool FreeLibrary(IntPtr module);
}
