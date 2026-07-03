// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Recording hub interactions.
 *
 * @module     mod_googlemeet/recording_hub
 * @copyright  2026 Eduardo Kraus
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import $ from 'jquery';
import Ajax from 'core/ajax';
import * as Notification from 'core/notification';
import Modal from 'core/modal';
import {getStrings} from 'core/str';

const COMPONENT = 'mod_googlemeet';

const stringRequests = [
    {key: 'question_generating', component: COMPONENT},
    {key: 'question_unpublish', component: COMPONENT},
    {key: 'question_unpublish_confirm', component: COMPONENT},
    {key: 'question_discard', component: COMPONENT},
    {key: 'question_discard_confirm', component: COMPONENT},
    {key: 'question_bulk_discard', component: COMPONENT},
    {key: 'question_bulk_discard_confirm', component: COMPONENT},
    {key: 'recording_rename', component: COMPONENT},
    {key: 'question_empty_student', component: COMPONENT},
    {key: 'ai_error_unknown', component: COMPONENT},
    {key: 'practice_correct', component: COMPONENT},
    {key: 'practice_incorrect', component: COMPONENT},
    {key: 'practice_correct_answer', component: COMPONENT},
    {key: 'practice_finish', component: COMPONENT},
    {key: 'practice_next', component: COMPONENT},
    {key: 'name', component: 'core'},
    {key: 'cancel', component: 'core'},
    {key: 'savechanges', component: 'core'},
];

let initialised = false;
let settings = {};
let strings = {};

/**
 * Load and cache the strings used by this module.
 *
 * @returns {Promise<void>}
 */
const loadStrings = () => getStrings(stringRequests).then(values => {
    stringRequests.forEach((request, index) => {
        strings[request.key] = values[index];
    });
});

/**
 * Escape text for safe insertion into HTML strings.
 *
 * @param {string} value Raw text.
 * @returns {string}
 */
const escapeHtml = value => $('<div>').text(value || '').html();

/**
 * Get the currently active hub tab target.
 *
 * @returns {string}
 */
const activeTabTarget = () => $('#googlemeet-recording-hub .nav-link.active[data-bs-target]')
    .attr('data-bs-target') || '';

/**
 * Remember active tab and scroll position for a reload.
 *
 * @returns {void}
 */
const rememberHubState = () => {
    try {
        sessionStorage.setItem(settings.hubStateKey, JSON.stringify({
            tab: activeTabTarget(),
            y: window.pageYOffset || document.documentElement.scrollTop || 0,
        }));
    } catch {
        return;
    }
};

/**
 * Reload the hub after preserving tab/scroll state.
 *
 * @returns {void}
 */
const reloadHub = () => {
    rememberHubState();
    window.location.reload();
};

/**
 * Restore tab and scroll state after a reload.
 *
 * @returns {void}
 */
const restoreHubState = () => {
    let raw = '';
    try {
        raw = sessionStorage.getItem(settings.hubStateKey) || '';
        sessionStorage.removeItem(settings.hubStateKey);
    } catch {
        raw = '';
    }
    if (!raw) {
        return;
    }

    let state = {};
    try {
        state = JSON.parse(raw);
    } catch {
        state = {};
    }

    if (state.tab) {
        const tab = $('#googlemeet-recording-hub .nav-link[data-bs-target]').filter(function() {
            return $(this).attr('data-bs-target') === state.tab;
        }).get(0);
        if (tab) {
            if (window.bootstrap && window.bootstrap.Tab) {
                window.bootstrap.Tab.getOrCreateInstance(tab).show();
            } else if (typeof $(tab).tab === 'function') {
                $(tab).tab('show');
            }
        }
    }

    setTimeout(() => {
        window.scrollTo(0, parseInt(state.y, 10) || 0);
    }, 80);
};

/**
 * Show a save/cancel confirmation modal.
 *
 * @param {string} title Modal title.
 * @param {string} body Modal body.
 * @param {string} label Save button label.
 * @param {HTMLElement} triggerElement Element opening the modal.
 * @param {Function} callback Confirmation callback.
 * @returns {void}
 */
