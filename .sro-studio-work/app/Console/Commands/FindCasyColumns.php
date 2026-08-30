<?php

namespace App\Console\Commands;

use App\Models\ServerProfile;
use App\Services\SqlServerConnectionFactory;
use Illuminate\Console\Command;

class FindCasyColumns extends Command
{
    protected $signature = 'casy:find-columns {pattern}';

    protected $description = 'Find discovered SQL tables by column-name pattern';

    public function handle(SqlServerConnectionFactory $connections): int
    {
        $profile = ServerProfile::query()->where('is_active', true)->latest()->firstOrFail();
        $pattern = '%'.(string) $this->argument('pattern').'%';
        $rows = [];
        foreach (data_get($profile->schema_stats, 'databases', []) as $database => $info) {
            if (! ($info['available'] ?? false)) {
                continue;
            }
            $pdo = $connections->connect($profile, $database);
            $statement = $pdo->prepare('SELECT t.name AS table_name, c.name AS column_name, ty.name AS type_name FROM sys.tables t JOIN sys.columns c ON c.object_id = t.object_id JOIN sys.types ty ON ty.user_type_id = c.user_type_id WHERE c.name LIKE ? ORDER BY t.name, c.column_id');
            $statement->execute([$pattern]);
            foreach ($statement->fetchAll() as $row) {
                $rows[] = [$database, $row['table_name'], $row['column_name'], $row['type_name']];
            }
        }
        $this->table(['Database', 'Table', 'Column', 'Type'], $rows);
        return self::SUCCESS;
    }
}
