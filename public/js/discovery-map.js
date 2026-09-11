// Map of nearby games. Author: Ng Jing Siang
//
// This used to be an inline <script> inside app/views/discovery-map.php, with
// Leaflet pulled from unpkg.com. Both were blocked by the Content Security
// Policy set in app/bootstrap.php, which allows scripts and styles from 'self'
// only and permits no inline script at all, so the map never drew.
//
// Rather than punch a hole in the policy, the library now lives in
// public/vendor/leaflet/ and this file holds the code. The policy is unchanged
// and the map no longer depends on a CDN being reachable, which also means it
// still works when the demo machine is offline.
//
// The marker list arrives in a data attribute on the map container, the same way
// the venue list reaches app.js, so no PHP ends up inside a JavaScript file.

(function () {
    'use strict';

    function start() {
        var container = document.getElementById('discovery-map');

        if (!container || typeof L === 'undefined') {
            return;
        }

        var markers;

        try {
            markers = JSON.parse(container.getAttribute('data-markers') || '[]');
        } catch (error) {
            return;
        }

        var center = markers.length > 0
            ? [markers[0].lat, markers[0].lng]
            : [3.139, 101.6869];

        var map = L.map('discovery-map').setView(center, 12);

        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; OpenStreetMap contributors',
            maxZoom: 19
        }).addTo(map);

        // A pin is a venue, not a game. Several games at the same venue share
        // its coordinates exactly, so one marker each would stack them on the
        // same pixel and only the last one drawn could ever be clicked. Grouping
        // first means the pin opens with everything happening there.
        function groupByVenue(list) {
            var venues = [];
            var seen = {};

            list.forEach(function (m) {
                if (typeof m.lat !== 'number' || typeof m.lng !== 'number') {
                    return;
                }

                var key = m.lat + ',' + m.lng;

                if (!seen[key]) {
                    seen[key] = { lat: m.lat, lng: m.lng, venue: m.venue, events: [] };
                    venues.push(seen[key]);
                }

                seen[key].events.push(m);
            });

            return venues;
        }

        // Built as a DOM tree rather than an HTML string, so that a name typed
        // by an organiser can never become markup.
        function line(parent, text) {
            parent.appendChild(document.createTextNode(text));
            parent.appendChild(document.createElement('br'));
        }

        function popupFor(venue) {
            var wrap = document.createElement('div');

            var heading = document.createElement('strong');
            heading.textContent = venue.venue || 'This venue';
            wrap.appendChild(heading);
            wrap.appendChild(document.createElement('br'));

            var count = venue.events.length;
            line(wrap, count === 1 ? '1 game here' : count + ' games here');

            if (venue.events[0].distance !== null && venue.events[0].distance !== undefined) {
                line(wrap, venue.events[0].distance.toFixed(1) + ' km away');
            }

            var list = document.createElement('ul');
            list.className = 'map-popup-list';

            venue.events.forEach(function (m) {
                var item = document.createElement('li');

                var link = document.createElement('a');
                link.href = m.url;
                link.textContent = m.name === null || m.name === undefined ? 'Untitled game' : m.name;
                item.appendChild(link);
                item.appendChild(document.createElement('br'));

                var detail = [m.sport || '', (m.date || '') + ', ' + (m.time || '')].join(' · ');

                if (m.spaces !== null && m.spaces !== undefined) {
                    detail += ' · ' + m.spaces + ' spots left';
                }

                var small = document.createElement('span');
                small.className = 'map-popup-detail';
                small.textContent = detail;
                item.appendChild(small);

                list.appendChild(item);
            });

            wrap.appendChild(list);

            return wrap;
        }

        groupByVenue(markers).forEach(function (venue) {
            L.marker([venue.lat, venue.lng]).addTo(map).bindPopup(popupFor(venue));
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
})();
