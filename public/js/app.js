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
        var fields = ['name', 'address', 'rating', 'fee', 'hours'];

        function render() {
            var venue = venues[select.value];

            if (!venue) {
                panel.hidden = true;
                return;
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
       Suggestions under a text box, for the sport and the venue type.

       The whole list arrives with the page in a data-suggest attribute, the
       same way the venue preview gets its venues, so typing does not send a
       request per letter. It can be done that way because the list is short: it
       is one entry per sport, not one per event, so it stays about the same size
       however many events the site ends up with.

       Anything can still be typed in. If what is typed is not in the list, the
       last chip offers to add it, which is only there to make that obvious -
       the value in the box is what gets submitted either way.
       ---------------------------------------------------------------------- */

    function setUpSuggestions(box) {
        var list;

        try {
            list = JSON.parse(box.getAttribute('data-suggest') || '[]');
        } catch (error) {
            return;
        }

        var panel = document.getElementById(box.id + 'Suggest');

        if (!panel) {
            return;
        }

        function choose(value) {
            box.value = value;
            panel.hidden = true;
            box.focus();
        }

        function render() {
            var typed = box.value.trim().toLowerCase();
            var shown = 0;
            var exact = false;

            panel.innerHTML = '';

            for (var i = 0; i < list.length; i++) {
                var name = list[i];

                if (name.toLowerCase() === typed) {
                    exact = true;
                }

                if (typed !== '' && name.toLowerCase().indexOf(typed) === -1) {
                    continue;
                }

                if (shown >= 8) {
                    continue;
                }

                var chip = document.createElement('button');
                chip.type = 'button';
                chip.className = 'btn ghost small';
                // textContent, not innerHTML: these came from what other people
                // typed into their own events.
                chip.textContent = name;
                chip.addEventListener('click', pick(name));
                panel.appendChild(chip);
                shown++;
            }

            if (typed !== '' && !exact) {
                var add = document.createElement('button');
                add.type = 'button';
                add.className = 'btn ghost small';
                add.textContent = 'Add "' + box.value.trim() + '"';
                add.addEventListener('click', pick(box.value.trim()));
                panel.appendChild(add);
                shown++;
            }

            panel.hidden = shown === 0;
        }

        // A separate function so the loop variable is not shared by every chip.
        function pick(value) {
            return function () {
                choose(value);
            };
        }

        box.addEventListener('input', render);
        box.addEventListener('focus', render);

        // Hiding on blur would fire before the click on a chip lands, so the
        // panel closes only once the focus has gone somewhere outside it.
        document.addEventListener('click', function (event) {
            if (event.target !== box && !panel.contains(event.target)) {
                panel.hidden = true;
            }
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
       Keep the start time ahead of now.

       The date box already refuses any day before today, because the view
       gives it a min. What it cannot do is notice that 09:00 is in the past
       when it is already 21:00 today, so when today is the chosen day the
       start time gets a min of the current time, and no min on any later day.

       The server checks the same thing in Validator::notInThePast(). This is
       only so the box says no before the form is sent.
       ---------------------------------------------------------------------- */

    function setUpPastTimeGuard() {
        var date  = document.getElementById('eventDate');
        var start = document.getElementById('startTime');

        if (!date || !start) {
            return;
        }

        function pad(n) {
            return (n < 10 ? '0' : '') + n;
        }

        function sync() {
            var now   = new Date();
            var today = now.getFullYear() + '-' + pad(now.getMonth() + 1) + '-' + pad(now.getDate());

            if (date.value === today) {
                start.min = pad(now.getHours()) + ':' + pad(now.getMinutes());
            } else {
                start.removeAttribute('min');
            }
        }

        date.addEventListener('change', sync);

        // A date may already be filled in, either from a failed save or from
        // the browser restoring the form.
        sync();
    }

    /* ----------------------------------------------------------------------
       Stop a password being copied out of the box.  (Module 2 - Ivan)

       The confirm box exists so a typo in the password is caught before the
       account is created. Copying the first box into the second defeats that -
       the two match, and both are wrong. Blocking copy, cut and drag means the
       confirmation is actually typed.

       Worth being honest about what this is: a nudge, not a security control.
       Anyone determined can read the value from the developer tools. It is on
       the sign-up form only, never on sign-in, where blocking paste would just
       break password managers for no benefit.
       ---------------------------------------------------------------------- */

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

    function start() {
        setUpVenuePreview();
        setUpPickers();
        setUpRoleFields();
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
