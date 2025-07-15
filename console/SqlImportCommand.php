<?php namespace Logingrupa\StoreExtender\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Exception;

class SqlImportCommand extends Command
{
    protected $name = 'storeextender:sql-import';
    protected $description = 'Import raw SQL INSERT statements to database with duplicate checks';
    protected $signature = 'storeextender:sql-import 
                            {file? : Path to SQL file containing INSERT statements}
                            {--sql= : Direct SQL INSERT statement}
                            {--dry-run : Show what would be executed without actually running}
                            {--mysql-compat : Convert MySQL syntax to PostgreSQL compatible syntax}
                            {--debug : Show detailed debug information}';

    public function handle()
    {
        $this->info('Starting SQL Import Process...');
        
        $sqlFile = $this->argument('file');
        $directSql = $this->option('sql');
        $dryRun = $this->option('dry-run');
        $mysqlCompat = $this->option('mysql-compat');
        
        if (!$sqlFile && !$directSql) {
            $this->error('Please provide either a file path or direct SQL statement.');
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
            $validationErrors = 0;
    
            foreach ($sqlStatements as $index => $sql) {
                $sql = trim($sql);
                if (empty($sql) || !$this->isInsertStatement($sql)) {
                    continue;
                }
    
                $this->info(sprintf('Processing statement %d...', $index + 1));
                
                // Validate SQL statement first
                $validationIssues = $this->validateSqlStatement($sql);
                if (!empty($validationIssues)) {
                    $validationErrors++;
                    $this->error(sprintf('✗ Statement %d validation failed:', $index + 1));
                    foreach ($validationIssues as $issue) {
                        $this->error("  - $issue");
                    }
                    
                    if ($this->option('debug')) {
                        $this->warn("DEBUG Invalid statement preview: " . substr($sql, 0, 200) . "...");
                        $this->warn("DEBUG Invalid statement ending: ..." . substr($sql, -200));
                    }
                    
                    $errors++;
                    continue;
                }
    
                if ($mysqlCompat) {
                    $sql = $this->convertMySqlToPostgreSQL($sql);
                }
    
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
    
            $this->info("\nImport Summary:");
            $this->info(sprintf('Processed: %d', $processed));
            $this->info(sprintf('Skipped: %d', $skipped));
            $this->info(sprintf('Errors: %d', $errors));
            if ($validationErrors > 0) {
                $this->warn(sprintf('Validation Errors: %d', $validationErrors));
                $this->warn('Tip: Check if your SQL file is complete and properly formatted');
            }
    
            return $errors > 0 ? 1 : 0;
    
        } catch (Exception $e) {
            $this->error('Import failed: ' . $e->getMessage());
            if ($this->option('debug')) {
                $this->error('Exception trace: ' . $e->getTraceAsString());
            }
            return 1;
        }
    }

    protected function readSqlFile($filePath)
    {
        if (!file_exists($filePath)) {
            throw new Exception("SQL file not found: {$filePath}");
        }

        $content = file_get_contents($filePath);
        
        if ($this->option('debug')) {
            $this->warn("DEBUG File reading - Original content length: " . strlen($content));
            $this->warn("DEBUG File reading - First 200 chars: " . substr($content, 0, 200));
            $this->warn("DEBUG File reading - Last 200 chars: " . substr($content, -200));
        }

        // Enhanced SQL statement splitting that better handles multiline and complex statements
        $statements = $this->splitSqlStatements($content);
        
        if ($this->option('debug')) {
            $this->warn("DEBUG Found " . count($statements) . " SQL statements after splitting");
            foreach ($statements as $index => $stmt) {
                $this->warn("DEBUG Statement $index length: " . strlen($stmt));
                $this->warn("DEBUG Statement $index preview: " . substr($stmt, 0, 100) . "...");
                
                // Check if statement appears complete
                $openParens = substr_count($stmt, '(');
                $closeParens = substr_count($stmt, ')');
                if ($openParens !== $closeParens) {
                    $this->warn("DEBUG *** Statement $index has unbalanced parentheses! Open: $openParens, Close: $closeParens ***");
                }
            }
        }

        // Filter valid INSERTs
        $validStatements = array_filter($statements, fn($stmt) => !empty($stmt) && $this->isInsertStatement($stmt));
        
        if ($this->option('debug')) {
            $this->warn("DEBUG Valid INSERT statements: " . count($validStatements));
        }

        return $validStatements;
    }

    /**
     * Enhanced SQL statement splitting that better handles complex multiline statements
     */
    protected function splitSqlStatements($content)
    {
        $statements = [];
        $currentStatement = '';
        $inQuotes = false;
        $quoteChar = '';
        $parenthesesLevel = 0;
        $length = strlen($content);
        
        if ($this->option('debug')) {
            $this->warn("DEBUG Starting enhanced SQL splitting for content of length: $length");
        }
        
        for ($i = 0; $i < $length; $i++) {
            $char = $content[$i];
            $nextChar = ($i + 1 < $length) ? $content[$i + 1] : '';
            $prevChar = ($i > 0) ? $content[$i - 1] : '';
            
            // Handle string literals
            if (!$inQuotes && ($char === '"' || $char === "'")) {
                $inQuotes = true;
                $quoteChar = $char;
                $currentStatement .= $char;
            } elseif ($inQuotes && $char === $quoteChar) {
                // Check if it's escaped
                if ($prevChar !== '\\') {
                    $inQuotes = false;
                    $quoteChar = '';
                }
                $currentStatement .= $char;
            } elseif ($inQuotes) {
                // Inside quotes, just add the character
                $currentStatement .= $char;
            } else {
                // Outside quotes - track parentheses and semicolons
                if ($char === '(') {
                    $parenthesesLevel++;
                    $currentStatement .= $char;
                } elseif ($char === ')') {
                    $parenthesesLevel--;
                    $currentStatement .= $char;
                } elseif ($char === ';') {
                    // Only split on semicolon if we're not inside parentheses and not in quotes
                    if ($parenthesesLevel === 0) {
                        $currentStatement .= $char;
                        $trimmed = trim($currentStatement);
                        if (!empty($trimmed)) {
                            $statements[] = $trimmed;
                            if ($this->option('debug')) {
                                $this->warn("DEBUG Split statement at position $i, length: " . strlen($trimmed));
                            }
                        }
                        $currentStatement = '';
                    } else {
                        $currentStatement .= $char;
                    }
                } else {
                    $currentStatement .= $char;
                }
            }
        }
        
        // Add any remaining statement
        $trimmed = trim($currentStatement);
        if (!empty($trimmed)) {
            $statements[] = $trimmed;
            if ($this->option('debug')) {
                $this->warn("DEBUG Added final statement, length: " . strlen($trimmed));
                
                // Check if final statement appears incomplete
                $openParens = substr_count($trimmed, '(');
                $closeParens = substr_count($trimmed, ')');
                if ($openParens > $closeParens) {
                    $this->warn("DEBUG *** FINAL STATEMENT APPEARS INCOMPLETE! ***");
                    $this->warn("DEBUG Final statement ends with: '" . substr($trimmed, -100) . "'");
                }
            }
        }
        
        if ($this->option('debug')) {
            $this->warn("DEBUG Enhanced splitting completed. Total statements: " . count($statements));
            if ($inQuotes) {
                $this->warn("DEBUG *** WARNING: Ended while still inside quotes! ***");
            }
            if ($parenthesesLevel !== 0) {
                $this->warn("DEBUG *** WARNING: Unbalanced parentheses at end! Level: $parenthesesLevel ***");
            }
        }
        
        return $statements;
    }

    /**
     * Validate that a SQL statement appears complete
     */
    protected function validateSqlStatement($sql)
    {
        $issues = [];
        
        // Check parentheses balance
        $openParens = substr_count($sql, '(');
        $closeParens = substr_count($sql, ')');
        if ($openParens !== $closeParens) {
            $issues[] = "Unbalanced parentheses (open: $openParens, close: $closeParens)";
        }
        
        // Check quotes balance
        $singleQuotes = substr_count($sql, "'");
        if ($singleQuotes % 2 !== 0) {
            $issues[] = "Unbalanced single quotes (count: $singleQuotes)";
        }
        
        // Check if INSERT statement ends properly
        if ($this->isInsertStatement($sql)) {
            $trimmed = rtrim($sql);
            if (!preg_match('/\)\s*;?\s*$/', $trimmed)) {
                $issues[] = "INSERT statement doesn't end with closing parenthesis";
            }
        }
        
        return $issues;
    }

    protected function isInsertStatement($sql)
    {
        return preg_match('/^\s*INSERT\s+INTO/i', $sql);
    }

    protected function cleanEscapedJson($value)
    {
        // Step 1: Unwrap quotes if present
        if (str_starts_with($value, "'") && str_ends_with($value, "'")) {
            $value = substr($value, 1, -1);
        }

        // Step 2: Fix SQL-style escaping
        $value = str_replace(['\\\\\\/', '\\\\', '\\"'], ['/', '\\', '"'], $value);

        return $value;
    }

    protected function parseInsertValues($sql)
    {
        // Remove newline mess
        $sql = preg_replace('/\s+/', ' ', $sql);

        $columnsStart = strpos($sql, '(');
        $columnsEnd = strpos($sql, ')', $columnsStart);
        $valuesStart = strpos($sql, '(', $columnsEnd);
        $valuesEnd = strrpos($sql, ')');

        $columnsStr = substr($sql, $columnsStart + 1, $columnsEnd - $columnsStart - 1);
        $valuesStr = substr($sql, $valuesStart + 1, $valuesEnd - $valuesStart - 1);

        $columns = array_map('trim', explode(',', str_replace('`', '', $columnsStr)));
        $values = $this->splitValues($valuesStr);

        return [$columns, $values];
    }

    protected function splitValues($valueString)
    {
        $values = [];
        $length = strlen($valueString);
        $buffer = '';
        $inQuotes = false;

        for ($i = 0; $i < $length; $i++) {
            $char = $valueString[$i];

            if ($char === "'" && ($i === 0 || $valueString[$i - 1] !== '\\')) {
                $inQuotes = !$inQuotes;
            }

            if ($char === ',' && !$inQuotes) {
                $values[] = trim($buffer);
                $buffer = '';
                continue;
            }

            $buffer .= $char;
        }

        if (strlen($buffer)) {
            $values[] = trim($buffer);
        }

        return $values;
    }


    protected function processSqlStatement($sql)
    {
        try {
            $jsonHeavyTables = ['cms_theme_data', 'settings', 'renatio_formbuilder_field_types', 'renatio_formbuilder_forms', 'renatio_formbuilder_fields'];
            $tableName = $this->extractTableName($sql);
    
            if (!$tableName) {
                return ['status' => 'error', 'message' => 'Could not extract table name'];
            }
    
            if (!Schema::hasTable($tableName)) {
                return ['status' => 'skipped', 'message' => "Table '{$tableName}' does not exist"];
            }
    
            if (in_array($tableName, $jsonHeavyTables)) {
                if ($this->option('debug')) {
                    $this->warn("=== DEBUG: Processing JSON-heavy table '{$tableName}' ===");
                    $this->warn("DEBUG raw SQL length: " . strlen($sql));
                    $this->warn("DEBUG raw SQL (first 200 chars): " . substr($sql, 0, 200));
                    $this->warn("DEBUG raw SQL (last 200 chars): " . substr($sql, -200));
                    
                    // Show line count and structure
                    $lines = explode("\n", $sql);
                    $this->warn("DEBUG SQL has " . count($lines) . " lines");
                    
                    // Show the structure more clearly
                    $normalizedSql = preg_replace('/\s+/', ' ', trim($sql));
                    $this->warn("DEBUG normalized SQL (first 300 chars): " . substr($normalizedSql, 0, 300));
                }
            
                // Try multiple regex patterns for better matching
                $patterns = [
                    // Original pattern
                    '/\((.*?)\)\s+VALUES\s*\((.*)\)/is',
                    // More flexible pattern that handles multiline better
                    '/INSERT\s+INTO\s+[`\w]+\s*\((.*?)\)\s+VALUES\s*\((.*)\)$/is',
                    // Even more flexible pattern
                    '/\((.*?)\)\s+VALUES\s*\((.*)\)(?:\s*;?\s*)$/is',
                    // Pattern that handles the specific case with potential line breaks
                    '/\(([^)]+)\)\s+VALUES\s*\((.*)\)(?:\s*;?\s*)$/is'
                ];
                
                $matched = false;
                $patternUsed = '';
                $matches = [];
                
                foreach ($patterns as $index => $pattern) {
                    if ($this->option('debug')) {
                        $this->warn("DEBUG trying pattern $index: $pattern");
                    }
                    
                    if (preg_match($pattern, $sql, $tempMatches)) {
                        $matches = $tempMatches;
                        $matched = true;
                        $patternUsed = "Pattern $index";
                        if ($this->option('debug')) {
                            $this->warn("DEBUG pattern $index MATCHED!");
                        }
                        break;
                    } else {
                        if ($this->option('debug')) {
                            $this->warn("DEBUG pattern $index failed");
                        }
                    }
                }
            
                if ($matched) {
                    $rawCols = $matches[1];
                    $rawVals = $matches[2];
            
                    if ($this->option('debug')) {
                        $this->warn("DEBUG SUCCESS: Used $patternUsed");
                        $this->warn("DEBUG rawCols length: " . strlen($rawCols));
                        $this->warn("DEBUG rawCols: " . $rawCols);
                        $this->warn("DEBUG rawVals length: " . strlen($rawVals));
                        $this->warn("DEBUG rawVals (first 500 chars): " . substr($rawVals, 0, 500));
                        $this->warn("DEBUG rawVals (last 500 chars): " . substr($rawVals, -500));
                    }
            
                    // Parse columns with better error handling
                    $columns = array_map('trim', explode(',', str_replace('`', '', $rawCols)));
                    $columns = array_filter($columns); // Remove empty elements
            
                    if ($this->option('debug')) {
                        $this->warn("DEBUG parsed columns count: " . count($columns));
                        $this->warn("DEBUG parsed columns: " . json_encode($columns));
                    }
            
                    // Enhanced value parsing with multiple strategies
                    $values = $this->parseValuesWithMultipleStrategies($rawVals);
            
                    if ($this->option('debug')) {
                        $this->warn("DEBUG parsed values count: " . count($values));
                        $this->warn("DEBUG first few values: " . json_encode(array_slice($values, 0, 3)));
                        if (count($values) > 3) {
                            $this->warn("DEBUG last few values: " . json_encode(array_slice($values, -2)));
                        }
                    }
            
                    if (count($columns) !== count($values)) {
                        if ($this->option('debug')) {
                            $this->warn("DEBUG Column count: " . count($columns));
                            $this->warn("DEBUG Value count: " . count($values));
                            $this->warn("DEBUG MISMATCH! Columns: " . json_encode($columns));
                            $this->warn("DEBUG MISMATCH! Values preview: " . json_encode(array_map(function($v) {
                                return is_string($v) ? substr($v, 0, 100) . (strlen($v) > 100 ? '...' : '') : $v;
                            }, $values)));
                            
                            // Try to identify the issue
                            $this->analyzeColumnValueMismatch($rawCols, $rawVals, $columns, $values);
                        }
                        return ['status' => 'error', 'message' => "Column/value count mismatch (columns: " . count($columns) . ", values: " . count($values) . ")"];
                    }
            
                    // Combine and insert
                    $cleaned = array_combine($columns, $values);
            
                    if ($this->option('debug')) {
                        $this->warn("DEBUG final cleaned row keys: " . json_encode(array_keys($cleaned)));
                        $this->warn("DEBUG final cleaned row (data preview): " . json_encode(array_map(function($v) {
                            return is_string($v) ? substr($v, 0, 200) . (strlen($v) > 200 ? '...' : '') : $v;
                        }, $cleaned)));
                    }
            
                    DB::table($tableName)->insert($cleaned);
            
                    return ['status' => 'processed', 'message' => "Safe insert for '{$tableName}' using bound values"];
                }
            
                if ($this->option('debug')) {
                    $this->warn("DEBUG ALL PATTERNS FAILED!");
                    $this->warn("DEBUG Let's examine the SQL structure more carefully:");
                    
                    // Look for key parts of the SQL
                    $insertPos = stripos($sql, 'INSERT');
                    $intoPos = stripos($sql, 'INTO');
                    $valuesPos = stripos($sql, 'VALUES');
                    
                    $this->warn("DEBUG INSERT position: $insertPos");
                    $this->warn("DEBUG INTO position: $intoPos");
                    $this->warn("DEBUG VALUES position: $valuesPos");
                    
                    if ($valuesPos !== false) {
                        $beforeValues = substr($sql, 0, $valuesPos);
                        $afterValues = substr($sql, $valuesPos);
                        $this->warn("DEBUG Before VALUES: " . substr($beforeValues, -100));
                        $this->warn("DEBUG After VALUES (first 200): " . substr($afterValues, 0, 200));
                        
                        // Count parentheses
                        $openParens = substr_count($afterValues, '(');
                        $closeParens = substr_count($afterValues, ')');
                        $this->warn("DEBUG Parentheses in VALUES section - Open: $openParens, Close: $closeParens");
                        
                        // Check if SQL appears incomplete
                        if ($openParens > $closeParens) {
                            $this->warn("DEBUG *** SQL APPEARS INCOMPLETE! ***");
                            $this->warn("DEBUG Missing " . ($openParens - $closeParens) . " closing parentheses");
                            $this->warn("DEBUG SQL ends with: '" . substr($sql, -50) . "'");
                            
                            // Check if SQL ends properly
                            $trimmedSql = rtrim($sql);
                            if (!preg_match('/\)\s*;?\s*$/', $trimmedSql)) {
                                $this->warn("DEBUG SQL does not end with closing parenthesis (expected for complete INSERT)");
                            }
                            
                            return ['status' => 'error', 'message' => "Incomplete SQL statement detected - missing closing parentheses. Check file reading logic."];
                        }
                    }
                }
            
                return ['status' => 'error', 'message' => "Failed to parse values for table '{$tableName}' - no regex pattern matched"];
            }
            
    
            // Try to detect duplicate on ID if present
            if (preg_match('/\bVALUES\s*\((\d+),/i', $sql, $idMatch)) {
                $id = $idMatch[1];
                if (Schema::hasColumn($tableName, 'id') && DB::table($tableName)->where('id', $id)->exists()) {
                    return ['status' => 'skipped', 'message' => "Row with ID {$id} already exists in '{$tableName}'"];
                }
            }
    
            // Run insert
            DB::statement($sql);
            return ['status' => 'processed', 'message' => "Executed SQL for '{$tableName}'"];
    
        } catch (Exception $e) {
            if ($this->option('debug')) {
                $this->error("DEBUG Exception details: " . $e->getMessage());
                $this->error("DEBUG Exception trace: " . $e->getTraceAsString());
            }
            return ['status' => 'error', 'message' => 'SQL execution failed: ' . $e->getMessage()];
        }
    }
    
    /**
     * Enhanced value parsing with multiple strategies
     */
    protected function parseValuesWithMultipleStrategies($rawVals)
    {
        if ($this->option('debug')) {
            $this->warn("DEBUG parseValuesWithMultipleStrategies called with length: " . strlen($rawVals));
        }
        
        // Strategy 1: Enhanced regex that handles unquoted values too
        $pattern = "/'((?:[^'\\\\]|\\\\.)*)'|NULL|(-?\d+(?:\.\d+)?)|([a-zA-Z_][a-zA-Z0-9_]*)/i";
        preg_match_all($pattern, $rawVals, $matches, PREG_SET_ORDER);
        
        $values = [];
        foreach ($matches as $match) {
            if (isset($match[1]) && $match[1] !== '') {
                // Quoted string
                $value = stripslashes($match[1]);
                $values[] = $value;
            } elseif (isset($match[2]) && $match[2] !== '') {
                // Number (int or float)
                $values[] = (strpos($match[2], '.') !== false) ? (float)$match[2] : (int)$match[2];
            } elseif (isset($match[3]) && strtoupper($match[3]) === 'NULL') {
                // NULL value
                $values[] = null;
            } elseif (isset($match[3]) && $match[3] !== '') {
                // Unquoted string/identifier
                $values[] = $match[3];
            } elseif (strtoupper($match[0]) === 'NULL') {
                // Direct NULL match
                $values[] = null;
            }
        }
        
        if ($this->option('debug')) {
            $this->warn("DEBUG Strategy 1 (enhanced regex) found " . count($values) . " values");
            $this->warn("DEBUG Strategy 1 values preview: " . json_encode(array_map(function($v) {
                return is_string($v) ? substr($v, 0, 100) . (strlen($v) > 100 ? '...' : '') : $v;
            }, $values)));
        }
        
        // If enhanced regex didn't work well, try manual parsing
        if (count($values) < 3) { // We expect at least 3 values (id, theme, data, etc.)
            if ($this->option('debug')) {
                $this->warn("DEBUG Enhanced regex found insufficient values (" . count($values) . "), trying Strategy 2 (manual parsing)");
            }
            $values = $this->manualValueParsing($rawVals);
        }
        
        return $values;
    }
    
    /**
     * Manual value parsing for complex cases
     */
    protected function manualValueParsing($rawVals)
    {
        $values = [];
        $length = strlen($rawVals);
        $i = 0;
        
        if ($this->option('debug')) {
            $this->warn("DEBUG Manual parsing starting, length: $length");
        }
        
        while ($i < $length) {
            // Skip whitespace and commas
            while ($i < $length && in_array($rawVals[$i], [' ', "\t", "\n", "\r", ','])) {
                $i++;
            }
            
            if ($i >= $length) break;
            
            // Check for NULL
            if (substr($rawVals, $i, 4) === 'NULL') {
                $values[] = null;
                $i += 4;
                if ($this->option('debug')) {
                    $this->warn("DEBUG Found NULL at position $i");
                }
                continue;
            }
            
            // Check for quoted string
            if ($rawVals[$i] === "'") {
                $i++; // Skip opening quote
                $value = '';
                $escaped = false;
                
                while ($i < $length) {
                    if ($escaped) {
                        $value .= $rawVals[$i];
                        $escaped = false;
                    } elseif ($rawVals[$i] === '\\') {
                        $escaped = true;
                    } elseif ($rawVals[$i] === "'") {
                        // End of string
                        break;
                    } else {
                        $value .= $rawVals[$i];
                    }
                    $i++;
                }
                
                if ($i < $length && $rawVals[$i] === "'") {
                    $i++; // Skip closing quote
                }
                
                $values[] = $value;
                
                if ($this->option('debug')) {
                    $valuePreview = strlen($value) > 100 ? substr($value, 0, 100) . '...' : $value;
                    $this->warn("DEBUG Found quoted string at position $i, length: " . strlen($value) . ", preview: $valuePreview");
                }
            } else {
                // Unquoted value (number, etc.)
                $value = '';
                while ($i < $length && !in_array($rawVals[$i], [',', ' ', "\t", "\n", "\r"])) {
                    $value .= $rawVals[$i];
                    $i++;
                }
                
                if ($value !== '') {
                    // Try to convert to appropriate type
                    if (is_numeric($value)) {
                        $values[] = (strpos($value, '.') !== false) ? (float)$value : (int)$value;
                    } else {
                        $values[] = $value;
                    }
                    
                    if ($this->option('debug')) {
                        $this->warn("DEBUG Found unquoted value: $value");
                    }
                }
            }
        }
        
        if ($this->option('debug')) {
            $this->warn("DEBUG Manual parsing completed, found " . count($values) . " values");
        }
        
        return $values;
    }
    
    /**
     * Analyze column/value mismatch to help debugging
     */
    protected function analyzeColumnValueMismatch($rawCols, $rawVals, $columns, $values)
    {
        $this->warn("=== MISMATCH ANALYSIS ===");
        
        // Look for potential issues in column parsing
        $this->warn("Raw columns string: '$rawCols'");
        $commaCount = substr_count($rawCols, ',');
        $this->warn("Comma count in columns: $commaCount (expected " . (count($columns) - 1) . ")");
        
        // Look for potential issues in value parsing
        $this->warn("Raw values string length: " . strlen($rawVals));
        $singleQuoteCount = substr_count($rawVals, "'");
        $this->warn("Single quote count in values: $singleQuoteCount");
        $nullCount = substr_count(strtoupper($rawVals), 'NULL');
        $this->warn("NULL count in values: $nullCount");
        
        // Check for balanced quotes
        $this->warn("Quote balance check: " . ($singleQuoteCount % 2 === 0 ? 'BALANCED' : 'UNBALANCED!'));
        
        // Try to identify where parsing might have gone wrong
        if (count($values) < count($columns)) {
            $this->warn("Too few values parsed - might be missing complex strings");
        } elseif (count($values) > count($columns)) {
            $this->warn("Too many values parsed - might be splitting on commas inside strings");
        }
    }

    protected function extractTableName($sql)
    {
        if (preg_match('/INSERT\s+INTO\s+`?([a-zA-Z0-9_]+)`?/i', $sql, $matches)) {
            return $matches[1];
        }
        return null;
    }

    protected function convertMySqlToPostgreSQL($sql)
    {
        $sql = str_replace('`', '', $sql);

        $replacements = [
            "'0'" => 'false',
            "'1'" => 'true',
            'NOW()' => 'CURRENT_TIMESTAMP',
        ];

        foreach ($replacements as $search => $replace) {
            $sql = str_ireplace($search, $replace, $sql);
        }

        return $sql;
    }
}
