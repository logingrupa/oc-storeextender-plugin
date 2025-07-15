<?php namespace Logingrupa\StoreExtender\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Exception;

/**
 * SQL Import Console Command
 * 
 * This command reads raw SQL INSERT statements and executes them against the database
 * with proper table existence and duplicate record checks.
 */
class SqlImportCommand extends Command
{
    /**
     * @var string The console command name.
     */
    protected $name = 'storeextender:sql-import';

    /**
     * @var string The console command description.
     */
    protected $description = 'Import raw SQL INSERT statements to database with duplicate checks';

    /**
     * @var string The console command signature.
     */
    protected $signature = 'storeextender:sql-import 
                            {file? : Path to SQL file containing INSERT statements}
                            {--sql= : Direct SQL INSERT statement}
                            {--dry-run : Show what would be executed without actually running}
                            {--mysql-compat : Convert MySQL syntax to PostgreSQL compatible syntax}
                            {--debug : Show detailed debug information}';

    /**
     * Execute the console command.
     * @return void
     */
    public function handle()
    {
        $this->info('Starting SQL Import Process...');
        
        $sqlFile = $this->argument('file');
        $directSql = $this->option('sql');
        $dryRun = $this->option('dry-run');
        $mysqlCompat = $this->option('mysql-compat');
        
        if (!$sqlFile && !$directSql) {
            $this->error('Please provide either a file path or direct SQL statement.');
            $this->info('Usage examples:');
            $this->info('  php artisan storeextender:sql-import /path/to/file.sql');
            $this->info('  php artisan storeextender:sql-import --sql="INSERT INTO table..."');
            return 1;
        }
        
        try {
            $sqlStatements = [];
            
            if ($sqlFile) {
                $sqlStatements = $this->readSqlFile($sqlFile);
            } else {
                $sqlStatements = [$directSql];
            }
            
            $this->info(sprintf('Found %d SQL statement(s) to process.', count($sqlStatements)));
            
            $processed = 0;
            $skipped = 0;
            $errors = 0;
            
            foreach ($sqlStatements as $index => $sql) {
                $sql = trim($sql);
                if (empty($sql) || !$this->isInsertStatement($sql)) {
                    continue;
                }
                
                // Convert MySQL syntax to PostgreSQL if needed
                if ($mysqlCompat) {
                    $sql = $this->convertMySqlToPostgreSQL($sql);
                }
                
                $this->info(sprintf('Processing statement %d...', $index + 1));
                
                if ($dryRun) {
                    $this->line('DRY RUN: ' . substr($sql, 0, 100) . '...');
                    $processed++;
                    continue;
                }
                
                $result = $this->processSqlStatement($sql);
                
                switch ($result['status']) {
                    case 'processed':
                        $processed++;
                        $this->info('✓ ' . $result['message']);
                        break;
                    case 'skipped':
                        $skipped++;
                        $this->warn('⚠ ' . $result['message']);
                        break;
                    case 'error':
                        $errors++;
                        $this->error('✗ ' . $result['message']);
                        break;
                }
            }
            
            $this->info('\nImport Summary:');
            $this->info(sprintf('Processed: %d', $processed));
            $this->info(sprintf('Skipped: %d', $skipped));
            $this->info(sprintf('Errors: %d', $errors));
            
            return 0;
            
        } catch (Exception $e) {
            $this->error('Import failed: ' . $e->getMessage());
            return 1;
        }
    }
    
    /**
     * Read SQL statements from file
     * @param string $filePath
     * @return array
     */
    protected function readSqlFile($filePath)
    {
        if (!file_exists($filePath)) {
            throw new Exception("SQL file not found: {$filePath}");
        }
        
        $content = file_get_contents($filePath);
        
        // Split by semicolon and filter empty statements
        $statements = array_filter(
            array_map('trim', explode(';', $content)),
            function($stmt) {
                return !empty($stmt) && $this->isInsertStatement($stmt);
            }
        );
        
        return $statements;
    }
    
    /**
     * Check if statement is an INSERT statement
     * @param string $sql
     * @return bool
     */
    protected function isInsertStatement($sql)
    {
        return preg_match('/^\s*INSERT\s+INTO/i', $sql);
    }
    
