-- ============================================================================
--  BMIT3173 Integrative Programming  |  Assignment 202605
--  Initial data. Run AFTER schema.sql.
--
--  Author : Goh Jian Yu, Ooi Kean Wei, Ng Jing Siang, Khor Zhi Hong, Ivan Lim Tze Yang
--
--  All demo accounts use the password:  Password123!
--  The stored value is a real bcrypt hash produced by PHP password_hash().
--  Regenerate with:  php -r 'echo password_hash("Password123!", PASSWORD_BCRYPT);'
--
--  Event dates are written relative to CURDATE(), so the upcoming lists still
--  have something in them however long after this file was written the demo is
--  run. Anything with a fixed date is a record of something that already
--  happened and is meant to stay put.
--
--  Seed identifiers are readable (usr-001, fac-001, ...) so the demo is easy to
--  follow. The application itself must generate real UUIDs:
--      bin2hex(random_bytes(16)) formatted as 8-4-4-4-12
--  Sequential integer IDs are deliberately NOT used: opaque identifiers remove
--  the enumeration surface behind IDOR (walking /event?id=1,2,3...).
-- ============================================================================

USE sports_platform;

-- ---------------------------------------------------------------------------
--  Accounts
-- ---------------------------------------------------------------------------
INSERT INTO `BaseUser`
    (`baseUserId`, `email`, `password`, `username`, `contactNumber`, `registerTime`, `userType`, `accountStatus`) VALUES
('adm-001', 'admin@sportsplatform.my',   '$2y$10$84d3fVCy9wd8ArZX6R/yj.6EcQCi31saZ5.rdJhkD/7gM6m3hTzdm', 'sysadmin',    '0312345678', '2026-01-05 09:00:00', 'ADMIN',          'ACTIVE'),
('own-001', 'contact@smashpoint.my',     '$2y$10$84d3fVCy9wd8ArZX6R/yj.6EcQCi31saZ5.rdJhkD/7gM6m3hTzdm', 'smashpoint',  '0387654321', '2026-01-12 10:30:00', 'FACILITY_OWNER', 'ACTIVE'),
('own-002', 'admin@arenaklang.my',       '$2y$10$84d3fVCy9wd8ArZX6R/yj.6EcQCi31saZ5.rdJhkD/7gM6m3hTzdm', 'arenaklang',  '0333221100', '2026-02-02 14:15:00', 'FACILITY_OWNER', 'ACTIVE'),
('usr-001', 'aisyah.rahman@example.com', '$2y$10$84d3fVCy9wd8ArZX6R/yj.6EcQCi31saZ5.rdJhkD/7gM6m3hTzdm', 'aisyahr',     '0121234567', '2026-02-10 19:20:00', 'USER',           'ACTIVE'),
('usr-002', 'daniel.tan@example.com',    '$2y$10$84d3fVCy9wd8ArZX6R/yj.6EcQCi31saZ5.rdJhkD/7gM6m3hTzdm', 'danieltan',   '0169876543', '2026-02-11 08:45:00', 'USER',           'ACTIVE'),
('usr-003', 'priya.kumar@example.com',   '$2y$10$84d3fVCy9wd8ArZX6R/yj.6EcQCi31saZ5.rdJhkD/7gM6m3hTzdm', 'priyak',      '0195551234', '2026-03-01 21:05:00', 'USER',           'ACTIVE'),
('usr-004', 'wei.jie.lim@example.com',   '$2y$10$84d3fVCy9wd8ArZX6R/yj.6EcQCi31saZ5.rdJhkD/7gM6m3hTzdm', 'weijie',      '0182223344', '2026-03-04 12:00:00', 'USER',           'ACTIVE'),
('usr-005', 'nurul.hidayah@example.com', '$2y$10$84d3fVCy9wd8ArZX6R/yj.6EcQCi31saZ5.rdJhkD/7gM6m3hTzdm', 'nurulh',      '0134445566', '2026-03-18 17:40:00', 'USER',           'ACTIVE'),
('usr-006', 'kai.wen.ng@example.com',    '$2y$10$84d3fVCy9wd8ArZX6R/yj.6EcQCi31saZ5.rdJhkD/7gM6m3hTzdm', 'kaiweng',     '0177778888', '2026-04-02 11:25:00', 'USER',           'DEACTIVATED');

INSERT INTO `Admin` (`baseUserId`, `adminId`) VALUES
('adm-001', 'STAFF-0001');

INSERT INTO `FacilityOwner` (`baseUserId`, `bankName`, `bankAccountNum`, `businessRegNum`) VALUES
('own-001', 'Maybank',    '514012345678', 'SSM-202401001234'),
('own-002', 'CIMB Bank',  '800987654321', 'SSM-202402005678');

