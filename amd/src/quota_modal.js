/**
 * Global quota / usage exceeded modal for HACC Gen.
 *
 * @module     local_haccgen/quota_modal
 * @copyright  2026 Dynamicpixel Multimedia Solutions
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define([], function() {

    /** @type {boolean} */
    let closeHandlersBound = false;

    /**
     * @returns {HTMLElement|null}
     */
    const getModalElement = function() {
        return document.getElementById('haccgen-quota-modal');
    };

    /**
     * Ensure the modal lives directly under body.
     *
     * @return {void}
     */
    const ensureModalInBody = function() {
        const modalEl = getModalElement();
        if (modalEl && modalEl.parentElement !== document.body) {
            document.body.appendChild(modalEl);
        }
    };

    /**
     * @param {*} config Init config from PHP.
     * @return {boolean}
     */
    const shouldShowOnLoad = function(config) {
        if (config === true) {
            return true;
        }
        if (config && typeof config === 'object') {
            if (Array.isArray(config) && config.length > 0) {
                config = config[0];
            }
            if (config && config.showOnLoad) {
                return true;
            }
        }
        const modalEl = getModalElement();
        return !!(modalEl && modalEl.dataset.showOnLoad === '1');
    };

    /**
     * Remove modal-open state and any leftover backdrops.
     *
     * @return {void}
     */
    const cleanupModal = function() {
        document.body.classList.remove('modal-open');
        document.body.style.removeProperty('padding-right');
        document.body.style.removeProperty('overflow');

        const backdropEl = document.getElementById('haccgen-quota-modal-backdrop');
        if (backdropEl) {
            backdropEl.remove();
        }

        document.querySelectorAll('.modal-backdrop').forEach(function(backdrop) {
            backdrop.remove();
        });
    };

    /**
     * Ensure a single backdrop exists behind the modal.
     *
     * @return {HTMLElement}
     */
    const ensureBackdrop = function() {
        let backdropEl = document.getElementById('haccgen-quota-modal-backdrop');
        if (!backdropEl) {
            backdropEl = document.createElement('div');
            backdropEl.id = 'haccgen-quota-modal-backdrop';
            backdropEl.className = 'modal-backdrop fade show';
            backdropEl.style.zIndex = '1055';
            document.body.appendChild(backdropEl);
        }
        return backdropEl;
    };

    /**
     * Hide the quota exceeded modal.
     *
     * @return {void}
     */
    const hide = function() {
        const modalEl = getModalElement();
        if (!modalEl) {
            cleanupModal();
            return;
        }

        modalEl.classList.remove('show');
        modalEl.style.display = 'none';
        modalEl.setAttribute('aria-hidden', 'true');
        cleanupModal();
    };

    /**
     * Wire close handlers via event delegation (survives DOM moves).
     *
     * @return {void}
     */
    const bindCloseHandlers = function() {
        if (closeHandlersBound) {
            return;
        }
        closeHandlersBound = true;

        document.addEventListener('click', function(e) {
            const closeBtn = e.target.closest('[data-haccgen-quota-close]');
            if (closeBtn) {
                const modalEl = getModalElement();
                if (!modalEl || !modalEl.contains(closeBtn)) {
                    return;
                }
                e.preventDefault();
                e.stopPropagation();
                hide();
                return;
            }

            if (e.target.id === 'haccgen-quota-modal-backdrop') {
                e.preventDefault();
                hide();
            }
        }, true);

        document.addEventListener('keydown', function(e) {
            if (e.key !== 'Escape') {
                return;
            }
            const modalEl = getModalElement();
            if (modalEl && modalEl.classList.contains('show')) {
                e.preventDefault();
                hide();
            }
        });
    };

    /**
     * Show the quota exceeded modal.
     *
     * @return {void}
     */
    const show = function() {
        const modalEl = getModalElement();
        if (!modalEl) {
            return;
        }

        ensureModalInBody();
        bindCloseHandlers();
        ensureBackdrop();

        modalEl.classList.add('show');
        modalEl.style.display = 'block';
        modalEl.style.zIndex = '1060';
        modalEl.style.position = 'fixed';
        modalEl.removeAttribute('aria-hidden');
        document.body.classList.add('modal-open');
    };

    /**
     * Whether a message looks like a quota / usage limit error.
     *
     * @param {string} message Error message text.
     * @return {boolean}
     */
    const isQuotaMessage = function(message) {
        const msg = String(message || '').toLowerCase();
        if (!msg) {
            return false;
        }
        if (msg.indexOf('quota_exceeded') !== -1) {
            return true;
        }
        if (msg.indexOf('usage limit exceeded') !== -1) {
            return true;
        }
        if (msg.indexOf('usage limit') !== -1 && msg.indexOf('exceed') !== -1) {
            return true;
        }
        if (msg.indexOf('quota') !== -1 && (msg.indexOf('exceed') !== -1 || msg.indexOf('limit') !== -1)) {
            return true;
        }
        return false;
    };

    /**
     * Initialise the modal module.
     *
     * @param {Object|boolean} config Optional config.
     * @return {void}
     */
    const init = function(config) {
        ensureModalInBody();
        bindCloseHandlers();

        // Always start hidden; JS owns open/close to avoid Bootstrap conflicts.
        hide();

        if (shouldShowOnLoad(config)) {
            show();
        }

        window.local_haccgen_quota_modal = {
            show: show,
            hide: hide,
            isQuotaMessage: isQuotaMessage
        };
    };

    return {
        init: init,
        show: show,
        hide: hide,
        isQuotaMessage: isQuotaMessage
    };
});
