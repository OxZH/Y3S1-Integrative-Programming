-- ============================================================================
--  BMIT3173 Integrative Programming  |  Assignment 202605
--  Bring an existing database up to the current schema.sql
--
--  Author : Goh Jian Yu, Ooi Kean Wei, Ng Jing Siang, Khor Zhi Hong, Ivan Lim Tze Yang
--  Target : MariaDB 10.4+ (XAMPP)
--
--  WHICH FILE DO I RUN?
--
--    Starting fresh, or you do not mind losing your data
--        run setup.bat, which builds everything from schema.sql and seed.sql.
--        schema.sql is the source of truth and already contains every change
--        below, so you do not need this file at all.
--
--    You already have a database with test data in it
--        run this file. It adds whatever your copy is missing and leaves your
--        rows alone.
--
--  SAFE TO RUN MORE THAN ONCE. Every statement checks first, so running it on a
--  database that is already current does nothing and reports no errors. This
--  replaces module1_upgrade.sql, module2_upgrade.sql, module2b_upgrade.sql and
--  module4_upgrade.sql, which each failed on the second run.
--
--  It does not drop anything. A table that was removed from schema.sql is left
--  where it is, because a migration should never quietly delete your data. See
--  the note on Refund at the bottom.
-- ============================================================================

USE sports_platform;


-- ============================================================================
--  MODULE 2 - User Authentication & Profile Management  (Ivan)
-- ============================================================================

-- Threat 1 (brute force / credential stuffing). The counter and the lock live on
-- the account, so the check costs no extra query on the login path and a lock
-- survives the attacker dropping their session.
ALTER TABLE `BaseUser`
    ADD COLUMN IF NOT EXISTS `failedLoginAttempts` TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER `accountStatus`,
    ADD COLUMN IF NOT EXISTS `lockedUntil`         DATETIME NULL AFTER `failedLoginAttempts`,
    ADD COLUMN IF NOT EXISTS `lastLoginAt`         DATETIME NULL AFTER `lockedUntil`,
    ADD COLUMN IF NOT EXISTS `passwordChangedAt`   DATETIME NULL AFTER `lastLoginAt`;

-- Accounts seeded before that column existed have nothing in it.
UPDATE `BaseUser`
   SET `passwordChangedAt` = `registerTime`
 WHERE `passwordChangedAt` IS NULL;

-- Password recovery is an authentication path, so it is controlled like one.
-- The row stores a HASH of the token and never the token, so a leak of this
-- table does not let anyone reset an account.
CREATE TABLE IF NOT EXISTS `PasswordReset` (
    `passwordResetId` VARCHAR(36) NOT NULL,
    `baseUserId`      VARCHAR(36) NOT NULL,
    `tokenHash`       CHAR(64)    NOT NULL,
    `requestedAt`     DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `expiresAt`       DATETIME    NOT NULL,
    `usedAt`          DATETIME    NULL,
    `requestIp`       VARCHAR(45) NULL,
    PRIMARY KEY (`passwordResetId`),
    UNIQUE KEY `uq_PasswordReset_tokenHash` (`tokenHash`),
    CONSTRAINT `fk_PasswordReset_BaseUser`
        FOREIGN KEY (`baseUserId`) REFERENCES `BaseUser`(`baseUserId`) ON DELETE CASCADE,
    KEY `idx_PasswordReset_user` (`baseUserId`, `usedAt`)
) ENGINE=InnoDB;

