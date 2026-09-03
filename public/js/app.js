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
    // --- The scan viewer ----------------------------------------------------
    // The review screens show the rendered page images rather than embedding
    // the PDF: they are already on disk, they are what the model was shown, and
    // an <img> paints where an <object> boots a PDF viewer.
    //
    // Everything below is an enhancement on markup that already works. The
    // pages are stacked in a scrolling box in document order and the thumbnail
    // strip is ordinary anchors, both of which need no script; what this adds
    // is the page arrows, the actual-size toggle, a counter that follows the
    // scroll, and turning the "View PDF" link into a panel that opens beneath
    // the images instead of a new tab. The two controls that cannot work
    // without it ship `hidden` and are revealed here, so the bar never offers a
    // button that does nothing.
    (function () {
        var viewers = document.querySelectorAll('[data-scan]');
        if (!viewers.length) return;

        Array.prototype.forEach.call(viewers, function (scan) {
            var stage = scan.querySelector('[data-scan-stage]');
            var pages = stage ? Array.prototype.slice.call(stage.querySelectorAll('[data-scan-page]')) : [];
            var count = scan.querySelector('[data-scan-count]');
            var prev = scan.querySelector('[data-scan-prev]');
            var next = scan.querySelector('[data-scan-next]');
            var zoom = scan.querySelector('[data-scan-zoom]');
            var pdfLink = scan.querySelector('[data-scan-pdf]');
            var pdfPanel = scan.querySelector('[data-scan-pdf-panel]');
            var current = 0;

            // --- Paging ------------------------------------------------------
            if (pages.length > 1 && stage) {
                if (prev) prev.hidden = false;
                if (next) next.hidden = false;

                var describe = function () {
                    if (count) {
                        count.textContent = 'Page ' + (current + 1) + ' of ' + pages.length;
                    }
                    if (prev) prev.disabled = current === 0;
                    if (next) next.disabled = current === pages.length - 1;

                    scan.querySelectorAll('[data-scan-goto]').forEach(function (link) {
                        var isHere = parseInt(link.getAttribute('data-scan-goto'), 10) === current + 1;
                        // Removed rather than set to "false": aria-current has
                        // no falsy value, and "false" is a state of its own.
                        if (isHere) {
                            link.setAttribute('aria-current', 'true');
                        } else {
                            link.removeAttribute('aria-current');
                        }
                    });
                };

                var show = function (index) {
                    current = Math.max(0, Math.min(pages.length - 1, index));

                    // Measured rather than read off offsetTop: that is relative
                    // to the offset *parent*, which is not this box unless it
                    // happens to be positioned — a CSS detail that would break
                    // paging silently the day it changed.
                    stage.scrollTop += pages[current].getBoundingClientRect().top
                        - stage.getBoundingClientRect().top;

                    describe();
                };

                if (prev) prev.addEventListener('click', function () { show(current - 1); });
                if (next) next.addEventListener('click', function () { show(current + 1); });

                scan.addEventListener('click', function (event) {
                    var jump = event.target.closest('[data-scan-goto]');
                    if (!jump) return;

                    event.preventDefault();
                    show(parseInt(jump.getAttribute('data-scan-goto'), 10) - 1);
                });

                // Which page is being read, so the counter follows a scroll
                // rather than only a button. The threshold is deliberately
                // low: a page half out of the box is still the one being read.
                if ('IntersectionObserver' in window) {
                    var watcher = new IntersectionObserver(function (entries) {
                        entries.forEach(function (entry) {
                            if (!entry.isIntersecting) return;
                            current = pages.indexOf(entry.target);
                            describe();
                        });
                    }, { root: stage, threshold: 0.3 });

                    pages.forEach(function (page) { watcher.observe(page); });
                }

                describe();
            }

            // --- Actual size -------------------------------------------------
            // Two sizes, not a slider: "fit the pane" and "the pixels the model
            // was shown". The second is what a handwritten annotation needs.
            if (zoom && stage && pages.length) {
                zoom.hidden = false;

                zoom.addEventListener('click', function () {
                    var actual = stage.classList.toggle('is-actual');
                    zoom.setAttribute('aria-pressed', actual ? 'true' : 'false');
                    zoom.textContent = actual ? 'Fit the width' : 'Actual size';

                    // Scrolled to the middle horizontally, because a scan is
                    // centred on the sheet and the left edge is margin.
                    if (actual) {
                        stage.scrollLeft = (stage.scrollWidth - stage.clientWidth) / 2;
                    }
                });
            }

            // --- The PDF, underneath -----------------------------------------
            if (pdfLink && pdfPanel && pages.length) {
                pdfLink.addEventListener('click', function (event) {
                    // A deliberate "open in a new tab" — middle click, or Ctrl
                    // held — is left alone. Hijacking that is the thing people
                    // hate most about an intercepted link.
                    if (event.metaKey || event.ctrlKey || event.shiftKey || event.button !== 0) return;

                    event.preventDefault();

                    var open = pdfPanel.hidden;
                    pdfPanel.hidden = !open;
                    pdfLink.setAttribute('aria-expanded', open ? 'true' : 'false');
                    pdfLink.textContent = open ? 'Hide the PDF' : 'View PDF';

                    if (open) {
                        pdfPanel.scrollIntoView({ block: 'nearest' });
                    }
                });
            }
        });
    })();

    // --- The upload page ----------------------------------------------------
    // Purely so the page can answer "will this go through?" before somebody
    // waits for a 30MB upload to be rejected at the far end. The server checks
    // all of this again and does not trust any of it; nothing here can let a
    // file through that the server would refuse.
    (function () {
        var input = document.querySelector('[data-upload-input]');
        if (!input) return;

        var list = document.querySelector('[data-upload-list]');
        var submit = document.querySelector('[data-upload-submit]');
        var maxBytes = parseInt(input.getAttribute('data-max-bytes'), 10) || 0;
        var maxFiles = parseInt(input.getAttribute('data-max-files'), 10) || 0;

        function readable(bytes) {
            if (bytes >= 1048576) return (Math.round(bytes / 104857.6) / 10) + ' MB';
            return Math.max(1, Math.round(bytes / 1024)) + ' KB';
        }

        input.addEventListener('change', function () {
            var files = Array.prototype.slice.call(input.files || []);
            var blocked = false;

            list.innerHTML = '';
            list.hidden = files.length === 0;

            if (files.length > maxFiles) {
                blocked = true;
            }

            files.forEach(function (file) {
                // A .pdf that is 40MB and a .jpg renamed to .pdf are the two
                // mistakes worth catching here. The name check is a courtesy;
                // the server reads the file's first five bytes.
                var tooBig = maxBytes > 0 && file.size > maxBytes;
                var notPdf = !/\.pdf$/i.test(file.name);
                if (tooBig || notPdf) blocked = true;

                var item = document.createElement('li');
                var name = document.createElement('strong');
                name.textContent = file.name;
                item.appendChild(name);

                var note = document.createTextNode(
                    ' ' + readable(file.size) +
                    (notPdf ? ' — not a PDF' : (tooBig ? ' — over the limit' : ''))
                );
                item.appendChild(note);

                if (tooBig || notPdf) item.className = 'text-danger';
                list.appendChild(item);
            });

            if (files.length > maxFiles) {
                var warning = document.createElement('li');
                warning.className = 'text-danger';
                warning.textContent = files.length + ' files chosen, and ' + maxFiles + ' is the most at once.';
                list.appendChild(warning);
            }

            if (submit) submit.disabled = blocked;
        });
    })();
})();