const confirmAction = (title, body, label, triggerElement, callback) => {
    Notification.saveCancelPromise(title, body, label, {triggerElement: triggerElement})
        .then(callback)
        .catch(() => {
            return;
        });
};

/**
 * Call an external function and reload on success.
 *
 * @param {string} methodname Web service method name.
 * @param {Object} args Web service arguments.
 * @returns {JQuery.Promise}
 */
const call = (methodname, args) => Ajax.call([{methodname: methodname, args: args}])[0]
    .then(() => {
        reloadHub();
    })
    .fail(Notification.exception);

/**
 * Return selected question IDs.
 *
 * @returns {Array}
 */
const selectedIds = () => $('.googlemeet-question-select:checked').map(function() {
    return parseInt(this.value, 10);
}).get();

/**
 * Enable or disable bulk action buttons.
 *
 * @returns {void}
 */
const refreshBulkState = () => {
    const hasSelection = selectedIds().length > 0;
    $('.googlemeet-question-bulk-publish, .googlemeet-question-bulk-discard').prop('disabled', !hasSelection);
};

/**
 * Bind teacher question-management actions.
 *
 * @returns {void}
 */
const bindQuestionManagement = () => {
    $('.googlemeet-question-generate').on('click', function() {
        $(this).prop('disabled', true).text(strings.question_generating);
        call('mod_googlemeet_queue_generate_questions', {
            recordingid: settings.recordingid,
            coursemoduleid: settings.cmid,
            count: parseInt($(this).attr('data-count'), 10) || 10,
            sesskey: settings.sesskey,
        });
    });

    $('.googlemeet-question-publish').on('click', function() {
        call('mod_googlemeet_publish_questions', {
            recordingid: settings.recordingid,
            coursemoduleid: settings.cmid,
            questionids: [parseInt($(this).closest('.googlemeet-question-card').attr('data-questionid'), 10)],
            sesskey: settings.sesskey,
        });
    });

    $('.googlemeet-question-unpublish').on('click', function() {
        const button = this;
        const questionid = parseInt($(this).closest('.googlemeet-question-card').attr('data-questionid'), 10);
        confirmAction(
            strings.question_unpublish,
            strings.question_unpublish_confirm,
            strings.question_unpublish,
            button,
            () => {
                call('mod_googlemeet_unpublish_questions', {
                    recordingid: settings.recordingid,
                    coursemoduleid: settings.cmid,
                    questionids: [questionid],
                    sesskey: settings.sesskey,
                });
            }
        );
    });

    $('.googlemeet-question-discard').on('click', function() {
        const button = this;
        const questionid = parseInt($(this).closest('.googlemeet-question-card').attr('data-questionid'), 10);
        confirmAction(
            strings.question_discard,
            strings.question_discard_confirm,
            strings.question_discard,
            button,
            () => {
                call('mod_googlemeet_discard_questions', {
                    recordingid: settings.recordingid,
                    coursemoduleid: settings.cmid,
                    questionids: [questionid],
                    sesskey: settings.sesskey,
                });
            }
        );
    });

    $('.googlemeet-question-select').on('change', refreshBulkState);
    refreshBulkState();

    $('.googlemeet-question-bulk-publish').on('click', () => {
        const ids = selectedIds();
        if (!ids.length) {
            return;
        }
        call('mod_googlemeet_publish_questions', {
            recordingid: settings.recordingid,
            coursemoduleid: settings.cmid,
            questionids: ids,
            sesskey: settings.sesskey,
        });
    });

    $('.googlemeet-question-bulk-discard').on('click', function() {
        const ids = selectedIds();
        if (!ids.length) {
            return;
        }
        confirmAction(
            strings.question_bulk_discard,
            strings.question_bulk_discard_confirm,
            strings.question_bulk_discard,
            this,
            () => {
                call('mod_googlemeet_discard_questions', {
                    recordingid: settings.recordingid,
                    coursemoduleid: settings.cmid,
                    questionids: ids,
                    sesskey: settings.sesskey,
                });
            }
        );
    });

    $('.googlemeet-question-edit').on('click', function() {
        const button = $(this);
        $('#googlemeet-question-edit-id').val(button.closest('.googlemeet-question-card').attr('data-questionid'));
        $('#googlemeet-question-edit-stem').val(button.attr('data-stem') || '');
        $('#googlemeet-question-edit-feedback').val(button.attr('data-feedback') || '');
        $('#googlemeet-question-edit-correct').val(button.attr('data-correctindex') || '0');
        $('.googlemeet-question-edit-answer').each(function() {
            $(this).val(button.attr('data-answer' + $(this).attr('data-index')) || '');
        });
        $('#googlemeet-question-edit-modal').modal('show');
    });

    $('#googlemeet-question-edit-save').on('click', () => {
        const options = $('.googlemeet-question-edit-answer').map(function() {
            return $(this).val();
        }).get();
        call('mod_googlemeet_update_question', {
            recordingid: settings.recordingid,
            coursemoduleid: settings.cmid,
            questionid: parseInt($('#googlemeet-question-edit-id').val(), 10),
            stem: $('#googlemeet-question-edit-stem').val(),
            options: options,
            correctindex: parseInt($('#googlemeet-question-edit-correct').val(), 10),
            explanation: $('#googlemeet-question-edit-feedback').val(),
            citation: '',
            sesskey: settings.sesskey,
        });
    });
};

