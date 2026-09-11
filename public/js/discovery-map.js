// Map of nearby games. Author: Ng Jing Siang
//
// Leaflet is loaded from public/vendor/leaflet/ (the CSP does not allow CDNs
// or inline scripts). Marker data comes from the data-markers attribute.

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

        // group games by venue, otherwise pins at the same venue would overlap
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

        // build with DOM methods (not innerHTML) so event names can't inject HTML
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
