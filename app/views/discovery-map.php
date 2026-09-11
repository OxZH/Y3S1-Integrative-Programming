<?php
// Map page (Leaflet + OpenStreetMap). Author: Ng Jing Siang

use App\Domain\Discovery\EventFeedItem;

/** @var EventFeedItem[] $markers */

// only venue coordinates go to the browser, never the user's own location
$markerData = array_map(static fn (EventFeedItem $m): array => [
    'id'       => $m->eventId,
    'name'     => $m->name,
    'venue'    => $m->venueName,
    'sport'    => $m->sport,
    'date'     => $m->eventDate,
    'time'     => hhmm($m->startTime),
    'lat'      => $m->latitude,
    'lng'      => $m->longitude,
    'spaces'   => $m->spacesLeft,
    'distance' => $m->distanceKm,
    'url'      => url('event', 'show', ['id' => $m->eventId]),
], $markers);
?>
<h1>Map</h1>
<p class="lede">Nearby games and venues. Only venue locations are ever shown here - never a member's own address.</p>

<?php // markers passed via data attribute, CSP does not allow inline scripts ?>
<div id="discovery-map" class="map-canvas"
     data-markers="<?= e(json_encode($markerData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?>"></div>

<?php // Leaflet is served locally (CSP only allows 'self') ?>
<link rel="stylesheet" href="<?= e(asset('vendor/leaflet/leaflet.css')) ?>">
<script src="<?= e(asset('vendor/leaflet/leaflet.js')) ?>"></script>
<script src="<?= e(asset('js/discovery-map.js')) ?>" defer></script>
