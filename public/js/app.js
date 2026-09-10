/* ==========================================================================
   Sports Platform - shared behaviour
   Author: Goh Jian Yu, Ooi Kean Wei, Ng Jing Siang, Khor Zhi Hong, Ivan Lim Tze Yang

   Loaded by every page. Nothing here is required for a page to work: forms
   submit and links follow without it. It only adds the venue preview and the
   confirmation prompts.
   ========================================================================== */

(function () {
    'use strict';

    /* ----------------------------------------------------------------------
       Confirm before a destructive submit.

       Written as one delegated listener rather than an onsubmit attribute on
       each form, so the markup stays free of JavaScript and any new form only
       needs data-confirm="...".
       ---------------------------------------------------------------------- */

    document.addEventListener('submit', function (event) {
        var form = event.target;

        if (!form || !form.hasAttribute('data-confirm')) {
            return;
        }

        if (!window.confirm(form.getAttribute('data-confirm'))) {
            event.preventDefault();
        }
    });


    /* ----------------------------------------------------------------------
       Venue preview on the event form.

       A <select> can hold text and nothing else, so the image and rating for
       the chosen venue are shown in a panel underneath it. The venue details
       are read from the data-venues attribute the view writes out, which keeps
       PHP out of this file.
       ---------------------------------------------------------------------- */

    function setUpVenuePreview() {
        var select = document.getElementById('facilityId');
        var panel  = document.getElementById('venuePreview');

        if (!select || !panel) {
            return;
        }

        var venues;

        try {
            venues = JSON.parse(select.getAttribute('data-venues') || '{}');
        } catch (error) {
            return;
        }

        var image  = panel.querySelector('[data-field="image"]');
        var link   = panel.querySelector('[data-field="link"]');
        var sport  = document.getElementById('sportShown');
        var fields = ['name', 'address', 'rating', 'fee', 'hours'];

        function render() {
            var venue = venues[select.value];

            if (!venue) {
                panel.hidden = true;

                // The sport belongs to the venue, so with no venue there is
                // nothing to show.
                if (sport) {
                    sport.value = '';
                }

                return;
            }

            if (sport) {
                sport.value = venue.sport || '';
            }

            fields.forEach(function (field) {
                var node = panel.querySelector('[data-field="' + field + '"]');

                if (node) {
                    // textContent, not innerHTML: a venue name is user supplied.
                    node.textContent = venue[field] || '';
                }
            });

            if (link) {
                link.setAttribute('href', venue.url || '#');
            }

            if (image) {
                if (venue.image) {
                    image.setAttribute('src', venue.image);
                    image.setAttribute('alt', venue.name || '');
                    image.hidden = false;
                } else {
                    image.hidden = true;
                }
            }

            panel.hidden = false;
        }

        select.addEventListener('change', render);

        // A venue may already be chosen, either pre-selected from a venue page
        // or kept after a failed save.
        render();
    }

    /* ----------------------------------------------------------------------
       Open the browser's own date and time picker.

       type="date" and type="time" already come with a picker, but only the
       small icon opens it - clicking the rest of the box puts the cursor in it
       to type. Clicking anywhere on the field now opens the picker instead.
       showPicker() is newer than the rest of this file, so it is only called
       when the browser has it. Typing still works either way.
       ---------------------------------------------------------------------- */

    function setUpPickers() {
        var fields = document.querySelectorAll('input[type="date"], input[type="time"]');

        Array.prototype.forEach.call(fields, function (field) {
            field.addEventListener('click', function () {
                if (typeof field.showPicker === 'function') {
                    try {
                        field.showPicker();
                    } catch (error) {
                        // Some browsers refuse unless the click was on the icon.
                    }
                }
            });
        });
    }

    /* ----------------------------------------------------------------------
       Role fields on the registration form.  (Module 2 - Ivan)

       A player and a facility owner need different questions, so only the block
       matching the chosen account type is shown. This is convenience only: the
       server decides which fields it will accept from the submitted userType,
       so hiding a block here is not what keeps a player from sending bank
       details, and unhiding one in the browser achieves nothing.
       ---------------------------------------------------------------------- */

    function setUpRoleFields() {
        var select = document.querySelector('[data-role-toggle]');
        var blocks = document.querySelectorAll('[data-role-fields]');

        if (!select || blocks.length === 0) {
            return;
        }

        function render() {
            Array.prototype.forEach.call(blocks, function (block) {
                block.hidden = block.getAttribute('data-role-fields') !== select.value;
            });
        }

        select.addEventListener('change', render);

        // Runs once on load so the correct block is showing after a failed save.
        render();
    }

    /* ----------------------------------------------------------------------
       Grey out the times that cannot be booked.

       Which half hours are free depends on the venue and the date together, so
       it cannot be worked out until both have been chosen. Rather than asking
       the server on every change, the page arrives with the venue opening hours
       and the slots already taken, and the work happens here. Those lists stay
       small, because there is one entry per booking rather than per half hour.

       All of this is convenience. AvailabilityChecker and Validator check the
       opening hours, the clashes and the past again on the server, so a
       tampered dropdown gains nothing.
       ---------------------------------------------------------------------- */

    function setUpSlotGuard() {
        var venueSelect = document.getElementById('facilityId');
        var dateInput   = document.getElementById('eventDate');
        var startSelect = document.getElementById('startTime');
        var endSelect   = document.getElementById('endTime');

        if (!venueSelect || !dateInput || !startSelect || !endSelect) {
            return;
        }

        var venues = {};
        var busy   = [];

        try {
            venues = JSON.parse(venueSelect.getAttribute('data-venues') || '{}');
            busy   = JSON.parse(startSelect.getAttribute('data-busy') || '[]');
        } catch (error) {
            return;
        }

        // "14:30" or "14:30:00" as minutes since midnight, so every comparison
        // below is plain arithmetic.
        function toMinutes(text) {
            var parts = String(text).split(':');

            return (parseInt(parts[0], 10) * 60) + parseInt(parts[1], 10);
        }

        function openWindows() {
            var venue = venues[venueSelect.value];

            if (!venue || !venue.opens || !venue.closes) {
                return [];
            }

            var opens  = toMinutes(venue.opens);
            var closes = toMinutes(venue.closes);

            // A venue closing after midnight runs in two stretches. An event
            // never crosses midnight, so it has to fit inside one of them.
            if (closes > opens) {
                return [[opens, closes]];
            }

            return [[opens, 24 * 60], [0, closes]];
        }

        function insideOpeningHours(from, to) {
            var windows = openWindows();

            for (var i = 0; i < windows.length; i++) {
                if (from >= windows[i][0] && to <= windows[i][1]) {
                    return true;
                }
            }

            return false;
        }

        function clashes(from, to) {
            for (var i = 0; i < busy.length; i++) {
                var taken = busy[i];

                if (taken.facilityId !== venueSelect.value) {
                    continue;
                }

                if (String(taken.eventDate).slice(0, 10) !== dateInput.value) {
                    continue;
                }

                // Two slots overlap when each starts before the other ends, so
                // a court handed over exactly on the hour is not a clash.
                if (from < toMinutes(taken.endTime) && to > toMinutes(taken.startTime)) {
                    return true;
                }
            }

            return false;
        }

        // Nothing earlier than now, but only when the chosen day is today.
        function earliestAllowed() {
            var now   = new Date();
            var today = now.getFullYear() + '-'
                      + ('0' + (now.getMonth() + 1)).slice(-2) + '-'
                      + ('0' + now.getDate()).slice(-2);

            if (dateInput.value !== today) {
                return 0;
            }

            return (now.getHours() * 60) + now.getMinutes();
        }

        function refresh() {
            var ready  = venueSelect.value !== '' && dateInput.value !== '';
            var floor  = earliestAllowed();
            var chosen = startSelect.value === '' ? null : toMinutes(startSelect.value);

            Array.prototype.forEach.call(startSelect.options, function (option) {
                if (option.value === '') {
                    return;
                }

                var from = toMinutes(option.value);

                // A start is offered only when at least one half hour is free
                // from it, because nothing shorter can be booked.
                option.disabled = !ready
                    || from < floor
                    || !insideOpeningHours(from, from + 30)
                    || clashes(from, from + 30);
            });

            Array.prototype.forEach.call(endSelect.options, function (option) {
                if (option.value === '') {
                    return;
                }

                var to = toMinutes(option.value);

                option.disabled = !ready
                    || chosen === null
                    || to <= chosen
                    || !insideOpeningHours(chosen, to)
                    || clashes(chosen, to);
            });

            // A time chosen before the venue or the date changed may no longer
            // be on offer, so it is cleared instead of left sitting there.
            if (startSelect.selectedIndex > -1 && startSelect.options[startSelect.selectedIndex].disabled) {
                startSelect.value = '';
            }

            if (endSelect.selectedIndex > -1 && endSelect.options[endSelect.selectedIndex].disabled) {
                endSelect.value = '';
            }
        }

        venueSelect.addEventListener('change', refresh);
        dateInput.addEventListener('change', refresh);
        startSelect.addEventListener('change', refresh);

        refresh();
    }

    function start() {
        setUpVenuePreview();
        setUpPickers();
        setUpRoleFields();
        setUpSlotGuard();
        setUpNoCopyFields();
        setUpPasswordReveal();
        setUpDropzones();
        setUpLocationConfirm();
        setUpPastTimeGuard();

        var boxes = document.querySelectorAll('[data-suggest]');

        Array.prototype.forEach.call(boxes, setUpSuggestions);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
}());
