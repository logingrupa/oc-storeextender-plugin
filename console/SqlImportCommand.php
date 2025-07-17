<?php namespace Logingrupa\StoreExtender\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Exception;

class SqlImportCommand extends Command
{
    protected $signature = 'storeextender:sql-import 
                            {file? : Path to .sql file containing INSERT statements}
                            {--sql= : Direct SQL INSERT statement}
                            {--dry-run : Show what would be executed without actually running}
                            {--debug : Show detailed debug information}
                            {--chunk-size=1000 : Number of records to insert in each batch}
                            {--skip-missing-columns : Skip columns that don\'t exist in target table}
                            {--ignore-duplicates : Use INSERT IGNORE to skip duplicate entries}';

    protected $description = 'Import raw SQL INSERT statements to database with schema validation';

    private bool $debug = false;
    private bool $dryRun = false;
    private int $chunkSize = 1000;
    private bool $skipMissingColumns = false;
    private bool $ignoreDuplicates = false;
    private array $processedTables = [];
    private int $totalRecords = 0;
    private int $totalTables = 0;
    private array $tableSchemas = [];

    public function handle(): int
    {
        $this->debug = $this->option('debug');
        $this->dryRun = $this->option('dry-run');
        $this->chunkSize = (int) $this->option('chunk-size');
        $this->skipMissingColumns = $this->option('skip-missing-columns');
        $this->ignoreDuplicates = $this->option('ignore-duplicates');

        $this->info('Starting SQL Import Process...');
        
        try {
            $sqlContent = $this->getSqlContent();
            $insertStatements = $this->parseInsertStatements($sqlContent);
            $groupedStatements = $this->groupStatementsByTable($insertStatements);
            $this->processTableGroups($groupedStatements);
            $this->displaySummary();
            
        } catch (Exception $e) {
            $this->error("Import failed: " . $e->getMessage());
            if ($this->debug) {
                $this->error("Stack trace: " . $e->getTraceAsString());
            }
            return 1;
        }

        return 0;
    }

    private function getSqlContent(): string
    {
        $sqlFile = $this->argument('file');
        $directSql = $this->option('sql');

        if ($directSql) {
            return $directSql;
        }

        if (!$sqlFile) {
            throw new Exception('Either file path or --sql option must be provided');
        }

        if (!file_exists($sqlFile)) {
            throw new Exception("SQL file not found: {$sqlFile}");
        }

        $content = file_get_contents($sqlFile);
        if ($content === false) {
            throw new Exception("Could not read SQL file: {$sqlFile}");
        }

        return $content;
    }

    private function parseInsertStatements(string $sqlContent): array
    {
        $sqlContent = $this->cleanSqlContent($sqlContent);
        $statements = $this->splitSqlStatements($sqlContent);
        
        $insertStatements = [];
        
        foreach ($statements as $index => $statement) {
            $statement = trim($statement);
            
            if (empty($statement) || !preg_match('/^\s*INSERT\s+INTO\s+/i', $statement)) {
                continue;
            }
            
            try {
                $parsedStatement = $this->parseIndividualInsertStatement($statement);
                if ($parsedStatement) {
                    $insertStatements[] = $parsedStatement;
                    $this->info("✓ Parsed table: " . $parsedStatement['table'] . " (" . count($parsedStatement['rows']) . " rows)");
                }
            } catch (Exception $e) {
                $this->warn("Failed to parse statement " . ($index + 1) . ": " . $e->getMessage());
            }
        }

        if (empty($insertStatements)) {
            throw new Exception('No valid INSERT statements found in the provided SQL');
        }

        return $insertStatements;
    }

    private function cleanSqlContent(string $content): string
    {
        $content = preg_replace('/--.*$/m', '', $content);
        $content = preg_replace('/\/\*.*?\*\//s', '', $content);
        $content = preg_replace('/[ \t]+/', ' ', $content);
        $content = preg_replace('/\n\s*\n/', "\n", $content);
        return trim($content);
    }

    private function splitSqlStatements(string $sql): array
    {
        $this->line("Splitting SQL statements...");
        
        // Use regex to find INSERT statements reliably
        $pattern = '/INSERT\s+INTO\s+.*?(?=\s*(?:INSERT\s+INTO|$))/is';
        
        if (preg_match_all($pattern, $sql, $matches)) {
            $statements = [];
            
            foreach ($matches[0] as $match) {
                $statement = trim($match, " \t\n\r\0\x0B;");
                if (!empty($statement)) {
                    $statements[] = $statement;
                }
            }
            
            $this->info("Found " . count($statements) . " INSERT statements");
            return $statements;
        }
        
        // Simple fallback
        $this->warn("Regex failed, using fallback method");
        $parts = preg_split('/(?=INSERT\s+INTO)/i', $sql);
        $statements = [];
        
        foreach ($parts as $part) {
            $part = trim($part);
            if (!empty($part) && preg_match('/^INSERT\s+INTO/i', $part)) {
                $statements[] = $part;
            }
        }
        
        return $statements;
    }

    private function parseIndividualInsertStatement(string $statement): ?array
    {
        if (!preg_match('/INSERT\s+INTO\s+`?([^`\s(]+)`?\s*\(/i', $statement, $tableMatches)) {
            throw new Exception('Could not extract table name from INSERT statement');
        }
        
        $tableName = trim($tableMatches[1], '`');
        
        if (!preg_match('/INSERT\s+INTO\s+`?[^`\s(]+`?\s*\(([^)]+)\)\s+VALUES/i', $statement, $columnMatches)) {
            throw new Exception('Could not extract columns from INSERT statement');
        }
        
        $columnsString = $columnMatches[1];
        $columns = array_map(fn($col) => trim($col, '` '), explode(',', $columnsString));
        
        if (!preg_match('/VALUES\s+(.+)$/is', $statement, $valuesMatches)) {
            throw new Exception('Could not extract VALUES section from INSERT statement');
        }
        
        $valuesSection = trim($valuesMatches[1], '; ');
        
        try {
            $rows = $this->parseValuesSection($valuesSection);
        } catch (Exception $e) {
            throw new Exception("Failed to parse VALUES section for table {$tableName}: " . $e->getMessage());
        }
        
        return [
            'table' => $tableName,
            'columns' => $columns,
            'rows' => $rows
        ];
    }

    private function parseValuesSection(string $valuesSection): array
    {
        $rows = [];
        $valuesSection = trim($valuesSection, '; ');
        $length = strlen($valuesSection);
        
        if ($length === 0) {
            return $rows;
        }
        
        $current = '';
        $inQuotes = false;
        $quoteChar = '';
        $escaped = false;
        $parenDepth = 0;
        $rowStart = false;
        
        for ($i = 0; $i < $length; $i++) {
            $char = $valuesSection[$i];
            
            if ($escaped) {
                $current .= $char;
                $escaped = false;
                continue;
            }
            
            if ($char === '\\') {
                $escaped = true;
                $current .= $char;
                continue;
            }
            
            if (!$inQuotes && ($char === '"' || $char === "'")) {
                $inQuotes = true;
                $quoteChar = $char;
                $current .= $char;
                continue;
            }
            
            if ($inQuotes && $char === $quoteChar) {
                $nextChar = ($i + 1 < $length) ? $valuesSection[$i + 1] : '';
                if ($nextChar === $quoteChar) {
                    $current .= $char . $nextChar;
                    $i++;
                    continue;
                } else {
                    $inQuotes = false;
                    $quoteChar = '';
                    $current .= $char;
                    continue;
                }
            }
            
            if (!$inQuotes && $char === '(') {
                $parenDepth++;
                if ($parenDepth === 1) {
                    $rowStart = true;
                    $current = '';
                    continue;
                }
            }
            
            if (!$inQuotes && $char === ')') {
                $parenDepth--;
                if ($parenDepth === 0 && $rowStart) {
                    try {
                        $values = $this->parseValueSet($current);
                        if (!empty($values)) {
                            $rows[] = $values;
                        }
                    } catch (Exception $e) {
                        if ($this->debug) {
                            $this->warn("Failed to parse row: " . $e->getMessage());
                        }
                    }
                    $current = '';
                    $rowStart = false;
                    continue;
                }
            }
            
            if ($rowStart) {
                $current .= $char;
            }
        }

        return $rows;
    }

    private function parseValueSet(string $valueSet): array
    {
        $values = [];
        $current = '';
        $inQuotes = false;
        $quoteChar = '';
        $escaped = false;
        $braceDepth = 0;
        $length = strlen($valueSet);

        for ($i = 0; $i < $length; $i++) {
            $char = $valueSet[$i];
            
            if ($escaped) {
                $current .= $char;
                $escaped = false;
                continue;
            }
            
            if ($char === '\\') {
                $escaped = true;
                $current .= $char;
                continue;
            }
            
            if (!$inQuotes && ($char === '"' || $char === "'")) {
                $inQuotes = true;
                $quoteChar = $char;
                $current .= $char;
                continue;
            }
            
            if ($inQuotes && $char === $quoteChar) {
                $nextChar = ($i + 1 < $length) ? $valueSet[$i + 1] : '';
                if ($nextChar === $quoteChar) {
                    $current .= $char . $nextChar;
                    $i++;
                    continue;
                } else {
                    $inQuotes = false;
                    $quoteChar = '';
                    $current .= $char;
                    continue;
                }
            }
            
            if (!$inQuotes && $char === '{') {
                $braceDepth++;
                $current .= $char;
                continue;
            }
            
            if (!$inQuotes && $char === '}') {
                $braceDepth--;
                $current .= $char;
                continue;
            }
            
            if (!$inQuotes && $braceDepth === 0 && $char === ',') {
                $values[] = $this->processValue(trim($current));
                $current = '';
                continue;
            }
            
            $current .= $char;
        }
        
        if ($current !== '') {
            $values[] = $this->processValue(trim($current));
        }

        return $values;
    }

    private function processValue(mixed $value): mixed
    {
        $value = trim((string) $value);
        
        if (strtoupper($value) === 'NULL') {
            return null;
        }
        
        if ((str_starts_with($value, "'") && str_ends_with($value, "'")) ||
            (str_starts_with($value, '"') && str_ends_with($value, '"'))) {
            $unquoted = substr($value, 1, -1);
            $unquoted = str_replace(["\'", '\"', '\\\\'], ["'", '"', '\\'], $unquoted);
            return $unquoted;
        }
        
        if (is_numeric($value)) {
            return str_contains($value, '.') ? (float) $value : (int) $value;
        }
        
        if (str_starts_with($value, '{') && str_ends_with($value, '}')) {
            return $value;
        }
        
        return $value;
    }

    private function groupStatementsByTable(array $statements): array
    {
        $grouped = [];
        foreach ($statements as $statement) {
            $table = $statement['table'];
            if (!isset($grouped[$table])) {
                $grouped[$table] = [
                    'columns' => $statement['columns'],
                    'rows' => []
                ];
            }
            
            $grouped[$table]['rows'] = array_merge($grouped[$table]['rows'], $statement['rows']);
        }

        return $grouped;
    }

    private function processTableGroups(array $groupedStatements): void
    {
        $this->totalTables = count($groupedStatements);

        foreach ($groupedStatements as $tableName => $tableData) {
            $this->info("Processing table: {$tableName}");
            
            try {
                $this->processTable($tableName, $tableData);
                $this->processedTables[$tableName] = [
                    'status' => 'success',
                    'records' => count($tableData['rows'])
                ];
            } catch (Exception $e) {
                $this->error("Failed to process table {$tableName}: " . $e->getMessage());
                $this->processedTables[$tableName] = [
                    'status' => 'failed',
                    'error' => $e->getMessage()
                ];
            }
        }
    }

    private function processTable(string $tableName, array $tableData): void
    {
        if (!$this->tableExists($tableName)) {
            throw new Exception("Table '{$tableName}' does not exist in database");
        }

        $tableSchema = $this->getTableSchema($tableName);
        $validatedData = $this->validateAndFilterColumns($tableName, $tableData, $tableSchema);
        
        if (empty($validatedData['columns']) || empty($validatedData['rows'])) {
            $this->warn("No valid data found for table: {$tableName}");
            return;
        }

        $this->truncateTable($tableName);
        $this->insertDataInChunks($tableName, $validatedData['columns'], $validatedData['rows']);
    }

    private function tableExists(string $tableName): bool
    {
        return Schema::hasTable($tableName);
    }

    private function getTableSchema(string $tableName): array
    {
        if (isset($this->tableSchemas[$tableName])) {
            return $this->tableSchemas[$tableName];
        }
        
        $columns = Schema::getColumnListing($tableName);
        $this->tableSchemas[$tableName] = $columns;
        
        return $columns;
    }

    private function validateAndFilterColumns(string $tableName, array $tableData, array $tableSchema): array
    {
        $sourceColumns = $tableData['columns'];
        $validColumns = [];
        $columnMapping = [];
        $missingColumns = [];

        foreach ($sourceColumns as $index => $column) {
            if (in_array($column, $tableSchema)) {
                $validColumns[] = $column;
                $columnMapping[$index] = count($validColumns) - 1;
            } else {
                $missingColumns[] = $column;
                if (!$this->skipMissingColumns) {
                    throw new Exception("Column '{$column}' does not exist in table '{$tableName}'. Use --skip-missing-columns to ignore.");
                }
            }
        }

        if (!empty($missingColumns)) {
            $this->warn("Skipping missing columns in {$tableName}: " . implode(', ', $missingColumns));
        }

        $validRows = [];
        foreach ($tableData['rows'] as $row) {
            if (count($row) !== count($sourceColumns)) {
                $this->warn("Skipping row with mismatched column count. Expected: " . count($sourceColumns) . ", Got: " . count($row));
                continue;
            }

            $filteredRow = [];
            foreach ($columnMapping as $sourceIndex => $targetIndex) {
                $filteredRow[] = $row[$sourceIndex] ?? null;
            }
            
            if (!empty($filteredRow)) {
                $validRows[] = $filteredRow;
            }
        }

        return [
            'columns' => $validColumns,
            'rows' => $validRows
        ];
    }

    private function truncateTable(string $tableName): void
    {
        if ($this->dryRun) {
            $this->line("  [DRY RUN] Would truncate table: {$tableName}");
            return;
        }

        try {
            DB::statement('SET FOREIGN_KEY_CHECKS=0');
            DB::table($tableName)->truncate();
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
            
            $this->line("  ✓ Truncated table: {$tableName}");
            
        } catch (Exception $e) {
            try {
                DB::statement('SET FOREIGN_KEY_CHECKS=0');
                DB::table($tableName)->delete();
                DB::statement('SET FOREIGN_KEY_CHECKS=1');
                
                $this->line("  ✓ Cleared table: {$tableName}");
            } catch (Exception $e2) {
                throw new Exception("Failed to clear table {$tableName}: " . $e2->getMessage());
            }
        }
    }

    private function insertDataInChunks(string $tableName, array $columns, array $rows): void
    {
        if ($this->dryRun) {
            $this->line("  [DRY RUN] Would insert " . count($rows) . " rows into {$tableName}");
            $this->line("  [DRY RUN] Columns: " . implode(', ', $columns));
            return;
        }

        $chunks = array_chunk($rows, $this->chunkSize);
        $totalChunks = count($chunks);
        $insertedRows = 0;

        DB::beginTransaction();
        
        try {
            foreach ($chunks as $chunkIndex => $chunk) {
                $chunkData = [];
                
                foreach ($chunk as $row) {
                    if (count($row) !== count($columns)) {
                        $this->warn("  ⚠ Skipping row with mismatched column count. Expected: " . 
                                  count($columns) . ", Got: " . count($row));
                        continue;
                    }
                    
                    $chunkData[] = array_combine($columns, $row);
                }
                
                if (!empty($chunkData)) {
                    if ($this->ignoreDuplicates) {
                        $this->insertIgnoreDuplicates($tableName, $chunkData);
                    } else {
                        DB::table($tableName)->insert($chunkData);
                    }
                    
                    $insertedRows += count($chunkData);
                    
                    $this->line("  ✓ Inserted chunk " . ($chunkIndex + 1) . "/{$totalChunks} " .
                              "({$insertedRows}/" . count($rows) . " rows)");
                }
            }
            
            DB::commit();
            $this->totalRecords += $insertedRows;
            
            $this->info("  ✅ Successfully inserted {$insertedRows} rows into {$tableName}");
            
        } catch (Exception $e) {
            DB::rollback();
            throw new Exception("Failed to insert data into {$tableName}: " . $e->getMessage());
        }
    }

    private function insertIgnoreDuplicates(string $tableName, array $data): void
    {
        foreach ($data as $row) {
            try {
                DB::table($tableName)->insert($row);
            } catch (Exception $e) {
                if (str_contains($e->getMessage(), 'Duplicate entry') || 
                    str_contains($e->getMessage(), 'Integrity constraint violation')) {
                    continue;
                } else {
                    throw $e;
                }
            }
        }
    }

    private function displaySummary(): void
    {
        $this->info("\n" . str_repeat('=', 60));
        $this->info('IMPORT SUMMARY');
        $this->info(str_repeat('=', 60));
        
        $successCount = 0;
        $failedCount = 0;
        
        foreach ($this->processedTables as $table => $info) {
            if ($info['status'] === 'success') {
                $this->line("✅ {$table}: {$info['records']} records");
                $successCount++;
            } else {
                $this->error("❌ {$table}: {$info['error']}");
                $failedCount++;
            }
        }
        
        $this->info("\nTotals:");
        $this->info("- Tables processed: {$this->totalTables}");
        $this->info("- Successful: {$successCount}");
        $this->info("- Failed: {$failedCount}");
        $this->info("- Total records imported: {$this->totalRecords}");
        
        if ($this->dryRun) {
            $this->warn("\n⚠ This was a DRY RUN - no data was actually imported!");
        }
    }
}