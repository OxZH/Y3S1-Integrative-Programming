# Event & Facility Management module

BMIT3173 Integrative Programming, Assignment 202605.

Venue onboarding and management, event creation, invite links and visibility,
and the facility search engine exposed as a web service. Plain PHP 8.2, MVC,
with a Data Mapper ORM.

## Setup

Start XAMPP (Apache + MySQL), then run `database\setup.bat`.

Install the Stripe PHP SDK once:

```
composer install
```

Only `public/` should be reachable over HTTP. Link it into `htdocs` once, from
an **Administrator** Command Prompt:

```
mklink /J "C:\xampp\htdocs\sportsplatform" "<path to this project>\public"
```

Then open <http://localhost/sportsplatform/>.

Editing files in the project updates the site straight away - a junction is a
link, not a copy. Remove it with `rmdir "C:\xampp\htdocs\sportsplatform"`
(that deletes the link only, not the project).

Sign in with `smashpoint` (facility owner) or `aisyahr` (player).

### Stripe Test Mode

Enable Stripe Connect on the platform's Stripe Test account, then expose these
environment variables to Apache and restart Apache:

```
STRIPE_SECRET_KEY=sk_test_...
STRIPE_PUBLISHABLE_KEY=pk_test_...
STRIPE_WEBHOOK_SECRET=whsec_...
SP_PAYMENT_SERVICE_KEY=replace-with-a-shared-random-value
```

For local Webhook delivery, install Stripe CLI, sign in, and run:

```
stripe listen --forward-to http://localhost/sportsplatform/api/stripe-webhook.php
```

Copy the printed `whsec_...` value into `STRIPE_WEBHOOK_SECRET`. In Test Mode,
facility owners connect their account from **Payments** before organizers pay
for a venue. Organizers also connect before accepting participant fees.

### Other ways to run it

Copying the whole project into `htdocs` also works, at
`http://localhost/<folder>/public/index.php`. Note that it puts `database/`
inside the web root, and Apache serves `.sql` files as plain text, so the schema
and seed data become downloadable. Fine on a local machine, but the junction
avoids it.

The built-in server needs no Apache, but it is single threaded, so a page that
calls a web service on its own host times out. Run a second instance for the
stub services:

```
php -S localhost:8001 -t public
SP_STUB_BASE=http://localhost:8001 php -S localhost:8000 -t public
```

`app.base_url` is worked out from the request, so none of these need a config
change. Override it with the `SP_BASE_URL` environment variable if you ever
need to.

## Layout

```
config.php              database, service endpoints, timeouts
database/               schema.sql, seed.sql, setup.bat
app/
  bootstrap.php         autoloader, config, session
  helpers.php           e(), url(), money(), uuid()
  Enums.php             status, visibility, skill, fitness value lists
  Exceptions.php
  Core/                 Database, DataMapper, Entity, Controller, View
  Model/                Facility, Event, EventInvite, Account + mappers
  Domain/               EventManagementFacade + its policies and factory
  Service/              IFA envelope, log, HTTP client, RemoteServices
  Security/             Auth, Csrf, Validator (shared)
                        EventFacilitySecurity (this module)
  Controller/
  views/
public/
  index.php             front controller
  css/style.css         the one stylesheet every page loads
  js/app.js             venue preview and confirm prompts
  api/facility.php      exposed service, IFA in the file header
  api/event.php         exposed service, IFA in the file header
  api/stub.php          stand-in for teammate services
  booking.php           stand-in for the booking and payment screen
```

## Web services

Exposed, on `POST /api/facility.php` and `POST /api/event.php`:
`getFacilityDetails`, `searchFacilities`, `getEventDetails`,
`listUpcomingEvents`, `getEventsByFacility`. Full IFA tables are in each file's
header comment.

Venue Booking & Payment also exposes `POST /api/payment.php`:
`getBookingStatus`, `getEventPaymentSummary`, `cancelEventPayments` and
`settleEventPayout`. The two mutation functions require the shared
`X-Service-Key` header. Their complete IFA is in the endpoint's header.

The payment module is isolated from Jianyu's pages and controllers. Its own
entry points are:

```
payment.php
payment.php?action=venue&eventId=<eventId>
payment.php?action=participant&eventId=<eventId>
```

When integrating, Jianyu only needs to redirect a newly-created event to the
venue URL above. On event cancellation call `cancelEventPayments`; after the
Event module changes an event to `COMPLETED`, call `settleEventPayout`.
Venue payments are non-refundable. Participant fees receive a full refund only
when the participant or Event module cancels before the event's actual start
date and time.

```
curl -X POST http://localhost:8000/api/facility.php \
  -H 'Content-Type: application/json' \
  -d '{"requestId":"demo-001","timeStamp":"2026-08-25 14:30:00",
       "function":"getFacilityDetails","facilityId":"fac-001"}'
```

