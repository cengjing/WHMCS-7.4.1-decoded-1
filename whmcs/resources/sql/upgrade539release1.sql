ALTER TABLE tbladmins MODIFY COLUMN `password` VARCHAR(255) NOT NULL DEFAULT '';
ALTER TABLE tbladmins ADD COLUMN `passwordhash` VARCHAR(255) NOT NULL DEFAULT '' AFTER `password`;
