<?php
// Facility onboarding and management. Author: Goh Jian Yu

declare(strict_types=1);

namespace App\Controller;

use App\Core\Controller;
use App\Domain\EventManagementFacade;
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
            $this->redirect(url('event'));
        }

        $facility = $this->facade->getFacility($facilityId);
        $date     = $this->requestedDate();

        $this->view('facility-show', [
            'title'    => $facility->getName(),
            'facility' => $facility,
            'events'   => $this->facade->listEventsAtFacility($facilityId),
            'slots'    => $this->facade->findOpenSlots($facilityId, $date, 2),
            'slotDate' => $date,
        ]);
    }

    public function mine(): void
    {
        Auth::requireFacilityOwner();

        $this->view('facility-list', [
            'title'      => 'My venues',
            'facilities' => $this->facade->listMyFacilities(),
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
        ]);
    }

    public function store(): void
    {
        $this->requirePostWithCsrf();

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
            $facility = $this->facade->updateFacility($facilityId, $this->validated($_POST));

            $this->flash('success', $facility->getName() . ' has been updated.');
            $this->redirect(url('facility', 'mine'));
        } catch (ValidationException $e) {
            $this->view('facility-form', [
                'title'    => 'Edit venue',
                'facility' => $this->facade->getFacility($facilityId),
                'input'    => $_POST,
                'errors'   => $e->getErrors(),
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

        $this->flash('success', $outcome === 'DELETED'
            ? 'The venue has been deleted.'
            : 'This venue already has events or reviews against it, so it has been delisted rather than deleted.');

        $this->redirect(url('facility', 'mine'));
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    private function validated(array $input): array
    {
        return (new Validator($input))
            ->required('name', 'Venue name')->text('name', 'Venue name', 2, 150)
            ->required('addressLine', 'Address')->text('addressLine', 'Address', 5, 255)
            ->required('city', 'City')->text('city', 'City', 2, 100)
            ->required('state', 'State')->text('state', 'State', 2, 100)
            ->required('type', 'Venue type')->text('type', 'Venue type', 2, 50)
            ->required('bookingFee', 'Booking fee')->decimal('bookingFee', 'Booking fee', 0, 99999.99)
            ->required('operationalHrsStart', 'Opening time')->time('operationalHrsStart', 'Opening time')
            ->required('operationalHrsEnd', 'Closing time')->time('operationalHrsEnd', 'Closing time')
            ->timeAfter('operationalHrsStart', 'operationalHrsEnd', 'Closing time')
            ->required('latitude', 'Latitude')->latitude('latitude', 'Latitude')
            ->required('longitude', 'Longitude')->longitude('longitude', 'Longitude')
            ->imageUrl('imageUrl', 'Image address')
            ->validate();
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