-- latitude/longitude are what geocoding `location` produces. They are seeded
-- here so the demo has them without calling OpenStreetMap 6 times on setup.
INSERT INTO `User`
    (`baseUserId`, `location`, `profilePicURL`, `birthDate`, `latitude`, `longitude`) VALUES
-- profilePicURL is seeded NULL. A profile picture is an uploaded file living in
-- public/static/profile/<baseUserId>.jpg, and there are no image files in the
-- repository - pointing at ones that do not exist would show a broken image on
-- every demo profile. Upload one from Edit profile to see it.
('usr-001', 'Setapak, Kuala Lumpur',    NULL, '2003-05-14', 3.2168000, 101.7291000),
('usr-002', 'Wangsa Maju, Kuala Lumpur',NULL, '2002-11-30', 3.2050000, 101.7370000),
('usr-003', 'Cheras, Kuala Lumpur',     NULL, '2004-02-08', 3.1041000, 101.7420000),
('usr-004', 'Kepong, Kuala Lumpur',     NULL, '2001-07-22', 3.2088000, 101.6355000),
('usr-005', 'Klang, Selangor',          NULL, '2003-09-03', 3.0449000, 101.4455000),
('usr-006', 'Ampang, Selangor',         NULL, '2000-12-19', 3.1500000, 101.7600000);

-- Favourite sports, one row per sport per player. Several players list more
-- than one, so the recommendation engine has something to match on.
INSERT INTO `UserFavoriteSport` (`baseUserId`, `sport`) VALUES
('usr-001', 'Badminton'),  ('usr-001', 'Table Tennis'),
('usr-002', 'Futsal'),     ('usr-002', 'Football'),
('usr-003', 'Basketball'),
('usr-004', 'Badminton'),  ('usr-004', 'Squash'),      ('usr-004', 'Running'),
('usr-005', 'Futsal'),     ('usr-005', 'Sepak Takraw'),
('usr-006', 'Basketball');

-- ---------------------------------------------------------------------------
--  Facilities   (MODULE 1 - mine)
--  fac-004 is left PENDING to demonstrate the onboarding approval state.
--  fac-005 closes after it opens (22:00 to 02:00), which is allowed: the CHECK
--  that used to forbid it has been dropped, because plenty of courts run late.
--  fac-002 has no street name in its address, which is also allowed - only the
--  unit and the area are required. The postcode is a field of its own.
-- ---------------------------------------------------------------------------
INSERT INTO `Facility`
    (`facilityId`, `ownerId`, `name`, `imageUrl`, `addressLine`, `postcode`, `city`, `state`, `type`,
     `bookingFee`, `operationalHrsStart`, `operationalHrsEnd`, `latitude`, `longitude`, `status`, `createdAt`) VALUES
('fac-001', 'own-001', 'SmashPoint Badminton Centre', 'https://a.storyblok.com/f/247285/1200x920/00b754ae09/steelpedia_architectural_design_skyarena_sports_complex_10.webp', 'No. 12, Taman Melawati, Jalan Genting Klang', '53100', 'Setapak', 'Kuala Lumpur', 'Badminton',      35.00, '08:00:00', '23:00:00', 3.2145000, 101.7268000, 'ACTIVE',  '2026-01-15 09:00:00'),
('fac-002', 'own-001', 'SmashPoint Court 2 (Indoor)',  NULL, 'No. 14, Taman Melawati', '53100', 'Setapak', 'Kuala Lumpur', 'Basketball',     50.00, '09:00:00', '22:00:00', 3.2147000, 101.7271000, 'ACTIVE',  '2026-01-16 09:30:00'),
('fac-003', 'own-002', 'Arena Klang Futsal',           'https://apicms.thestar.com.my/uploads/images/2023/06/09/2116926.webp', 'Lot 88, Bandar Baru Klang, Jalan Meru', '41050', 'Klang', 'Selangor',     'Futsal',          80.00, '10:00:00', '23:59:00', 3.0521000, 101.4381000, 'ACTIVE',  '2026-02-05 11:00:00'),
('fac-004', 'own-002', 'Arena Klang Annex (New)',      NULL,                           'Lot 90, Bandar Baru Klang, Jalan Meru', '41050','Klang',      'Selangor',     'Badminton',      40.00, '08:00:00', '22:00:00', 3.0525000, 101.4386000, 'PENDING', '2026-08-20 16:45:00'),
('fac-005', 'own-002', 'Arena Klang Late Night',      NULL, 'Lot 92, Bandar Baru Klang, Jalan Meru', '41050', 'Klang', 'Selangor', 'Futsal',       60.00, '22:00:00', '02:00:00', 3.0528000, 101.4390000, 'ACTIVE',  '2026-03-10 20:00:00');

