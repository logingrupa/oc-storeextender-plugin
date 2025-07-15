# SQL Import Command - Usage Examples

This document provides practical examples of how to use the SQL import command for migrating data to your PostgreSQL database.

## Basic Usage

### 1. Import from SQL File

```bash
# Import all INSERT statements from a file
php artisan storeextender:sql-import plugins/logingrupa/storeextender/sample_import.sql
```

### 2. Import Single SQL Statement

```bash
# Import a single INSERT statement directly
php artisan storeextender:sql-import --sql="INSERT INTO lovata_buddies_groups (id, name, code, price_type_id) VALUES (12, 'New Group', 'new-group', 1)"
```

### 3. Dry Run (Preview Mode)

```bash
# Preview what would be imported without actually executing
php artisan storeextender:sql-import plugins/logingrupa/storeextender/sample_import.sql --dry-run
```

## Real-World Migration Scenarios

### Migrating User Data

```bash
# Import user accounts from backup
php artisan storeextender:sql-import user_backup.sql --dry-run

# If dry run looks good, run for real
php artisan storeextender:sql-import user_backup.sql
```

### Importing Complex JSON Data

```bash
# Import theme data with complex JSON (like cms_theme_data)
php artisan storeextender:sql-import theme_data.sql --mysql-compat --dry-run

# The command handles complex JSON with:
# - Nested quotes and escape sequences
# - Unicode characters (\u sequences)
# - Large JSON payloads
# - MySQL backticks (converted to PostgreSQL format)
```

### Scenario 1: Migrating User Groups

Create a file `user_groups_migration.sql`:

```sql
INSERT INTO `lovata_buddies_groups` (`id`, `name`, `code`, `description`, `created_at`, `updated_at`, `price_type_id`) VALUES 
(100, 'Premium Members', 'premium', 'Premium membership group', '2025-01-01 00:00:00', '2025-01-01 00:00:00', 1);

INSERT INTO `lovata_buddies_groups` (`id`, `name`, `code`, `description`, `created_at`, `updated_at`, `price_type_id`) VALUES 
(101, 'VIP Members', 'vip', 'VIP membership group', '2025-01-01 00:00:00', '2025-01-01 00:00:00', 2);
```

Then run:

```bash
php artisan storeextender:sql-import user_groups_migration.sql
```

### Scenario 2: Migrating Product Data

For products table:

```sql
INSERT INTO `lovata_shopaholic_products` (`id`, `name`, `slug`, `code`, `created_at`, `updated_at`) VALUES 
(1001, 'Premium Product', 'premium-product', 'PROD001', '2025-01-01 00:00:00', '2025-01-01 00:00:00');
```

### Scenario 3: Batch Migration with Error Handling

```bash
# First, preview the migration
php artisan storeextender:sql-import large_migration.sql --dry-run

# If everything looks good, run the actual migration
php artisan storeextender:sql-import large_migration.sql
```

## Safety Features in Action

### Table Existence Check

If you try to insert into a non-existent table:

```bash
php artisan storeextender:sql-import --sql="INSERT INTO non_existent_table (id, name) VALUES (1, 'test')"
```

Output:
```
⚠ Table 'non_existent_table' does not exist
```

### Duplicate Record Prevention

If you try to insert a record with an existing ID:

```bash
php artisan storeextender:sql-import --sql="INSERT INTO lovata_buddies_groups (id, name, code) VALUES (2, 'Duplicate', 'dup')"
```

Output:
```
⚠ Record already exists in table 'lovata_buddies_groups': ID 2 already exists
```

## Advanced Usage

### MySQL to PostgreSQL Migration

```bash
# Convert MySQL dump to PostgreSQL compatible format
php artisan storeextender:sql-import mysql_dump.sql --mysql-compat

# Example: MySQL syntax with backticks
# Original: INSERT INTO `cms_theme_data` (`id`, `theme`) VALUES (1, 'test');
# Converted: INSERT INTO cms_theme_data (id, theme) VALUES (1, 'test');
```

The `--mysql-compat` flag automatically:
- Removes backticks from table/column names
- Converts MySQL functions to PostgreSQL equivalents
- Handles boolean value conversions
- Processes complex JSON data with escape sequences

### Working with Large Files

For large SQL files, consider splitting them into smaller chunks:

```bash
# Split large file into smaller parts
split -l 1000 large_migration.sql migration_part_

# Import each part
php artisan storeextender:sql-import migration_part_aa
php artisan storeextender:sql-import migration_part_ab
# ... and so on
```

### PostgreSQL Specific Considerations

1. **Sequence Updates**: After importing data with specific IDs, you may need to update PostgreSQL sequences:

```sql
-- Update sequence after manual ID inserts
SELECT setval('lovata_buddies_groups_id_seq', (SELECT MAX(id) FROM lovata_buddies_groups));
```

2. **Data Types**: Ensure your INSERT statements use PostgreSQL-compatible data types and syntax.

### Error Recovery

If an import fails partway through:

1. Check the error message in the output
2. Fix the problematic SQL statement
3. Re-run the import (duplicate checking will skip already imported records)

## Best Practices

1. **Always use dry-run first**: Preview your imports before executing
2. **Backup your database**: Before running large migrations
3. **Test with small datasets**: Verify your SQL syntax with a few records first
4. **Monitor sequences**: Update PostgreSQL sequences after importing data with specific IDs
5. **Use transactions**: For critical imports, consider wrapping in database transactions

## Troubleshooting

### Common Issues

1. **File not found**
   ```
   Error: File not found: /path/to/file.sql
   ```
   Solution: Check file path and permissions

2. **Table doesn't exist**
   ```
   Warning: Table 'unknown_table' does not exist. Skipping statement.
   ```
   Solution: Create the table first or check table name

3. **Duplicate records**
   ```
   Skipping duplicate record with ID: 123
   ```
   Solution: This is normal behavior to prevent duplicates

4. **JSON syntax errors (resolved)**
   ```
   Old error: SQLSTATE[42000]: Syntax error... near '{"site_name"...
   ```
   Solution: Use the enhanced parser with `--mysql-compat` flag for complex JSON data

5. **MySQL backticks causing errors**
   ```
   Error with: INSERT INTO `table_name`...
   ```
   Solution: Use `--mysql-compat` flag to automatically remove backticks

6. **Command not found**: Ensure the plugin is properly installed and registered
7. **Permission errors**: Check database user permissions
8. **Syntax errors**: Verify SQL statement syntax
9. **Encoding issues**: Ensure your SQL files use UTF-8 encoding

### Getting Help

```bash
# Show command help
php artisan storeextender:sql-import --help

# List all available commands
php artisan list | grep storeextender
```

## Performance Tips

1. **Batch processing**: Group multiple INSERT statements in a single file
2. **Remove unnecessary checks**: For trusted data, consider modifying the duplicate checking logic
3. **Use appropriate indexes**: Ensure target tables have proper indexes for faster lookups
4. **Monitor memory usage**: For very large imports, monitor system resources

This command provides a safe and efficient way to migrate your raw SQL data to PostgreSQL while maintaining data integrity and providing detailed feedback on the import process.