# Sports Platform

BMIT3173 Integrative Programming, Assignment 202605. Plain PHP 8.2, MVC, with a
Data Mapper ORM.

## Setup

Start XAMPP (Apache + MySQL), then run `database\setup.bat`. That drops and
rebuilds the database from `schema.sql` and `seed.sql`, which are the source of
truth for every module.

```
setup.bat          tables + demo data
setup.bat schema   tables only, no demo data
setup.bat force    skip the confirmation
```

Those three files - `schema.sql`, `seed.sql`, `setup.bat` - are the whole of
`database/`. There are no migration scripts and no per-module scripts to run in
sequence: when the schema changes, you rebuild. Pull, run `setup.bat`, carry on.

Because rebuilding is the only path, it is also destructive - anything you typed
in by hand goes, and everything in `seed.sql` comes back. The script asks before
it does that.

Only `public/` should be reachable over HTTP. Link it into `htdocs` once, from
an **Administrator** Command Prompt:

```
mklink /J "C:\xampp\htdocs\sportsplatform" "<path to this project>\public"
```

Then open <http://localhost/sportsplatform/>.

Editing files in the project updates the site straight away - a junction is a
link, not a copy. Remove it with `rmdir "C:\xampp\htdocs\sportsplatform"`
(that deletes the link only, not the project).

Every seeded account uses the password `Password123!`:

| Email | Role |
|---|---|
| `aisyah.rahman@example.com` | Player |
| `contact@smashpoint.my` | Facility owner |
| `admin@sportsplatform.my` | Administrator |

Or register a new account at **Register**.

### Internal Demo Payments

No external payment account, API key, Composer package or Webhook is required.
The payment page simulates Card, FPX and E-wallet payments and records the
result in MySQL. No real money is charged or transferred.

Checkout uses the Strategy pattern (`PaymentMethodStrategy` plus Card / FPX /
E-wallet classes). Each method collects its own demo fields, stores a **masked
snapshot** on the payment row for history, and can optionally be saved for
autofill next time (`SavedPaymentMethod`, max 5 per user). Full card numbers and
CVVs are not kept. Venue fees stay non-refundable; participant refunds before
the event starts are unchanged.

`setup.bat` includes all of this from `schema.sql` (see Setup). Free participant
events still go through checkout so a method is selected; the amount is RM0.00.

### Other ways to run it

Copying the whole project into `htdocs` also works, at
`http://localhost/<folder>/public/index.php`. Note that it puts `database/`
inside the web root, and Apache serves `.sql` files as plain text, so the schema
and seed data become downloadable. Fine on a local machine, but the junction
avoids it.

The built-in server needs no Apache, but it is single threaded, so a page that
calls a web service on its own host times out. Run a second instance to answer
those calls:

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
  Model/                Facility, Event, EventInvite + mappers        (module 1)
                        Account -> User / FacilityOwner / Admin,
                        AuthEvent, PasswordResetToken + mappers       (module 2)
  Domain/               EventManagementFacade + its policies          (module 1)
                        AccountService, AccountServiceProxy,
                        ResetLinkDelivery                             (module 2)
                        PaymentService + Payment/ strategies           (module 4)
  Service/              IFA envelope, log, HTTP client, RemoteServices
                        ParticipationHistory                          (module 2)
  Security/             Auth, Csrf, Validator (shared)
                        EventFacilitySecurity                         (module 1)
                        PasswordPolicy, AuthEventLogger               (module 2)
  Controller/           Facility, Event                               (module 1)
                        Auth, Profile, Admin                          (module 2)
                        Payment                                       (module 4)
  views/
public/
  index.php             front controller
  payment.php           demo checkout, saved methods, history         (module 4)
  css/style.css         the one stylesheet every page loads
  js/app.js             venue preview, confirm prompts, role fields
  api/facility.php      exposed service, IFA in the file header       (module 1)
  api/event.php         exposed service, IFA in the file header       (module 1)
  api/payment.php       exposed service, IFA in the file header       (module 4)
  api/user.php          exposed service, IFA in the file header       (module 2)
  api/discovery.php     exposed service, IFA in the file header       (module 5)
  booking.php           stand-in for the booking and payment screen
storage/
  mail.log              where reset links are written, outside the web root
```

## Web services

Exposed, on `POST /api/facility.php` and `POST /api/event.php`:
`getFacilityDetails`, `searchFacilities`, `getEventDetails`,
`listUpcomingEvents`, `getEventsByFacility`. Full IFA tables are in each file's
header comment.

Venue Booking & Payment also exposes `POST /api/payment.php` in the same
RESTful JSON style as Event and Facility: one URL, operation chosen by
`function` in the body, IFA envelope (`requestId`, `timeStamp`, `status` S/F/E).
Functions: `getBookingStatus`, `getEventPaymentSummary`, `cancelEventPayments`
and `settleEventPayout`. The two mutation functions require the shared
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
curl -X POST http://localhost:8000/api/payment.php \
  -H 'Content-Type: application/json' \
  -d '{"requestId":"demo-pay-001","timeStamp":"2026-09-11 15:00:00",
       "function":"getBookingStatus","eventId":"evt-001"}'

curl -X POST http://localhost:8000/api/facility.php \
  -H 'Content-Type: application/json' \
  -d '{"requestId":"demo-001","timeStamp":"2026-08-25 14:30:00",
       "function":"getFacilityDetails","facilityId":"fac-001"}'
```

