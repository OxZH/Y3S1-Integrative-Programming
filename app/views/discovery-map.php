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

<div id="discovery-map" style="height:520px;border-radius:12px;"></div>

<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
(function () {
    var markers = <?= json_encode($markerData, JSON_UNESCAPED_SLASHES) ?>;
    var center = markers.length > 0 ? [markers[0].lat, markers[0].lng] : [3.139, 101.6869];

    var map = L.map('discovery-map').setView(center, 12);

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; OpenStreetMap contributors',
        maxZoom: 19
    }).addTo(map);

    // An event name is whatever its organiser typed, and this popup is built as
    // HTML, so every value is escaped on the way in.
    function esc(value) {
        return String(value === null || value === undefined ? '' : value)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    markers.forEach(function (m) {
        if (typeof m.lat !== 'number' || typeof m.lng !== 'number') {
            return;
        }

        var distance = m.distance !== null ? m.distance.toFixed(1) + ' km away' : '';

        L.marker([m.lat, m.lng]).addTo(map).bindPopup(
            '<strong>' + esc(m.name) + '</strong><br>' +
            esc(m.sport) + ' &middot; ' + esc(m.date) + ', ' + esc(m.time) + '<br>' +
            (m.spaces !== null ? esc(m.spaces) + ' spots left' : '') +
            (distance ? '<br>' + esc(distance) : '') +
            '<br><a href="' + esc(m.url) + '">View event</a>'
        );
    });
})();
</script>
