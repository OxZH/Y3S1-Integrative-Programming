-- ============================================================================
--  BMIT3173 Integrative Programming  |  Assignment 202605
--  Database schema derived from the analysis class diagram
--
--  Author  : Goh Jian Yu, Ooi Kean Wei, Ng Jing Siang, Khor Zhi Hong, Ivan Lim Tze Yang
--  Target  : MariaDB 10.4+ / MySQL 8.0.16+ (XAMPP)
--  Engine  : InnoDB  -- foreign keys + transactions are required by the
--                       event-creation -> booking -> payment flow
--  Charset : utf8mb4 -- reviews / venue names may hold non-Latin text
--
--  COLUMN COMMENT LEGEND
--    [D]  attribute taken directly from the analysis class diagram
--    [+]  addition NOT on the diagram -- reason and owning module are stated
--
--  INHERITANCE MAPPING
--    BaseUser -> Admin / User / FacilityOwner  and  BaseRating -> UserRating /
--    FacilityRating are mapped with CLASS TABLE INHERITANCE (a.k.a. JOINED):
--    the parent table holds the shared attributes, each child table holds only
--    its own attributes and reuses the parent primary key as its own PK + FK.
--    Chosen over Single Table Inheritance because it avoids a wide table full
--    of NULLs, and over Concrete Table Inheritance because it keeps email and
--    username uniqueness enforceable in ONE place across all three roles.
--
--  ASSOCIATIONS THAT LOOK "MISSING"
--    Event.booking, Booking.payment, Payment.refund, User.reviews,
--    User.ratings, User.participationHistory and FacilityOwner.facilities are
--    navigations, not columns. For each 1..0..1 pair the FK is placed on the
--    OPTIONAL side (Booking.eventId, Payment.bookingId, Refund.paymentId) so
--    there are no nullable FKs and no circular table dependency. The PHP
--    entity classes still expose the properties exactly as drawn.
-- ============================================================================

DROP DATABASE IF EXISTS sports_platform;   -- TODO rename once the system has a title
CREATE DATABASE sports_platform
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;
USE sports_platform;


-- ============================================================================
--  MODULE 2 - User Authentication & Profile Management  (Ivan)
-- ============================================================================

CREATE TABLE `BaseUser` (
    `baseUserId`    VARCHAR(36)  NOT NULL,                           -- [D] UUID v4, opaque
    `email`         VARCHAR(255) NOT NULL,                           -- [D]
    `password`      VARCHAR(255) NOT NULL,                           -- [D] password_hash() output, never plaintext
    `username`      VARCHAR(50)  NOT NULL,                           -- [D]
    `contactNumber` VARCHAR(20)  NOT NULL,                           -- [D]
    `registerTime`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP, -- [D]
    `userType`      ENUM('USER','FACILITY_OWNER','ADMIN') NOT NULL,  -- [+] CTI discriminator: tells the Data Mapper which subclass to build
    `accountStatus` ENUM('ACTIVE','DEACTIVATED','SUSPENDED')
                    NOT NULL DEFAULT 'ACTIVE',                       -- [+] Ivan: module 2 lists account deactivation
    PRIMARY KEY (`baseUserId`),
    UNIQUE KEY `uq_BaseUser_email`    (`email`),
    UNIQUE KEY `uq_BaseUser_username` (`username`),
    KEY `idx_BaseUser_type` (`userType`)
) ENGINE=InnoDB;