-- ---------------------------------------------------------------------------
--  Events   (MODULE 1 - mine)
--  Dates are counted from whenever this file is run, so the four upcoming
--  events are always still upcoming and evt-005 is always already over.
--    evt-001 PUBLISHED public       evt-002 PUBLISHED friends-only
--    evt-003 PENDING_PAYMENT        evt-004 CANCELLED (drives the refund path)
--    evt-005 COMPLETED (past)       -- gives the ratings and reviews below a reason to exist
--  All four upcoming dates sit inside the three month booking window that the
--  event form enforces, so a seeded event could also have been created by hand.
-- ---------------------------------------------------------------------------
INSERT INTO `Event`
    (`eventId`, `hostId`, `facilityId`, `name`, `sport`, `eventDate`, `startTime`, `endTime`,
     `minParticipants`, `maxParticipants`, `status`, `skillLevel`, `fitnessRequirement`,
     `competitiveness`, `visibility`, `feePerParticipant`, `createdAt`) VALUES
('evt-001', 'usr-001', 'fac-001', 'Friday Night Doubles',      'Badminton',  CURDATE() + INTERVAL  5 DAY, '20:00:00', '22:00:00',  4,  8, 'PUBLISHED',       'INTERMEDIATE', 'MODERATE', 'CASUAL',      'PUBLIC',       10.00, NOW() - INTERVAL 12 DAY),
('evt-002', 'usr-002', 'fac-003', 'Sunday Futsal Kickabout',   'Futsal',     CURDATE() + INTERVAL  7 DAY, '16:00:00', '18:00:00',  8, 12, 'PUBLISHED',       'ANY',          'HIGH',     'CASUAL',      'FRIENDS_ONLY', 12.00, NOW() - INTERVAL 11 DAY),
('evt-003', 'usr-003', 'fac-002', 'Midweek 3v3 Basketball',    'Basketball', CURDATE() + INTERVAL 10 DAY, '19:00:00', '21:00:00',  6, 10, 'PENDING_PAYMENT', 'BEGINNER',     'MODERATE', 'CASUAL',      'PUBLIC',        8.00, NOW() - INTERVAL  8 DAY),
('evt-004', 'usr-001', 'fac-001', 'Weekend Singles Ladder',    'Badminton',  CURDATE() + INTERVAL 14 DAY, '09:00:00', '12:00:00',  4,  6, 'CANCELLED',       'ADVANCED',     'HIGH',     'COMPETITIVE', 'PUBLIC',       15.00, NOW() - INTERVAL 16 DAY),
('evt-005', 'usr-002', 'fac-001', 'Pre-semester Warmup Games', 'Badminton',  CURDATE() - INTERVAL 25 DAY, '20:00:00', '22:00:00',  4,  8, 'COMPLETED',       'INTERMEDIATE', 'MODERATE', 'CASUAL',      'PUBLIC',       10.00, NOW() - INTERVAL 35 DAY);

-- Invite links (MODULE 1 - mine). inv-001 is how a non-friend reaches evt-002.
INSERT INTO `EventInvite`
    (`eventInviteId`, `eventId`, `token`, `createdBy`, `createdAt`, `expiresAt`, `maxUses`, `useCount`, `revoked`) VALUES
('inv-001', 'evt-002', '7f3a9c1e4b8d2a6f0c5e9b3d7a1f4c8e2b6d0a9f3c7e1b5d8a2f6c0e4b9d3a7f', 'usr-002', NOW() - INTERVAL 11 DAY, CURDATE() + INTERVAL 7 DAY + INTERVAL 16 HOUR, 20, 1, 0),
('inv-002', 'evt-001', 'c4e8b2d6a0f3c7e1b5d9a3f7c1e5b9d3a7f1c5e9b3d7a1f5c9e3b7d1a5f9c3e7', 'usr-001', '2026-08-20 21:15:00', NULL,                    NULL, 0, 0),
('inv-003', 'evt-004', 'a1b2c3d4e5f60718293a4b5c6d7e8f901a2b3c4d5e6f708192a3b4c5d6e7f809', 'usr-001', '2026-08-18 18:05:00', NULL,                    NULL, 0, 1);

