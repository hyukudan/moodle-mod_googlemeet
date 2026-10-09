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
 * Activity-wide practice session ("review my failed questions" / "practise by topic").
 *
 * Mirrors the recording hub player (same markup and CSS classes) but loads questions from
 * several recordings; each answer is checked with the per-recording WS, which also stores
 * the attempt (ANA-05).
 *
 * @module     mod_googlemeet/practice_session
 * @copyright  2026 PreparaOposiciones
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import $ from 'jquery';
import Ajax from 'core/ajax';
import {getStrings} from 'core/str';

const COMPONENT = 'mod_googlemeet';
const LETTERS = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H'];

const stringKeys = [
    'practice_correct',
    'practice_incorrect',
    'practice_correct_answer',
    'practice_finish',
    'practice_next',
    'practice_round_review',
    'practice_right_option',
    'practice_your_answer',
    'practice_session_from',
    'ai_error_unknown',
];

const escapeHtml = value => $('<div>').text(value || '').html();

/**
 * Initialise the practice session.
 *
 * @param {Object} config Page configuration.
 * @param {number} config.cmid Course module id.
 * @param {string} config.mode failed|topic.
 * @param {string} config.topic Topic (topic mode).
 * @param {string} config.hubbaseurl Activity view URL (the hub is view.php?id=..&recording=..).
 */
