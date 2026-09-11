-- ============================================================================
--  BMIT3173 Integrative Programming  |  Assignment 202605
--  MODULE 4 - Venue Booking & Payment  (Khor Zhi Hong)
--
--  Stripe was dropped in favour of a demo payment flow with a payment history,
--  which introduced ParticipantPayment. This catches an existing database up
--  without rebuilding it, so the data already entered for testing survives.
--
--  Refund is left alone. schema.sql no longer creates it and nothing reads it
--  any more, but dropping a table is not something a migration should do behind
--  your back. Remove it by hand once the team agrees it is dead.
--
--  Not needed after a fresh `setup.bat`, which builds all of this from
--  schema.sql already.
-- ============================================================================

USE sports_platform;

CREATE TABLE `ParticipantPayment` (
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
