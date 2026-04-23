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
 * @module     local_haccgen/ai_form
 * @copyright  2026 Dynamicpixel Multimedia Solutions
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

export const init = () => {
    // No DOMContentLoaded in AMD init; template runs after render.
    const stepInput = document.querySelector('input[name="step"]');
    if (!stepInput) {
        return;
    }

    let activelang = '';

    const buildLanguagePrompt = (lang) => {
        const L = (lang || '').trim();

        if (!L) {
            return '';
        }

        const line1 = `[AI Language]: ${L}`;
        const line2 =
            `Please generate all content in ${L}, and localize terminology,`;
        const line3 =
            'examples, UI labels, and tone accordingly.';

        return `${line1}\n${line2}\n${line3}`;
    };
    const stripLangPrompt = (text) => {
        if (!text){
            return '';
        }
        return text
            .replace(/\s*\[AI Language\]:[^\n]*(?:\n[^\n]*)?/gi, '')
            .trim();
    };

    // --- Language selector bootstrap ---
    const LANG_OPTIONS = [
        'English',
        'हिन्दी (Hindi)',
        'తెలుగు (Telugu)',
        'தமிழ் (Tamil)',
        'ಕನ್ನಡ (Kannada)',
        'বাংলা (Bengali)',
    ];

    const selectEl = document.getElementById('language-select');
    const hiddenEl = document.getElementById('active-lang');
    const promptEl = document.getElementById('lang-prompt');

    const guessFromNavigator = () => {
        const nav = (navigator.language || navigator.userLanguage || '').toLowerCase();
        if (!nav) {
            return '';
        }

        if (nav.startsWith('en')){
            return 'English';
        }
        if (nav.startsWith('hi')){
            return 'हिन्दी (Hindi)';
        }
        if (nav.startsWith('te')){
            return 'తెలుగు (Telugu)';
        }
        if (nav.startsWith('ta')){
            return 'தமிழ் (Tamil)';
        }
        if (nav.startsWith('kn')){
            return 'ಕನ್ನಡ (Kannada)';
        }
        if (nav.startsWith('bn')){
            return 'বাংলা (Bengali)';
        }

        return '';
    };

    const applyLang = (lang) => {
        activelang = (lang || '').trim();
        if (hiddenEl) {
            hiddenEl.value = activelang;
        }
        if (promptEl) {
            promptEl.textContent = buildLanguagePrompt(activelang);
        }
        try {
            window.localStorage.setItem('haccgen_activelang', activelang);
        } catch (e) {
            // ignore
        }
    };

    const fillOptions = () => {
        if (!selectEl){
            return;
        }
        selectEl.innerHTML = '';
        LANG_OPTIONS.forEach((l) => {
            const opt = document.createElement('option');
            opt.value = l;
            opt.textContent = l;
            selectEl.appendChild(opt);
        });
    };

    const bootstrapLang = () => {
        if (!selectEl){
            return;
        }

        const saved = (() => {
            try {
                return window.localStorage.getItem('haccgen_activelang') || '';
            } catch (e) {
                return '';
            }
        })();

        const initial = saved || guessFromNavigator() || LANG_OPTIONS[0];
        const idx = LANG_OPTIONS.indexOf(initial);
        selectEl.selectedIndex = idx >= 0 ? idx : 0;
        applyLang(selectEl.value);
    };

    if (selectEl) {
        fillOptions();
        bootstrapLang();
        selectEl.addEventListener('change', () => applyLang(selectEl.value));
    }

    // Provide getter like you had.
    window.getActiveLang = () => (hiddenEl ? hiddenEl.value : activelang);

    // --- Elements for toggling form sections ---
    const form = document.getElementById('haccgen-form');
    const radioButtons = document.querySelectorAll('input[name="generationtype"]');
    const aiFields = document.getElementById('ai-generation-fields');
    const uploadedFields = document.getElementById('uploaded-content-fields');

    // AI generation elements
    const tagInputAI = document.getElementById('tag-input-ai');
    const tagContainerAI = document.getElementById('tag-container-ai');
    const hiddenInputAI = document.getElementById('targetaudience-hidden-ai');
    const TOPICTITLEInputAI = document.getElementById('TOPICTITLE_ai');
    const descriptionInputAI = document.getElementById('description_ai');
    let tagsAI = [];

    // Uploaded elements
    const tagInputUploaded = document.getElementById('tag-input-uploaded');
    const tagContainerUploaded = document.getElementById('tag-container-uploaded');
    const hiddenInputUploaded = document.getElementById('targetaudience-hidden-uploaded');
    const TOPICTITLEInputUploaded = document.getElementById('TOPICTITLE_uploaded');
    const pdfInput = document.getElementById('pdf_upload');
    const descriptionInputUploaded = document.getElementById('description_uploaded');
    let tagsUploaded = [];

    // Preview elements
    const previewTitle = document.getElementById('preview-title');
    const previewAudience = document.getElementById('preview-audience');
    const previewDescription = document.getElementById('preview-description');
    const previewDescriptionValue = document.getElementById('preview-description-value');
    const previewPdf = document.getElementById('preview-pdf');
    const previewPdfValue = document.getElementById('preview-pdf-value');

    // Strip any prompt that slipped into textareas from previous submits
    if (descriptionInputAI) {
        descriptionInputAI.value = stripLangPrompt(descriptionInputAI.value);
    }
    if (descriptionInputUploaded) {
        descriptionInputUploaded.value = stripLangPrompt(descriptionInputUploaded.value);
    }

    // NOTE: These were previously injected by Mustache into JS strings.
    // To keep this AMD module reusable and reviewer-friendly, read initial values from DOM inputs instead.
    // (If you truly need server values, pass them via data-* attributes and read them here.)

    // Initialize tag arrays from existing hidden input values if present.
    const initTagsFromHidden = (hiddenInput) => {
        const v = hiddenInput ? (hiddenInput.value || '') : '';
        return v.split(',').map(t => t.trim()).filter(Boolean);
    };

    tagsAI = initTagsFromHidden(hiddenInputAI);
    tagsUploaded = initTagsFromHidden(hiddenInputUploaded);

    const updateTagsAI = () => {
        if (!tagContainerAI || !hiddenInputAI || !tagInputAI){
            return;
        }

        tagContainerAI.innerHTML = '';
        tagsAI.forEach((tag, i) => {
            const el = document.createElement('span');
            el.className = 'tag';
            el.innerHTML = `${tag} <span class="remove-tag remove-tag-ai" data-index="${i}">×</span>`;
            tagContainerAI.appendChild(el);
        });

        hiddenInputAI.value = tagsAI.join(',');
        tagContainerAI.appendChild(tagInputAI);

        // Bind removes just for AI
        tagContainerAI.querySelectorAll('.remove-tag-ai').forEach((btn) => {
            btn.addEventListener('click', function () {
                const i = Number(this.getAttribute('data-index'));
                if (!Number.isNaN(i)) {
                    tagsAI.splice(i, 1);
                    updateTagsAI();
                    updatePreview('ai');
                }
            });
        });
    };

    const updateTagsUploaded = () => {
        if (!tagContainerUploaded || !hiddenInputUploaded || !tagInputUploaded){
            return;
        }

        tagContainerUploaded.innerHTML = '';
        tagsUploaded.forEach((tag, i) => {
            const el = document.createElement('span');
            el.className = 'tag';
            el.innerHTML = `${tag} <span class="remove-tag remove-tag-uploaded" data-index="${i}">×</span>`;
            tagContainerUploaded.appendChild(el);
        });

        hiddenInputUploaded.value = tagsUploaded.join(',');
        tagContainerUploaded.appendChild(tagInputUploaded);

        // Bind removes just for uploaded
        tagContainerUploaded.querySelectorAll('.remove-tag-uploaded').forEach((btn) => {
            btn.addEventListener('click', function () {
                const i = Number(this.getAttribute('data-index'));
                if (!Number.isNaN(i)) {
                    tagsUploaded.splice(i, 1);
                    updateTagsUploaded();
                    updatePreview('uploaded');
                }
            });
        });
    };

    if (tagContainerAI && hiddenInputAI && tagInputAI) {
        updateTagsAI();
    }
    if (tagContainerUploaded && hiddenInputUploaded && tagInputUploaded) {
        updateTagsUploaded();
    }

    const updatePreview = (mode) => {
        let title = '';
        let audience = '';
        let description = '';

        if (mode === 'ai') {
            title = TOPICTITLEInputAI ? TOPICTITLEInputAI.value.trim() : '';
            audience = tagsAI.join(', ');
            description = descriptionInputAI ? descriptionInputAI.value.trim() : '';

            if (previewTitle){
                previewTitle.textContent = title || 'TOPIC TITLE';
            }
            if (previewAudience) {
                previewAudience.textContent = `This course is on ${title || 'TOPIC TITLE'} with ${audience || 'Target Audience'}.`;
            }
            if (previewDescription) {
                previewDescription.style.display = description ? 'block' : 'none';
                if (previewDescriptionValue){
                    previewDescriptionValue.textContent = description || '';
                }
            }
            if (previewPdf){
                previewPdf.style.display = 'none';
            }
        } else {
            title = TOPICTITLEInputUploaded ? TOPICTITLEInputUploaded.value.trim() : '';
            audience = tagsUploaded.join(', ');
            description = descriptionInputUploaded ? descriptionInputUploaded.value.trim() : '';
            const pdfFile = pdfInput && pdfInput.files && pdfInput.files[0];

            if (previewTitle){
                previewTitle.textContent = title || 'TOPIC TITLE';
            }
            if (previewAudience) {
                previewAudience.textContent = `This course is on ${title || 'TOPIC TITLE'} with ${audience || 'Target Audience'}.`;
            }
            if (previewDescription) {
                previewDescription.style.display = description ? 'block' : 'none';
                if (previewDescriptionValue){
                    previewDescriptionValue.textContent = description || '';
                }
            }
            if (previewPdf) {
                previewPdf.style.display = pdfFile ? 'block' : 'none';
                if (previewPdfValue){
                    previewPdfValue.textContent = pdfFile ? pdfFile.name : '';
                }
            }
        }

        // reset animations
        if (previewTitle){
            previewTitle.style.animation = 'none';
        }
        if (previewAudience){
            previewAudience.style.animation = 'none';
        }
        if (previewDescription){
            previewDescription.style.animation = 'none';
        }
        if (previewPdf){
            previewPdf.style.animation = 'none';
        }

        window.setTimeout(() => {
            if (previewTitle){
                previewTitle.style.animation = 'slideIn 0.5s ease';
            }
            if (previewAudience){
                previewAudience.style.animation = 'slideIn 0.5s ease 0.2s';
            }
            if (description && previewDescription){
                previewDescription.style.animation = 'slideIn 0.5s ease 0.4s';
            }
            if (pdfInput && pdfInput.files && pdfInput.files[0] && previewPdf) {
                previewPdf.style.animation = 'slideIn 0.5s ease 0.6s';
            }
        }, 10);
    };

    const toggleFields = (mode) => {
        const isAi = mode === 'ai';
        if (aiFields){
            aiFields.style.display = isAi ? 'block' : 'none';
        }
        if (uploadedFields){
            uploadedFields.style.display = isAi ? 'none' : 'block';
        }

        // AI inputs
        [TOPICTITLEInputAI, tagInputAI, hiddenInputAI, descriptionInputAI].forEach((input) => {
            if (!input){
                return;
            }
            input.disabled = !isAi;
            if (!isAi && input !== descriptionInputAI){
                input.value = '';
            }
            if (input.id === 'TOPICTITLE_ai'){
                input.required = isAi;
            }
        });

        // Uploaded inputs
        [TOPICTITLEInputUploaded, tagInputUploaded, hiddenInputUploaded, pdfInput, descriptionInputUploaded].forEach((input) => {
            if (!input){
                return;
            }
            input.disabled = isAi;
            if (isAi){
                input.value = '';
            }
            if (input.id === 'TOPICTITLE_uploaded' || input.id === 'pdf_upload') {
                input.required = !isAi;
            }
        });

        updatePreview(mode);
    };

    if (radioButtons && aiFields && uploadedFields) {
        radioButtons.forEach((radio) => {
            radio.addEventListener('change', function () {
                toggleFields(this.value);
            });
        });

        const selectedRadio = document.querySelector('input[name="generationtype"]:checked');
        toggleFields(selectedRadio ? selectedRadio.value : 'ai');
    }

    // Tag handlers (AI)
    const addTagAI = () => {
        if (!tagInputAI || !hiddenInputAI){
            return;
        }
        const tag = tagInputAI.value.trim();
        if (tag && !tagsAI.includes(tag)) {
            tagsAI.push(tag);
            tagInputAI.value = '';
            updateTagsAI();
            updatePreview('ai');
        }
    };

    if (tagInputAI) {
        tagInputAI.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' || e.key === ',') {
                e.preventDefault();
                addTagAI();
            }
        });
        tagInputAI.addEventListener('blur', addTagAI);
    }

    // Tag handlers (Uploaded)
    const addTagUploaded = () => {
        if (!tagInputUploaded || !hiddenInputUploaded){
            return;
        }
        const tag = tagInputUploaded.value.trim();
        if (tag && !tagsUploaded.includes(tag)) {
            tagsUploaded.push(tag);
            tagInputUploaded.value = '';
            updateTagsUploaded();
            updatePreview('uploaded');
        }
    };

    if (tagInputUploaded) {
        tagInputUploaded.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' || e.key === ',') {
                e.preventDefault();
                addTagUploaded();
            }
        });
        tagInputUploaded.addEventListener('blur', addTagUploaded);
    }

    // Live preview bindings
    if (TOPICTITLEInputAI){
        TOPICTITLEInputAI.addEventListener('input', () => updatePreview('ai'));
    }
    if (descriptionInputAI){
        descriptionInputAI.addEventListener('input', () => updatePreview('ai'));
    }

    if (TOPICTITLEInputUploaded){
        TOPICTITLEInputUploaded.addEventListener('input', () => updatePreview('uploaded'));
    }
    if (descriptionInputUploaded){
        descriptionInputUploaded.addEventListener('input', () => updatePreview('uploaded'));
    }
    if (pdfInput){
        pdfInput.addEventListener('change', () => updatePreview('uploaded'));
    }

    // Submit-time: append prompt for processing, keep UI clean
    if (form) {
        form.addEventListener('submit', function () {
            const lang = (window.getActiveLang && window.getActiveLang()) || activelang || '';

            // Ensure activelang is submitted even if the hidden input is outside the <form>
            let langField = form.querySelector('input[name="activelang"]');
            if (!langField) {
                langField = document.createElement('input');
                langField.type = 'hidden';
                langField.name = 'activelang';
                form.appendChild(langField);
            }
            langField.value = lang;

            const prompt = buildLanguagePrompt(lang);
            const selectedRadio = document.querySelector('input[name="generationtype"]:checked');
            const mode = (selectedRadio && selectedRadio.value) || 'ai';

            const rawAI = stripLangPrompt((document.getElementById('description_ai') || {}).value || '');
            const rawUploaded = stripLangPrompt((document.getElementById('description_uploaded') || {}).value || '');
            const raw = mode === 'ai' ? rawAI : rawUploaded;

            const withPrompt = prompt ? (raw ? `${raw}\n\n${prompt}` : prompt) : raw;

            const h1 = document.createElement('input');
            h1.type = 'hidden';
            h1.name = 'description_raw';
            h1.value = raw;
            form.appendChild(h1);

            const h2 = document.createElement('input');
            h2.type = 'hidden';
            h2.name = 'description_with_prompt';
            h2.value = withPrompt;
            form.appendChild(h2);

            const main = document.createElement('input');
            main.type = 'hidden';
            main.name = 'description';
            main.value = withPrompt;
            form.appendChild(main);
        });
    }

    // Initial preview
    updatePreview('ai');
};