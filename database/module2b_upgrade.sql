-- ============================================================================
--  BMIT3173 Integrative Programming  |  Assignment 202605
--  MODULE 2 - User Authentication & Profile Management  (Ivan)
--
--  Second upgrade: a player may now have MORE THAN ONE favourite sport, so the
--  single `User.favoriteSport` column becomes its own table.
--
--  Only needed to preserve an existing database. Running `setup.bat` rebuilds
--  everything from schema.sql, which already contains all of this.
-- ============================================================================

USE sports_platform;

-- A repeating group in one column would break 1NF, and "who likes futsal"
-- would become a LIKE over a delimited string.
CREATE TABLE `UserFavoriteSport` (
    `baseUserId` VARCHAR(36) NOT NULL,
    `sport`      VARCHAR(50) NOT NULL,
    PRIMARY KEY (`baseUserId`, `sport`),
    CONSTRAINT `fk_UserFavoriteSport_User`
        FOREIGN KEY (`baseUserId`) REFERENCES `User`(`baseUserId`) ON DELETE CASCADE,
    KEY `idx_UserFavoriteSport_sport` (`sport`)
) ENGINE=InnoDB;

-- Carry across whatever each player had already chosen, before the column goes.
INSERT INTO `UserFavoriteSport` (`baseUserId`, `sport`)
SELECT `baseUserId`, TRIM(`favoriteSport`)
  FROM `User`
 WHERE `favoriteSport` IS NOT NULL
   AND TRIM(`favoriteSport`) <> '';

ALTER TABLE `User` DROP COLUMN `favoriteSport`;
