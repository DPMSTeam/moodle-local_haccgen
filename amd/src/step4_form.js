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
 * @module     local_haccgen/step4_form
 * @copyright  2026 Dynamicpixel Multimedia Solutions
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


/* ---------- JSON helper ---------- */
const readJsonScript = (id, fallback = {}) => {
    const el = document.getElementById(id);
    if (!el) {
        return fallback;
    }

    const raw = (el.textContent || '').trim();
    if (!raw) {
        return fallback;
    }

    try {
        return JSON.parse(raw);
    } catch (e) {
        return fallback;
    }
};

/**
 * Get the active Moodle editor instance.
 *
 * @param {string} id Editor element id
 * @returns {{type: string, inst: Object|null}}
 */
function getActiveEditor(id = 'id_contenteditor') {
    if (window.tinyMCE && window.tinyMCE.get && window.tinyMCE.get(id)) {
        return { type: 'tinymce', inst: window.tinyMCE.get(id) };
    }

    if (window.Y && window.Y.M && window.Y.M.editor_atto?.Editor) {
        try {
            const inst = window.Y.M.editor_atto.Editor.getEditor(id);
            if (inst) {
                return { type: 'atto', inst };
            }
        } catch (e) {
            // ignore
        }
    }

    return { type: 'textarea', inst: document.getElementById(id) };
}

/**
 * Set HTML content into the active editor.
 *
 * @param {string} html Editor HTML
 * @param {string} id Editor element id
 */
function setEditorContent(html, id = 'id_contenteditor') {
    const ed = getActiveEditor(id);
    if (!ed.inst) {
        return;
    }

    if (ed.type === 'tinymce') {
        ed.inst.setContent(html);
    } else if (ed.type === 'atto') {
        ed.inst.setHTML(html);
        ed.inst.fire('change');
    } else {
        ed.inst.value = html;
    }
}

/**
 * Get HTML content from the active editor.
 *
 * @param {string} id Editor element id
 * @returns {string}
 */
function getEditorContent(id = 'id_contenteditor') {
    const ed = getActiveEditor(id);
    if (!ed.inst) {
        return '';
    }

    if (ed.type === 'tinymce') {
        return ed.inst.getContent({ format: 'html' });
    }

    if (ed.type === 'atto') {
        return ed.inst.getHTML();
    }

    return ed.inst.value || '';
}

/**
 * Execute callback once editor becomes available.
 *
 * @param {Function} cb Callback function
 * @param {string} id Editor element id
 * @param {number} tries Retry count
 */
function whenEditorReady(cb, id = 'id_contenteditor', tries = 0) {
    const ed = getActiveEditor(id);
    if (ed.inst || tries > 40) {
        cb();
        return;
    }

    window.setTimeout(() => {
        whenEditorReady(cb, id, tries + 1);
    }, 150);
}

/* ---------- itemid helpers ---------- */
/**
 * Set draft item id for the editor.
 *
 * @param {number|string} id Item id
 */
function setItemId(id) {    const h = document.getElementById('contenteditor_itemid');
    if (h) {
        h.value = String(id);
    }
}

/**
 * Get current draft item id.
 *
 * @returns {number}
 */
function getItemId() {    const h = document.getElementById('contenteditor_itemid');
    return h ? Number(h.value) : 0;
}

/* ========================================================= */