/**
 * Bind teacher recording actions.
 *
 * @returns {void}
 */
const bindRecordingManagement = () => {
    $('.recordinghowhide').on('click', () => {
        call('mod_googlemeet_showhide_recording', {
            recordingid: settings.recordingid,
            coursemoduleid: settings.cmid,
        });
    });

    $('.recordingeditname').on('click', function() {
        const trigger = this;
        const title = $('.recording-name-text').first();
        const current = title.attr('data-originalname') || title.text().trim();

        Modal.create({
            title: strings.recording_rename,
            body: '<div class="mb-3">' +
                '<label class="form-label" for="googlemeet-recording-rename-input">' +
                escapeHtml(strings.name) + '</label>' +
                '<input type="text" class="form-control" id="googlemeet-recording-rename-input">' +
                '</div>',
            footer: '<button type="button" class="btn btn-secondary" data-action="hide">' +
                escapeHtml(strings.cancel) + '</button>' +
                '<button type="button" class="btn btn-primary googlemeet-recording-rename-save">' +
                escapeHtml(strings.savechanges) + '</button>',
            removeOnClose: true,
            show: true,
            returnElement: trigger,
        }).then(modal => {
            const root = modal.getRoot();
            root.on('click', '.googlemeet-recording-rename-save', function(event) {
                event.preventDefault();
                const saveButton = $(this);
                const name = root.find('#googlemeet-recording-rename-input').val();
                if (!name) {
                    root.find('#googlemeet-recording-rename-input').focus();
                    return;
                }
                saveButton.prop('disabled', true);
                modal.destroy();
                call('mod_googlemeet_recording_edit_name', {
                    recordingid: settings.recordingid,
                    name: name,
                    coursemoduleid: settings.cmid,
                });
            });
            root.on('keydown', '#googlemeet-recording-rename-input', event => {
                if ((event.which || event.keyCode) === 13) {
                    event.preventDefault();
                    root.find('.googlemeet-recording-rename-save').trigger('click');
                }
            });
            modal.getBodyPromise().then(() => {
                root.find('#googlemeet-recording-rename-input').val(current).focus().select();
            });
        }).catch(Notification.exception);
    });
};

/**
 * Find the correct option in the current practice question.
 *
 * @param {Object} question Practice question.
 * @param {number|string} answerid Answer ID.
 * @returns {Object|null}
 */
const findCorrectOption = (question, answerid) => {
    for (let i = 0; i < question.options.length; i++) {
        if (parseInt(question.options[i].answerid, 10) === parseInt(answerid, 10)) {
            return question.options[i];
        }
    }
    return null;
};

/**
 * Render the current practice question.
 *
 * @param {Object} practice Practice state.
 * @param {JQuery} player Practice player.
 * @returns {void}
 */
