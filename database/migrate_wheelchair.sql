-- Migration: Add wheelchair seat type and companion flag
-- Idempotent: safe to run multiple times. Preserves any pre-existing ENUM values
-- (e.g. legacy 'stage' that some installations carry) and skips work that is
-- already done.

DROP PROCEDURE IF EXISTS migrate_wheelchair;

DELIMITER $$
CREATE PROCEDURE migrate_wheelchair()
BEGIN
    -- 1. Add 'wheelchair' to seat_plan_elements.element_type ENUM if missing,
    --    keeping every value that's already there.
    DECLARE current_enum TEXT;
    DECLARE new_enum TEXT;

    SELECT COLUMN_TYPE INTO current_enum
    FROM information_schema.COLUMNS
    WHERE table_schema = DATABASE()
      AND table_name = 'seat_plan_elements'
      AND column_name = 'element_type';

    IF current_enum IS NOT NULL AND LOCATE('wheelchair', current_enum) = 0 THEN
        -- Insert 'wheelchair' before the closing parenthesis of the ENUM list
        SET new_enum = REPLACE(current_enum, ')', ",'wheelchair')");
        SET @sql = CONCAT('ALTER TABLE seat_plan_elements MODIFY element_type ', new_enum, ' NOT NULL');
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;

    -- 2. Add reservations.is_companion if missing
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE table_schema = DATABASE()
          AND table_name = 'reservations'
          AND column_name = 'is_companion'
    ) THEN
        ALTER TABLE reservations
            ADD COLUMN is_companion tinyint(1) NOT NULL DEFAULT 0 AFTER is_honor_card;
    END IF;

    -- 3. Add seat_plan_elements.consumes_number if missing (distance with number-skip)
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE table_schema = DATABASE()
          AND table_name = 'seat_plan_elements'
          AND column_name = 'consumes_number'
    ) THEN
        ALTER TABLE seat_plan_elements
            ADD COLUMN consumes_number tinyint(1) NOT NULL DEFAULT 0 AFTER category_id;
    END IF;
END$$
DELIMITER ;

CALL migrate_wheelchair();
DROP PROCEDURE migrate_wheelchair;
