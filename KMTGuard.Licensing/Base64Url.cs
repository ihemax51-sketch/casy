using System.Text;

namespace KMTGuard.Licensing;

internal static class Base64Url
{
    public static string Encode(ReadOnlySpan<byte> value) =>
        Convert.ToBase64String(value).TrimEnd('=').Replace('+', '-').Replace('/', '_');

    public static string EncodeText(string value) => Encode(Encoding.UTF8.GetBytes(value));

    public static byte[] Decode(string value)
    {
        var normalized = value.Replace('-', '+').Replace('_', '/');
        normalized = normalized.PadRight(normalized.Length + ((4 - normalized.Length % 4) % 4), '=');
        return Convert.FromBase64String(normalized);
    }

    public static string DecodeText(string value) => Encoding.UTF8.GetString(Decode(value));
}