-- ---------------------------------------------------------------------------
--  Bookings and payments   (MODULE 4 - zh)
--  evt-003 intentionally has a booking with NO payment row: that is the 0..1.
-- ---------------------------------------------------------------------------
INSERT INTO `Booking`
    (`bookingId`, `eventId`, `madeById`, `bookingStatus`, `bookingAmount`, `createdAt`) VALUES
('bkg-001', 'evt-001', 'usr-001', 'CONFIRMED', 70.00, '2026-08-20 21:12:00'),
('bkg-002', 'evt-002', 'usr-002', 'CONFIRMED', 160.00,'2026-08-21 09:32:00'),
('bkg-003', 'evt-003', 'usr-003', 'PENDING',   100.00,'2026-08-24 13:07:00'),
('bkg-004', 'evt-004', 'usr-001', 'CANCELLED', 105.00,'2026-08-18 18:02:00'),
('bkg-005', 'evt-005', 'usr-002', 'CONFIRMED', 70.00, '2026-08-05 10:02:00');

INSERT INTO `Payment`
    (`paymentId`, `bookingId`, `amount`, `paymentDateTime`, `paymentMethod`, `paymentStatus`) VALUES
('pay-001', 'bkg-001',  70.00, '2026-08-20 21:13:40', 'card', 'PAID'),
('pay-002', 'bkg-002', 160.00, '2026-08-21 09:33:55', 'fpx',  'PAID'),
('pay-004', 'bkg-004', 105.00, '2026-08-18 18:03:20', 'card', 'REFUNDED'),
('pay-005', 'bkg-005',  70.00, '2026-08-05 10:03:10', 'card', 'PAID');

-- ---------------------------------------------------------------------------
--  Event registrations   (MODULE 5 - js)
--  evt-005 rows carry ATTENDED / NO_SHOW, which is what the attendance rating reads.
-- ---------------------------------------------------------------------------
INSERT INTO `EventRegistration`
    (`eventRegistrationId`, `userId`, `eventId`, `status`, `registerTime`, `eventInviteId`) VALUES
('reg-001', 'usr-002', 'evt-001', 'CONFIRMED', '2026-08-21 08:00:00', NULL),
('reg-002', 'usr-004', 'evt-001', 'CONFIRMED', '2026-08-21 12:30:00', NULL),
('reg-003', 'usr-005', 'evt-001', 'PENDING',   '2026-08-23 19:45:00', NULL),
('reg-004', 'usr-003', 'evt-002', 'CONFIRMED', '2026-08-22 10:05:00', 'inv-001'),
('reg-005', 'usr-005', 'evt-002', 'CONFIRMED', '2026-08-22 14:20:00', NULL),
('reg-006', 'usr-001', 'evt-005', 'ATTENDED',  '2026-08-08 09:00:00', NULL),
('reg-007', 'usr-003', 'evt-005', 'ATTENDED',  '2026-08-09 16:10:00', NULL),
('reg-008', 'usr-004', 'evt-005', 'NO_SHOW',   '2026-08-10 11:00:00', NULL),
('reg-009', 'usr-002', 'evt-004', 'CANCELLED', '2026-08-18 19:00:00', NULL);

-- ---------------------------------------------------------------------------
--  Friend connections   (MODULE 3 - kw)
--  One row per state so every concrete FriendState class has live data.
-- ---------------------------------------------------------------------------
INSERT INTO `FriendConnection`
    (`friendConnectionId`, `requesterId`, `addresseeId`, `state`, `createdAt`) VALUES
('frd-001', 'usr-001', 'usr-002', 'ACCEPTED', '2026-03-02 20:00:00'),
('frd-002', 'usr-002', 'usr-003', 'ACCEPTED', '2026-03-15 18:30:00'),
('frd-003', 'usr-002', 'usr-005', 'ACCEPTED', '2026-04-01 09:10:00'),
('frd-004', 'usr-003', 'usr-004', 'PENDING',  '2026-08-14 21:00:00'),
('frd-005', 'usr-004', 'usr-005', 'REJECTED', '2026-07-20 13:45:00'),
('frd-006', 'usr-001', 'usr-006', 'REMOVED',  '2026-05-11 15:20:00');

-- ---------------------------------------------------------------------------
--  Reviews, votes and ratings   (MODULE 3 - kw)
--  rev-003 targets a USER, the rest target FACILITIES.
--  reviewVotes is kept consistent with the ReviewVote rows below.
-- ---------------------------------------------------------------------------
INSERT INTO `Review`
    (`reviewId`, `authorId`, `facilityId`, `targetUserId`, `reviewTitle`, `reviewComment`,
     `reviewVotes`, `reviewTimestamp`, `moderationStatus`) VALUES
