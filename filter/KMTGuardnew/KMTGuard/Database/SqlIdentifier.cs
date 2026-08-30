using System.Text.RegularExpressions;

namespace KMTGuard.Database;

public static partial class SqlIdentifier
{
    [GeneratedRegex("^[A-Za-z_][A-Za-z0-9_]*$")]
    private static partial Regex IdentifierPattern();

    public static string Quote(string identifier)
    {
        if (string.IsNullOrWhiteSpace(identifier) ||
            !IdentifierPattern().IsMatch(identifier))
            throw new ArgumentException("Invalid SQL identifier.", nameof(identifier));

        return $"[{identifier}]";
    }
}
