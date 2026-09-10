<?php
// Facility onboarding and management. Author: Goh Jian Yu

namespace App\Controller;

use App\Core\Controller;
use App\Domain\EventManagementFacade;
use App\Domain\FacilityImage;
use App\Domain\Geocoder;
use App\Domain\Postcodes;
use App\Domain\SearchCriteria;
use App\Domain\Sports;
use App\Security\Auth;
use App\Security\EventFacilitySecurity;
use App\Security\Validator;
use App\ValidationException;
use DateTimeImmutable;

final class FacilityController extends Controller
{
    private EventManagementFacade $facade;

    public function __construct()
    {
        $this->facade = new EventManagementFacade();
    }

    public function show(): void
    {
        $facilityId = $this->queryId();

        if ($facilityId === null) {
            $this->redirect(url('discovery')); // js part - was url('event')
        }

        $facility = $this->facade->getFacility($facilityId);
        $date     = $this->requestedDate();

        // The rating belongs to the Social Networking & Review module, so it is
        // asked for over their web service. Null means they have no score yet.
        $ratings = $this->facade->ratingsFor([$facility]);

        $this->view('facility-show', [
            'title'    => $facility->getName(),
            'facility' => $facility,
            'rating'   => $ratings[$facilityId] ?? null,
            'events'   => $this->facade->listEventsAtFacility($facilityId),
            'slots'    => $this->facade->findOpenSlots($facilityId, $date, 2),
            'slotDate' => $date,
        ]);
    }

    // Venue search for organisers. The filtering and sorting already existed for
    // the web service the Discovery module calls; this is the same search with a
    // page in front of it.
    public function search(): void
    {
        $criteria   = SearchCriteria::fromArray($_GET);
        $facilities = $this->facade->searchFacilities($criteria);

        $this->view('facility-search', [
            'title'      => 'Find a venue',
            'facilities' => $facilities,
            'ratings'    => $this->facade->ratingsFor($facilities),
            'criteria'   => $criteria,
            'cities'     => $this->facade->listCities(),
            'sports'     => $this->facade->listTypes(),
            'input'      => $_GET,
        ]);
    }

    public function mine(): void
    {
        Auth::requireFacilityOwner();

        $facilities = $this->facade->listMyFacilities();

        // Worked out per venue so the list can offer Delete only where it would
        // really delete. A venue with games or reviews behind it can only be
        // delisted, and there is already a button for that.
        $deletable = [];

        foreach ($facilities as $facility) {
            $id = (string) $facility->getFacilityId();
            $deletable[$id] = $this->facade->canDeleteFacility($id);
        }

        $this->view('facility-list', [
            'title'      => 'My venues',
            'facilities' => $facilities,
            'deletable'  => $deletable,
        ]);
    }

    public function create(): void
    {
        Auth::requireFacilityOwner();

        $this->view('facility-form', [
            'title'    => 'Register a venue',
            'facility' => null,
            'input'    => [],
            'errors'   => [],
            'sports'   => Sports::ALL,
        ]);
    }

    public function store(): void
    {
        $this->requirePostWithCsrf();

        // Checked here and not only inside the facade, because validated() saves
        // the uploaded photo. Without this, somebody who is not a venue owner
        // could write a file to the server and only then be turned away.
        Auth::requireFacilityOwner();

        try {
            $facility = $this->facade->onboardFacility($this->validated($_POST));

            $this->flash('success', $facility->getName() . ' has been submitted and is waiting for approval.');
            $this->redirect(url('facility', 'mine'));
        } catch (ValidationException $e) {
            $this->view('facility-form', [
                'title'    => 'Register a venue',
                'facility' => null,
                'input'    => $_POST,
                'errors'   => $e->getErrors(),
                'sports'   => Sports::ALL,
                ]);
        }
    }

    public function edit(): void
    {
        $facilityId = $this->queryId();

        if ($facilityId === null) {
            $this->redirect(url('facility', 'mine'));
        }

        $facility = $this->facade->getFacility($facilityId);

        EventFacilitySecurity::assertOwnsFacility($facility);

        $this->view('facility-form', [
            'title'    => 'Edit ' . $facility->getName(),
            'facility' => $facility,
            'input'    => [],
            'errors'   => [],
            'sports'   => Sports::ALL,
        ]);
    }

