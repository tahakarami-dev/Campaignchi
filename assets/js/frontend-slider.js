/**
 * Campaignchi — Campaign Slider frontend engine.
 *
 * Exposes a single global, window.CMCSlider, with two entry points:
 *   - initAll(root)    initializes every .cmc-slider found under `root`.
 *   - destroyAll(root)  tears down Swiper instances + countdown timers
 *                        under `root` (used by the admin live-preview,
 *                        which repeatedly replaces its preview pane's DOM).
 *
 * Reused as-is by the admin Templates page preview, so the WYSIWYG
 * preview behaves pixel-identically to the real frontend.
 */
(function (window, document) {
    'use strict';

    var PERSIAN_DIGITS = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];

    /** Convert an integer to Persian-Farsi digits, e.g. 12 -> "۱۲". */
    function toPersian(num) {
        return String(num).replace(/[0-9]/g, function (digit) {
            return PERSIAN_DIGITS[Number(digit)];
        });
    }

    /** Zero-pad a number to 2 digits, then convert to Persian digits. */
    function pad2(num) {
        return toPersian(num < 10 ? '0' + num : String(num));
    }

    /**
     * Build a Swiper config from the wrapper's own data-attributes
     * (written by SliderRenderer::render()) and initialize it.
     */
    function initSwiper(wrapper) {
        // ⚠️ ISOLATION FIX: use our own captured reference
        // (window.CMCSwiperLib, set right after our script loads — see
        // TemplatesServiceProvider::enqueueFrontendAssets()) instead of the
        // shared window.Swiper global, so a theme or another plugin
        // loading a different Swiper build can never break our slider by
        // overwriting window.Swiper after us (or being broken by us).
        var SwiperLib = window.CMCSwiperLib || window.Swiper;

        if (typeof SwiperLib === 'undefined') {
            return;
        }

        var swiperEl = wrapper.querySelector('.cmc-slider__swiper');
        if (!swiperEl || wrapper._cmcSwiper) {
            return;
        }

        var hasArrows = wrapper.dataset.arrows === '1';
        var hasDots = wrapper.dataset.dots === '1';
        var isAutoplay = wrapper.dataset.autoplay === '1';

        var config = {
            slidesPerView: 'auto',
            spaceBetween: 16,
            rtl: true,
            grabCursor: true,
            loop: wrapper.dataset.loop === '1',
        };

        if (hasArrows) {
            config.navigation = {
                nextEl: wrapper.querySelector('.cmc-slider__next'),
                prevEl: wrapper.querySelector('.cmc-slider__prev'),
            };
        }

        if (hasDots) {
            config.pagination = {
                el: wrapper.querySelector('.swiper-pagination'),
                clickable: true,
            };
        }

        if (isAutoplay) {
            config.autoplay = {
                delay: parseInt(wrapper.dataset.autoplaySpeed, 10) || 4000,
                disableOnInteraction: false,
            };
        }

        wrapper._cmcSwiper = new SwiperLib(swiperEl, config);
    }

    /**
     * Start a 1-second countdown tick for one slider's countdown block.
     * The timer id is stored directly on the element so destroyAll() can
     * clear it reliably even after the wrapper is detached/replaced.
     */
    function initCountdown(wrapper) {
        var countdownEl = wrapper.querySelector('[data-cmc-countdown]');
        if (!countdownEl || countdownEl._cmcCountdownTimer) {
            return;
        }

        var target = new Date(countdownEl.getAttribute('data-cmc-countdown')).getTime();

        if (isNaN(target)) {
            return;
        }

        function tick() {
            var diff = target - Date.now();

            if (diff <= 0) {
                countdownEl.classList.add('is-ended');
                ['d', 'h', 'm', 's'].forEach(function (unit) {
                    var el = countdownEl.querySelector('[data-u="' + unit + '"]');
                    if (el) {
                        el.textContent = toPersian('00');
                    }
                });
                clearInterval(countdownEl._cmcCountdownTimer);
                countdownEl._cmcCountdownTimer = null;
                return;
            }

            var totalSeconds = Math.floor(diff / 1000);
            var days = Math.floor(totalSeconds / 86400);
            var hours = Math.floor((totalSeconds % 86400) / 3600);
            var minutes = Math.floor((totalSeconds % 3600) / 60);
            var seconds = totalSeconds % 60;

            setUnit(countdownEl, 'd', days);
            setUnit(countdownEl, 'h', hours);
            setUnit(countdownEl, 'm', minutes);
            setUnit(countdownEl, 's', seconds);
        }

        tick();
        countdownEl._cmcCountdownTimer = setInterval(tick, 1000);
    }

    function setUnit(countdownEl, unit, value) {
        var el = countdownEl.querySelector('[data-u="' + unit + '"]');
        if (el) {
            el.textContent = pad2(value);
        }
    }

    /** Initialize every .cmc-slider found under `root` (defaults to the whole document). */
    function initAll(root) {
        root = root || document;
        var wrappers = root.querySelectorAll('.cmc-slider');

        for (var i = 0; i < wrappers.length; i++) {
            initSwiper(wrappers[i]);
            initCountdown(wrappers[i]);
        }
    }

    /** Tear down Swiper instances + countdown intervals under `root`, before its DOM is replaced. */
    function destroyAll(root) {
        root = root || document;
        var wrappers = root.querySelectorAll('.cmc-slider');

        for (var i = 0; i < wrappers.length; i++) {
            var wrapper = wrappers[i];

            var countdownEl = wrapper.querySelector('[data-cmc-countdown]');
            if (countdownEl && countdownEl._cmcCountdownTimer) {
                clearInterval(countdownEl._cmcCountdownTimer);
                countdownEl._cmcCountdownTimer = null;
            }

            if (wrapper._cmcSwiper) {
                wrapper._cmcSwiper.destroy(true, true);
                wrapper._cmcSwiper = null;
            }
        }
    }

    window.CMCSlider = {
        initAll: initAll,
        destroyAll: destroyAll,
    };

    document.addEventListener('DOMContentLoaded', function () {
        initAll(document);
    });
})(window, document);