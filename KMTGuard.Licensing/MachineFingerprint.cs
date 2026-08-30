using System.Runtime.InteropServices;
using System.Runtime.Versioning;
using System.Security.Cryptography;
using System.Text;
using Microsoft.Win32;

namespace KMTGuard.Licensing;

public static class MachineFingerprint
{
    public static string GetHash()
    {
        if (!OperatingSystem.IsWindows())
            throw new PlatformNotSupportedException("KMTGuard licensing requires Windows.");

        var machineGuid = ReadMachineGuid();
        var volumeSerial = ReadSystemVolumeSerial();
        var canonical = $"{machineGuid.Trim().ToUpperInvariant()}|{volumeSerial:X8}";
        return Convert.ToHexString(SHA256.HashData(Encoding.UTF8.GetBytes(canonical)));
    }

    public static string GetDisplayId()
    {
        var hash = GetHash();
        return string.Join('-', Enumerable.Range(0, 4).Select(index => hash.Substring(index * 4, 4)));
    }

    [SupportedOSPlatform("windows")]
    private static string ReadMachineGuid()
    {
        using var root = RegistryKey.OpenBaseKey(RegistryHive.LocalMachine, RegistryView.Registry64);
        using var key = root.OpenSubKey(@"SOFTWARE\Microsoft\Cryptography", writable: false);
        var value = key?.GetValue("MachineGuid")?.ToString();
        if (string.IsNullOrWhiteSpace(value))
            throw new InvalidOperationException("Windows MachineGuid could not be read.");
        return value;
    }

    [SupportedOSPlatform("windows")]
    private static uint ReadSystemVolumeSerial()
    {
        var root = Path.GetPathRoot(Environment.GetFolderPath(Environment.SpecialFolder.Windows));
        if (string.IsNullOrWhiteSpace(root) ||
            !GetVolumeInformation(root, null, 0, out var serial, out _, out _, null, 0))
        {
            throw new InvalidOperationException("The Windows system volume serial could not be read.");
        }

        return serial;
    }

    [DllImport("kernel32.dll", CharSet = CharSet.Unicode, SetLastError = true)]
    private static extern bool GetVolumeInformation(
        string rootPathName,
        StringBuilder? volumeNameBuffer,
        int volumeNameSize,
        out uint volumeSerialNumber,
        out uint maximumComponentLength,
        out uint fileSystemFlags,
        StringBuilder? fileSystemNameBuffer,
        int fileSystemNameSize);
}
