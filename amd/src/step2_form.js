/**
 * This file is part of Moodle - http://moodle.org/
 *
 * Moodle is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Moodle is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Moodle.  If not, see <http://www.gnu.org/licenses/>.
 */

/**
 * Poll the background job until it is done or failed.
 *
 * @module     local_haccgen/step2_form
 * @copyright  2026 Dynamicpixel Multimedia Solutions
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

export const init = () => {
    // Run only on step 2
    const stepInput = document.querySelector('input[name="step"]');
    if (!stepInput || stepInput.value !== '2') {
        return;
    }

    // Form elements
    const levelSelect = document.getElementById('levelofunderstanding');
    const toneSelect = document.getElementById('toneofnarrative');
    const durationSelect = document.getElementById('courseduration');

    const previewLevel = document.getElementById('preview-level-value');
    const previewTone = document.getElementById('preview-tone-value');
    const previewDuration = document.getElementById('preview-duration-value');

    const animateUpdate = (el, value, delay = 0) => {
        if (!el) {
            return;
        }

        el.textContent = value || 'Not selected';
        el.style.animation = 'none';

        window.setTimeout(() => {
            el.style.animation = `slideIn 0.5s ease ${delay}s`;
        }, 10);
    };

    const updatePreview = () => {
        animateUpdate(previewLevel, levelSelect?.value, 0);
        animateUpdate(previewTone, toneSelect?.value, 0.2);
        animateUpdate(previewDuration, durationSelect?.value, 0.4);
    };

    // Initial render
    updatePreview();

    // Change listeners
    levelSelect?.addEventListener('change', updatePreview);
    toneSelect?.addEventListener('change', updatePreview);
    durationSelect?.addEventListener('change', updatePreview);

    // Spinner logic on submit
    const form = document.getElementById('haccgen-form');
    if (!form) {
        return;
    }

    const continueButton = form.querySelector(
        'button[type="submit"]:not([name="action"])'
    );
    const backButton = form.querySelector(
        'button[name="action"][value="back"]'
    );

    form.addEventListener('submit', (e) => {
        // Skip spinner when Back is clicked
        if (e.submitter === backButton) {
            return;
        }

        if (continueButton) {
            continueButton.disabled = true;
            continueButton.innerHTML =
                '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Just a sec…';
        }

        if (backButton) {
            backButton.disabled = true;
        }
    });
};