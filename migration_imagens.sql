SET @gallery_column_exists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'eventos'
      AND COLUMN_NAME = 'galeria_fotos'
);

SET @gallery_migration = IF(
    @gallery_column_exists = 0,
    'ALTER TABLE eventos ADD COLUMN galeria_fotos TEXT DEFAULT NULL',
    'SELECT 1'
);

PREPARE gallery_migration_statement FROM @gallery_migration;
EXECUTE gallery_migration_statement;
DEALLOCATE PREPARE gallery_migration_statement;

SET @latitude_column_exists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'eventos'
      AND COLUMN_NAME = 'latitude'
);

SET @latitude_migration = IF(
    @latitude_column_exists = 0,
    'ALTER TABLE eventos ADD COLUMN latitude DECIMAL(10, 8) DEFAULT -10.4431',
    'SELECT 1'
);

PREPARE latitude_migration_statement FROM @latitude_migration;
EXECUTE latitude_migration_statement;
DEALLOCATE PREPARE latitude_migration_statement;

SET @longitude_column_exists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'eventos'
      AND COLUMN_NAME = 'longitude'
);

SET @longitude_migration = IF(
    @longitude_column_exists = 0,
    'ALTER TABLE eventos ADD COLUMN longitude DECIMAL(11, 8) DEFAULT -45.1611',
    'SELECT 1'
);

PREPARE longitude_migration_statement FROM @longitude_migration;
EXECUTE longitude_migration_statement;
DEALLOCATE PREPARE longitude_migration_statement;
