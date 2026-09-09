-- ============================================================================
--  BMIT3173 Integrative Programming  |  Assignment 202605
--  MODULE 2 - User Authentication & Profile Management  (Ivan)
--
--  Upgrade script for a database that was already created from the ORIGINAL
--  schema.sql. It adds only what module 2 introduced. If you are creating the
--  database from scratch, run schema.sql instead - it already contains all of
--  this - and skip this file.
--
--  Safe to run once. Running it twice will error on the duplicate columns,
--  which is intended: it tells you the upgrade is already applied.
-- ============================================================================

USE sports_platform;

-- Threat 1 (brute force / credential stuffing): attempt counter and lockout.
ALTER TABLE `BaseUser`
    ADD COLUMN `failedLoginAttempts` TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER `accountStatus`,
    ADD COLUMN `lockedUntil`         DATETIME NULL AFTER `failedLoginAttempts`,
    ADD COLUMN `lastLoginAt`         DATETIME NULL AFTER `lockedUntil`,
    ADD COLUMN `passwordChangedAt`   DATETIME NULL AFTER `lastLoginAt`;

-- Password recovery. Stores a hash of the token, never the token.
CREATE TABLE `PasswordReset` (
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
CREATE TABLE `AuthEventLog` (
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

-- The seeded accounts were created before this column existed.
UPDATE `BaseUser` SET `passwordChangedAt` = `registerTime` WHERE `passwordChangedAt` IS NULL;