Consumed, via `App\Service\RemoteServices`:

| Function | From | Used for |
|---|---|---|
| `getUserContactInfo` | User Authentication | owner details at onboarding |
| `getBookingStatus` | Venue Booking & Payment | gating event publication |
| `getFacilityRatings` | Social Networking | ratings in facility search |
| `areFriends` | Social Networking | friends-only visibility |

Requests carry `requestId` and `timeStamp`; responses carry `status` (S/F/E),
`timeStamp`, `message` and `data`. Both directions are written to
`WebServiceLog`.

## Flows

**Creating an event** is one form, then a hand-over and a return:

```
1  create     game details + venue dropdown     event saved as DRAFT on submit
   ->  Venue Booking & Payment module takes over  (booking.php stands in for it)
2  finalise   confirm and publish               event becomes PUBLISHED
```

Entry points are the **Create an event** button on My events and Upcoming games,
or a venue page (`event&a=create&facilityId=...`), which pre-selects that venue
in the dropdown.

The venue field is a `<select>` listing every bookable venue with its city,
hourly fee and rating. A `<select>` can only hold text, so picking one fills a
preview panel underneath with the venue image, rating, address and hours, plus a
**View venue details** button that opens in a new tab so the form is not lost.
Ratings come from the Social Networking module and read "Not rated yet" until it
is wired in.

The event row is written on submit rather than after payment, because
`Booking.eventId` is a NOT NULL foreign key - the other module cannot record a
booking against an event that does not exist yet. Abandoning the form before
submitting leaves nothing behind.

There is no venue browse screen. Facility search lives on as the
`searchFacilities` web service for the Discovery module to build its browse and
map screens on.

**Creating a facility** is a single form. The owner's contact number is filled
in from the User Authentication module; opening hours and the booking fee are
entered per venue. A new venue starts PENDING and is invisible to search until
approved.

**Deleting** is hard or soft depending on what references the row:

| | Hard delete when | Otherwise |
|---|---|---|
| Event | no booking and no registrations | `CANCELLED` |
| Facility | no events, reviews or ratings | `SUSPENDED` (delisted) |

Invite links do not count as history - one is minted with every event, so
counting them would mean nothing was ever deletable. They cascade away with the
row.

## Styling

No CSS framework - `public/css/style.css` is the whole of it, hand written, and
every page loads it. There is no inline `style` attribute, no `<style>` block
and no inline `<script>` anywhere in the views, so a teammate restyling one
thing changes it everywhere.

Colours, spacing and the corner radius are custom properties on `:root` at the
top of the file - edit that block to restyle the whole site.

`public/js/app.js` holds the little behaviour there is: the venue preview on the
event form, and the confirmation prompt on destructive buttons. Add
`data-confirm="Are you sure?"` to any form to get the prompt; nothing else is
needed. Both files are linked with `?v=<file modified time>`, so an edit is
picked up without clearing the browser cache.

## Notes

- An event cannot publish until `getBookingStatus` reports CONFIRMED and PAID.
  There is no pay-later option. If that module is unreachable the event stays
  hidden rather than being treated as unpaid.
- Friends-only visibility fails closed if the friend service cannot answer.
- Ratings are decoration, so search still renders when that service is down.
- A new venue starts PENDING and is invisible to search until approved.
- A missing record and a forbidden one give the same message.
- Record-level access control lives in `Security/EventFacilitySecurity.php`,
  separate from the shared `Auth.php`, so each module keeps its own checks in
  its own file. `Auth` answers who is signed in; this answers whether they may
  touch a given venue or event.

## Not part of this module

`app/Model/Account.php` is a placeholder for the User Authentication module's
account classes; `app/Controller/LoginController.php` with `views/login.php` is
a password-less account picker for testing; `public/api/stub.php` stands in for
the three teammate services, and `public/booking.php` stands in for the Venue
Booking & Payment booking screen. All of them go once the real modules are
integrated - for the booking screen, point EventController::store() at theirs.

`database/schema.sql` and `seed.sql` cover every table in the system, since the
schema is shared and this module needs the other tables present to run.

## Before submitting

- Author headers are done. 36 files this module owns are credited to Goh Jian Yu.
  The 22 shared files - anything another module would use unchanged - carry all
  five names: `app/Core`, `bootstrap.php`, `config.php`, `Enums.php`,
  `Exceptions.php`, `helpers.php`, `Security/Validator.php`, `Service/Ifa.php`,
  `Service/ServiceClient.php`, `Service/ServiceLog.php`, `views/layout.php`,
  `views/error.php`, `public/index.php`, the stylesheet, the script and the
  three files under `database/`.
- Rename the `sports_platform` database once the system has a title.
- Set `app.debug` to `false` in `config.php`.