    /**
     * Process a single SQL INSERT statement
     * @param string $sql
     * @return array
     */
    protected function processSqlStatement($sql)
    {
        try {
            // Extract table name from INSERT statement
            $tableName = $this->extractTableName($sql);
            
            if (!$tableName) {
                return [
                    'status' => 'error',
                    'message' => 'Could not extract table name from SQL statement'
                ];
            }
            
            // Check if table exists
            if (!Schema::hasTable($tableName)) {
                return [
                    'status' => 'skipped',
                    'message' => "Table '{$tableName}' does not exist"
                ];
            }
            
            // Parse the INSERT statement to extract data
            $parsed = $this->parseInsertStatement($sql);
            
            if (!$parsed) {
                return [
                    'status' => 'error',
                    'message' => 'Could not parse INSERT statement'
                ];
            }
            
            // Check for duplicates
            $duplicateCheck = $this->checkForDuplicatesAdvanced($parsed, $tableName);
            
            if ($duplicateCheck['has_duplicates']) {
                return [
                    'status' => 'skipped',
                    'message' => "Record already exists in table '{$tableName}': " . $duplicateCheck['message']
                ];
            }
            
            // Use prepared statement for safer execution
            $this->executeInsertStatement($parsed, $tableName);
            
            return [
                'status' => 'processed',
                'message' => "Successfully inserted record into '{$tableName}'"
            ];
            
        } catch (Exception $e) {
            return [
                'status' => 'error',
                'message' => 'SQL execution failed: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Extract table name from INSERT statement
     * @param string $sql
     * @return string|null
     */
    protected function extractTableName($sql)
    {
        if (preg_match('/INSERT\s+INTO\s+`?([a-zA-Z0-9_]+)`?/i', $sql, $matches)) {
            return $matches[1];
        }
        
        return null;
    }
    
    /**
     * Check for duplicate records using parsed data
     * @param array $parsed
     * @param string $tableName
     * @return array
     */
    protected function checkForDuplicatesAdvanced($parsed, $tableName)
    {
        try {
            // Check if record with same ID exists (assuming 'id' column)
            if (in_array('id', $parsed['columns'])) {
                $idIndex = array_search('id', $parsed['columns']);
                $idValue = $parsed['values'][$idIndex];
                
                $exists = DB::table($tableName)->where('id', $idValue)->exists();
                
                if ($exists) {
                    return [
                        'has_duplicates' => true,
                        'message' => "ID {$idValue} already exists"
                    ];
                }
            }
            
            return ['has_duplicates' => false, 'message' => ''];
            
        } catch (Exception $e) {
            return ['has_duplicates' => false, 'message' => 'Duplicate check failed: ' . $e->getMessage()];
        }
    }
    
    /**
     * Parse INSERT statement to extract columns and values
     * Enhanced to handle JSON data and complex strings
     * @param string $sql
     * @return array|null
     */
    protected function parseInsertStatement($sql)
    {
        // First, normalize the SQL by removing extra whitespace and newlines but preserve structure
        $normalizedSql = preg_replace('/\r\n|\r|\n/', ' ', trim($sql));
        $normalizedSql = preg_replace('/\s+/', ' ', $normalizedSql);
        
        // Match INSERT INTO table (columns) VALUES with multi-line support
        $pattern = '/INSERT\s+INTO\s+`?([a-zA-Z0-9_]+)`?\s*\(([^)]+)\)\s*VALUES\s*(.+)/is';
        
        if (preg_match($pattern, $normalizedSql, $matches)) {
            $tableName = $matches[1];
            $columnsStr = $matches[2];
            $valuesSection = $matches[3];
            
            // Parse columns
            $columns = $this->parseColumns($columnsStr);
            
            // Parse all value rows from the VALUES section
            $allValues = $this->parseMultipleValueRows($valuesSection);
            
            if (empty($allValues)) {
                $this->warn("Could not parse any values from INSERT statement");
                $this->warn("Table: $tableName");
                $this->warn("Values section length: " . strlen($valuesSection));
                $this->warn("Values section preview: " . substr($valuesSection, 0, 200) . "...");
                return null;
            }
            
            // For now, just take the first row of values
            $values = $allValues[0];
            
            if (count($columns) !== count($values)) {
                $this->warn("Column count (" . count($columns) . ") doesn't match value count (" . count($values) . ")");
                if ($tableName === 'cms_theme_data') {
                    $this->warn("Columns: " . implode(', ', $columns));
                    $this->warn("Values found: " . count($values));
                    foreach ($values as $idx => $value) {
                        $this->warn("Value $idx: " . substr($value, 0, 100) . (strlen($value) > 100 ? '...' : ''));
                    }
                    
                    // Debug the raw values section
                    $this->warn("Raw values section length: " . strlen($valuesSection));
                    $this->warn("Raw values preview: " . substr($valuesSection, 0, 200) . '...');
                }
                return null;
            }
            
            return [
                'table' => $tableName,
                'columns' => $columns,
                'values' => $values,
                'all_rows' => $allValues
            ];
        }
        
        return null;
    }
    
    /**
     * Parse column names from INSERT statement
     * @param string $columnsStr
     * @return array
     */
    protected function parseColumns($columnsStr)
    {
        $columns = [];
        $parts = explode(',', $columnsStr);
        
        foreach ($parts as $part) {
            $column = trim($part, ' `\'"');
            $columns[] = $column;
        }
        
        return $columns;
    }
    
    /**
     * Parse multiple value rows from VALUES section
     * @param string $valuesSection
     * @return array
     */
    protected function parseMultipleValueRows($valuesSection)
    {
        $allRows = [];
        $current = '';
        $inString = false;
        $stringChar = null;
        $parenLevel = 0;
        $i = 0;
        $len = strlen($valuesSection);
        
        while ($i < $len) {
            $char = $valuesSection[$i];
            
            // Handle escape sequences properly
            if ($char === '\\' && $i + 1 < $len && $inString) {
                // Always include the backslash and next character when in string
                $current .= $char;
                $i++;
                if ($i < $len) {
                    $current .= $valuesSection[$i];
                }
            } else {
                if (!$inString) {
                    if ($char === '\'' || $char === '"') {
                        $inString = true;
                        $stringChar = $char;
                        $current .= $char;
                    } elseif ($char === '(') {
                        $parenLevel++;
                        if ($parenLevel === 1) {
                            // Start of a new value row, don't include the opening paren
                            $current = '';
                        } else {
                            $current .= $char;
                        }
                    } elseif ($char === ')') {
                        $parenLevel--;
                        if ($parenLevel === 0) {
                            // End of a value row
                            $values = $this->parseValues($current);
                            if (!empty($values)) {
                                $allRows[] = $values;
                            }
                            $current = '';
                        } else {
                            $current .= $char;
                        }
                    } else {
                        $current .= $char;
                    }
                } else {
                    $current .= $char;
                    if ($char === $stringChar) {
                        // Check if this quote is escaped by counting preceding backslashes
                        $backslashCount = 0;
                        $j = $i - 1;
                        while ($j >= 0 && $valuesSection[$j] === '\\') {
                            $backslashCount++;
                            $j--;
                        }
                        
                        // If even number of backslashes (including 0), the quote is not escaped
                        if ($backslashCount % 2 === 0) {
                            $inString = false;
                            $stringChar = null;
                        }
                    }
                }
            }
            
            $i++;
        }
        
        // If we still have content and paren level is 1, we might have missed the closing paren
        if ($parenLevel === 1 && !empty(trim($current))) {
            $values = $this->parseValues($current);
            if (!empty($values)) {
                $allRows[] = $values;
            }
        }
        
        return $allRows;
    }
    
    /**
     * Parse values from INSERT statement with proper JSON handling
     * @param string $valuesStr
     * @return array
     */
    protected function parseValues($valuesStr)
    {
        $values = [];
        $current = '';
        $inQuote = false;
        $quoteChar = null;
        $parenLevel = 0;
        $len = strlen($valuesStr);
        
        // Debug information
        if ($this->option('debug')) {
            $this->info("Parsing values string of length: {$len}");
            $this->info("First 200 chars: " . substr($valuesStr, 0, 200));
        }
        
        $i = 0;
        while ($i < $len) {
            $char = $valuesStr[$i];
            
            if (!$inQuote) {
                // Not in a quoted string
                if ($char === '\'' || $char === '"') {
                    // Starting a quoted string
                    $inQuote = true;
                    $quoteChar = $char;
                    $current .= $char;
                    if ($this->option('debug')) {
                        $this->info("Started quote at position {$i} with char '{$char}'");
                    }
                } elseif ($char === '(') {
                    $parenLevel++;
                    $current .= $char;
                } elseif ($char === ')') {
                    $parenLevel--;
                    $current .= $char;
                } elseif ($char === ',' && $parenLevel === 0) {
                    // Found a value separator
                    $trimmed = trim($current);
                    if ($trimmed !== '') {
                        $values[] = $this->cleanValue($trimmed);
                        if ($this->option('debug')) {
                            $valuePreview = strlen($trimmed) > 50 ? substr($trimmed, 0, 50) . '...' : $trimmed;
                            $this->info("Found value " . count($values) . ": {$valuePreview}");
                        }
                    }
                    $current = '';
                } else {
                    $current .= $char;
                }
            } else {
                // Inside a quoted string
                if ($char === '\\') {
                    // Handle escape sequence - add the backslash and next character
                    $current .= $char;
                    if ($i + 1 < $len) {
                        $i++;
                        $current .= $valuesStr[$i];
                    }
                } elseif ($char === $quoteChar) {
                    // Check if this quote is escaped by looking at the previous characters
                    $isEscaped = false;
                    if ($i > 0) {
                        // Count consecutive backslashes before this quote
                        $backslashCount = 0;
                        $j = $i - 1;
                        while ($j >= 0 && $valuesStr[$j] === '\\') {
                            $backslashCount++;
                            $j--;
                        }
                        // If odd number of backslashes, the quote is escaped
                        $isEscaped = ($backslashCount % 2 === 1);
                        
                        if ($this->option('debug') && $i > 8900) {
                            $this->info("Quote at position {$i}, backslash count: {$backslashCount}, escaped: " . ($isEscaped ? 'yes' : 'no'));
                            $this->info("Context: " . substr($valuesStr, max(0, $i-10), 21));
                        }
                    }
                    
                    $current .= $char;
                    
                    if (!$isEscaped) {
                        $inQuote = false;
                        $quoteChar = null;
                        if ($this->option('debug')) {
                            $this->info("Ended quote at position {$i}");
                        }
                    }
                } else {
                    $current .= $char;
                }
            }
            
            $i++;
        }
        
        // Add the last value
        $trimmed = trim($current);
        if ($trimmed !== '') {
            $values[] = $this->cleanValue($trimmed);
            if ($this->option('debug')) {
                $valuePreview = strlen($trimmed) > 50 ? substr($trimmed, 0, 50) . '...' : $trimmed;
                $this->info("Found final value " . count($values) . ": {$valuePreview}");
            }
        }
        
        if ($this->option('debug')) {
            $this->info("Total values found: " . count($values));
        }
        
        return $values;
    }
    
    /**
     * Clean and prepare value for database insertion
     * @param string $value
     * @return mixed
     */
    protected function cleanValue($value)
    {
        $value = trim($value);
        
        // Handle NULL values
        if (strtoupper($value) === 'NULL') {
            return null;
        }
        
        // Handle quoted strings
        if ((substr($value, 0, 1) === '\'' && substr($value, -1) === '\'') ||
            (substr($value, 0, 1) === '"' && substr($value, -1) === '"')) {
            $value = substr($value, 1, -1);
            // Unescape quotes
            $value = str_replace(["\\\\", "\\'", '\\"'], ["\\", "'", '"'], $value);
        }
        
        return $value;
    }
    
    /**
     * Execute INSERT statement using prepared statement
     * @param array $parsed
     * @param string $tableName
     * @return void
     */
    protected function executeInsertStatement($parsed, $tableName)
     {
         $data = array_combine($parsed['columns'], $parsed['values']);
         DB::table($tableName)->insert($data);
     }
     
     /**
      * Convert MySQL syntax to PostgreSQL compatible syntax
      * @param string $sql
      * @return string
      */
     protected function convertMySqlToPostgreSQL($sql)
     {
         // Remove backticks around table and column names
         $sql = str_replace('`', '', $sql);
         
         // Convert MySQL specific functions and syntax
         $replacements = [
             // MySQL boolean values
             "'0'" => 'false',
             "'1'" => 'true',
             // MySQL date functions (basic conversion)
             'NOW()' => 'CURRENT_TIMESTAMP',
             'CURDATE()' => 'CURRENT_DATE',
             'CURTIME()' => 'CURRENT_TIME',
         ];
         
         foreach ($replacements as $search => $replace) {
             $sql = str_ireplace($search, $replace, $sql);
         }
         
         return $sql;
     }
}