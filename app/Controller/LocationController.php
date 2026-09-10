<?php
// Address lookup for the account forms. Author: Ivan Lim Tze Yang

declare(strict_types=1);

namespace App\Controller;

use App\Core\Controller;
use App\Domain\AddressGeocoder;
use App\Security\Validator;
use App\ValidationException;
use Throwable;

/**
 * Answers "where is this address?" for the registration and profile forms, so
 * the page can show the player what was found and ask them to confirm it before
 * the form is submitted.
 *
 * It deliberately does NOT save anything, and the coordinates it returns are
 * for display only. When the form is finally submitted the server geocodes the
 * address again itself and stores that result - so a crafted POST cannot put a
 * player wherever it likes by sending its own numbers. See
 * AccountService::applyGeocodedCoordinates().
 *
 * POST with a CSRF token, like every other write-shaped request here. Not
 * because this changes anything, but because it spends an outbound call to
 * OpenStreetMap, and an endpoint that does that should only answer our pages.
 */
final class LocationController extends Controller
{
    public function lookup(): void
    {
        $this->requirePostWithCsrf();

        try {
            // Deliberately text(), not address(). address() enforces the strict
            // "unit, area, postcode" shape the venue form needs; a player is
            // allowed to type just "Setapak, Kuala Lumpur". This has to accept
            // exactly what the profile and registration forms accept, or the
            // lookup would refuse an address the form will happily save.
            $validated = (new Validator($_POST))
                ->required('location', 'Location')
                ->text('location', 'Location', 2, 255)
                ->validate();
        } catch (ValidationException $e) {
            $this->json(['found' => false, 'message' => 'Please enter a location first.']);
        }

        try {
            $match = (new AddressGeocoder())->describe((string) $validated['location']);
        } catch (Throwable $e) {
            // Geocoding is a convenience. If OpenStreetMap is down the form
            // must still be usable, so the page is told to carry on.
            error_log('Location lookup failed: ' . $e->getMessage());

            $this->json(['found' => false, 'message' => 'Could not check that address just now. You can still save it.']);
        }

        if ($match === null) {
            $this->json([
                'found'   => false,
                'message' => 'That address could not be found on the map. You can still save it, '
                           . 'but events will not be sorted by how far away they are.',
            ]);
        }

        // Only the label goes back. The coordinates stay on the server: the
        // player never sees or edits them, they are only ever used to work out
        // how far away an event is.
        $this->json([
            'found'       => true,
            'label'       => $match['label'],
            'approximate' => $match['label'] === null,
        ]);
    }

    /** @param array<string,mixed> $payload */
    private function json(array $payload): never
    {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: no-store');
        }

        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        exit;
    }
}
