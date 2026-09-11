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
        var panel = document.getElementById('venuePreview');

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
       Payment checkout method fields.  (Module 4 - ZH)

       Card, FPX and e-wallet ask for different details. Only the block for the
       chosen radio is shown. Saved methods fill those fields from a data
       attribute on the option. Convenience only: the server validates the
       posted method through the matching strategy, so hiding a block here is
       not what keeps a card number off an FPX payment.
       ---------------------------------------------------------------------- */

    function setUpPaymentCheckout() {
        var form = document.querySelector('[data-payment-checkout]');

        if (!form) {
            return;
        }

        var radios = form.querySelectorAll('input[name="paymentMethod"]');
        var panels = form.querySelectorAll('[data-method-fields]');
        var saved = form.querySelector('[data-saved-methods]');

        function selectedCode() {
            var checked = form.querySelector('input[name="paymentMethod"]:checked');

            return checked ? checked.value : '';
        }

        function showPanels() {
            var code = selectedCode();

            Array.prototype.forEach.call(panels, function (panel) {
                panel.hidden = panel.getAttribute('data-method-fields') !== code;
            });
        }

        function fillFrom(data) {
            [
                'holderName', 'cardNumber', 'expiryMonth', 'expiryYear',
                'bankName', 'accountHolder', 'accountNumber',
                'walletProvider', 'walletAccount'
            ].forEach(function (name) {
                var field = form.elements[name];

                if (field && Object.prototype.hasOwnProperty.call(data, name)) {
                    field.value = data[name] || '';
                }
            });

            if (form.elements.cvv) {
                form.elements.cvv.value = '';
            }
        }

        Array.prototype.forEach.call(radios, function (radio) {
            radio.addEventListener('change', function () {
                if (saved) {
                    var option = saved.options[saved.selectedIndex];
                    var savedMethod = option ? option.getAttribute('data-method') : '';

                    if (saved.value !== '' && savedMethod !== radio.value) {
                        saved.value = '';
                    }
                }

                showPanels();
            });
        });

        if (saved) {
            saved.addEventListener('change', function () {
                var option = saved.options[saved.selectedIndex];
                var method = option ? option.getAttribute('data-method') || '' : '';
                var payload = {};

                try {
                    payload = JSON.parse(option && option.getAttribute('data-autofill') || '{}') || {};
                } catch (error) {
                    payload = {};
                }

                if (method) {
                    var match = form.querySelector('input[name="paymentMethod"][value="' + method + '"]');

                    if (match) {
                        match.checked = true;
                    }
                }

                fillFrom(payload);
                showPanels();
            });
        }

        showPanels();
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

    /* ----------------------------------------------------------------------
       Fill in the town and the state from the postcode.

       Pos Malaysia gives every postcode one post town in one state, so the
       owner types five digits and both boxes fill themselves. They are
       read-only, and nothing in them is submitted.

       The list is fetched from public/data/postcodes.json, which is the very
       file FacilityController reads. One copy means the box on the form and the
       row in the database can never disagree about what a postcode means. It is
       a static file rather than a lookup endpoint, so the browser caches it and
       asks for it once.

       None of this is a check. The server looks the postcode up again and
       ignores whatever the browser sends for city and state, so a tampered
       field has nothing to tamper with.
       ---------------------------------------------------------------------- */

    function setUpStateFromPostcode() {
        var postcode = document.getElementById('postcode');
        var cityBox  = document.getElementById('cityShown');
        var stateBox = document.getElementById('stateShown');

        if (!postcode || !cityBox || !stateBox) {
            return;
        }

        var source = postcode.getAttribute('data-postcode-source');

        if (!source) {
            return;
        }

        var places = null;
        var asked  = false;

        function show(city, state) {
            cityBox.value  = city;
            stateBox.value = state;
        }

        function refresh() {
            var typed = String(postcode.value).replace(/\D/g, '');

            // Nothing to say until all five digits are in, or the boxes would
            // read "not found" while somebody is still typing.
            if (typed.length < 5) {
                show('', '');

                return;
            }

            if (places === null) {
                // The file is still on its way. refresh() runs again when it
                // lands, so this only means "not yet".
                show('Looking up…', '');

                return;
            }

            var found = Object.prototype.hasOwnProperty.call(places, typed) ? places[typed] : null;

            if (found === null) {
                show('No such postcode', '');

                return;
            }

            show(found[0], found[1]);
        }

        function load() {
            if (asked) {
                return;
            }

            asked = true;

            window.fetch(source, { credentials: 'same-origin' })
                .then(function (response) {
                    return response.ok ? response.json() : null;
                })
                .then(function (rows) {
                    places = rows || {};
                    refresh();
                })
                .catch(function () {
                    // The server still fills both in on save, so a failed
                    // fetch costs the preview and nothing else.
                    places = {};
                    show('', '');
                });
        }

        postcode.addEventListener('input', function () {
            load();
            refresh();
        });

        // A postcode may already be filled in, on the edit form or after a save
        // that failed, in which case the boxes beside it are already correct
        // and only need refreshing once the list arrives.
        if (String(postcode.value).replace(/\D/g, '').length === 5) {
            load();
        }
    }

    function setUpNoCopyFields() {
        var fields = document.querySelectorAll('[data-no-copy]');

        Array.prototype.forEach.call(fields, function (field) {
            ['copy', 'cut', 'dragstart'].forEach(function (event) {
                field.addEventListener(event, function (e) {
                    e.preventDefault();
                });
            });
        });
    }


    /* ----------------------------------------------------------------------
       Drag a picture onto the box, or click it to pick one.  (Module 2 - Ivan)

       The file input inside is the real control and is what gets submitted -
       dropping a file just assigns it to that input. So with JavaScript off
       the box is still a working file picker, and the server sees exactly the
       same request either way.

       Nothing here validates the file. The browser cannot be trusted about
       what a file contains, so the type and size checks live in
       App\Domain\ProfileImage, which reads the image header itself. The
       preview below is only a courtesy.
       ---------------------------------------------------------------------- */

    function setUpDropzones() {
        var zones = document.querySelectorAll('[data-dropzone]');

        Array.prototype.forEach.call(zones, function (zone) {
            var input = document.getElementById(zone.getAttribute('data-dropzone'));

            if (!input) {
                return;
            }

            var preview = document.getElementById(input.id + 'Preview');
            var hint    = document.getElementById(input.id + 'Hint');

            function showChosen(file) {
                if (hint && file) {
                    hint.textContent = file.name;
                }

                if (preview && file && window.FileReader) {
                    var reader = new FileReader();

                    reader.onload = function (event) {
                        preview.src = event.target.result;
                        preview.classList.remove('is-hidden');
                    };

                    reader.readAsDataURL(file);
                }
            }

            // Clicking anywhere on the box opens the picker - except when the
            // click was already on the input itself, which would loop.
            zone.addEventListener('click', function (event) {
                if (event.target !== input) {
                    input.click();
                }
            });

            input.addEventListener('change', function () {
                showChosen(input.files && input.files[0]);
            });

            // Without preventDefault on both, the browser navigates away to
            // open the dropped file instead of letting the page have it.
            ['dragenter', 'dragover'].forEach(function (name) {
                zone.addEventListener(name, function (event) {
                    event.preventDefault();
                    zone.classList.add('is-dragging');
                });
            });

            ['dragleave', 'dragend'].forEach(function (name) {
                zone.addEventListener(name, function () {
                    zone.classList.remove('is-dragging');
                });
            });

            zone.addEventListener('drop', function (event) {
                event.preventDefault();
                zone.classList.remove('is-dragging');

                var dropped = event.dataTransfer && event.dataTransfer.files;

                if (!dropped || dropped.length === 0) {
                    return;
                }

                // DataTransfer is how a dropped file is handed to a file
                // input; older browsers without it still have the click path.
                if (typeof DataTransfer === 'function') {
                    var carrier = new DataTransfer();
                    carrier.items.add(dropped[0]);
                    input.files = carrier.files;
                }

                showChosen(dropped[0]);
            });
        });
    }


    /* ----------------------------------------------------------------------
       Hold to show a password.  (Module 2 - Ivan)

       A password box hides what is typed, which is what stops someone reading
       it over your shoulder, but it also means a typo is invisible until the
       form comes back rejected. Holding this button shows the characters for
       exactly as long as the button is held, and hiding again is not something
       the user has to remember to do.

       Held, not toggled, on purpose: a toggle can be left on, and then the
       password sits in plain view on an unattended screen.

       The button is type="button" so it never submits the form, and it works
       from the keyboard as well - hold Space or Enter - so it is not
       mouse-only. It is also hidden again if the tab is switched away from or
       the form is submitted, in case a finger stays down.
       ---------------------------------------------------------------------- */

    function setUpPasswordReveal() {
        var buttons = document.querySelectorAll('[data-reveal-password]');

        Array.prototype.forEach.call(buttons, function (button) {
            var field = document.getElementById(button.getAttribute('data-reveal-password'));

            if (!field) {
                return;
            }

            function show() {
                field.type = 'text';
                button.setAttribute('aria-pressed', 'true');
            }

            function hide() {
                field.type = 'password';
                button.setAttribute('aria-pressed', 'false');
            }

            button.addEventListener('mousedown', show);

            button.addEventListener('touchstart', function (event) {
                // Stops the browser also firing a mouse event and a long-press
                // menu on top of this one.
                event.preventDefault();
                show();
            });

            ['mouseup', 'mouseleave', 'touchend', 'touchcancel', 'blur'].forEach(function (name) {
                button.addEventListener(name, hide);
            });

            button.addEventListener('keydown', function (event) {
                if (event.key === ' ' || event.key === 'Enter') {
                    event.preventDefault();
                    show();
                }
            });

            button.addEventListener('keyup', function (event) {
                if (event.key === ' ' || event.key === 'Enter') {
                    event.preventDefault();
                    hide();
                }
            });

            if (field.form) {
                field.form.addEventListener('submit', hide);
            }

            document.addEventListener('visibilitychange', function () {
                if (document.hidden) {
                    hide();
                }
            });
        });
    }


    /* ----------------------------------------------------------------------
       Confirm the address before it is saved.  (Module 2 - Ivan)

       The player types where they play; the server looks it up with the
       Discovery module's AddressGeocoder and answers with the place it
       matched. Showing that back means a wrong address is caught here rather
       than quietly placing someone in the wrong city.

       Coordinates are never sent to this page and never posted back. The
       server geocodes the address again when the form is submitted and stores
       that result, so what is shown here is only ever information.
       ---------------------------------------------------------------------- */

    function setUpLocationConfirm() {
        var box   = document.getElementById('location');
        var panel = document.getElementById('locationConfirm');

        if (!box || !panel || !box.hasAttribute('data-location-lookup')) {
            return;
        }

        var form     = box.form;
        var endpoint = box.getAttribute('data-location-lookup');
        var token    = form ? form.querySelector('input[name="_token"]') : null;
        var lastAsked = box.value.trim();

        function say(text, tone) {
            panel.textContent = text;
            panel.className = 'small ' + (tone || 'muted');
            panel.hidden = false;
        }

        function check() {
            var address = box.value.trim();

            if (address === '' || address === lastAsked) {
                return;
            }

            lastAsked = address;
            say('Checking that address...', 'muted');

            var body = new FormData();
            body.append('location', address);

            if (token) {
                body.append('_token', token.value);
            }

            fetch(endpoint, {
                method: 'POST',
                body: body,
                credentials: 'same-origin'
            }).then(function (response) {
                return response.json();
            }).then(function (data) {
                if (!data.found) {
                    say(data.message || 'That address could not be found.', 'muted');
                    return;
                }

                if (data.approximate || !data.label) {
                    say('Found, but only roughly - distances will be approximate.', 'muted');
                    return;
                }

                // confirm() rather than a custom dialog: it blocks, so the
                // answer is given before anything else happens on the form.
                if (window.confirm('We found:\n\n' + data.label + '\n\nIs that right?')) {
                    say('Confirmed: ' + data.label, 'muted');
                } else {
                    say('Not confirmed - please make the address more specific.', 'muted');
                    box.focus();
                    box.select();
                    lastAsked = '';
                }
            }).catch(function () {
                // The address is still saved; only the confirmation is missing.
                say('Could not check that address just now.', 'muted');
            });
        }

        box.addEventListener('blur', check);
    }

    /* ----------------------------------------------------------------------
       Copy to clipboard.

       An invite link is 64 hex characters on the end of a URL, which nobody is
       going to select accurately by hand. The button carries the value in a
       data attribute and the work happens here, because the Content Security
       Policy allows no inline handler.

       Delegated from the document, so links generated after the page loaded
       would work too without anything being wired up again.
       ---------------------------------------------------------------------- */

    function setUpCopyButtons() {
        document.addEventListener('click', function (event) {
            var button = event.target.closest('[data-copy]');

            if (!button) {
                return;
            }

            event.preventDefault();

            var value = button.getAttribute('data-copy') || '';
            var label = button.querySelector('.copy-label');

            function done(ok) {
                if (!label) {
                    return;
                }

                label.textContent = ok ? 'Copied' : 'Press Ctrl+C';
                button.classList.toggle('is-copied', ok);

                window.setTimeout(function () {
                    label.textContent = 'Copy';
                    button.classList.remove('is-copied');
                }, 1500);
            }

            // The clipboard API needs a secure context. localhost counts as one,
            // so this is the path that runs in the demo, but a plain http host
            // on the network does not - hence the fallback below.
            if (window.navigator.clipboard && window.navigator.clipboard.writeText) {
                window.navigator.clipboard.writeText(value).then(function () {
                    done(true);
                }, function () {
                    done(selectFallback(value));
                });

                return;
            }

            done(selectFallback(value));
        });
    }

    // Puts the text in a box off screen and selects it. execCommand('copy') is
    // deprecated and may do nothing, so if it fails the text is left selected
    // and the button says to press Ctrl+C.
    function selectFallback(value) {
        var box = document.createElement('textarea');

        box.value = value;
        box.setAttribute('readonly', 'readonly');
        box.className = 'offscreen-copy';
        document.body.appendChild(box);
        box.select();

        var copied = false;

        try {
            copied = document.execCommand('copy');
        } catch (error) {
            copied = false;
        }

        document.body.removeChild(box);

        return copied;
    }

    function start() {
        setUpVenuePreview();
        setUpPickers();
        setUpRoleFields();
        setUpPaymentCheckout();
        setUpSlotGuard();
        setUpStateFromPostcode();
        setUpNoCopyFields();
        setUpPasswordReveal();
        setUpDropzones();
        setUpLocationConfirm();
        setUpRatingWidgets();
        setUpRemovalReasons();
        setUpCopyButtons();
    }

    function setUpRatingWidgets() {
        document.querySelectorAll('[data-rating-widget]').forEach(function (widget) {
            var stars = widget.querySelectorAll('.rating-star:not([data-rating-star])');
            var selectableStars = widget.querySelectorAll('[data-rating-star]');
            var current = parseInt(widget.getAttribute('data-current-rating') || '0', 10);

            function paint(value, preview) {
                stars.forEach(function (star) {
                    var selected = parseInt(star.value, 10) <= value;
                    star.classList.toggle('is-preview', preview && selected);
                    star.classList.toggle('is-selected', selected);
                });
            }

            stars.forEach(function (star) {
                star.addEventListener('mouseenter', function () {
                    paint(parseInt(star.value, 10), true);
                });
            });

            if (selectableStars.length > 0) {
                widget.querySelectorAll('[data-rating-group]').forEach(function (group) {
                    var groupStars = group.querySelectorAll('[data-rating-star]');
                    var valueInput = group.querySelector('[data-rating-value]');
                    var groupCurrent = parseInt(group.getAttribute('data-current-rating') || '0', 10);

                    function paintGroup(value, preview) {
                        groupStars.forEach(function (star) {
                            var selected = parseInt(star.getAttribute('data-rating-star'), 10) <= value;
                            star.classList.toggle('is-preview', preview && selected);
                            star.classList.toggle('is-selected', selected);
                        });
                    }

                    groupStars.forEach(function (star) {
                        star.addEventListener('mouseenter', function () {
                            paintGroup(parseInt(star.getAttribute('data-rating-star'), 10), true);
                        });
                        star.addEventListener('click', function () {
                            groupCurrent = parseInt(star.getAttribute('data-rating-star'), 10);
                            valueInput.value = groupCurrent;
                            paintGroup(groupCurrent, false);
                        });
                    });

                    group.addEventListener('mouseleave', function () {
                        paintGroup(groupCurrent, false);
                    });
                });
            } else {
                widget.addEventListener('mouseleave', function () {
                    paint(current, false);
                });
            }
        });
    }

    function setUpRemovalReasons() {
        document.addEventListener('click', function (event) {
            var button = event.target.closest('[data-remove-review]');

            if (!button) {
                return;
            }

            var form = button.closest('[data-remove-review-form]');
            if (!form) {
                return;
            }

            event.preventDefault();

            var overlay = document.createElement('div');
            overlay.className = 'moderation-modal';
            var box = document.createElement('div');
            box.className = 'moderation-modal-box';
            box.setAttribute('role', 'dialog');
            box.setAttribute('aria-modal', 'true');
            box.setAttribute('aria-labelledby', 'moderationReasonTitle');

            var heading = document.createElement('h2');
            heading.id = 'moderationReasonTitle';
            heading.textContent = 'Reason for removal';

            var label = document.createElement('label');
            label.htmlFor = 'moderationReason';
            label.textContent = 'Select a reason';

            var select = document.createElement('select');
            select.id = 'moderationReason';
            select.required = true;
            [
                ['', 'Choose a reason'],
                ['abusive_language', 'Abusive language'],
                ['spam_or_repetitive', 'Spam or repetitive content'],
                ['personal_information', 'Personal information'],
                ['other', 'Other policy violation']
            ].forEach(function (optionData) {
                var option = document.createElement('option');
                option.value = optionData[0];
                option.textContent = optionData[1];
                select.appendChild(option);
            });

            var actions = document.createElement('div');
            actions.className = 'button-row moderation-modal-actions';

            var cancel = document.createElement('button');
            cancel.className = 'btn ghost small';
            cancel.type = 'button';
            cancel.textContent = 'Cancel';

            var confirm = document.createElement('button');
            confirm.className = 'btn danger small';
            confirm.type = 'button';
            confirm.disabled = true;
            confirm.textContent = 'Remove review';

            actions.appendChild(cancel);
            actions.appendChild(confirm);
            box.appendChild(heading);
            box.appendChild(label);
            box.appendChild(select);
            box.appendChild(actions);
            overlay.appendChild(box);

            document.body.appendChild(overlay);
            select.addEventListener('change', function () {
                confirm.disabled = select.value === '';
            });

            function close() {
                overlay.remove();
            }

            cancel.addEventListener('click', close);
            confirm.addEventListener('click', function () {
                if (select.value === '') {
                    return;
                }

                var reason = form.querySelector('[name="removalReason"]') || document.createElement('input');
                reason.type = 'hidden';
                reason.name = 'removalReason';
                reason.value = select.value;
                form.appendChild(reason);
                form.submit();
            });

            select.focus();
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
})();