export const init = async(config) => {
    const player = $('.googlemeet-practice-session-player');
    if (!player.length || player.attr('data-initialised') === '1') {
        return;
    }
    player.attr('data-initialised', '1');

    const values = await getStrings(stringKeys.map(key => ({key, component: COMPONENT})));
    const strings = {};
    stringKeys.forEach((key, i) => {
        strings[key] = values[i];
    });

    const state = {
        questions: [],
        queue: [],
        index: 0,
        checked: false,
        correct: 0,
        wrong: [],
        reviewing: false,
    };

    const find = selector => player.find(selector);

    const showError = message => {
        find('.googlemeet-practice-loading').addClass('d-none');
        find('.googlemeet-practice-error').removeClass('d-none').text(message || strings.ai_error_unknown);
    };

    const updateHeader = answered => {
        const total = state.queue.length;
        const current = Math.min(state.index + 1, total);
        const done = state.index + (answered ? 1 : 0);
        const label = (player.attr('data-progress-tpl') || '{$a->current} / {$a->total}')
            .replace('{$a->current}', current)
            .replace('{$a->total}', total);
        find('.googlemeet-practice-progress').text((state.reviewing ? strings.practice_round_review + ' · ' : '') + label);
        const live = find('.googlemeet-practice-livescore');
        live.removeClass('d-none').text((live.attr('data-template') || '{$a}').replace('{$a}', state.correct));
        const pct = total ? Math.round((done / total) * 100) : 0;
        find('.googlemeet-practice-meter').removeClass('d-none').attr('aria-valuenow', pct)
            .find('.progress-bar').css('width', pct + '%');
    };

    const renderQuestion = () => {
        const question = state.queue[state.index];
        let optionsHtml = '';
        state.checked = false;
        find('.googlemeet-practice-loading, .googlemeet-practice-complete, .googlemeet-practice-error, ' +
            '.googlemeet-practice-empty').addClass('d-none');
        find('.googlemeet-practice-question').removeClass('d-none');
        find('.googlemeet-practice-options').removeClass('googlemeet-practice-options-answered');
        updateHeader(false);

        const sourceUrl = new URL(config.hubbaseurl, window.location.href);
        sourceUrl.searchParams.set('recording', question.recordingid);
        find('.googlemeet-practice-source')
            .attr('href', sourceUrl.toString())
            .text(strings.practice_session_from.replace('{$a}', question.recordingname));

        // Stem/options are sanitised server-side with format_text() (same contract as the hub player).
        find('.googlemeet-practice-stem').html(question.stem);
        find('.googlemeet-practice-feedback').addClass('d-none').removeClass('alert alert-success alert-warning').empty();
        find('.googlemeet-practice-check').prop('disabled', true).removeClass('d-none');
        find('.googlemeet-practice-next').addClass('d-none');

        question.options.forEach((option, index) => {
            const inputid = 'googlemeet-practice-' + question.questionid + '-' + option.answerid;
            optionsHtml += '<label class="googlemeet-practice-option" for="' + inputid + '">' +
                '<input class="form-check-input googlemeet-practice-answer" type="radio" ' +
                'name="googlemeet-practice-answer" id="' + inputid + '" value="' + option.answerid + '">' +
                '<span class="googlemeet-practice-letter" aria-hidden="true">' + (LETTERS[index] || index + 1) + '</span>' +
                '<span class="googlemeet-practice-option-text">' + option.text + '</span>' +
                '<span class="googlemeet-practice-option-state"></span>' +
                '</label>';
        });
        find('.googlemeet-practice-options').html(optionsHtml);
    };

    const showComplete = () => {
        const total = state.queue.length;
        const pct = total ? Math.round((state.correct / total) * 100) : 0;
        const complete = find('.googlemeet-practice-complete');
        const message = complete.find('.googlemeet-practice-message');
        const scoreText = complete.find('.googlemeet-practice-score-text');
        let level = 'low';
        if (pct >= 80) {
            level = 'great';
        } else if (pct >= 50) {
            level = 'good';
        }
        updateHeader(true);
        find('.googlemeet-practice-question, .googlemeet-practice-loading, .googlemeet-practice-error').addClass('d-none');
        complete.removeClass('d-none googlemeet-practice-level-great googlemeet-practice-level-good googlemeet-practice-level-low')
            .addClass('googlemeet-practice-level-' + level);
        complete.find('.googlemeet-practice-score-value').text(pct + '%');
        scoreText.text((scoreText.attr('data-template') || '{$a->correct}/{$a->total}')
            .replace('{$a->correct}', state.correct)
            .replace('{$a->total}', total));
        message.text(message.attr('data-' + level) || '');
        const review = complete.find('.googlemeet-practice-review');
        review.toggleClass('d-none', state.wrong.length === 0)
            .text((review.attr('data-template') || '{$a}').replace('{$a}', state.wrong.length));
        complete.trigger('focus');
    };

    const startRound = (queue, reviewing) => {
        state.queue = queue.slice();
        state.index = 0;
        state.correct = 0;
        state.wrong = [];
        state.reviewing = reviewing;
        renderQuestion();
        find('.googlemeet-practice-stem').attr('tabindex', '-1').trigger('focus');
    };

    player.on('change', '.googlemeet-practice-answer', function() {
        find('.googlemeet-practice-option').removeClass('googlemeet-practice-option-selected');
        $(this).closest('.googlemeet-practice-option').addClass('googlemeet-practice-option-selected');
        find('.googlemeet-practice-check').prop('disabled', false);
    });

    find('.googlemeet-practice-check').on('click', () => {
        if (state.checked) {
            return;
        }
        const question = state.queue[state.index];
        const answerid = parseInt(find('.googlemeet-practice-answer:checked').val(), 10);
        if (!answerid) {
            return;
        }
        find('.googlemeet-practice-error').addClass('d-none');
        find('.googlemeet-practice-check').prop('disabled', true);
        Ajax.call([{
            methodname: 'mod_googlemeet_check_practice_answer',
            args: {
                recordingid: question.recordingid,
                coursemoduleid: config.cmid,
                questionid: question.questionid,
                answerid: answerid,
            },
        }])[0].then(response => {
            state.checked = true;
            if (response.correct) {
                state.correct++;
            } else {
                state.wrong.push(question);
            }
            find('.googlemeet-practice-answer').prop('disabled', true);
            find('.googlemeet-practice-options').addClass('googlemeet-practice-options-answered');
            let correctText = '';
            find('.googlemeet-practice-answer').each(function() {
                const option = $(this).closest('.googlemeet-practice-option');
                const stateEl = option.find('.googlemeet-practice-option-state');
                if (parseInt($(this).val(), 10) === parseInt(response.correctanswerid, 10)) {
                    option.addClass('googlemeet-practice-option-correct');
                    correctText = option.find('.googlemeet-practice-option-text').html();
                    stateEl.html('<span aria-hidden="true">&#10003;</span><span class="visually-hidden">' +
                        escapeHtml(strings.practice_right_option) + '</span>');
                } else if ($(this).is(':checked')) {
                    option.addClass('googlemeet-practice-option-incorrect');
                    stateEl.html('<span aria-hidden="true">&#10007;</span><span class="visually-hidden">' +
                        escapeHtml(strings.practice_your_answer) + '</span>');
                }
            });
            const feedbackClass = response.correct ? 'alert-success' : 'alert-warning';
            const statusText = response.correct ? strings.practice_correct : strings.practice_incorrect;
            const icon = response.correct ? '&#10003;' : '&#10007;';
            find('.googlemeet-practice-feedback')
                .removeClass('d-none alert-success alert-warning')
                .addClass('alert ' + feedbackClass)
                .html('<div class="fw-bold"><span aria-hidden="true">' + icon + '</span> ' +
                    escapeHtml(statusText) + '</div>' +
                    (response.correct ? '' : '<div class="mt-2"><strong>' + escapeHtml(strings.practice_correct_answer) +
                        '</strong> ' + correctText + '</div>') +
                    (response.explanation ? '<div class="mt-2">' + response.explanation + '</div>' : ''));
            updateHeader(true);
            find('.googlemeet-practice-check').addClass('d-none');
            find('.googlemeet-practice-next').removeClass('d-none').text(
                state.index + 1 >= state.queue.length ? strings.practice_finish : strings.practice_next
            ).trigger('focus');
            return response;
        }).catch(error => {
            find('.googlemeet-practice-check').prop('disabled', false);
            showError(error.message);
        });
    });

    find('.googlemeet-practice-next').on('click', () => {
        state.index++;
        if (state.index >= state.queue.length) {
            showComplete();
        } else {
            renderQuestion();
            find('.googlemeet-practice-stem').attr('tabindex', '-1').trigger('focus');
        }
    });

    find('.googlemeet-practice-review').on('click', () => startRound(state.wrong, true));
    find('.googlemeet-practice-retry').on('click', () => startRound(state.questions, false));

    Ajax.call([{
        methodname: 'mod_googlemeet_get_practice_session',
        args: {
            coursemoduleid: config.cmid,
            mode: config.mode,
            topic: config.topic || '',
        },
    }])[0].then(response => {
        state.questions = response.questions || [];
        if (!state.questions.length) {
            find('.googlemeet-practice-loading').addClass('d-none');
            find('.googlemeet-practice-empty').removeClass('d-none');
            return response;
        }
        startRound(state.questions, false);
        return response;
    }).catch(error => showError(error.message));
};