const renderPracticeQuestion = (practice, player) => {
    const question = practice.questions[practice.index];
    const progressCurrent = practice.index + 1;
    const progressTotal = practice.questions.length;
    let optionsHtml = '';

    practice.checked = false;
    $('.googlemeet-practice-loading, .googlemeet-practice-complete, .googlemeet-practice-error').addClass('d-none');
    $('.googlemeet-practice-question').removeClass('d-none');
    $('.googlemeet-practice-progress')
        .text(progressCurrent + ' / ' + progressTotal)
        .attr('aria-label', (player.attr('data-progress-tpl') || (progressCurrent + ' / ' + progressTotal))
            .replace('{$a->current}', progressCurrent)
            .replace('{$a->total}', progressTotal));
    // Question stem/options/explanation arrive pre-sanitised: the WS builds them
    // with format_text() (HTMLPurifier) in question_service, the standard Moodle
    // boundary for question content. Do not client-escape — it would break
    // legitimate formatting (bold, sub/sup) that questions rely on.
    $('.googlemeet-practice-stem').html(question.stem);
    $('.googlemeet-practice-feedback').addClass('d-none').removeClass('alert alert-success alert-warning').empty();
    $('.googlemeet-practice-check').prop('disabled', true).removeClass('d-none');
    $('.googlemeet-practice-next').addClass('d-none');

    question.options.forEach(option => {
        const inputid = 'googlemeet-practice-' + question.questionid + '-' + option.answerid;
        optionsHtml += '<div class="form-check mb-2">' +
            '<input class="form-check-input googlemeet-practice-answer" type="radio" ' +
            'name="googlemeet-practice-answer" id="' + inputid + '" value="' + option.answerid + '">' +
            '<label class="form-check-label" for="' + inputid + '">' + option.text + '</label>' +
            '</div>';
    });
    $('.googlemeet-practice-options').html(optionsHtml);
};

/**
 * Show the practice completion state.
 *
 * @param {Object} practice Practice state.
 * @returns {void}
 */
const showPracticeComplete = practice => {
    $('.googlemeet-practice-question, .googlemeet-practice-loading, .googlemeet-practice-error').addClass('d-none');
    $('.googlemeet-practice-progress').text(practice.questions.length + ' / ' + practice.questions.length);
    $('.googlemeet-practice-complete').removeClass('d-none');
};

/**
 * Bind the student practice player.
 *
 * @returns {void}
 */