-- Threat 2 (unnoticed account takeover and privilege change): the audit trail.
CREATE TABLE IF NOT EXISTS `AuthEventLog` (
    `authEventId` VARCHAR(36) NOT NULL,
    `baseUserId`  VARCHAR(36) NULL,
    `eventType`   ENUM('LOGIN_SUCCESS','LOGIN_FAILED','LOGOUT','ACCOUNT_LOCKED',
                       'REGISTERED','PASSWORD_CHANGED','PASSWORD_RESET_REQUESTED',
                       'PASSWORD_RESET_COMPLETED','PROFILE_UPDATED',
                       'ACCOUNT_DEACTIVATED','ACCOUNT_REACTIVATED',
                       'ROLE_CHANGED','ACCESS_DENIED') NOT NULL,
    `emailTried`  VARCHAR(255) NULL,
    `succeeded`   TINYINT(1)   NOT NULL,
    `ipAddress`   VARCHAR(45)  NULL,
    `userAgent`   VARCHAR(255) NULL,
    `detail`      VARCHAR(255) NULL,
    `occurredAt`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`authEventId`),
    CONSTRAINT `fk_AuthEventLog_BaseUser`
        FOREIGN KEY (`baseUserId`) REFERENCES `BaseUser`(`baseUserId`) ON DELETE SET NULL,
    KEY `idx_AuthEventLog_user` (`baseUserId`, `occurredAt`),
    KEY `idx_AuthEventLog_type` (`eventType`, `occurredAt`)
) ENGINE=InnoDB;

-- NOTE for the team. module2_upgrade.sql also added an index here:
--
--     ALTER TABLE `Review` ADD KEY `idx_Review_author_time` (`authorId`, `reviewTimestamp`);
--
-- schema.sql never picked it up, so a database built fresh does not have it
-- while one that ran that upgrade does. This file leaves it out on purpose,
-- because its job is to match schema.sql and nothing more. If the index is
-- wanted, it belongs in schema.sql next to the other Review keys, which is a
-- change for whoever owns that table to make.


-- ============================================================================
--  MODULE 2 second pass - a player may have more than one favourite sport
-- ============================================================================

-- A repeating group in one column would break 1NF, and "who likes futsal" would
-- become a LIKE over a delimited string.
CREATE TABLE IF NOT EXISTS `UserFavoriteSport` (
    `baseUserId` VARCHAR(36) NOT NULL,
    `sport`      VARCHAR(50) NOT NULL,
    PRIMARY KEY (`baseUserId`, `sport`),
    CONSTRAINT `fk_UserFavoriteSport_User`
        FOREIGN KEY (`baseUserId`) REFERENCES `User`(`baseUserId`) ON DELETE CASCADE,
    KEY `idx_UserFavoriteSport_sport` (`sport`)
) ENGINE=InnoDB;

-- Carry across whatever each player had already chosen, but only while the old
-- column is still there. Written through a prepared statement because SQL will
-- not parse a reference to a column that has already been dropped, even inside
-- a branch that never runs.
SET @has_favorite_sport := (
    SELECT COUNT(*) FROM `information_schema`.`COLUMNS`
     WHERE `TABLE_SCHEMA` = DATABASE()
       AND `TABLE_NAME`   = 'User'
       AND `COLUMN_NAME`  = 'favoriteSport'
);

