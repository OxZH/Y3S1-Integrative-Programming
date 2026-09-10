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

        var image = panel.querySelector('[data-field="image"]');
        var link = panel.querySelector('[data-field="link"]');
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

    function start() {
        setUpVenuePreview();
        setUpRoleFields();
        setUpRemovalReasons();
        setUpRatingWidgets();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
}());
