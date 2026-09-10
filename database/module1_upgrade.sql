-- ============================================================================
--  BMIT3173 Integrative Programming  |  Assignment 202605
--  MODULE 1 - Event & Facility Management  (Goh Jian Yu)
--
--  The postcode used to sit at the end of `addressLine`. It now has a field of
--  its own, which is how a form normally asks for one, and it matters more here
--  than in most systems because the postcode is what decides the state.
--
--  Only needed to preserve an existing database. Running `setup.bat` rebuilds
--  everything from schema.sql, which already contains all of this.
-- ============================================================================

USE sports_platform;

-- A default only so the column can be added to rows that already exist. It goes
-- away again at the bottom, once every row carries a real value.
ALTER TABLE `Facility`
    ADD COLUMN `postcode` CHAR(5) NOT NULL DEFAULT '00000' AFTER `addressLine`;

-- Copy the five digits off the end of the address, where the old format put
-- them. A row whose address never had a postcode keeps 00000 and its stored
-- state, and the owner will be asked for a real one the next time they edit it.
UPDATE `Facility`
   SET `postcode` = REGEXP_SUBSTR(`addressLine`, '[0-9]{5}$')
 WHERE `addressLine` REGEXP '[0-9]{5}$';

-- Now take those digits out of the address, along with the comma that separated
-- them, so the two fields do not both hold the same thing.
UPDATE `Facility`
   SET `addressLine` = REGEXP_REPLACE(`addressLine`, '[[:space:]]*,?[[:space:]]*[0-9]{5}$', '')
 WHERE `addressLine` REGEXP '[0-9]{5}$';

ALTER TABLE `Facility`
    ADD CONSTRAINT `chk_Facility_postcode` CHECK (`postcode` REGEXP '^[0-9]{5}$');

ALTER TABLE `Facility`
    ALTER COLUMN `postcode` DROP DEFAULT;
