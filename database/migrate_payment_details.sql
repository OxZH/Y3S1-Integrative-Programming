-- ============================================================================
--  BMIT3173 Integrative Programming  |  Assignment 202605
--  MODULE 4 - payment method snapshots + saved checkout profiles
--  Author: Khor Zhi Hong
--
--  Adds account-detail columns to Payment and ParticipantPayment, and creates
--  SavedPaymentMethod for optional autofill. Safe to run on an existing local
--  database; a fresh setup.bat already has these from schema.sql.
--
--      C:\xampp\mysql\bin\mysql -u root < database\migrate_payment_details.sql
-- ============================================================================

USE sports_platform;

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

SELECT 'Payment detail columns and SavedPaymentMethod are in place.' AS result;
