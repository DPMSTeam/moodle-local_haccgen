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
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 *
 * @module     local_haccgen/step3_form
 * @copyright  2026 Dynamicpixel Multimedia Solutions
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/* ---------- XSS guard ---------- */
const escapeHtml = (str) =>
    (str || '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');

export const init = () => {
    /* ---------- Step guard ---------- */
    const stepInput = document.querySelector('input[name="step"]');
    if (!stepInput || stepInput.value !== '3') {
        return;
    }

    /* ---------- Local state ---------- */
    let isAddingNewTopic = false;
    let currentEditingItem = null;
    let draggedItem = null;

    /* ---------- Core elements ---------- */
    const topicsWrapper = document.getElementById('topics-wrapper');
    const topicOrderInput = document.getElementById('topic-order');
    const addTopicBtn = document.getElementById('add-topic-btn');
    const form = document.getElementById('haccgen-form');
    const saveBtn = document.getElementById('save-btn');

    if (!topicsWrapper || !topicOrderInput || !addTopicBtn || !form || !saveBtn) {
        return;
    }

    const backBtn = form.querySelector('button[name="action"][value="back"]');

    /* ---------- Modal elements ---------- */
    const editModal = document.getElementById('editTopicModal');
    const editForm = document.getElementById('edit-topic-form');
    const titleInput = document.getElementById('edit-topic-title');
    const descriptionInput = document.getElementById('edit-topic-description');
    const durationInput = document.getElementById('edit-topic-duration');
    const objectivesList = document.getElementById('edit-topic-objectives');
    const editIdInput = document.getElementById('edit-topic-id');

    const newObjectiveInput = document.getElementById('new-objective-input');
    const addObjectiveBtn = document.getElementById('add-objective-btn');

    const quizYesRadio = document.getElementById('quiz-yes');
    const quizNoRadio = document.getElementById('quiz-no');
    const quizQuestionCountGroup = document.getElementById('quiz-question-count-group');
    const quizQuestionInput = document.getElementById('edit-topic-quiz-questions');

    if (
        !editModal || !editForm || !titleInput || !descriptionInput ||
        !durationInput || !objectivesList || !editIdInput ||
        !newObjectiveInput || !addObjectiveBtn ||
        !quizYesRadio || !quizNoRadio ||
        !quizQuestionCountGroup || !quizQuestionInput
    ) {
        return;
    }

    /* ---------- Bootstrap modal ---------- */
    const hasBootstrap =
        typeof window.bootstrap !== 'undefined' && window.bootstrap.Modal;

    const openModal = () => {
        if (hasBootstrap) {
            new window.bootstrap.Modal(editModal).show();
        }
    };

    const closeModal = () => {
        if (!hasBootstrap) {
            return;
        }
        const instance = window.bootstrap.Modal.getInstance(editModal);
        if (instance) {
            instance.hide();
        }
    };

    /* ---------- Prevent Enter submit ---------- */
    editForm.addEventListener('keydown', (e) => {
        if (
            e.key === 'Enter' &&
            e.target.tagName !== 'TEXTAREA' &&
            e.target.id !== 'new-objective-input'
        ) {
            e.preventDefault();
        }
    });

    /* ---------- Objectives ---------- */
    addObjectiveBtn.addEventListener('click', () => {
        const text = newObjectiveInput.value.trim();
        if (!text) {
            return;
        }

        const li = document.createElement('li');
        li.className = 'list-group-item d-flex justify-content-between align-items-center';

        const span = document.createElement('span');
        span.textContent = text;

        const removeBtn = document.createElement('button');
        removeBtn.type = 'button';
        removeBtn.className = 'btn btn-sm btn-danger ms-2';
        removeBtn.textContent = '×';
        removeBtn.addEventListener('click', () => li.remove());

        li.appendChild(span);
        li.appendChild(removeBtn);
        objectivesList.appendChild(li);

        newObjectiveInput.value = '';
    });

    newObjectiveInput.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
            e.preventDefault();
            addObjectiveBtn.click();
        }
    });

    /* ---------- Quiz toggle ---------- */
    const toggleQuizGroup = () => {
        quizQuestionCountGroup.style.display =
            quizYesRadio.checked ? 'block' : 'none';
    };

    quizYesRadio.addEventListener('change', toggleQuizGroup);
    quizNoRadio.addEventListener('change', toggleQuizGroup);

    /* ---------- Topic actions ---------- */
    topicsWrapper.addEventListener('click', (e) => {
        const item = e.target.closest('.topic-item');
        if (!item) {
            return;
        }

        if (e.target.closest('.delete-btn')) {
            item.remove();
            updateTopicOrder();
            return;
        }

        if (e.target.closest('.edit-btn')) {
            let data = {};
            try {
                data = JSON.parse(decodeURIComponent(item.dataset.topicdata || '{}'));
            } catch (ignore) { }

            editIdInput.value = data.id || '';
            titleInput.value = data.title || '';
            descriptionInput.value = data.description || '';
            durationInput.value = data.estimated_duration || '';

            quizYesRadio.checked = !!data.has_quiz;
            quizNoRadio.checked = !data.has_quiz;
            toggleQuizGroup();

            quizQuestionInput.value = data.quiz_question_count || 1;

            objectivesList.innerHTML = '';
            (data.learning_objectives || []).forEach((obj) => {
                const li = document.createElement('li');
                li.className = 'list-group-item d-flex justify-content-between align-items-center';

                const span = document.createElement('span');
                span.textContent = obj;

                const removeBtn = document.createElement('button');
                removeBtn.type = 'button';
                removeBtn.className = 'btn btn-sm btn-danger ms-2';
                removeBtn.textContent = '×';
                removeBtn.addEventListener('click', () => li.remove());

                li.appendChild(span);
                li.appendChild(removeBtn);
                objectivesList.appendChild(li);
            });

            currentEditingItem = item;
            isAddingNewTopic = false;
            openModal();
        }
    });

    /* ---------- Add topic ---------- */
    addTopicBtn.addEventListener('click', () => {
        editForm.reset();
        objectivesList.innerHTML = '';
        quizNoRadio.checked = true;
        toggleQuizGroup();

        editIdInput.value = '';
        currentEditingItem = null;
        isAddingNewTopic = true;

        openModal();
    });

    /* ---------- Save topic ---------- */
    editForm.addEventListener('submit', (e) => {
        e.preventDefault();

        const title = titleInput.value.trim();
        if (!title) {
            return;
        }

        const data = {
            id: editIdInput.value || `topic-${Date.now()}`,
            title,
            description: descriptionInput.value.trim(),
            estimated_duration: durationInput.value.trim(),
            learning_objectives: Array.from(
                objectivesList.querySelectorAll('li span')
            ).map((s) => s.textContent),
            has_quiz: quizYesRadio.checked,
            quiz_question_count: quizYesRadio.checked
                ? parseInt(quizQuestionInput.value || '1', 10)
                : 0,
            content: ''
        };

        const encoded = encodeURIComponent(JSON.stringify(data));

        if (isAddingNewTopic) {
            const index = topicsWrapper.children.length;
            const div = document.createElement('div');

            div.className = 'topic-item';
            div.draggable = true;
            div.dataset.topicdata = encoded;

            div.innerHTML = `
                <span>Topic ${index + 1}: ${escapeHtml(title)}</span>
                <div>
                    <button type="button" class="move-btn">⇅</button>
                    <button type="button" class="edit-btn">✎</button>
                    <button type="button" class="delete-btn">🗑</button>
                </div>
            `;

            topicsWrapper.appendChild(div);
        } else if (currentEditingItem) {
            currentEditingItem.dataset.topicdata = encoded;
            const span = currentEditingItem.querySelector('span');
            if (span) {
                span.textContent = span.textContent.replace(/:.*/, `: ${title}`);
            }
        }

        updateTopicOrder();
        closeModal();
        saveBtn.disabled = false;
    });

    /**
     * Update topic ordering after add, delete, or drag-and-drop.
     *
     * - Renumbers topic labels in the UI
     * - Rebuilds the ordered topic data array
     * - Updates the hidden topic-order input as JSON
     *
     * @returns {void}
     */
    function updateTopicOrder() {
        const order = Array.from(
            topicsWrapper.querySelectorAll('.topic-item')
        ).map((item, index) => {
            const span = item.querySelector('span');
            if (span) {
                span.textContent = span.textContent.replace(
                    /^Topic \d+/,
                    `Topic ${index + 1}`
                );
            }

            let data = {};
            try {
                data = JSON.parse(decodeURIComponent(item.dataset.topicdata || '{}'));
            } catch (ignore) { }

            return data;
        });

        topicOrderInput.value = JSON.stringify(order);
    }

    topicsWrapper.addEventListener('dragstart', (e) => {
        draggedItem = e.target.closest('.topic-item');
    });

    topicsWrapper.addEventListener('dragover', (e) => {
        e.preventDefault();

        const target = e.target.closest('.topic-item');
        if (!target || !draggedItem || target === draggedItem) {
            return;
        }

        const rect = target.getBoundingClientRect();
        const after = (e.clientY - rect.top) > (rect.height / 2);

        topicsWrapper.insertBefore(
            draggedItem,
            after ? target.nextSibling : target
        );
    });

    topicsWrapper.addEventListener('drop', (e) => {
        e.preventDefault();
        draggedItem = null;
        updateTopicOrder();
    });

    /* ---------- Spinner ---------- */
    const spinnerHTML =
        '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span> Generating…';
    form.addEventListener('submit', (e) => {
        const submitter = e.submitter;

        if (submitter !== saveBtn) {
            return;
        }

        // Let the browser submit first
        window.setTimeout(() => {
            saveBtn.disabled = true;

            if (backBtn) {
                backBtn.disabled = true;
            }

            addTopicBtn.disabled = true;
            saveBtn.innerHTML = spinnerHTML;
        }, 0);
    });

    updateTopicOrder();
};