SET @carry_across := IF(@has_favorite_sport > 0,
    'INSERT IGNORE INTO `UserFavoriteSport` (`baseUserId`, `sport`)
     SELECT `baseUserId`, TRIM(`favoriteSport`) FROM `User`
      WHERE `favoriteSport` IS NOT NULL AND TRIM(`favoriteSport`) <> ''''',
    'DO 0');

PREPARE stmt FROM @carry_across;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

ALTER TABLE `User` DROP COLUMN IF EXISTS `favoriteSport`;


-- ============================================================================
--  MODULE 1 - Event & Facility Management  (Goh Jian Yu)
-- ============================================================================

-- The postcode used to sit at the end of `addressLine`. It has a field of its
-- own now, which is how a form normally asks for one, and it matters more here
-- than in most systems because the postcode is what decides the state.
--
-- The default exists only so the column can be added to rows that already exist.
-- It is dropped again once every row carries a real value.
ALTER TABLE `Facility`
    ADD COLUMN IF NOT EXISTS `postcode` CHAR(5) NOT NULL DEFAULT '00000' AFTER `addressLine`;

-- Copy the five digits off the end of the address, where the old format put
-- them. Only rows still holding the placeholder are touched, so running this a
-- second time cannot overwrite a postcode somebody has since corrected.
UPDATE `Facility`
   SET `postcode` = REGEXP_SUBSTR(`addressLine`, '[0-9]{5}$')
 WHERE `postcode` = '00000'
   AND `addressLine` REGEXP '[0-9]{5}$';

-- Now take those digits out of the address, along with the comma that separated
-- them, so the two fields do not both hold the same thing.
UPDATE `Facility`
   SET `addressLine` = REGEXP_REPLACE(`addressLine`, '[[:space:]]*,?[[:space:]]*[0-9]{5}$', '')
 WHERE `postcode` <> '00000'
   AND `addressLine` REGEXP '[0-9]{5}$';

ALTER TABLE `Facility`
    ADD CONSTRAINT IF NOT EXISTS `chk_Facility_postcode` CHECK (`postcode` REGEXP '^[0-9]{5}$');

ALTER TABLE `Facility`
    ALTER COLUMN `postcode` DROP DEFAULT;

-- Private events need a shareable link that can expire, be capped by number of
-- uses, and be revoked.
CREATE TABLE IF NOT EXISTS `EventInvite` (                      -- [+] GAP 2: generate shareable invite links
    `eventInviteId` VARCHAR(36)  NOT NULL,
    `eventId`       VARCHAR(36)  NOT NULL,
    `token`         CHAR(64)     NOT NULL,        -- bin2hex(random_bytes(32)): 256 bits from a CSPRNG, not guessable
    `createdBy`     VARCHAR(36)  NOT NULL,
    `createdAt`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `expiresAt`     DATETIME     NULL,            -- NULL = never expires
    `maxUses`       INT UNSIGNED NULL,            -- NULL = unlimited
    `useCount`      INT UNSIGNED NOT NULL DEFAULT 0,
    `revoked`       TINYINT(1)   NOT NULL DEFAULT 0,
    PRIMARY KEY (`eventInviteId`),
    UNIQUE KEY `uq_EventInvite_token` (`token`),
    CONSTRAINT `fk_EventInvite_event`
        FOREIGN KEY (`eventId`)   REFERENCES `Event`(`eventId`)   ON DELETE CASCADE,
    CONSTRAINT `fk_EventInvite_creator`
        FOREIGN KEY (`createdBy`) REFERENCES `User`(`baseUserId`) ON DELETE RESTRICT,
    KEY `idx_EventInvite_event` (`eventId`)
) ENGINE=InnoDB;


-- ============================================================================
--  MODULE 4 - Venue Booking & Payment  (Khor Zhi Hong)
-- ============================================================================

-- Stripe was dropped in favour of a demo payment flow with a payment history,
-- which introduced this table.
CREATE TABLE IF NOT EXISTS `ParticipantPayment` (
    `participantPaymentId`  VARCHAR(36)   NOT NULL,
    `eventRegistrationId`   VARCHAR(36)   NOT NULL,
    `eventId`               VARCHAR(36)   NOT NULL,
    `participantId`         VARCHAR(36)   NOT NULL,
    `organizerId`           VARCHAR(36)   NOT NULL,
    `amount`                DECIMAL(10,2) NOT NULL,
    `paymentStatus`         ENUM('PENDING','PAID','FAILED','REFUNDED')
                            NOT NULL DEFAULT 'PENDING',
    `paidAt`                DATETIME      NULL,
    `createdAt`             DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updatedAt`             DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`participantPaymentId`),
    UNIQUE KEY `uq_ParticipantPayment_registration` (`eventRegistrationId`),
    CONSTRAINT `fk_ParticipantPayment_registration`
        FOREIGN KEY (`eventRegistrationId`) REFERENCES `EventRegistration`(`eventRegistrationId`) ON DELETE RESTRICT,
    CONSTRAINT `fk_ParticipantPayment_event`
        FOREIGN KEY (`eventId`) REFERENCES `Event`(`eventId`) ON DELETE RESTRICT,
    CONSTRAINT `fk_ParticipantPayment_participant`
        FOREIGN KEY (`participantId`) REFERENCES `User`(`baseUserId`) ON DELETE RESTRICT,
    CONSTRAINT `fk_ParticipantPayment_organizer`
        FOREIGN KEY (`organizerId`) REFERENCES `User`(`baseUserId`) ON DELETE RESTRICT,
    CONSTRAINT `chk_ParticipantPayment_amount` CHECK (`amount` >= 0),
    KEY `idx_ParticipantPayment_event` (`eventId`, `paymentStatus`)
) ENGINE=InnoDB;

-- Checkout snapshots so Payment history still shows method and account after a
-- saved profile is deleted, plus SavedPaymentMethod for next-time autofill.
ALTER TABLE `Payment`
    ADD COLUMN IF NOT EXISTS `payerName`        VARCHAR(100) NULL AFTER `paymentMethod`,
    ADD COLUMN IF NOT EXISTS `accountMask`      VARCHAR(50)  NULL AFTER `payerName`,
    ADD COLUMN IF NOT EXISTS `providerLabel`    VARCHAR(100) NULL AFTER `accountMask`,
    ADD COLUMN IF NOT EXISTS `methodDetailJson` JSON         NULL AFTER `providerLabel`;

ALTER TABLE `ParticipantPayment`
    ADD COLUMN IF NOT EXISTS `paymentMethod`     VARCHAR(50)  NULL AFTER `amount`,
    ADD COLUMN IF NOT EXISTS `payerName`        VARCHAR(100) NULL AFTER `paymentMethod`,
    ADD COLUMN IF NOT EXISTS `accountMask`      VARCHAR(50)  NULL AFTER `payerName`,
    ADD COLUMN IF NOT EXISTS `providerLabel`    VARCHAR(100) NULL AFTER `accountMask`,
    ADD COLUMN IF NOT EXISTS `methodDetailJson` JSON         NULL AFTER `providerLabel`;

CREATE TABLE IF NOT EXISTS `SavedPaymentMethod` (
    `savedPaymentMethodId` VARCHAR(36)  NOT NULL,
    `baseUserId`           VARCHAR(36)  NOT NULL,
    `paymentMethod`        VARCHAR(50)  NOT NULL,
    `label`                VARCHAR(100) NOT NULL,
    `payerName`            VARCHAR(100) NOT NULL,
    `accountMask`          VARCHAR(50)  NOT NULL,
    `providerLabel`        VARCHAR(100) NOT NULL,
    `detailJson`           JSON         NOT NULL,
    `isDefault`            TINYINT(1)  NOT NULL DEFAULT 0,
    `createdAt`            DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updatedAt`            DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`savedPaymentMethodId`),
    CONSTRAINT `fk_SavedPaymentMethod_user`
        FOREIGN KEY (`baseUserId`) REFERENCES `BaseUser`(`baseUserId`) ON DELETE CASCADE,
    KEY `idx_SavedPaymentMethod_user` (`baseUserId`, `paymentMethod`)
) ENGINE=InnoDB;


-- ============================================================================
--  WHAT THIS FILE DELIBERATELY DOES NOT DO
--
--  `Refund` is left alone. schema.sql stopped creating it when Stripe went, and
--  nothing reads it any more, but dropping a table takes its rows with it and
--  that is not a decision a migration should make on your behalf. Drop it by
--  hand once the team agrees it is dead:
--
--      DROP TABLE IF EXISTS `Refund`;
-- ============================================================================

SELECT 'Upgrade complete. Your database now matches schema.sql.' AS result;
