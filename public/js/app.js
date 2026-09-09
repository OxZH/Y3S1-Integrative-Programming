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
    
    //    Role fields on the registration form.  (Module 2 - Ivan)

    //    A player and a facility owner need different questions, so only the block
    //    matching the chosen account type is shown. This is convenience only: the
    //    server decides which fields it will accept from the submitted userType,
    //    so hiding a block here is not what keeps a player from sending bank
    //    details, and unhiding one in the browser achieves nothing.
    //    ---------------------------------------------------------------------- */

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

    function start() {
        setUpVenuePreview();
        setUpPickers();

        var boxes = document.querySelectorAll('[data-suggest]');

        Array.prototype.forEach.call(boxes, setUpSuggestions);
        setUpRoleFields();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
}());
