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
        $this->initializeOptions();
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

    private function initializeOptions(): void
    {
        $this->debug = $this->option('debug');
        $functionName = __FUNCTION__;
        
        if ($this->debug) {
            $this->debugFunction($functionName, 'Starting function');
        }

        $this->dryRun = $this->option('dry-run');
        $this->chunkSize = (int) $this->option('chunk-size');
        $this->skipMissingColumns = $this->option('skip-missing-columns');
        $this->ignoreDuplicates = $this->option('ignore-duplicates');

        if ($this->debug) {
            $this->debugFunction($functionName, 'Options initialized', [
                'dry_run' => $this->dryRun,
                'chunk_size' => $this->chunkSize,
                'skip_missing_columns' => $this->skipMissingColumns,
                'ignore_duplicates' => $this->ignoreDuplicates
            ]);
        }
    }

    private function getSqlContent(): string
    {
        $functionName = __FUNCTION__;
        $this->debugFunction($functionName, 'Starting function');

        $sqlFile = $this->argument('file');
        $directSql = $this->option('sql');

        if ($directSql) {
            $this->debugFunction($functionName, 'Using direct SQL input', ['length' => strlen($directSql)]);
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

        $this->debugFunction($functionName, 'File content loaded', [
            'file_path' => $sqlFile,
            'content_length' => strlen($content),
            'first_100_chars' => substr($content, 0, 100)
        ]);

        return $content;
    }

    private function parseInsertStatements(string $sqlContent): array
    {
        $functionName = __FUNCTION__;
        $this->debugFunction($functionName, 'Starting function');

        // Clean and normalize SQL content
        $sqlContent = $this->cleanSqlContent($sqlContent);
        
        // Split by semicolons but preserve semicolons inside quotes
        $statements = $this->splitSqlStatements($sqlContent);
        
        $insertStatements = [];
        
        foreach ($statements as $statement) {
            $statement = trim($statement);
            
            // Skip empty statements
            if (empty($statement)) {
                continue;
            }
            
            // Check if it's an INSERT statement
            if (!preg_match('/^\s*INSERT\s+INTO\s+/i', $statement)) {
                continue;
            }
            
            try {
                $parsedStatement = $this->parseIndividualInsertStatement($statement);
                if ($parsedStatement) {
                    $insertStatements[] = $parsedStatement;
                }
            } catch (Exception $e) {
                $this->warn("Failed to parse statement: " . substr($statement, 0, 100) . "... Error: " . $e->getMessage());
                if ($this->debug) {
                    $this->debugFunction($functionName, 'Parse error', [
                        'statement_preview' => substr($statement, 0, 200),
                        'error' => $e->getMessage()
                    ]);
                }
            }
        }

        $this->debugFunction($functionName, 'Parsing completed', [
            'total_statements' => count($insertStatements),
            'tables_found' => array_unique(array_column($insertStatements, 'table'))
        ]);

        if (empty($insertStatements)) {
            throw new Exception('No valid INSERT statements found in the provided SQL');
        }

        return $insertStatements;
    }

    private function cleanSqlContent(string $content): string
    {
        $functionName = __FUNCTION__;
        $this->debugFunction($functionName, 'Starting function');

        // Remove SQL comments
        $content = preg_replace('/--.*$/m', '', $content);
        $content = preg_replace('/\/\*.*?\*\//s', '', $content);
        
        // Normalize whitespace but preserve structure
        $content = preg_replace('/[ \t]+/', ' ', $content);
        $content = preg_replace('/\n\s*\n/', "\n", $content);
        
        $this->debugFunction($functionName, 'Content cleaned', [
            'cleaned_length' => strlen($content)
        ]);

        return trim($content);
    }

    private function splitSqlStatements(string $sql): array
    {
        $statements = [];
        $current = '';
        $inQuotes = false;
        $quoteChar = '';
        $escaped = false;
        
        for ($i = 0; $i < strlen($sql); $i++) {
            $char = $sql[$i];
            
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
                $inQuotes = false;
                $current .= $char;
                continue;
            }
            
            if (!$inQuotes && $char === ';') {
                $statements[] = trim($current);
                $current = '';
                continue;
            }
            
            $current .= $char;
        }
        
        // Add final statement if exists
        if (!empty(trim($current))) {
            $statements[] = trim($current);
        }
        
        return array_filter($statements, fn($stmt) => !empty($stmt));
    }

    private function parseIndividualInsertStatement(string $statement): ?array
    {
        $functionName = __FUNCTION__;
        
        // Extract table name
        if (!preg_match('/INSERT\s+INTO\s+`?([^`\s(]+)`?\s*\(/i', $statement, $tableMatches)) {
            throw new Exception('Could not extract table name from INSERT statement');
        }
        
        $tableName = trim($tableMatches[1], '`');
        
        // Extract columns
        if (!preg_match('/INSERT\s+INTO\s+`?[^`\s(]+`?\s*\(([^)]+)\)\s+VALUES/i', $statement, $columnMatches)) {
            throw new Exception('Could not extract columns from INSERT statement');
        }
        
        $columnsString = $columnMatches[1];
        $columns = array_map(fn($col) => trim($col, '` '), explode(',', $columnsString));
        
        // Extract VALUES section
        if (!preg_match('/VALUES\s+(.+)$/is', $statement, $valuesMatches)) {
            throw new Exception('Could not extract VALUES section from INSERT statement');
        }
        
        $valuesSection = trim($valuesMatches[1], '; ');
        $rows = $this->parseValuesSection($valuesSection);
        
        if ($this->debug) {
            $this->debugFunction($functionName, 'Individual statement parsed', [
                'table' => $tableName,
                'columns_count' => count($columns),
                'rows_count' => count($rows),
                'columns' => $columns
            ]);
        }
        
        return [
            'table' => $tableName,
            'columns' => $columns,
            'rows' => $rows
        ];
    }

    private function parseValuesSection(string $valuesSection): array
    {
        $functionName = __FUNCTION__;
        $this->debugFunction($functionName, 'Starting function');

        $rows = [];
        $valuesSection = trim($valuesSection, '; ');
        
        // Split by ),( to get individual value sets
        $pattern = '/\),\s*\(/';
        $valueSets = preg_split($pattern, $valuesSection);
        
        foreach ($valueSets as $index => $valueSet) {
            // Clean up the value set
            $valueSet = trim($valueSet, '() ');
            
            if (empty($valueSet)) {
                continue;
            }
            
            $values = $this->parseValueSet($valueSet);
            if (!empty($values)) {
                $rows[] = $values;
            }
        }

        $this->debugFunction($functionName, 'Values section parsed', [
            'total_rows' => count($rows),
            'sample_row' => isset($rows[0]) ? $rows[0] : null
        ]);

        return $rows;
    }

    private function parseValueSet(string $valueSet): array
    {
        $functionName = __FUNCTION__;
        
        $values = [];
        $current = '';
        $inQuotes = false;
        $quoteChar = '';
        $escaped = false;
        $depth = 0;

        for ($i = 0; $i < strlen($valueSet); $i++) {
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
                $inQuotes = false;
                $quoteChar = '';
                $current .= $char;
                continue;
            }
            
            if (!$inQuotes && $char === '{') {
                $depth++;
                $current .= $char;
                continue;
            }
            
            if (!$inQuotes && $char === '}') {
                $depth--;
                $current .= $char;
                continue;
            }
            
            if (!$inQuotes && $depth === 0 && $char === ',') {
                $values[] = $this->processValue(trim($current));
                $current = '';
                continue;
            }
            
            $current .= $char;
        }
        
        // Add the last value
        if ($current !== '') {
            $values[] = $this->processValue(trim($current));
        }

        if ($this->debug && count($values) <= 5) { // Only debug small value sets
            $this->debugFunction($functionName, 'Value set parsed', [
                'values_count' => count($values),
                'values' => $values
            ]);
        }

        return $values;
    }

    private function processValue(mixed $value): mixed
    {
        $value = trim((string) $value);
        
        // Handle NULL
        if (strtoupper($value) === 'NULL') {
            return null;
        }
        
        // Handle quoted strings
        if ((str_starts_with($value, "'") && str_ends_with($value, "'")) ||
            (str_starts_with($value, '"') && str_ends_with($value, '"'))) {
            $unquoted = substr($value, 1, -1);
            // Unescape quotes
            $unquoted = str_replace(["\'", '\"', '\\\\'], ["'", '"', '\\'], $unquoted);
            return $unquoted;
        }
        
        // Handle numbers
        if (is_numeric($value)) {
            return str_contains($value, '.') ? (float) $value : (int) $value;
        }
        
        // Handle JSON-like structures
        if (str_starts_with($value, '{') && str_ends_with($value, '}')) {
            return $value; // Keep as string for now
        }
        
        return $value;
    }

    private function groupStatementsByTable(array $statements): array
    {
        $functionName = __FUNCTION__;
        $this->debugFunction($functionName, 'Starting function');

        $grouped = [];
        foreach ($statements as $statement) {
            $table = $statement['table'];
            if (!isset($grouped[$table])) {
                $grouped[$table] = [
                    'columns' => $statement['columns'],
                    'rows' => []
                ];
            }
            
            // Merge rows from this statement
            $grouped[$table]['rows'] = array_merge($grouped[$table]['rows'], $statement['rows']);
        }

        $this->debugFunction($functionName, 'Statements grouped', [
            'tables_count' => count($grouped),
            'tables' => array_keys($grouped),
            'rows_per_table' => array_map(fn($data) => count($data['rows']), $grouped)
        ]);

        return $grouped;
    }

    private function processTableGroups(array $groupedStatements): void
    {
        $functionName = __FUNCTION__;
        $this->debugFunction($functionName, 'Starting function');

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
                
                if ($this->debug) {
                    $this->error("Stack trace for {$tableName}: " . $e->getTraceAsString());
                }
            }
        }

        $this->debugFunction($functionName, 'All tables processed', [
            'processed_tables' => $this->processedTables
        ]);
    }

    private function processTable(string $tableName, array $tableData): void
    {
        $functionName = __FUNCTION__;
        $this->debugFunction($functionName, "Starting function for table: {$tableName}");

        // Check if table exists
        if (!$this->tableExists($tableName)) {
            throw new Exception("Table '{$tableName}' does not exist in database");
        }

        // Get table schema
        $tableSchema = $this->getTableSchema($tableName);
        
        // Validate and filter columns
        $validatedData = $this->validateAndFilterColumns($tableName, $tableData, $tableSchema);
        
        if (empty($validatedData['columns']) || empty($validatedData['rows'])) {
            $this->warn("No valid data found for table: {$tableName}");
            return;
        }

        // Truncate table
        $this->truncateTable($tableName);

        // Insert data in chunks
        $this->insertDataInChunks($tableName, $validatedData['columns'], $validatedData['rows']);

        $this->debugFunction($functionName, "Table processing completed", [
            'table' => $tableName,
            'columns_count' => count($validatedData['columns']),
            'rows_count' => count($validatedData['rows'])
        ]);
    }

    private function tableExists(string $tableName): bool
    {
        $functionName = __FUNCTION__;
        $this->debugFunction($functionName, "Checking table existence: {$tableName}");

        $exists = Schema::hasTable($tableName);
        
        $this->debugFunction($functionName, "Table existence check result", [
            'table' => $tableName,
            'exists' => $exists
        ]);

        return $exists;
    }

    private function getTableSchema(string $tableName): array
    {
        $functionName = __FUNCTION__;
        
        if (isset($this->tableSchemas[$tableName])) {
            return $this->tableSchemas[$tableName];
        }
        
        $columns = Schema::getColumnListing($tableName);
        $this->tableSchemas[$tableName] = $columns;
        
        if ($this->debug) {
            $this->debugFunction($functionName, "Retrieved schema for table: {$tableName}", [
                'columns' => $columns
            ]);
        }
        
        return $columns;
    }

    private function validateAndFilterColumns(string $tableName, array $tableData, array $tableSchema): array
    {
        $functionName = __FUNCTION__;
        $this->debugFunction($functionName, "Validating columns for table: {$tableName}");

        $sourceColumns = $tableData['columns'];
        $validColumns = [];
        $columnMapping = [];
        $missingColumns = [];

        // Check which columns exist in the target table
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

        // Filter rows based on valid columns
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

        $this->debugFunction($functionName, "Column validation completed", [
            'original_columns' => count($sourceColumns),
            'valid_columns' => count($validColumns),
            'missing_columns' => $missingColumns,
            'original_rows' => count($tableData['rows']),
            'valid_rows' => count($validRows)
        ]);

        return [
            'columns' => $validColumns,
            'rows' => $validRows
        ];
    }

    private function truncateTable(string $tableName): void
    {
        $functionName = __FUNCTION__;
        $this->debugFunction($functionName, "Truncating table: {$tableName}");

        if ($this->dryRun) {
            $this->line("  [DRY RUN] Would truncate table: {$tableName}");
            return;
        }

        try {
            // Disable foreign key checks temporarily
            DB::statement('SET FOREIGN_KEY_CHECKS=0');
            DB::table($tableName)->truncate();
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
            
            $this->line("  ✓ Truncated table: {$tableName}");
            
        } catch (Exception $e) {
            // Try alternative deletion method
            try {
                DB::statement('SET FOREIGN_KEY_CHECKS=0');
                DB::table($tableName)->delete();
                DB::statement('SET FOREIGN_KEY_CHECKS=1');
                
                $this->line("  ✓ Cleared table: {$tableName}");
            } catch (Exception $e2) {
                throw new Exception("Failed to clear table {$tableName}: " . $e2->getMessage());
            }
        }

        $this->debugFunction($functionName, "Table truncated successfully: {$tableName}");
    }

    private function insertDataInChunks(string $tableName, array $columns, array $rows): void
    {
        $functionName = __FUNCTION__;
        $this->debugFunction($functionName, "Starting bulk insert for table: {$tableName}");

        if ($this->dryRun) {
            $this->line("  [DRY RUN] Would insert " . count($rows) . " rows into {$tableName}");
            $this->line("  [DRY RUN] Columns: " . implode(', ', $columns));
            if ($this->debug && isset($rows[0])) {
                $this->line("  [DRY RUN] Sample row: " . json_encode($rows[0]));
            }
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
                        // Use INSERT IGNORE for MySQL or similar approach
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

        $this->debugFunction($functionName, "Bulk insert completed", [
            'table' => $tableName,
            'total_rows' => count($rows),
            'inserted_rows' => $insertedRows,
            'chunks_processed' => $totalChunks
        ]);
    }

    private function insertIgnoreDuplicates(string $tableName, array $data): void
    {
        foreach ($data as $row) {
            try {
                DB::table($tableName)->insert($row);
            } catch (Exception $e) {
                // Check if it's a duplicate key error
                if (str_contains($e->getMessage(), 'Duplicate entry') || 
                    str_contains($e->getMessage(), 'Integrity constraint violation')) {
                    // Silently skip duplicates
                    continue;
                } else {
                    // Re-throw other errors
                    throw $e;
                }
            }
        }
    }

    private function displaySummary(): void
    {
        $functionName = __FUNCTION__;
        $this->debugFunction($functionName, 'Displaying summary');

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

        $this->debugFunction($functionName, 'Summary displayed', [
            'successful_tables' => $successCount,
            'failed_tables' => $failedCount,
            'total_records' => $this->totalRecords
        ]);
    }

    private function debugFunction(string $functionName, string $message, array $data = []): void
    {
        if (!$this->debug) {
            return;
        }

        $this->line("🔍 [{$functionName}] {$message}");
        
        if (!empty($data)) {
            foreach ($data as $key => $value) {
                if (is_array($value) || is_object($value)) {
                    $jsonString = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                    // Truncate very long JSON for readability
                    if (strlen($jsonString) > 500) {
                        $jsonString = substr($jsonString, 0, 500) . '...}';
                    }
                    $this->line("   📊 {$key}: " . $jsonString);
                } else {
                    $this->line("   📊 {$key}: " . var_export($value, true));
                }
            }
        }
    }
}