('rev-001', 'usr-002', 'fac-001', NULL,      'Great courts, tight parking',  'Flooring and lighting are excellent and the nets are properly tensioned. Only complaint is that parking fills up fast after 8pm.', 2, '2026-08-16 09:15:00', 'VISIBLE'),
('rev-002', 'usr-001', 'fac-003', NULL,      'Good value for a full pitch',  'RM80 for two hours split between twelve people is very reasonable. Changing rooms could be cleaner.',                              1, '2026-08-02 22:40:00', 'VISIBLE'),
('rev-003', 'usr-002', NULL,      'usr-004', 'Did not turn up',              'Confirmed his slot for the warmup games and never showed, which left us a player short for the whole session.',                 1, '2026-08-16 09:30:00', 'VISIBLE'),
('rev-004', 'usr-006', 'fac-002', NULL,      'terrible!!!',                  'This review was removed by a moderator for abusive language.',                                                                  -1, '2026-08-17 02:05:00', 'REMOVED');

INSERT INTO `ReviewVote`
    (`reviewVoteId`, `reviewId`, `voterId`, `voteValue`, `votedAt`) VALUES
('vot-001', 'rev-001', 'usr-001',  1, '2026-08-16 10:00:00'),
('vot-002', 'rev-001', 'usr-003',  1, '2026-08-16 11:20:00'),
('vot-003', 'rev-002', 'usr-005',  1, '2026-08-03 08:30:00'),
('vot-004', 'rev-003', 'usr-001',  1, '2026-08-16 10:05:00'),
('vot-005', 'rev-004', 'usr-002', -1, '2026-08-17 08:00:00');

INSERT INTO `BaseRating` (`ratingId`, `authorId`, `ratingType`, `createdAt`) VALUES
('rat-001', 'usr-002', 'FACILITY', '2026-08-16 09:15:00'),
('rat-002', 'usr-001', 'FACILITY', '2026-08-02 22:40:00'),
('rat-003', 'usr-003', 'FACILITY', '2026-08-16 12:00:00'),
('rat-004', 'usr-002', 'USER',     '2026-08-16 09:30:00'),
('rat-005', 'usr-001', 'USER',     '2026-08-16 09:45:00'),
('rat-006', 'usr-003', 'USER',     '2026-08-16 13:10:00');

INSERT INTO `FacilityRating` (`ratingId`, `facilityId`, `facilityRating`) VALUES
('rat-001', 'fac-001', 5),
('rat-002', 'fac-003', 4),
('rat-003', 'fac-001', 4);

INSERT INTO `UserRating` (`ratingId`, `rateeId`, `attitudeRating`, `attendanceRating`) VALUES
('rat-004', 'usr-004', 3, 1),   -- no-show at evt-005
('rat-005', 'usr-002', 5, 5),
('rat-006', 'usr-001', 5, 4);

-- ---------------------------------------------------------------------------
--  Web service call log (cross-cutting)
--  Two exposed calls answered, one consumed call that failed - so the demo can
--  show the IFA status vocabulary S / F / E with real rows behind it.
-- ---------------------------------------------------------------------------
INSERT INTO `WebServiceLog`
    (`requestId`, `direction`, `sourceModule`, `targetModule`, `functionName`,
     `requestTimestamp`, `responseTimestamp`, `responseStatus`, `httpStatusCode`, `errorMessage`) VALUES
('req-0000000001', 'INBOUND',  'Discovery & Event Matchmaking', 'Event & Facility Management',   'searchFacilities',    '2026-08-24 10:00:01', '2026-08-24 10:00:01', 'S', 200, NULL),
('req-0000000002', 'INBOUND',  'Venue Booking & Payment',       'Event & Facility Management',   'getFacilityDetails',  '2026-08-24 13:06:58', '2026-08-24 13:06:59', 'S', 200, NULL),
('req-0000000003', 'OUTBOUND', 'Event & Facility Management',   'User Authentication & Profile', 'getUserContactInfo',  '2026-08-20 16:45:02', '2026-08-20 16:45:02', 'S', 200, NULL),
('req-0000000004', 'OUTBOUND', 'Event & Facility Management',   'Venue Booking & Payment',       'getBookingStatus',    '2026-08-24 13:07:10', '2026-08-24 13:07:12', 'F', 404, 'No booking found for eventId evt-003 at time of call'),
('req-0000000005', 'OUTBOUND', 'Event & Facility Management',   'Social Networking & Review',    'getFacilityRatings',  '2026-08-24 10:00:03', NULL,                  'E', 500, 'Connection timed out after 5000ms');