Exposed by module 2, on `POST /api/user.php`: `getUserContactInfo`,
`getUserProfile`, `accountExists`. Full IFA tables are in that file's header.

```
curl -X POST http://localhost:8000/api/user.php \
  -H 'Content-Type: application/json' \
  -d '{"requestId":"demo-002","timeStamp":"2026-09-08 14:30:00",
       "function":"getUserContactInfo","baseUserId":"own-001"}'
```

`getUserProfile` never returns an email or a phone number, returns an owner's
bank account number masked, and rounds a player's coordinates to two decimal
places — about a kilometre, enough to sort by distance and not enough to locate
a person.

Consumed, via `App\Service\PaymentRemoteServices` (module 4):

| Function | From | Used for |
|---|---|---|
| `getEventDetails` | Event & Facility | checkout quote, host and fee |
| `getFacilityDetails` | Event & Facility | venue hourly rate |

Consumed, via `App\Service\RemoteServices` (module 1):

| Function | From | Used for |
|---|---|---|
| `getUserContactInfo` | User Authentication | owner details at onboarding |
| `getBookingStatus` | Venue Booking & Payment | gating event publication |
| `getFacilityRatings` | Social Networking | ratings in facility search |
| `areFriends` | Social Networking | friends-only visibility |

Consumed, via `App\Service\ParticipationHistory` (module 2):

| Function | From | Used for |
|---|---|---|
| `getEventDetails` | Event & Facility | naming the events on a profile's history |

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

## Authentication & profiles (module 2)

**Signing in.** A wrong email and a wrong password produce the same message, and
an email that matches no account still spends the time a hash comparison would,
so the form answers nothing about who is registered. Five consecutive failures
lock the account for fifteen minutes; the counter only resets on a successful
sign-in, so spreading attempts out does not evade it. A correct password against
a locked account is told it is locked — you already had to know the password to
learn that.

**Passwords** are stored as bcrypt hashes (cost 12) and nowhere else. The column
is read by exactly one method, `AccountMapper::credentialsForEmail()`, which
returns a plain array — no entity ever holds a hash, so no view, no
`json_encode` and no `var_dump` can print one. Every rule about length, variety
and reuse lives in `Security/PasswordPolicy.php`, so registration, reset and
change cannot drift apart.

**Recovery.** The table stores the SHA-256 of the reset token, never the token,
so reading it gives nothing redeemable. A link works once, expires in thirty
minutes, and completing a reset invalidates every other outstanding link for
that account. Requests are capped at three per account per hour. There is no
mail server here, so `ResetLinkDelivery` appends the link to `storage/mail.log`
(outside the web root) and, on a debug build only, shows it on screen. Swapping
that class for a real mailer is the only change production needs.

**The audit trail.** Every sign-in, failed sign-in, lockout, password change,
profile edit, deactivation and refused access attempt is written to
`AuthEventLog` through one routine, `Security/AuthEventLogger.php`. It records
no password, no reset token and no session id — the log is evidence, not a
second copy of the credentials. `AuthEventMapper` has no `update()` and no
`delete()`. An administrator reads the whole log at **Accounts → Security log**;
everyone else sees only their own, on their profile.

**Access control.** `AccountServiceProxy` is a protection proxy in front of
`AccountService`: you may act on your own account, an administrator may act on
any account, and every other request is refused with one message whether the
account exists or not — and the refusal is logged. Controllers are handed the
proxy and never the real service, so the checks are not something a new screen
or endpoint can forget. Changing a password is stricter still — self only, and
the current password is required — so an administrator cannot take an account
over through that door.

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

## Not built yet

`public/booking.php` stands in for the Venue Booking & Payment booking screen.
It goes once that module is integrated — point `EventController::store()` at
theirs.

The `api/stub.php` stand-in is gone. Every service it answered for
(`getBookingStatus`, `getFacilityRatings`, `areFriends`, `getUserContactInfo`)
is now served by the real endpoint of the module that owns it.

The password-less account picker that used to stand in for authentication is
gone, replaced by the real sign-in. `?c=login` still redirects to it so older
links do not break.

**Known gap between modules 1 and 2.** A profile's participation history calls
`getEventDetails`, but `VisibilityPolicy` only makes `PUBLISHED` events visible,
so a past event — exactly what a history is made of — comes back refused and the
row reads "unavailable". The history degrades cleanly rather than erroring, but
it needs module 1 to agree that a user who registered for an event may still see
it after it completes. Not changed unilaterally, since the rule is module 1's.

`database/schema.sql` and `seed.sql` cover every table in the system, since the
schema is shared and this module needs the other tables present to run.

## Before submitting

- Add `storage/` to `.gitignore` if you do not want the demo mailbox committed.
- Author headers are done. 36 files module 1 owns are credited to Goh Jian Yu,
  and the files listed under module 2 above to Ivan Lim Tze Yang.
  The 22 shared files - anything another module would use unchanged - carry all
  five names: `app/Core`, `bootstrap.php`, `config.php`, `Enums.php`,
  `Exceptions.php`, `helpers.php`, `Security/Validator.php`, `Service/Ifa.php`,
  `Service/ServiceClient.php`, `Service/ServiceLog.php`, `views/layout.php`,
  `views/error.php`, `public/index.php`, the stylesheet, the script and the
  three files under `database/`.
- Rename the `sports_platform` database once the system has a title.
- Set `app.debug` to `false` in `config.php`.