CREATE TABLE `User` (
    `baseUserId`    VARCHAR(36)   NOT NULL,   -- PK + FK (Class Table Inheritance)
    `favoriteSport` VARCHAR(50)   NULL,       -- [D]
    `location`      VARCHAR(255)  NULL,       -- [D] human-readable home area
    `profilePicURL` VARCHAR(500)  NULL,       -- [D]
    `birthDate`     DATE          NULL,       -- [D]
    `latitude`      DECIMAL(10,7) NULL,       -- [+] js: recommendations based on how close events are to the user
    `longitude`     DECIMAL(10,7) NULL,       -- [+] js: same
    PRIMARY KEY (`baseUserId`),
    CONSTRAINT `fk_User_BaseUser`
        FOREIGN KEY (`baseUserId`) REFERENCES `BaseUser`(`baseUserId`) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE `FacilityOwner` (
    `baseUserId`     VARCHAR(36)  NOT NULL,
    `bankName`       VARCHAR(100) NOT NULL,   -- [D]
    `bankAccountNum` VARCHAR(50)  NOT NULL,   -- [D] store encrypted or tokenised, not raw
    `businessRegNum` VARCHAR(50)  NOT NULL,   -- [D]
    PRIMARY KEY (`baseUserId`),
    UNIQUE KEY `uq_FacilityOwner_businessRegNum` (`businessRegNum`),
    CONSTRAINT `fk_FacilityOwner_BaseUser`
        FOREIGN KEY (`baseUserId`) REFERENCES `BaseUser`(`baseUserId`) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE `Admin` (
    `baseUserId` VARCHAR(36) NOT NULL,
    `adminId`    VARCHAR(36) NOT NULL,        -- [D] staff number, separate from baseUserId
    PRIMARY KEY (`baseUserId`),
    UNIQUE KEY `uq_Admin_adminId` (`adminId`),
    CONSTRAINT `fk_Admin_BaseUser`
        FOREIGN KEY (`baseUserId`) REFERENCES `BaseUser`(`baseUserId`) ON DELETE CASCADE
) ENGINE=InnoDB;


-- ============================================================================
--  MODULE 1 - Event & Facility Management  (ME)
-- ============================================================================

CREATE TABLE `Facility` (
    `facilityId`          VARCHAR(36)   NOT NULL,   -- [D]
    `ownerId`             VARCHAR(36)   NOT NULL,   -- [+] GAP 3: diagram only draws FacilityOwner -> List<Facility>; the reverse reference is needed by facility search
    `name`                VARCHAR(150)  NOT NULL,   -- [D]
    `imageUrl`            VARCHAR(500)  NULL,       -- [D]
    `addressLine`         VARCHAR(255)  NOT NULL,   -- [D]
    `city`                VARCHAR(100)  NOT NULL,   -- [D]
    `state`               VARCHAR(100)  NOT NULL,   -- [D]
    `type`                VARCHAR(50)   NOT NULL,   -- [D] e.g. Badminton Hall, Futsal Court
    `bookingFee`          DECIMAL(10,2) NOT NULL,   -- [D] diagram says double; money MUST be DECIMAL (binary floats cannot hold 0.10 exactly)
    `operationalHrsStart` TIME          NOT NULL,   -- [D]
    `operationalHrsEnd`   TIME          NOT NULL,   -- [D]
    `latitude`            DECIMAL(10,7) NOT NULL,   -- [+] GAP 1: required by the OpenStreetMap module and by my distance sorting
    `longitude`           DECIMAL(10,7) NOT NULL,   -- [+] GAP 1
    `status`              ENUM('PENDING','ACTIVE','SUSPENDED')
                          NOT NULL DEFAULT 'PENDING', -- [+] GAP 4: onboarding approval, and delisting without deleting history
    `createdAt`           DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`facilityId`),
    CONSTRAINT `fk_Facility_owner`
        FOREIGN KEY (`ownerId`) REFERENCES `FacilityOwner`(`baseUserId`) ON DELETE RESTRICT,
    CONSTRAINT `chk_Facility_fee`   CHECK (`bookingFee` >= 0),
    CONSTRAINT `chk_Facility_hours` CHECK (`operationalHrsEnd` > `operationalHrsStart`),
    CONSTRAINT `chk_Facility_lat`   CHECK (`latitude`  BETWEEN  -90 AND  90),
    CONSTRAINT `chk_Facility_lng`   CHECK (`longitude` BETWEEN -180 AND 180),
    KEY `idx_Facility_owner`  (`ownerId`),
    KEY `idx_Facility_geo`    (`latitude`, `longitude`),            -- bounding-box prefilter before Haversine
    KEY `idx_Facility_search` (`status`, `city`, `type`, `bookingFee`)
) ENGINE=InnoDB;
-- NOTE: chk_Facility_hours rejects venues open past midnight (e.g. 18:00-02:00).
--       Acceptable for now. If overnight venues are needed later, drop this CHECK
--       and treat operationalHrsEnd <= operationalHrsStart as "crosses midnight".

CREATE TABLE `Event` (
    `eventId`            VARCHAR(36)  NOT NULL,   -- [D]
    `hostId`             VARCHAR(36)  NOT NULL,   -- [D] host: User
    `facilityId`         VARCHAR(36)  NOT NULL,   -- [D] location: Facility (1..1)
    `name`               VARCHAR(150) NOT NULL,   -- [D]
    `sport`              VARCHAR(50)  NOT NULL,   -- [D]
    `eventDate`          DATE         NOT NULL,   -- [D] diagram says DateTime, but startTime/endTime already carry the clock; splitting keeps the availability query indexable
    `startTime`          TIME         NOT NULL,   -- [D]
    `endTime`            TIME         NOT NULL,   -- [D]
    `minParticipants`    INT UNSIGNED NOT NULL,   -- [D]
    `maxParticipants`    INT UNSIGNED NOT NULL,   -- [D]
    `status`             ENUM('DRAFT','PENDING_PAYMENT','PUBLISHED','FULL',
                              'ONGOING','COMPLETED','CANCELLED')
                         NOT NULL DEFAULT 'DRAFT',                              -- [D] vocabulary made explicit
    `skillLevel`         ENUM('BEGINNER','INTERMEDIATE','ADVANCED','ANY') NOT NULL, -- [D]
    `fitnessRequirement` ENUM('LOW','MODERATE','HIGH')  NOT NULL,               -- [D]
    `competitiveness`    ENUM('CASUAL','COMPETITIVE')   NOT NULL,               -- [D]
    `visibility`         ENUM('PUBLIC','FRIENDS_ONLY')
                         NOT NULL DEFAULT 'PUBLIC',                             -- [+] GAP 2: in my module spec, absent from the diagram
    `feePerParticipant`  DECIMAL(10,2) NOT NULL DEFAULT 0.00,                   -- [+] GAP 5: zh needs it to split venue rental and equipment cost
    `createdAt`          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`eventId`),
    CONSTRAINT `fk_Event_host`
        FOREIGN KEY (`hostId`)     REFERENCES `User`(`baseUserId`)     ON DELETE RESTRICT,
    CONSTRAINT `fk_Event_facility`
        FOREIGN KEY (`facilityId`) REFERENCES `Facility`(`facilityId`) ON DELETE RESTRICT,
    CONSTRAINT `chk_Event_participants`
        CHECK (`minParticipants` > 0 AND `maxParticipants` >= `minParticipants`),
    CONSTRAINT `chk_Event_time` CHECK (`endTime` > `startTime`),
    CONSTRAINT `chk_Event_fee`  CHECK (`feePerParticipant` >= 0),
    KEY `idx_Event_discovery` (`status`, `visibility`, `sport`, `eventDate`),   -- the discovery filter query
    KEY `idx_Event_slot`      (`facilityId`, `eventDate`, `startTime`),         -- my booking-clash detection
    KEY `idx_Event_host`      (`hostId`)
) ENGINE=InnoDB;
-- Event.booking is a navigation only: the FK lives on Booking.eventId (UNIQUE),
-- which breaks the Event <-> Booking circular dependency at table level.