    public function update(): void
    {
        $this->requirePostWithCsrf();

        $facilityId = $_POST['facilityId'] ?? '';

        if (!is_string($facilityId) || $facilityId === '') {
            $this->redirect(url('facility', 'mine'));
        }

        try {
            // Ownership first, for the same reason as store(): validated() saves
            // the uploaded photo, so it must not run for somebody else's venue.
            $facility = $this->facade->getFacility($facilityId);
            EventFacilitySecurity::assertOwnsFacility($facility);

            // Copied out as a string before the update, because the entity is
            // about to be changed in place.
            $oldImage = $facility->getImageUrl();

            $facility = $this->facade->updateFacility($facilityId, $this->validated($_POST));

            // A replaced photo would otherwise sit in the uploads folder for
            // ever with nothing pointing at it.
            if ($facility->getImageUrl() !== $oldImage) {
                FacilityImage::remove($oldImage);
            }

            $this->flash('success', $facility->getName() . ' has been updated.');
            $this->redirect(url('facility', 'mine'));
        } catch (ValidationException $e) {
            $this->view('facility-form', [
                'title'    => 'Edit venue',
                'facility' => $this->facade->getFacility($facilityId),
                'input'    => $_POST,
                'errors'   => $e->getErrors(),
                'sports'   => Sports::ALL,
                ]);
        }
    }

    public function suspend(): void
    {
        $this->requirePostWithCsrf();

        $facility = $this->facade->suspendFacility((string) ($_POST['facilityId'] ?? ''));

        $this->flash('success', $facility->getName() . ' has been delisted. Existing bookings are unaffected.');
        $this->redirect(url('facility', 'mine'));
    }

    public function reactivate(): void
    {
        $this->requirePostWithCsrf();

        $facility = $this->facade->reactivateFacility((string) ($_POST['facilityId'] ?? ''));

        $this->flash('success', $facility->getName() . ' is listed again.');
        $this->redirect(url('facility', 'mine'));
    }

    public function delete(): void
    {
        $this->requirePostWithCsrf();

        $outcome = $this->facade->removeFacility((string) ($_POST['facilityId'] ?? ''));

        if ($outcome === 'DELETED') {
            $this->flash('success', 'The venue has been deleted.');
        } else {
            // Not reachable from the venue list any more, which only offers
            // Delete when it can delete. Kept for a request that arrives some
            // other way, and reported as a notice rather than a success,
            // because the owner did not get what they asked for.
            $this->flash('notice', 'This venue has games or reviews against it, so it was delisted '
                                 . 'instead of deleted. Its history is intact.');
        }

        $this->redirect(url('facility', 'mine'));
    }

    private function validated(array $input): array
    {
        $clean = (new Validator($input))
            ->required('name', 'Venue name')->text('name', 'Venue name', 2, 150)
            ->required('addressLine', 'Address')->text('addressLine', 'Address', 5, 255)
                ->address('addressLine', 'Address')
            ->required('postcode', 'Postcode')->postcode('postcode', 'Postcode')
            ->required('city', 'City')->text('city', 'City', 2, 100)
                ->placeName('city', 'City')
            // The venue's sport. Chosen from a list rather than typed, because every
            // event held here takes its sport from this field.
            ->required('type', 'Sport')->inList('type', 'Sport', Sports::ALL)
            ->required('bookingFee', 'Booking fee')->decimal('bookingFee', 'Booking fee', 0, 99999.99)
            // No check that closing is after opening. Plenty of venues run past
            // midnight, eg open 22:00 and close 02:00, so both orders are valid.
            ->required('operationalHrsStart', 'Opening time')->time('operationalHrsStart', 'Opening time')
            ->required('operationalHrsEnd', 'Closing time')->time('operationalHrsEnd', 'Closing time')

            // Not required. Most owners have no idea what their latitude is, so
            // the coordinates are worked out from the address below and these
            // two are only for an owner who wants to correct the pin.
            ->latitude('latitude', 'Latitude')
            ->longitude('longitude', 'Longitude')
            ->validate();

        // The state is never asked for. Pos Malaysia allocates postcodes by
        // state, so the postcode the owner already gave says which state this
        // is. Deriving it means a Kedah address can no longer be filed under
        // Terengganu, and it checks the postcode at the same time, because five
        // digits outside every range are not a Malaysian postcode.
        if (isset($clean['postcode'])) {
            $state = Postcodes::stateFor($clean['postcode']);

            if ($state === null) {
                throw new ValidationException([
                    'postcode' => 'That postcode does not belong to any Malaysian state. '
                                . 'Please check it.',
                ]);
            }

            $clean['state'] = $state;
        }

        if (!isset($clean['latitude']) || !isset($clean['longitude'])) {
            [$clean['latitude'], $clean['longitude']] = Geocoder::locate($clean['city'], $clean['state']);
        }

        // A new photo replaces whatever was there. No file means keep the old
        // one, so imageUrl is left out of the array entirely and updateDetails()
        // will not touch it.
        $image = FacilityImage::save($_FILES['image'] ?? null);

        if ($image !== null) {
            $clean['imageUrl'] = $image;
        }

        return $clean;
    }

    private function requestedDate(): DateTimeImmutable
    {
        $raw = $_GET['date'] ?? null;

        if (is_string($raw) && $raw !== '') {
            $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $raw);

            if ($parsed !== false) {
                return $parsed;
            }
        }

        return new DateTimeImmutable('today');
    }
}
