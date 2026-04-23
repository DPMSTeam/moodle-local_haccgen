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
 * @module     local_haccgen/jobprogress
 * @copyright  2026 Dynamicpixel Multimedia Solutions
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define([], function () {

    /**
     * Format milliseconds into mm:ss.
     *
     * @param {number} ms Time in milliseconds
     * @returns {string}
     */
    const fmtTime = function (ms) {
        const sec = Math.floor(ms / 1000);
        const m = Math.floor(sec / 60);
        const s = sec % 60;
        return m + ':' + s.toString().padStart(2, '0');
    };

    /**
     * Initialize job progress polling.
     *
     * @param {number} jobid The job ID
     */
    const init = function (jobid) {

        const root = document.getElementById('jobstatus');
        if (!root) {
            return;
        }

        const statusEl = document.getElementById('aic-status');
        const percentEl = document.getElementById('aic-percent');
        const barEl = document.getElementById('aic-bar');
        const msgEl = document.getElementById('aic-msg');
        const formEl = document.getElementById('continueform');
        const continueBtn = document.getElementById('continuebtn');
        const timerEl = document.getElementById('aic-timer');
        const rotatingEl = document.getElementById('aic-rotating');

        const statusUrl = root.dataset.statusurl;
        const url = statusUrl + '?id=' + encodeURIComponent(jobid);

        const startTime = Date.now();

        /**
         * Update progress bar.
         *
         * @param {number} pct Percentage value
         */
        const setProgress = function (pct) {
            const n = Math.max(0, Math.min(100, parseInt(pct || 0, 10)));
            percentEl.textContent = n;
            barEl.style.width = n + '%';
            barEl.setAttribute('aria-valuenow', n);
            barEl.textContent = n + '%';

            if (n >= 80) {
                barEl.classList.add('glow');
            } else {
                barEl.classList.remove('glow');
            }
        };

        /**
         * Update job status badge.
         *
         * @param {string} status Job status
         */
        const setStatus = function (status) {
            status = (status || 'queued').toLowerCase();
            const allowed = ['queued', 'running', 'success', 'error'];
            const safe = allowed.includes(status) ? status : 'queued';

            statusEl.textContent = safe;
            statusEl.className = 'aic-status badge badge-' + safe;
        };

        const rotatingTexts = [
            "🚀 Preparing your course structure...",
            "📚 Adding learning materials...",
            "🛠️ Organizing modules...",
            "🎶 Adding rhythm to learning flow...",
            "🗝️ Unlocking hidden insights...",
            "🧭 Setting the learning direction...",
            "🌱 Growing interactive elements...",
            "🎨 Personalizing learning journey...",
            "🔍 Checking consistency...",
            "🧩 Connecting all topics...",
            "🏗️ Assembling knowledge blocks...",
            "🎯 Aligning objectives...",
            "📝 Structuring content flow...",
            "📦 Packing your resources...",
            "⚡ Optimizing performance...",
            "🔧 Fine-tuning details...",
            "🗂️ Sorting topics into modules",
            "🌐 Polishing for final shine...",
            "✨ Finalizing details...",
            "⏳ Almost there, please wait..."
        ];

        let rotateIndex = 0;
        let rotateTimer = null;

        /**
         * Rotate loading text messages.
         */
        const rotateText = function () {
            if (!rotatingEl) {
                return;
            }

            rotatingEl.style.opacity = 0;

            setTimeout(function () {
                rotatingEl.textContent = rotatingTexts[rotateIndex];
                rotatingEl.style.opacity = 1;

                if (rotateIndex < rotatingTexts.length - 1) {
                    rotateIndex++;
                } else if (rotateTimer) {
                    clearInterval(rotateTimer);
                }
            }, 600);
        };

        rotateTimer = setInterval(rotateText, 8000);
        rotateText();

        /**
         * Poll job status endpoint.
         */
        const poll = async function () {
            try {
                const res = await fetch(url, {
                    credentials: 'same-origin'
                });

                if (!res.ok) {
                    throw new Error('HTTP ' + res.status);
                }

                const j = await res.json();

                const elapsed = Date.now() - startTime;
                if (timerEl) {
                    timerEl.textContent = fmtTime(elapsed);
                }

                if (typeof j.completed_topics === 'number' &&
                    typeof j.total_topics === 'number' &&
                    j.total_topics > 0) {

                    setStatus('running');

                    statusEl.textContent =
                        j.completed_topics + '/' + j.total_topics + ' topics';

                    const pct = Math.round(
                        (j.completed_topics / Math.max(1, j.total_topics)) * 100
                    );

                    setProgress(pct);

                } else {
                    setStatus(j.status || 'queued');
                    setProgress(j.progress || 0);
                }

                if (j.message && msgEl) {
                    msgEl.textContent = j.message;
                }

                if (j.status === 'success') {
                    setStatus('success');

                    if (rotateTimer) {
                        clearInterval(rotateTimer);
                    }

                    if (formEl) {
                        formEl.style.display = 'block';
                        formEl.submit();
                    }
                    return;
                }

                if (j.status === 'error') {
                    setStatus('error');

                    if (rotateTimer) {
                        clearInterval(rotateTimer);
                    }

                    if (formEl) {
                        formEl.style.display = 'block';
                    }

                    if (continueBtn) {
                        continueBtn.textContent = 'Continue';
                    }
                    return;
                }

            } catch (e) {
                if (msgEl) {
                    msgEl.textContent =
                        'Polling failed: ' + (e && e.message ? e.message : e);
                }
            }

            setTimeout(poll, 1200);
        };

        poll();
    };

    return {
        init: init
    };
});