CREATE TABLE `EventInvite` (                      -- [+] GAP 2: generate shareable invite links
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
--  MODULE 5 - Discovery & Event Matchmaking  (js)
-- ============================================================================

CREATE TABLE `EventRegistration` (
    `eventRegistrationId` VARCHAR(36) NOT NULL,   -- [D]
    `userId`              VARCHAR(36) NOT NULL,   -- [D] User --submit--> EventRegistration
    `eventId`             VARCHAR(36) NOT NULL,   -- [D] EventRegistration --make--> Event
    `status`              ENUM('PENDING','CONFIRMED','CANCELLED',
                               'ATTENDED','NO_SHOW')
                          NOT NULL DEFAULT 'PENDING', -- [D] ATTENDED / NO_SHOW feed the attendance rating
    `registerTime`        DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP, -- [D]
    `eventInviteId`       VARCHAR(36) NULL,       -- [+] which invite link this join came through (NULL = joined publicly)
    PRIMARY KEY (`eventRegistrationId`),
    UNIQUE KEY `uq_EventRegistration_user_event` (`userId`, `eventId`),   -- one registration per user per event
    CONSTRAINT `fk_EventRegistration_user`
        FOREIGN KEY (`userId`)        REFERENCES `User`(`baseUserId`)           ON DELETE RESTRICT,
    CONSTRAINT `fk_EventRegistration_event`
        FOREIGN KEY (`eventId`)       REFERENCES `Event`(`eventId`)             ON DELETE CASCADE,
    CONSTRAINT `fk_EventRegistration_invite`
        FOREIGN KEY (`eventInviteId`) REFERENCES `EventInvite`(`eventInviteId`) ON DELETE SET NULL,
    KEY `idx_EventRegistration_event` (`eventId`, `status`)               -- current headcount
) ENGINE=InnoDB;


-- ============================================================================
--  MODULE 4 - Venue Booking & Payment  (zh)
--  Financial rows use ON DELETE RESTRICT: they are audit records. Events and
--  bookings are CANCELLED, never DELETEd.
-- ============================================================================

CREATE TABLE `Booking` (
    `bookingId`     VARCHAR(36)   NOT NULL,   -- [D]
    `eventId`       VARCHAR(36)   NOT NULL,   -- [D] Event 1 --has-- 1 Booking
    `madeById`      VARCHAR(36)   NOT NULL,   -- [D] madeBy: User (the organiser)
    `bookingStatus` ENUM('AVAILABLE','PENDING','CONFIRMED','CANCELLED')
                    NOT NULL DEFAULT 'PENDING',  -- [D]
    `bookingAmount` DECIMAL(10,2) NOT NULL,   -- [+] price snapshot: if the owner edits Facility.bookingFee later, past bookings must not change
    `createdAt`     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updatedAt`     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`bookingId`),
    UNIQUE KEY `uq_Booking_event` (`eventId`),   -- enforces the 1:1 with Event
    CONSTRAINT `fk_Booking_event`
        FOREIGN KEY (`eventId`)  REFERENCES `Event`(`eventId`)   ON DELETE RESTRICT,
    CONSTRAINT `fk_Booking_user`
        FOREIGN KEY (`madeById`) REFERENCES `User`(`baseUserId`) ON DELETE RESTRICT,
    CONSTRAINT `chk_Booking_amount` CHECK (`bookingAmount` >= 0),
    KEY `idx_Booking_status` (`bookingStatus`)
) ENGINE=InnoDB;

CREATE TABLE `Payment` (
    `paymentId`             VARCHAR(36)   NOT NULL,   -- [D]
    `bookingId`             VARCHAR(36)   NOT NULL,   -- [D] Booking 1 --has-- 0..1 Payment; FK held on the optional side
    `amount`                DECIMAL(10,2) NOT NULL,   -- [D] diagram says double -> DECIMAL
    `paymentDateTime`       DATETIME      NOT NULL,   -- [D]
    `paymentMethod`         VARCHAR(50)   NOT NULL,   -- [D] e.g. card, fpx
    `paymentStatus`         ENUM('PENDING','PAID','FAILED',
                                 'REFUNDED','PARTIALLY_REFUNDED')
                            NOT NULL DEFAULT 'PENDING',  -- [+] zh: the refund routine branches on this
    `stripePaymentIntentId` VARCHAR(255)  NULL,          -- [+] zh: Stripe reconciliation handle
    PRIMARY KEY (`paymentId`),
    UNIQUE KEY `uq_Payment_booking` (`bookingId`),
    UNIQUE KEY `uq_Payment_stripe`  (`stripePaymentIntentId`),  -- idempotency: a replayed webhook cannot double-insert
    CONSTRAINT `fk_Payment_booking`
        FOREIGN KEY (`bookingId`) REFERENCES `Booking`(`bookingId`) ON DELETE RESTRICT,
    CONSTRAINT `chk_Payment_amount` CHECK (`amount` >= 0)
) ENGINE=InnoDB;

CREATE TABLE `ParticipantPayment` (
    `participantPaymentId`  VARCHAR(36)   NOT NULL,
    `eventRegistrationId`   VARCHAR(36)   NOT NULL,
    `eventId`               VARCHAR(36)   NOT NULL,
    `participantId`         VARCHAR(36)   NOT NULL,
    `organizerId`           VARCHAR(36)   NOT NULL,
    `amount`                DECIMAL(10,2) NOT NULL,
    `paymentStatus`         ENUM('PENDING','PAID','FAILED','REFUNDED','PARTIALLY_REFUNDED')
                            NOT NULL DEFAULT 'PENDING',
    `stripePaymentIntentId` VARCHAR(255)  NULL,
    `paidAt`                DATETIME      NULL,
    `createdAt`             DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updatedAt`             DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`participantPaymentId`),
    UNIQUE KEY `uq_ParticipantPayment_registration` (`eventRegistrationId`),
    UNIQUE KEY `uq_ParticipantPayment_stripe` (`stripePaymentIntentId`),
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

CREATE TABLE `Refund` (
    `refundId`       VARCHAR(36)   NOT NULL,   -- [D]
    `paymentId`      VARCHAR(36)   NOT NULL,   -- [D] Payment 1 --has been-- 0..1 Refund
    `datetime`       DATETIME      NOT NULL,   -- [D]
    `amount`         DECIMAL(10,2) NOT NULL,   -- [+] the PENDING and PAID refund paths produce different amounts
    `reason`         VARCHAR(255)  NULL,       -- [+]
    `stripeRefundId` VARCHAR(255)  NULL,       -- [+]
    PRIMARY KEY (`refundId`),
    UNIQUE KEY `uq_Refund_payment` (`paymentId`),
    UNIQUE KEY `uq_Refund_stripe`  (`stripeRefundId`),
    CONSTRAINT `fk_Refund_payment`
        FOREIGN KEY (`paymentId`) REFERENCES `Payment`(`paymentId`) ON DELETE RESTRICT,
    CONSTRAINT `chk_Refund_amount` CHECK (`amount` >= 0)
) ENGINE=InnoDB;

CREATE TABLE `ParticipantRefund` (
    `participantRefundId`  VARCHAR(36)   NOT NULL,
    `participantPaymentId` VARCHAR(36)   NOT NULL,
    `datetime`             DATETIME      NOT NULL,
    `amount`               DECIMAL(10,2) NOT NULL,
    `reason`               VARCHAR(255)  NULL,
    `stripeRefundId`       VARCHAR(255)  NULL,
    PRIMARY KEY (`participantRefundId`),
    UNIQUE KEY `uq_ParticipantRefund_payment` (`participantPaymentId`),
    UNIQUE KEY `uq_ParticipantRefund_stripe` (`stripeRefundId`),
    CONSTRAINT `fk_ParticipantRefund_payment`
        FOREIGN KEY (`participantPaymentId`) REFERENCES `ParticipantPayment`(`participantPaymentId`) ON DELETE RESTRICT,
    CONSTRAINT `chk_ParticipantRefund_amount` CHECK (`amount` >= 0)
) ENGINE=InnoDB;

CREATE TABLE `ConnectAccount` (
    `baseUserId`       VARCHAR(36)  NOT NULL,
    `stripeAccountId`  VARCHAR(255) NOT NULL,
    `detailsSubmitted` TINYINT(1)   NOT NULL DEFAULT 0,
    `chargesEnabled`   TINYINT(1)   NOT NULL DEFAULT 0,
    `payoutsEnabled`   TINYINT(1)   NOT NULL DEFAULT 0,
    `updatedAt`        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`baseUserId`),
    UNIQUE KEY `uq_ConnectAccount_stripe` (`stripeAccountId`),
    CONSTRAINT `fk_ConnectAccount_user`
        FOREIGN KEY (`baseUserId`) REFERENCES `BaseUser`(`baseUserId`) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE `PaymentTransfer` (
    `transferId`       VARCHAR(36)   NOT NULL,
    `eventId`          VARCHAR(36)   NOT NULL,
    `recipientId`      VARCHAR(36)   NOT NULL,
    `transferType`     ENUM('VENUE','PARTICIPANT_PAYOUT') NOT NULL,
    `amount`           DECIMAL(10,2) NOT NULL,
    `status`           ENUM('PENDING','PAID','FAILED','REVERSED') NOT NULL DEFAULT 'PENDING',
    `stripeTransferId` VARCHAR(255)  NULL,
    `reversedAmount`   DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `createdAt`        DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updatedAt`        DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`transferId`),
    UNIQUE KEY `uq_PaymentTransfer_event_type` (`eventId`, `transferType`),
    UNIQUE KEY `uq_PaymentTransfer_stripe` (`stripeTransferId`),
    CONSTRAINT `fk_PaymentTransfer_event`
        FOREIGN KEY (`eventId`) REFERENCES `Event`(`eventId`) ON DELETE RESTRICT,
    CONSTRAINT `fk_PaymentTransfer_recipient`
        FOREIGN KEY (`recipientId`) REFERENCES `BaseUser`(`baseUserId`) ON DELETE RESTRICT,
    CONSTRAINT `chk_PaymentTransfer_amount` CHECK (`amount` >= 0),
    CONSTRAINT `chk_PaymentTransfer_reversed` CHECK (`reversedAmount` >= 0 AND `reversedAmount` <= `amount`)
) ENGINE=InnoDB;

-- ============================================================================
--  MODULE 3 - Social Networking & Review System  (kw)
-- ============================================================================

CREATE TABLE `FriendConnection` (
    `friendConnectionId` VARCHAR(36) NOT NULL,   -- [+] surrogate key; the diagram shows no identifier
    `requesterId`        VARCHAR(36) NOT NULL,   -- [D]
    `addresseeId`        VARCHAR(36) NOT NULL,   -- [D]
    `state`              ENUM('PENDING','ACCEPTED','REJECTED','REMOVED')
                         NOT NULL DEFAULT 'PENDING',
        -- [D] persisted form of the FriendState interface. The Data Mapper reads
        --     this discriminator and rehydrates the matching concrete State class
        --     (PendingFriendState / AcceptedFriendState / ...). The State pattern
        --     lives in the object model; the table stores only which state it is in.
    `createdAt`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,            -- [D]
    `updatedAt`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, -- [+]
    PRIMARY KEY (`friendConnectionId`),
    UNIQUE KEY `uq_FriendConnection_pair` (`requesterId`, `addresseeId`),
    CONSTRAINT `fk_FriendConnection_requester`
        FOREIGN KEY (`requesterId`) REFERENCES `User`(`baseUserId`) ON DELETE CASCADE,
    CONSTRAINT `fk_FriendConnection_addressee`
        FOREIGN KEY (`addresseeId`) REFERENCES `User`(`baseUserId`) ON DELETE CASCADE,
    CONSTRAINT `chk_FriendConnection_self` CHECK (`requesterId` <> `addresseeId`),
    KEY `idx_FriendConnection_addressee` (`addresseeId`, `state`)
) ENGINE=InnoDB;

CREATE TABLE `Review` (
    `reviewId`         VARCHAR(36)  NOT NULL,   -- [D]
    `authorId`         VARCHAR(36)  NOT NULL,   -- [D] author: User
    `facilityId`       VARCHAR(36)  NULL,       -- [D] Facility --has 0..*--> Review
    `targetUserId`     VARCHAR(36)  NULL,       -- [+] kw module text says reviews for friends; the diagram only draws the Facility target
    `reviewTitle`      VARCHAR(150) NOT NULL,   -- [D]
    `reviewComment`    TEXT         NOT NULL,   -- [D] store raw, escape on OUTPUT (htmlspecialchars) to stop stored XSS
    `reviewVotes`      INT          NOT NULL DEFAULT 0,   -- [D] denormalised cache of SUM(ReviewVote.voteValue)
    `reviewTimestamp`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,  -- [D]
    `moderationStatus` ENUM('VISIBLE','HIDDEN','REMOVED')
                       NOT NULL DEFAULT 'VISIBLE',        -- [+] kw: admin tools to monitor, review and remove inappropriate comments
    PRIMARY KEY (`reviewId`),
    CONSTRAINT `fk_Review_author`
        FOREIGN KEY (`authorId`)     REFERENCES `User`(`baseUserId`)     ON DELETE RESTRICT,
    CONSTRAINT `fk_Review_facility`
        FOREIGN KEY (`facilityId`)   REFERENCES `Facility`(`facilityId`) ON DELETE CASCADE,
    CONSTRAINT `fk_Review_targetUser`
        FOREIGN KEY (`targetUserId`) REFERENCES `User`(`baseUserId`)     ON DELETE CASCADE,
    CONSTRAINT `chk_Review_one_target`
        CHECK ((`facilityId` IS NULL) <> (`targetUserId` IS NULL)),      -- exactly one target
    KEY `idx_Review_facility` (`facilityId`, `moderationStatus`),
    KEY `idx_Review_target`   (`targetUserId`, `moderationStatus`)
) ENGINE=InnoDB;

CREATE TABLE `ReviewVote` (                      -- [+] kw: upvote/downvote needs a record per voter, not just a counter
    `reviewVoteId` VARCHAR(36) NOT NULL,
    `reviewId`     VARCHAR(36) NOT NULL,
    `voterId`      VARCHAR(36) NOT NULL,
    `voteValue`    TINYINT     NOT NULL,         -- +1 upvote, -1 downvote
    `votedAt`      DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`reviewVoteId`),
    UNIQUE KEY `uq_ReviewVote_once` (`reviewId`, `voterId`),   -- one vote per user per review, enforced by the DB not by PHP
    CONSTRAINT `fk_ReviewVote_review`
        FOREIGN KEY (`reviewId`) REFERENCES `Review`(`reviewId`) ON DELETE CASCADE,
    CONSTRAINT `fk_ReviewVote_voter`
        FOREIGN KEY (`voterId`)  REFERENCES `User`(`baseUserId`) ON DELETE CASCADE,
    CONSTRAINT `chk_ReviewVote_value` CHECK (`voteValue` IN (-1, 1))
) ENGINE=InnoDB;

CREATE TABLE `BaseRating` (
    `ratingId`   VARCHAR(36) NOT NULL,                        -- [D]
    `authorId`   VARCHAR(36) NOT NULL,                        -- [D] author: User
    `ratingType` ENUM('USER','FACILITY') NOT NULL,            -- [+] CTI discriminator
    `createdAt`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, -- [+]
    PRIMARY KEY (`ratingId`),
    CONSTRAINT `fk_BaseRating_author`
        FOREIGN KEY (`authorId`) REFERENCES `User`(`baseUserId`) ON DELETE RESTRICT,
    KEY `idx_BaseRating_author` (`authorId`, `ratingType`)
) ENGINE=InnoDB;

CREATE TABLE `UserRating` (
    `ratingId`         VARCHAR(36)      NOT NULL,
    `rateeId`          VARCHAR(36)      NOT NULL,   -- [+] GAP: UserRating on the diagram has no target, only an author
    `attitudeRating`   TINYINT UNSIGNED NOT NULL,   -- [D] sportsmanship
    `attendanceRating` TINYINT UNSIGNED NOT NULL,   -- [D] reliability
    PRIMARY KEY (`ratingId`),
    CONSTRAINT `fk_UserRating_BaseRating`
        FOREIGN KEY (`ratingId`) REFERENCES `BaseRating`(`ratingId`) ON DELETE CASCADE,
    CONSTRAINT `fk_UserRating_ratee`
        FOREIGN KEY (`rateeId`)  REFERENCES `User`(`baseUserId`)     ON DELETE CASCADE,
    CONSTRAINT `chk_UserRating_attitude`   CHECK (`attitudeRating`   BETWEEN 1 AND 5),
    CONSTRAINT `chk_UserRating_attendance` CHECK (`attendanceRating` BETWEEN 1 AND 5),
    KEY `idx_UserRating_ratee` (`rateeId`)
) ENGINE=InnoDB;
-- "One rating per author per ratee" cannot be a single UNIQUE key here because
-- authorId sits on BaseRating. Enforce it in the Data Mapper, or denormalise
-- authorId into this table and add UNIQUE(authorId, rateeId).

CREATE TABLE `FacilityRating` (
    `ratingId`       VARCHAR(36)      NOT NULL,
    `facilityId`     VARCHAR(36)      NOT NULL,   -- [D] Facility --has 0..*--> FacilityRating
    `facilityRating` TINYINT UNSIGNED NOT NULL,   -- [D]
    PRIMARY KEY (`ratingId`),
    CONSTRAINT `fk_FacilityRating_BaseRating`
        FOREIGN KEY (`ratingId`)   REFERENCES `BaseRating`(`ratingId`) ON DELETE CASCADE,
    CONSTRAINT `fk_FacilityRating_facility`
        FOREIGN KEY (`facilityId`) REFERENCES `Facility`(`facilityId`) ON DELETE CASCADE,
    CONSTRAINT `chk_FacilityRating_value` CHECK (`facilityRating` BETWEEN 1 AND 5),
    KEY `idx_FacilityRating_facility` (`facilityId`)
) ENGINE=InnoDB;


-- ============================================================================
--  CROSS-CUTTING - Web service request tracking
--  The IFA makes timeStamp / requestID mandatory "to ensure proper tracking of
--  requests". This table is where that tracking lands, for both the services
--  we expose and the services we consume.
-- ============================================================================

CREATE TABLE `WebServiceLog` (
    `requestId`         VARCHAR(36)  NOT NULL,        -- the IFA requestID, echoed back in the response
    `direction`         ENUM('INBOUND','OUTBOUND') NOT NULL,
    `sourceModule`      VARCHAR(100) NOT NULL,
    `targetModule`      VARCHAR(100) NOT NULL,
    `functionName`      VARCHAR(100) NOT NULL,        -- e.g. getFacilityDetails
    `requestTimestamp`  DATETIME     NOT NULL,        -- YYYY-MM-DD HH:MM:SS
    `responseTimestamp` DATETIME     NULL,
    `responseStatus`    ENUM('S','F','E') NULL,       -- IFA status: Success / Fail / Error
    `httpStatusCode`    SMALLINT UNSIGNED NULL,
    `errorMessage`      VARCHAR(500) NULL,
    PRIMARY KEY (`requestId`),
    KEY `idx_WebServiceLog_fn` (`functionName`, `requestTimestamp`)
) ENGINE=InnoDB;