const bindPracticePlayer = () => {
    const player = $('.googlemeet-practice-player');
    if (!player.length || settings.canmanagequestions || !settings.hasquestions) {
        return;
    }

    const practice = {
        questions: [],
        index: 0,
        checked: false,
    };

    const loadPracticeQuestions = () => {
        if (player.attr('data-loaded') === '1') {
            return;
        }
        player.attr('data-loaded', '1');
        Ajax.call([{
            methodname: 'mod_googlemeet_get_practice_questions',
            args: {
                recordingid: settings.recordingid,
                coursemoduleid: settings.cmid,
            },
        }])[0].then(response => {
            practice.questions = response.questions || [];
            practice.index = 0;
            if (!practice.questions.length) {
                $('.googlemeet-practice-loading')
                    .removeClass('alert-info')
                    .addClass('alert-secondary')
                    .text(strings.question_empty_student);
                return;
            }
            renderPracticeQuestion(practice, player);
        }).fail(error => {
            $('.googlemeet-practice-loading').addClass('d-none');
            $('.googlemeet-practice-error')
                .removeClass('d-none')
                .text(error.message || strings.ai_error_unknown);
        });
    };

    $('#googlemeet-questions-tab').on('shown.bs.tab', loadPracticeQuestions);
    if ($('#googlemeet-questions-panel').hasClass('active')) {
        loadPracticeQuestions();
    }

    player.on('change', '.googlemeet-practice-answer', () => {
        $('.googlemeet-practice-check').prop('disabled', false);
    });

    $('.googlemeet-practice-check').on('click', () => {
        if (practice.checked) {
            return;
        }
        const question = practice.questions[practice.index];
        const answerid = parseInt($('.googlemeet-practice-answer:checked').val(), 10);
        if (!answerid) {
            return;
        }
        $('.googlemeet-practice-error').addClass('d-none');
        $('.googlemeet-practice-check').prop('disabled', true);
        Ajax.call([{
            methodname: 'mod_googlemeet_check_practice_answer',
            args: {
                recordingid: settings.recordingid,
                coursemoduleid: settings.cmid,
                questionid: question.questionid,
                answerid: answerid,
            },
        }])[0].then(response => {
            practice.checked = true;
            $('.googlemeet-practice-answer').prop('disabled', true);
            $('.googlemeet-practice-answer').each(function() {
                const wrapper = $(this).closest('.form-check');
                if (parseInt($(this).val(), 10) === parseInt(response.correctanswerid, 10)) {
                    wrapper.addClass('text-success fw-bold');
                } else if ($(this).is(':checked') && !response.correct) {
                    wrapper.addClass('text-danger text-decoration-line-through');
                }
            });

            const correctOption = findCorrectOption(question, response.correctanswerid);
            const correctText = correctOption ? correctOption.text : '';
            const feedbackClass = response.correct ? 'alert-success' : 'alert-warning';
            const statusText = response.correct ? strings.practice_correct : strings.practice_incorrect;
            const icon = response.correct ? '&#10003;' : '!';
            $('.googlemeet-practice-feedback')
                .removeClass('d-none alert-success alert-warning')
                .addClass('alert ' + feedbackClass)
                .html('<div class="fw-bold"><span aria-hidden="true">' + icon + '</span> ' +
                    escapeHtml(statusText) + '</div>' +
                    '<div class="mt-2"><strong>' + escapeHtml(strings.practice_correct_answer) + '</strong> ' +
                    correctText + '</div>' +
                    '<div class="mt-2">' + response.explanation + '</div>');
            $('.googlemeet-practice-next').removeClass('d-none').text(
                practice.index + 1 >= practice.questions.length ? strings.practice_finish : strings.practice_next
            ).focus();
        }).fail(error => {
            $('.googlemeet-practice-check').prop('disabled', false);
            $('.googlemeet-practice-error')
                .removeClass('d-none')
                .text(error.message || strings.ai_error_unknown);
        });
    });

    $('.googlemeet-practice-next').on('click', () => {
        practice.index++;
        if (practice.index >= practice.questions.length) {
            showPracticeComplete(practice);
        } else {
            renderPracticeQuestion(practice, player);
            $('.googlemeet-practice-stem').attr('tabindex', '-1').focus();
        }
    });

    $('.googlemeet-practice-retry').on('click', () => {
        practice.index = 0;
        renderPracticeQuestion(practice, player);
    });
};

/**
 * Initialise recording hub behaviours.
 *
 * @param {Object} config Module configuration.
 * @param {number} config.cmid Course module ID.
 * @param {number} config.recordingid Recording ID.
 * @param {string} config.sesskey Session key.
 * @param {boolean} config.caneditrecording Whether recording actions are available.
 * @param {boolean} config.canmanagequestions Whether question management is available.
 * @param {boolean} config.hasquestions Whether questions exist for the recording.
 * @returns {void}
 */
export const init = config => {
    if (initialised) {
        return;
    }
    initialised = true;
    settings = {
        cmid: parseInt(config.cmid, 10),
        recordingid: parseInt(config.recordingid, 10),
        sesskey: config.sesskey || '',
        caneditrecording: !!config.caneditrecording,
        canmanagequestions: !!config.canmanagequestions,
        hasquestions: !!config.hasquestions,
    };
    settings.hubStateKey = 'mod_googlemeet_hub_state_' + settings.cmid + '_' + settings.recordingid;

    loadStrings().then(() => {
        $(document).ready(() => {
            restoreHubState();
            bindQuestionManagement();
            if (settings.caneditrecording) {
                bindRecordingManagement();
            }
            bindPracticePlayer();
        });
    }).catch(Notification.exception);
};