export const init = () => {
    const stepInput = document.querySelector('input[name="step"]');
    if (!stepInput || stepInput.value !== '4') {
        return;
    }

    const step4Form = document.getElementById('step4-form');
    if (!step4Form) {
        return;
    }

    const subtopicContents = readJsonScript('topics-data', {});
    const quizContents = readJsonScript('quiz-data', {});

    let currentSubtopicTitle = null;
    let currentQuizTitle = null;

    /* ---------- Subtopic logic ---------- */
    const loadSubtopicContent = (title) => {
        const obj = subtopicContents[title] || {
            text: '<p><em>No content available.</em></p>',
            itemid: 0,
        };

        whenEditorReady(() => {
            const text = typeof obj === 'object' ? obj.text : obj;
            const itemid = typeof obj === 'object' ? obj.itemid : 0;
            setEditorContent(text);
            setItemId(itemid);
        });

        const t = document.getElementById('content-title');
        if (t) {
            t.innerText = title;
        }
    };

    const saveCurrent = () => {
        if (!currentSubtopicTitle) {
            return;
        }

        subtopicContents[currentSubtopicTitle] = {
            text: getEditorContent(),
            itemid: getItemId(),
        };
    };

    /* ---------- Quiz rendering ---------- */
    const showQuizContent = (title) => {
        const quizData = quizContents[title];
        const container = document.getElementById('quiz-content');

        if (!container) {
            return;
        }

        container.innerHTML = '';
        currentQuizTitle = title;

        if (!quizData || !quizData.questions || !quizData.questions.length) {
            container.innerHTML = '<p><em>No questions found.</em></p>';

            const contentTitle = document.getElementById('content-title');
            if (contentTitle) {
                contentTitle.innerText = title;
            }

            return;
        }

        quizData.questions.forEach((q, index) => {
            const qDiv = document.createElement('div');
            qDiv.className = 'quiz-question mb-4 p-3 border rounded';
            qDiv.dataset.index = String(index);

            qDiv.innerHTML = `
                <p><strong>Q${index + 1}:</strong> <span class="question-text"></span></p>
                <ul class="quiz-options"></ul>
                <p><em>Answer:</em> <span class="answer-text"></span></p>
                <p><em>Explanation:</em> <span class="explanation-text"></span></p>
                <button type="button" class="btn btn-sm btn-outline-primary edit-question-btn">Edit</button>
                <button type="button" class="btn btn-sm btn-outline-danger ms-2 delete-question-btn">Delete</button>
                <button type="button" class="btn btn-sm btn-success save-question-btn d-none">Save</button>
                <button type="button" class="btn btn-sm btn-secondary cancel-question-btn d-none ms-2">Cancel</button>
            `;

            qDiv.querySelector('.question-text').textContent = q.question || '';
            qDiv.querySelector('.answer-text').textContent =
                q.correct_answer || q.answer || '';
            qDiv.querySelector('.explanation-text').textContent =
                q.explanation || '';

            const ul = qDiv.querySelector('.quiz-options');
            (q.options || []).forEach((opt) => {
                const li = document.createElement('li');
                li.textContent = opt;
                ul.appendChild(li);
            });

            container.appendChild(qDiv);
        });

        const contentTitle = document.getElementById('content-title');
        if (contentTitle) {
            contentTitle.innerText = title;
        }
    };

    /* ---------- Build one canonical payload ---------- */
    /**
     * Build final submission payload for step 4.
     *
     * @returns {Object}
     */
    function buildPayload() {
        // capture current editor state into subtopicContents
        saveCurrent();

        const topics = [];
        document.querySelectorAll('.topic-item').forEach(li => {
            const header = li.querySelector('.topic-header');
            const title = header ? header.childNodes[0].textContent.trim() : 'Untitled Topic';

            // subtopics (respect left panel ordering)
            const subtopics = [...li.querySelectorAll('.subtopic-item[data-type="subtopic"]')].map(div => {
                const st = div.dataset.subtitle;
                const obj = subtopicContents[st] || { text: '<p><em>No content.</em></p>', itemid: 0 };
                const text = (obj && typeof obj === 'object' && 'text' in obj) ? obj.text : obj;
                const itemid = (obj && typeof obj === 'object' && 'itemid' in obj) ? Number(obj.itemid) : 0;
                return { title: st, content: { text, itemid }, type: 'page' };
            });

            // quiz (optional)
            let quiz = null;
            const quizEl = li.querySelector('.subtopic-item[data-type="quiz"]');
            if (quizEl) {
                const qtitle = quizEl.dataset.subtitle;
                const qdata = quizContents[qtitle];
                if (qdata && Array.isArray(qdata.questions)) {
                    quiz = {
                        quiz_title: qtitle,
                        instructions: qdata.instructions || '',
                        questions: qdata.questions.map((q, i) => ({
                            question_id: q.question_id || `q${i + 1}`,
                            type: q.type || 'multiple_choice',
                            difficulty: q.difficulty || 'easy',
                            question: (q.question || '').trim(),
                            options: Array.isArray(q.options) ? q.options.map(o => (o || '').trim()) : [],
                            correct_answer: (q.correct_answer || q.answer || '').trim(),
                            explanation: (q.explanation || '').trim()
                        }))
                    };
                }
            }

            topics.push({ title, subtopics, ...(quiz ? { quiz } : {}) });
        });
        return {
            meta: { courseid: Number(document.querySelector('input[name="id"]').value), step: 4, version: 1 },
            topics
        };
    }

    /* ---------- Chunking helper (avoids post_max_size pain) ---------- */
    /**
     * Store JSON payload into hidden inputs with chunking.
     *
     * @param {string} jsonStr JSON string
     * @param {number} chunkSize Chunk size limit
     */
    function setPayloadHidden(jsonStr, chunkSize = 200000) {        const form = document.getElementById('step4-form');

        // remove old chunks
        [...form.querySelectorAll('input[name^="payload_"]')].forEach(n => n.remove());
        const payloadField = document.getElementById('payload');
        const partsField = document.getElementById('payloadparts');

        if (jsonStr.length <= chunkSize) {
            payloadField.value = jsonStr;
            if (partsField){
                partsField.value = '0';
            }
            return;
        }
        payloadField.value = '';
        const num = Math.ceil(jsonStr.length / chunkSize);
        for (let i = 0; i < num; i++) {
            const hidden = document.createElement('input');
            hidden.type = 'hidden';
            hidden.name = `payload_${i + 1}`;
            hidden.value = jsonStr.slice(i * chunkSize, (i + 1) * chunkSize);
            form.appendChild(hidden);
        }
        if (partsField){
            partsField.value = String(num);
        }
    }

    /* ------------ DOM Ready ------------ */
    document.addEventListener('DOMContentLoaded', () => {
        // prevent accidental submits on Enter (outside textareas)
        document.getElementById('step4-form')?.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' && e.target.tagName !== 'TEXTAREA'){
                e.preventDefault();
            }
        });

        // initial load
        const firstSub = document.querySelector('.subtopic-item[data-type="subtopic"]') || document.querySelector('.subtopic-item');
        if (firstSub) {
            currentSubtopicTitle = firstSub.dataset.subtitle;
            firstSub.classList.add('active');   // highlight as default active
            loadSubtopicContent(currentSubtopicTitle);
            document.getElementById('quiz-display')?.classList.add('d-none');
            document.getElementById('content-body').style.display = '';
        }
        document.querySelectorAll('.topic-header').forEach(header => {
            header.addEventListener('click', () => {
                const subtopics = header.nextElementSibling;
                const icon = header.querySelector('.toggle-icon');

                if (subtopics.classList.contains('show')) {
                    subtopics.classList.remove('show');
                    icon.textContent = '+';
                } else {
                    subtopics.classList.add('show');
                    icon.textContent = '–';
                }
            });
        });
        // delegate clicks
        document.addEventListener('click', (e) => {
            const item = e.target.closest('.subtopic-item');
            if (!item){
                return;
            }
            document.querySelectorAll('.subtopic-item.active').forEach(i => i.classList.remove('active'));
            item.classList.add('active');

            const type = item.dataset.type || 'subtopic';
            const title = item.dataset.subtitle;

            const contentBody = document.getElementById('content-body');
            const quizDisplay = document.getElementById('quiz-display');
            if (type === 'subtopic') {
                if (title === currentSubtopicTitle){
                    return;
                }
                saveCurrent();
                currentQuizTitle = null;
                currentSubtopicTitle = title;
                quizDisplay?.classList.add('d-none');
                contentBody.style.display = '';
                loadSubtopicContent(title);
            } else if (type === 'quiz') {
                saveCurrent();
                currentSubtopicTitle = null;
                contentBody.style.display = 'none';
                quizDisplay?.classList.remove('d-none');
                showQuizContent(title);
            }
        });

        document.getElementById('add-new-quiz-btn')?.addEventListener('click', () => {
            if (!currentQuizTitle || !quizContents[currentQuizTitle]){
                return;
            }
            const questions = quizContents[currentQuizTitle].questions;
            const newIndex = questions.length;
            questions.push({
                question_id: `q${newIndex + 1}`,
                type: 'multiple_choice',
                difficulty: 'easy',
                question: '',
                options: ['', '', '', ''],
                correct_answer: '',
                explanation: ''
            });            showQuizContent(currentQuizTitle);
            setTimeout(() => {
                const lastCard = document.querySelector(`.quiz-question[data-index="${newIndex}"]`);
                if (lastCard) { lastCard.dataset.unsaved = 'true'; lastCard.querySelector('.edit-question-btn')?.click(); }
            }, 100);
        });


        document.getElementById('step4-form').addEventListener('submit', (e) => {
            const isScorm = e.submitter && e.submitter.value === 'generate_scorm';
            if (!isScorm) {
                document.querySelectorAll('#step4-form input[type="hidden"]').forEach(inp => {
                    if (inp.name === 'activelang'){
                        return; // 🔒 DO NOT TOUCH
                    }
                    if (inp.id.endsWith('_hidden')){
                        inp.remove();
                    }
                    if (inp.name === 'make_scorm'){
                        inp.remove();
                    }
                    if (inp.name === 'scormtype'){
                        inp.remove();
                    }
                    if (inp.name === 'completiontype'){
                        inp.remove();
                    }
                    if (inp.name === 'requiredslides'){
                        inp.remove();
                    }
                    if (inp.name === 'passingscore'){
                        inp.remove();
                    }
                    if (inp.name === 'quizmode'){
                        inp.remove();
                    }
                    if (inp.name === 'quizselection'){
                        inp.remove();
                    }
                    if (inp.name === 'requiredscos'){
                        inp.remove();
                    }
                    if (inp.name === 'completionquizmulti'){
                        inp.remove();
                    }
                    if (inp.name === 'multiquizmode'){
                        inp.remove();
                    }
                    if (inp.name === 'multipassingscore'){
                        inp.remove();
                    }
                    if (inp.name === 'multiquizselection'){
                        inp.remove();
                    }
                });

            }
            // normalize edited quiz questions (unchanged from your code)
            const quizBlocks = document.querySelectorAll('.quiz-question');
            quizBlocks.forEach(w => {
                const index = w.getAttribute('data-index');
                const qIn = w.querySelector('.question-input');
                const optIn = w.querySelectorAll('.option-input');
                const ansIn = w.querySelector('.answer-input');
                const exIn = w.querySelector('.explanation-input');
                if (qIn && optIn.length && currentQuizTitle && quizContents[currentQuizTitle]?.questions) {
                    const original = quizContents[currentQuizTitle].questions[index];
                    quizContents[currentQuizTitle].questions[index] = {
                        question_id: original.question_id || 'q' + (parseInt(index) + 1),
                        type: original.type || 'multiple_choice',
                        difficulty: original.difficulty || 'easy',
                        question: qIn.value.trim(),
                        options: Array.from(optIn).map(i => i.value.trim()),
                        correct_answer: ansIn?.value.trim() || original.correct_answer || '',
                        explanation: exIn?.value.trim() || ''
                    };
                }
            });

            // also normalize untouched questions
            for (const qtitle in quizContents) {
                if (!quizContents[qtitle]?.questions){
                    continue;
                }
                quizContents[qtitle].questions = quizContents[qtitle].questions.map((q, idx) => ({
                    question_id: q.question_id || `q${idx + 1}`,
                    type: q.type || 'multiple_choice',
                    difficulty: q.difficulty || 'easy',
                    question: (q.question || '').trim(),
                    options: Array.isArray(q.options) ? q.options.map(o => (o || '').trim()) : [],
                    correct_answer: (q.correct_answer || q.answer || '').trim(),
                    explanation: (q.explanation || '').trim()
                }));
            }

            // Build the single payload and place it in hidden inputs (with chunking if large)
            const json = JSON.stringify(buildPayload());
            setPayloadHidden(json); // will fill #payload or create payload_1..N + payloadparts

            // Also fill legacy fields for back-compat (safe to remove after server switch)
            document.getElementById('topicsjson').value = JSON.stringify(subtopicContents);
            document.getElementById('quizjson').value = JSON.stringify(quizContents);
        });

        // Optional: explicitly flag savedraft on click (keeps legacy button name/value too)
        document.getElementById('savedraft-btn')?.addEventListener('click', () => {
            // nothing else required; name="savedraft" value="1" already posts
            // we just ensure editor state is captured before submit:
            saveCurrent();
        });
        document.getElementById('generate-scorm-btn')?.addEventListener('click', () => {
            // nothing else required; name="make_scorm" value="1" already posts
            // we just ensure editor state is captured before submit:
            saveCurrent();
        });
    });
};