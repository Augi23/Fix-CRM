-- Expand inventory part_name for long catalog item names
ALTER TABLE `inventory`
    MODIFY COLUMN `part_name` VARCHAR(255) NOT NULL;
