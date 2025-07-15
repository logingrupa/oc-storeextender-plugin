# SQL Import Command

This console command allows you to import raw SQL INSERT statements into your database with built-in safety checks.

## Features

- **Table Existence Check**: Automatically verifies that target tables exist before attempting inserts
- **Duplicate Record Check**: Checks for existing records based on ID column to prevent duplicates
- **Dry Run Mode**: Preview what would be imported without actually executing the statements
- **Multiple Input Methods**: Support for both SQL files and direct command-line input
- **JSON Data Support**: Enhanced parser that properly handles complex JSON data with escape sequences
- **MySQL Compatibility**: Convert MySQL syntax (backticks, functions) to PostgreSQL compatible format
- **Prepared Statements**: Uses Laravel's query builder for safer database operations
- **Detailed Logging**: Comprehensive output showing processed, skipped, and error counts
- **PostgreSQL Compatibility**: Designed specifically for PostgreSQL databases

## Usage

### Basic Usage

```bash
# Import from SQL file
php artisan storeextender:sql-import /path/to/file.sql

# Import single SQL statement
php artisan storeextender:sql-import --sql="INSERT INTO table..."

# Dry run (preview mode)
php artisan storeextender:sql-import /path/to/file.sql --dry-run

# Import with MySQL compatibility (removes backticks, converts functions)
php artisan storeextender:sql-import /path/to/file.sql --mysql-compat

# Combine options
php artisan storeextender:sql-import /path/to/file.sql --dry-run --mysql-compat
```

## Examples

### Example 1: Import from the sample file

```bash
php artisan storeextender:sql-import plugins/logingrupa/storeextender/sample_import.sql
```

### Example 2: Import a single record

```bash
php artisan storeextender:sql-import --sql="INSERT INTO lovata_buddies_groups (id, name, code, price_type_id) VALUES (12, 'Test Group', 'test', 1)"
```

### Example 3: Preview what would be imported

```bash
php artisan storeextender:sql-import plugins/logingrupa/storeextender/sample_import.sql --dry-run
```

## Advanced Features

### JSON Data Handling

The enhanced parser can handle complex JSON data with:

- **Nested quotes and escape sequences**: Properly parses JSON strings with embedded quotes
- **Unicode escape sequences**: Handles \u sequences in JSON data
- **Nested JSON objects**: Supports complex nested structures
- **Large JSON payloads**: Efficiently processes large JSON strings

### MySQL to PostgreSQL Conversion

When using `--mysql-compat` flag, the command automatically:

- **Removes backticks**: Converts `table_name` to table_name
- **Converts functions**: NOW() → CURRENT_TIMESTAMP, CURDATE() → CURRENT_DATE
- **Boolean values**: Converts '0'/'1' to false/true

### Error Handling

The command provides detailed error messages for common issues:

- **Table not found**: Warns when trying to insert into non-existent tables
- **Duplicate records**: Skips records that already exist (based on ID)
- **SQL syntax errors**: Reports parsing and execution errors
- **File not found**: Clear error when SQL file doesn't exist
- **JSON parsing errors**: Detailed feedback on malformed JSON data

## Safety Features

### 1. Table Existence Check
Before executing any INSERT statement, the command verifies that the target table exists in the database. If the table doesn't exist, the statement is skipped with a warning message.

### 2. Duplicate Record Prevention
The command checks for existing records with the same ID (if an ID column is present) to prevent duplicate entries. If a record with the same ID already exists, the statement is skipped.

### 3. Error Handling
If any SQL statement fails to execute, the error is logged and the command continues with the next statement, ensuring that one failed statement doesn't stop the entire import process.

## Output Messages

- **✓ Processed**: Statement was successfully executed
- **⚠ Skipped**: Statement was skipped (table doesn't exist or duplicate record)
- **✗ Error**: Statement failed to execute due to an error

## SQL File Format

Your SQL file should contain INSERT statements separated by semicolons. Comments are supported using `--` syntax.

Example:
```sql
-- This is a comment
INSERT INTO table_name (column1, column2) VALUES ('value1', 'value2');
INSERT INTO table_name (column1, column2) VALUES ('value3', 'value4');
```

### Working with Complex Data

For complex data types like JSON:

```sql
INSERT INTO settings (id, data) VALUES 
(1, '{"key": "value", "nested": {"array": [1,2,3]}}');
```

The enhanced parser handles:

```sql
-- Complex JSON with escape sequences
INSERT INTO cms_theme_data (id, theme, data) VALUES 
(1, 'theme_name', '{"site_name":"My Site","description":"A site with \"quotes\" and \\backslashes","config":{"nested":true}}');

-- MySQL syntax with backticks (use --mysql-compat)
INSERT INTO `table_name` (`column1`, `column2`) VALUES ('value1', 'value2');
```

## PostgreSQL Compatibility

This command is designed to work with PostgreSQL databases in Laravel Cloud serverless environments. It uses Laravel's database abstraction layer, so it should work with any database supported by Laravel.

## Troubleshooting

### Command not found
Make sure the plugin is properly installed and the console command is registered in the Plugin.php file.

### Permission errors
Ensure your database user has INSERT permissions on the target tables.

### Parsing errors
The command uses basic SQL parsing. For complex INSERT statements with nested quotes or special characters, you may need to adjust the parsing logic in the `parseInsertStatement` method.

## Advanced Usage

For large imports or complex scenarios, consider:

1. **Batch Processing**: Split large SQL files into smaller chunks
2. **Transaction Wrapping**: Modify the command to wrap imports in database transactions
3. **Custom Duplicate Checks**: Extend the duplicate checking logic for tables without ID columns
4. **Logging**: Add file-based logging for audit trails

## Support

This command is part of the StoreExtender plugin for October CMS. For issues or feature requests, please contact the plugin maintainers.