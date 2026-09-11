<?php
// Interactive mapping via OpenStreetMap/Leaflet. Author: Ng Jing Siang

use App\Domain\Discovery\EventFeedItem;

/** @var EventFeedItem[] $markers */

// Only venue/event coordinates - which are already public information - ever go
// into this payload. A user's own location is never sent to the browser as raw
// latitude/longitude; see App\Domain\DiscoveryFacade::distanceTo(), which turns
// it into a rounded km figure server-side and discards the coordinates.
$markerData = array_map(static fn (EventFeedItem $m): array => [
    'id'       => $m->eventId,
    'name'     => $m->name,
    // The pin is the venue, so its name titles the popup and the games at it
    // are listed underneath.
    'venue'    => $m->venueName,
    'sport'    => $m->sport,
    'date'     => $m->eventDate,
    'time'     => hhmm($m->startTime),
    'lat'      => $m->latitude,
    'lng'      => $m->longitude,
    'spaces'   => $m->spacesLeft,
    'distance' => $m->distanceKm,
    // A pin answers "where"; the link is how the reader gets to "what and who".
    // Event & Facility Management decides what that page then shows them.
    'url'      => url('event', 'show', ['id' => $m->eventId]),
], $markers);
?>
<h1>Map</h1>
<p class="lede">Nearby games and venues. Only venue locations are ever shown here - never a member's own address.</p>

<?php
// The marker list travels in a data attribute rather than an inline script,
// because the Content Security Policy allows no inline script and because it
// keeps PHP out of public/js/discovery-map.js.
?>
<div id="discovery-map" class="map-canvas"
     data-markers="<?= e(json_encode($markerData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?>"></div>

<?php
// Leaflet is served from this application rather than from unpkg.com. The
// policy allows scripts and styles from 'self' only, and vendoring the library
// keeps it that way instead of allowlisting a CDN. It also means the map still
// works on a machine with no internet connection.
?>
<link rel="stylesheet" href="<?= e(asset('vendor/leaflet/leaflet.css')) ?>">
<script src="<?= e(asset('vendor/leaflet/leaflet.js')) ?>"></script>
<script src="<?= e(asset('js/discovery-map.js')) ?>" defer></script>
