/* InvoGrid — small vanilla-JS behaviours. No build step, no framework. */
(function () {
    'use strict';

    // --- Theme -------------------------------------------------------------
    // The initial theme is applied by an inline script in <head> so the page
    // never flashes the wrong one. This handles the toggle and remembers the
    // choice in both localStorage (fast) and a cookie (survives cleared storage
    // on iOS, and is readable by the server if it ever needs to be).
    function currentTheme() {
        return document.documentElement.getAttribute('data-theme') === 'dark' ? 'dark' : 'light';
    }

    function applyTheme(theme) {
        document.documentElement.setAttribute('data-theme', theme);

        try {
            localStorage.setItem('theme', theme);
        } catch (e) { /* private mode */ }

        var secure = location.protocol === 'https:' ? '; Secure' : '';
        document.cookie = 'theme=' + theme + '; path=/; max-age=31536000; SameSite=Lax' + secure;

        var meta = document.querySelector('meta[name="theme-color"]');
        if (meta) {
            meta.setAttribute('content', theme === 'dark' ? '#0b1120' : '#ffffff');
        }

        updateThemeLabels(theme);
    }

    function updateThemeLabels(theme) {
        var label = theme === 'dark' ? 'Light mode' : 'Dark mode';
        document.querySelectorAll('[data-theme-label]').forEach(function (el) {
            el.textContent = label;
        });
        document.querySelectorAll('[data-theme-toggle]').forEach(function (el) {
            el.setAttribute('aria-label', 'Switch to ' + label.toLowerCase());
        });
    }

    document.addEventListener('click', function (event) {
        var toggle = event.target.closest('[data-theme-toggle]');
        if (toggle) {
            applyTheme(currentTheme() === 'dark' ? 'light' : 'dark');
        }
    });

    updateThemeLabels(currentTheme());

    // --- Navigation drawer -------------------------------------------------
    var navToggle = document.querySelector('[data-nav-toggle]');
    var nav = document.querySelector('[data-nav]');

    if (navToggle && nav) {
        // The drawer is pinned under a sticky header and scrolls inside itself
        // (see .primary-nav), so it needs to know how tall the header is to
        // know how much room is left. Measured rather than assumed: the bar
        // grows a few pixels on an account whose name wraps, and the CSS
        // fallback would then hide the last item.
        var header = nav.closest('.site-header');

        var measureHeader = function () {
            if (!header) return;
            document.documentElement.style.setProperty(
                '--header-h',
                Math.round(header.getBoundingClientRect().height) + 'px'
            );
        };

        measureHeader();
        window.addEventListener('resize', measureHeader);

        navToggle.addEventListener('click', function () {
            var open = nav.classList.toggle('is-open');
            navToggle.setAttribute('aria-expanded', open ? 'true' : 'false');

            // Re-measure on open rather than only at load: the header's height
            // can change between the two — a flash message above it, a font
            // arriving late — and the stale value would be the one that clips.
            if (open) {
                measureHeader();
                nav.scrollTop = 0;
            }
        });

        // Close when tapping outside it, or on Escape.
        document.addEventListener('click', function (event) {
            if (!nav.classList.contains('is-open')) return;
            if (nav.contains(event.target) || navToggle.contains(event.target)) return;

            nav.classList.remove('is-open');
            navToggle.setAttribute('aria-expanded', 'false');
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && nav.classList.contains('is-open')) {
                nav.classList.remove('is-open');
                navToggle.setAttribute('aria-expanded', 'false');
                navToggle.focus();
            }
        });
    }

    // --- Navigation groups -------------------------------------------------
    // A group whose page you are on is rendered `open` so the menu shows where
    // you are. On the desktop bar the stylesheet keeps that panel shut until
    // the group is reached for; dropping the attribute on first interaction
    // hands the group back to ordinary <details> behaviour.
    document.addEventListener('click', function (event) {
        var summary = event.target.closest('[data-nav-group] > summary');
        if (summary && summary.parentElement) {
            summary.parentElement.removeAttribute('data-nav-autoopen');
        }
    });

    // --- Show/hide password ------------------------------------------------
    document.addEventListener('click', function (event) {
        var button = event.target.closest('[data-toggle-password]');
        if (!button) return;

        var input = document.getElementById(button.getAttribute('data-toggle-password'));
        if (!input) return;

        var reveal = input.type === 'password';
        input.type = reveal ? 'text' : 'password';
        button.textContent = reveal ? 'Hide' : 'Show';
    });

    // --- Dismissable flash messages ----------------------------------------
    document.addEventListener('click', function (event) {
        var dismiss = event.target.closest('[data-dismiss]');
        if (dismiss && dismiss.parentElement) {
            dismiss.parentElement.remove();
        }
    });

    // --- Auto-hiding confirmations -----------------------------------------
    // Only the banners the server marked: confirmations, never warnings or
    // errors. The server also decides the delay, so "0 = stay" needs no special
    // case here — an element with no attribute never enters this loop.
    //
    // The countdown pauses while the pointer is over the banner or the keyboard
    // is inside it. Text that removes itself while it is being read, or a
    // dismiss button that vanishes as it is tabbed to, both read as a bug.
    (function () {
        var banners = document.querySelectorAll('[data-flash-autohide]');
        if (!banners.length) return;

        Array.prototype.forEach.call(banners, function (banner) {
            var delay = (parseInt(banner.getAttribute('data-flash-autohide'), 10) || 0) * 1000;
            if (delay <= 0) return;

            var timer = null;

            function hide() {
                banner.classList.add('is-leaving');

                // Matches the CSS transition; the banner goes whether or not
                // the browser fires transitionend.
                window.setTimeout(function () {
                    if (banner.parentElement) banner.remove();
                }, 250);
            }

            function stop() {
                if (timer) { window.clearTimeout(timer); timer = null; }
            }

            function start() {
                stop();
                timer = window.setTimeout(hide, delay);
            }

            banner.addEventListener('mouseenter', stop);
            banner.addEventListener('mouseleave', start);
            banner.addEventListener('focusin', stop);
            banner.addEventListener('focusout', start);

            start();
        });
    })();

    // --- Confirm before something irreversible -----------------------------
    document.addEventListener('click', function (event) {
        var trigger = event.target.closest('[data-confirm]');
        if (!trigger) return;

        if (!window.confirm(trigger.getAttribute('data-confirm'))) {
            event.preventDefault();
            event.stopPropagation();
        }
    });

    // --- Open in Clear Books -----------------------------------------------
    // Clear Books has no API for a purchase line's project code, so a human
    // finishes that off in Clear Books itself. Every such link reuses one named
    // window: working through a queue of twenty documents should not leave
    // twenty tabs open, and the named window means the second click lands in
    // the tab already sitting on the previous record.
    document.addEventListener('click', function (event) {
        var link = event.target.closest('[data-clearbooks-window]');
        if (!link) return;

        event.preventDefault();
        window.open(link.href, 'clearbooksWindow');
    });
})();
