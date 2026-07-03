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
 * Recording AI analysis panel.
 *
 * @module     mod_googlemeet/ai_panel
 * @copyright  2026 Eduardo Kraus
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import $ from 'jquery';
import Ajax from 'core/ajax';
import * as Notification from 'core/notification';
import Templates from 'core/templates';
import {getString, getStrings} from 'core/str';

const COMPONENT = 'mod_googlemeet';
const TEMPLATE_AI_RESULT = 'mod_googlemeet/local/ai_result';

const stringRequests = [
    {key: 'ai_copied', component: COMPONENT},
    {key: 'ai_transcript_unavailable', component: COMPONENT},
    {key: 'ai_generating', component: COMPONENT},
    {key: 'ai_regenerate', component: COMPONENT},
    {key: 'ai_generate', component: COMPONENT},
    {key: 'ai_error_unknown', component: COMPONENT},
    {key: 'ai_status_pending', component: COMPONENT},
    {key: 'ai_analysis_available', component: COMPONENT},
    {key: 'ai_badge_label', component: COMPONENT},
    {key: 'ai_subtitles_unavailable', component: COMPONENT},
    {key: 'ai_transcribe_from_video', component: COMPONENT},
    {key: 'ai_transcribe_from_video_confirm', component: COMPONENT},
    {key: 'ai_processing_background', component: COMPONENT},
    {key: 'ai_processing_background_hint', component: COMPONENT},
    {key: 'ai_check_status', component: COMPONENT},
    {key: 'ai_status_processing', component: COMPONENT},
    {key: 'ai_edit_manual_title', component: COMPONENT},
    {key: 'ai_analyze_empty_transcript', component: COMPONENT},
    {key: 'ai_analyzing', component: COMPONENT},
    {key: 'ai_analyze_success', component: COMPONENT},
    {key: 'ai_edit_saving', component: COMPONENT},
    {key: 'ai_edit_save', component: COMPONENT},
    {key: 'ai_edit_saved', component: COMPONENT},
    {key: 'ai_timeout_hint', component: COMPONENT},
    {key: 'error', component: 'core'},
];

let initialised = false;
let settings = {};
let strings = {};
const loadedTranscripts = {};
const pollingIntervals = {};

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
 * @param {string} text Raw text.
 * @returns {string}
 */
const escapeHtml = text => $('<div>').text(text || '').html();

/**
 * Format plain multiline text as escaped HTML with line breaks.
 *
 * @param {string} text Raw text.
 * @returns {string}
 */
const formatText = text => {
    if (!text) {
        return '';
    }

    return escapeHtml(text).replace(/\n/g, '<br>');
};

/**
 * Find the visible card or list item for a recording.
 *
 * @param {number|string} recordingid Recording ID.
 * @returns {JQuery}
 */
const getRecordingItem = recordingid => $('.googlemeet-recording-card[data-recordingid="' + recordingid + '"], ' +
    '.googlemeet-recording-listitem[data-recordingid="' + recordingid + '"]');

/**
 * Call a Moodle external function.
 *
 * @param {string} methodname Web service method name.
 * @param {Object} args Web service arguments.
 * @param {number} [timeout] Optional timeout in milliseconds.
 * @returns {JQuery.Promise}
 */
const call = (methodname, args, timeout) => {
    const request = {
        methodname: methodname,
        args: args,
    };
    if (timeout) {
        request.timeout = timeout;
    }

    return Ajax.call([request])[0];
};

/**
 * Return a localized key-point count label.
 *
 * @param {number} count Key-point count.
 * @returns {Promise<string>}
 */
const keypointsLabel = count => {
    const key = count === 1 ? 'ai_keypoints_count' : 'ai_keypoints_count_plural';
    return getString(key, COMPONENT, count);
};

/**
 * Return a localized topic overflow label.
 *
 * @param {number} count Overflow count.
 * @returns {Promise<string>}
 */
const topicOverflowLabel = count => getString('recordings_topics_overflow_aria', COMPONENT, count);

/**
 * Build the context expected by mod_googlemeet/local/ai_result.
 *
 * @param {number|string} recordingid Recording ID.
 * @param {Object} data AI analysis data.
 * @returns {Object}
 */
const buildResultContext = (recordingid, data) => {
    const keypoints = data.keypoints || [];

    return {
        id: recordingid,
        hascapability: !!settings.canedit,
        aisummary: data.summary || '',
        aisummaryhtml: formatText(data.summary || ''),
        aikeypoints: keypoints,
        aikeypointscount: keypoints.length,
        aitopics: data.topics || [],
        transcriptloaded: true,
        transcripthtml: formatText(data.transcript || ''),
        aimodel: data.aimodel || '',
    };
};

/**
 * Render the shared AI result partial into the panel content.
 *
 * @param {JQuery} content Panel content element.
 * @param {Object} context Template context.
 * @returns {Promise<void>}
 */
const renderAiResult = async(content, context) => {
    const result = content.find('.googlemeet-ai-result');
    const rendered = await Templates.renderForPromise(TEMPLATE_AI_RESULT, context);

    if (result.length) {
        Templates.replaceNode(result, rendered.html, rendered.js || '');
    } else {
        Templates.appendNodeContents(content, rendered.html, rendered.js || '');
    }
};

/**
 * Update the list/card presentation after an analysis has completed.
 *
 * @param {Object} data AI analysis data.
 * @param {JQuery} item Recording card/list item.
 * @param {JQuery} content Panel content element.
 * @returns {Promise<void>}
 */
const updateRecordingPreview = async(data, item, content) => {
    const isListItem = item.hasClass('googlemeet-recording-listitem');
    const keypoints = data.keypoints || [];
    const topics = data.topics || [];
    const countLabel = await keypointsLabel(keypoints.length);
    const metaContainer = item.find('.googlemeet-card-meta, .googlemeet-listitem-meta');

    item.addClass('googlemeet-recording-has-ai');
    item.find('.googlemeet-ai-toggle-btn')
        .addClass('googlemeet-ai-toggle-active')
        .attr('data-hasai', '1');
    content.attr('data-hasai', '1');

    metaContainer.find('.googlemeet-ai-statuschip').remove();
    let keypointsElement = metaContainer.find('.googlemeet-ai-keypoints-count');
    if (!keypointsElement.length) {
        keypointsElement = $('<span class="googlemeet-ai-keypoints-count"></span>');
        metaContainer.append(keypointsElement);
    }
    keypointsElement.text(countLabel);

    if (isListItem) {
        await updateListPreview(item, metaContainer, topics);
        return;
    }

    await updateCardPreview(item, data, topics);
};

/**
 * Update a card-view recording preview after AI generation.
 *
 * @param {JQuery} item Recording card.
 * @param {Object} data AI analysis data.
 * @param {Array} topics Topic strings.
 * @returns {Promise<void>}
 */
const updateCardPreview = async(item, data, topics) => {
    const summaryPreview = data.summary
        ? (data.summary.length > 200 ? data.summary.substring(0, 200) + '...' : data.summary)
        : '';
    const cardBody = item.find('.googlemeet-card-body');
    const cardSummary = cardBody.find('.googlemeet-card-summary');

    if (cardSummary.length) {
        cardSummary.text(summaryPreview);
    } else {
        cardBody.append('<p class="googlemeet-card-summary">' + escapeHtml(summaryPreview) + '</p>');
    }

    if (topics.length > 0) {
        const topicsHtml = await cardTopicsHtml(topics);
        const cardTopics = cardBody.find('.googlemeet-card-topics');
        if (cardTopics.length) {
            cardTopics.replaceWith(topicsHtml);
        } else {
            cardBody.append(topicsHtml);
        }
    } else {
        cardBody.find('.googlemeet-card-topics').remove();
    }

    const cardMedia = item.find('.googlemeet-card-media');
    let cardBadges = cardMedia.find('.googlemeet-card-badges');
    if (!cardBadges.length) {
        cardBadges = $('<span class="googlemeet-card-badges"></span>');
        cardMedia.append(cardBadges);
    }
    if (!cardBadges.find('.googlemeet-card-aibadge').length) {
        cardBadges.append(
            '<span class="googlemeet-card-aibadge" title="' + escapeHtml(strings.ai_analysis_available) + '">' +
            escapeHtml(strings.ai_badge_label) + '</span>'
        );
    }
};

/**
 * Update a list-row recording preview after AI generation.
 *
 * @param {JQuery} item Recording list item.
 * @param {JQuery} metaContainer Metadata container.
 * @param {Array} topics Topic strings.
 * @returns {Promise<void>}
 */
const updateListPreview = async(item, metaContainer, topics) => {
    metaContainer.find('.googlemeet-ai-topic-tag').remove();
    if (topics.length > 0) {
        const chips = await topicChipsHtml(topics.slice(0, 2), topics.length - 2);
        metaContainer.append(chips);
    }

    const listTitle = item.find('.googlemeet-listitem-title');
    if (!listTitle.find('.googlemeet-card-aibadge').length) {
        listTitle.append(
            '<span class="googlemeet-card-aibadge" title="' + escapeHtml(strings.ai_analysis_available) + '">' +
            escapeHtml(strings.ai_badge_label) + '</span>'
        );
    }
};

/**
 * Build topic chips for a card.
 *
 * @param {Array} topics Topic strings.
 * @returns {Promise<string>}
 */
const cardTopicsHtml = async(topics) => {
    const visible = topics.slice(0, 2);
    const overflow = topics.length - visible.length;
    return '<div class="googlemeet-card-topics">' + await topicChipsHtml(visible, overflow) + '</div>';
};

/**
 * Build topic chip HTML.
 *
 * @param {Array} topics Visible topic strings.
 * @param {number} overflow Overflow count.
 * @returns {Promise<string>}
 */
const topicChipsHtml = async(topics, overflow) => {
    let html = '';
    topics.forEach(topic => {
        html += '<span class="googlemeet-ai-topic-tag">' + escapeHtml(topic) + '</span>';
    });

    if (overflow > 0) {
        const label = await topicOverflowLabel(overflow);
        html += '<span class="googlemeet-ai-topic-tag googlemeet-topic-overflow" title="' + escapeHtml(label) +
            '" aria-label="' + escapeHtml(label) + '">+' + overflow + '</span>';
    }

    return html;
};

/**
 * Display a completed AI analysis in the panel and list preview.
 *
 * @param {number|string} recordingid Recording ID.
 * @param {Object} data AI analysis response.
 * @param {JQuery} item Recording card/list item.
 * @param {JQuery} content Panel content.
 * @returns {Promise<void>}
 */
const displayAiAnalysis = async(recordingid, data, item, content) => {
    content.find('.googlemeet-ai-nodata').hide();
    content.find('.googlemeet-ai-loading').hide();
    content.find('.googlemeet-ai-error').hide();

    await renderAiResult(content, buildResultContext(recordingid, data));
    content.find('.googlemeet-ai-result').show();
    loadedTranscripts[recordingid] = true;

    await updateRecordingPreview(data, item, content);
};

/**
 * Load a transcript on demand.
 *
 * @param {number|string} recordingid Recording ID.
 * @returns {void}
 */
const loadTranscript = recordingid => {
    call('mod_googlemeet_get_ai_analysis', {
        recordingid: recordingid,
        coursemoduleid: settings.cmid,
    }).then(response => {
        const element = $('.googlemeet-ai-transcript-text[data-recordingid="' + recordingid + '"]');
        if (response.found && response.transcript) {
            element.html(formatText(response.transcript));
            loadedTranscripts[recordingid] = true;
        } else {
            element.text(strings.ai_transcript_unavailable);
            loadedTranscripts[recordingid] = true;
        }
    }).fail(() => {
        $('.googlemeet-ai-transcript-text[data-recordingid="' + recordingid + '"]')
            .text(strings.ai_transcript_unavailable);
    });
};

/**
 * Format elapsed seconds for the generation progress display.
 *
 * @param {number} elapsed Elapsed seconds.
 * @returns {string}
 */
const formatElapsed = elapsed => {
    const minutes = Math.floor(elapsed / 60);
    const seconds = elapsed % 60;
    if (minutes > 0) {
        return minutes + 'm ' + seconds + 's';
    }

    return seconds + 's';
};

/**
 * Generate an AI analysis.
 *
 * @param {number|string} recordingid Recording ID.
 * @param {boolean} regenerate Whether this is a regeneration.
 * @param {boolean} forcedownload Whether full-video transcription is allowed.
 * @returns {void}
 */
const generateAiAnalysis = (recordingid, regenerate, forcedownload) => {
    const content = $('.googlemeet-ai-content[data-recordingid="' + recordingid + '"]');
    const generateButton = $('.googlemeet-ai-generate-btn[data-id="' + recordingid + '"]');
    const item = getRecordingItem(recordingid);
    const loadingElement = content.find('.googlemeet-ai-loading');
    const startTime = Date.now();
    let progressDots = 0;

    forcedownload = !!forcedownload;

    content.find('.googlemeet-ai-nodata').hide();
    content.find('.googlemeet-ai-result').hide();
    content.find('.googlemeet-ai-error').hide();
    loadingElement.show();
    generateButton.prop('disabled', true).text(strings.ai_generating);

    const progressInterval = setInterval(() => {
        progressDots = (progressDots + 1) % 4;
        const dots = '.'.repeat(progressDots);
        const elapsed = Math.floor((Date.now() - startTime) / 1000);
        loadingElement.find('p').html(
            escapeHtml(strings.ai_generating) + dots + '<br><small style="opacity: 0.7;">&#9201; ' +
            escapeHtml(formatElapsed(elapsed)) + '</small>'
        );
    }, 500);

    call('mod_googlemeet_generate_ai_analysis', {
        recordingid: recordingid,
        coursemoduleid: settings.cmid,
        regenerate: regenerate,
        forcedownload: forcedownload,
    }, 180000).then(response => {
        clearInterval(progressInterval);
        content.find('.googlemeet-ai-loading').hide();
        generateButton.prop('disabled', false).text(strings.ai_regenerate);

        if (response.status === 'completed') {
            displayAiAnalysis(recordingid, response, item, content).catch(Notification.exception);
        } else if (response.status === 'failed') {
            showAiError(recordingid, response.error || strings.ai_error_unknown);
        } else if (response.status === 'processing') {
            showProcessingStatus(recordingid, content, generateButton);
        } else {
            showAiError(recordingid, strings.ai_status_pending);
        }
    }).fail(ex => {
        clearInterval(progressInterval);
        const elapsed = Math.floor((Date.now() - startTime) / 1000);
        content.find('.googlemeet-ai-loading').hide();
        generateButton.prop('disabled', false).text(strings.ai_generate);

        let errorMessage = '';
        if (ex.message) {
            errorMessage = ex.message;
        } else if (ex.error) {
            errorMessage = ex.error;
        } else if (ex.errorcode) {
            errorMessage = strings.error + ': ' + ex.errorcode;
        } else {
            errorMessage = strings.ai_error_unknown;
        }

        if (elapsed >= 170) {
            errorMessage += ' (' + strings.ai_timeout_hint + ')';
        }

        showAiError(recordingid, errorMessage);
    });
};

/**
 * Show an AI error message.
 *
 * @param {number|string} recordingid Recording ID.
 * @param {string} message Error message or special error code.
 * @returns {void}
 */
const showAiError = (recordingid, message) => {
    const content = $('.googlemeet-ai-content[data-recordingid="' + recordingid + '"]');
    const errorElement = content.find('.googlemeet-ai-error').attr('role', 'alert').show();
    const alert = errorElement.find('.alert');

    content.find('.googlemeet-ai-nodata').hide();
    content.find('.googlemeet-ai-loading').hide();
    content.find('.googlemeet-ai-result').hide();
    content.find('.googlemeet-ai-processing').hide();

    if (message === 'ai_subtitles_unavailable') {
        const explain = document.createElement('div');
        explain.textContent = strings.ai_subtitles_unavailable;
        const forceButton = document.createElement('button');
        forceButton.type = 'button';
        forceButton.className = 'btn btn-sm btn-warning mt-2 googlemeet-ai-force-video-btn';
        forceButton.setAttribute('data-id', recordingid);
        forceButton.textContent = strings.ai_transcribe_from_video;
        alert.empty().append(explain).append(forceButton);
        return;
    }

    alert.text(message);
};

/**
 * Show the background-processing status and start polling.
 *
 * @param {number|string} recordingid Recording ID.
 * @param {JQuery} content Panel content.
 * @param {JQuery} generateButton Generate button.
 * @returns {void}
 */
const showProcessingStatus = (recordingid, content, generateButton) => {
    content.find('.googlemeet-ai-nodata').hide();
    content.find('.googlemeet-ai-loading').hide();
    content.find('.googlemeet-ai-result').hide();
    content.find('.googlemeet-ai-error').hide();

    let processingElement = content.find('.googlemeet-ai-processing');
    if (!processingElement.length) {
        processingElement = $('<div class="googlemeet-ai-processing alert alert-info">' +
            '<div class="d-flex align-items-center">' +
            '<div class="spinner-border spinner-border-sm me-2" role="status"></div>' +
            '<div><strong>' + escapeHtml(strings.ai_processing_background) + '</strong><br>' +
            '<small>' + escapeHtml(strings.ai_processing_background_hint) + '</small></div>' +
            '</div>' +
            '<button class="btn btn-sm btn-outline-primary mt-2 googlemeet-ai-refresh-btn" data-id="' +
            recordingid + '">' + escapeHtml(strings.ai_check_status) + '</button>' +
            '</div>');
        content.append(processingElement);
    }
    processingElement.show();

    generateButton.prop('disabled', true).text(strings.ai_status_processing);

    if (pollingIntervals[recordingid]) {
        clearInterval(pollingIntervals[recordingid]);
    }
    pollingIntervals[recordingid] = setInterval(() => {
        checkAnalysisStatus(recordingid, status => {
            if (status !== 'processing') {
                clearInterval(pollingIntervals[recordingid]);
                delete pollingIntervals[recordingid];
            }
        });
    }, 10000);
};

/**
 * Check the current status of an AI analysis.
 *
 * @param {number|string} recordingid Recording ID.
 * @param {Function} [callback] Callback receiving the status string.
 * @returns {void}
 */
const checkAnalysisStatus = (recordingid, callback) => {
    call('mod_googlemeet_get_ai_analysis', {
        recordingid: recordingid,
        coursemoduleid: settings.cmid,
    }).then(response => {
        const content = $('.googlemeet-ai-content[data-recordingid="' + recordingid + '"]');
        const item = getRecordingItem(recordingid);
        const generateButton = $('.googlemeet-ai-generate-btn[data-id="' + recordingid + '"]');

        if (response.found && response.status === 'completed') {
            content.find('.googlemeet-ai-processing').hide();
            generateButton.prop('disabled', false).text(strings.ai_regenerate);
            displayAiAnalysis(recordingid, response, item, content).catch(Notification.exception);
        } else if (response.found && response.status === 'failed') {
            content.find('.googlemeet-ai-processing').hide();
            generateButton.prop('disabled', false).text(strings.ai_generate);
            showAiError(recordingid, response.error || strings.ai_error_unknown);
        }

        if (callback) {
            callback(response.status);
        }
    }).fail(() => {
        if (callback) {
            callback('error');
        }
    });
};

/**
 * Bind AI panel toggle/generation/copy events.
 *
 * @returns {void}
 */
const bindPanelEvents = () => {
    $(document).on('click', '.googlemeet-ai-toggle-btn', function() {
        const button = $(this);
        const recordingid = button.attr('data-id');
        const hasAi = button.attr('data-hasai') === '1';
        const panelRow = $('.googlemeet-ai-panel-row[data-recordingid="' + recordingid + '"]');
        const panel = panelRow.find('.googlemeet-ai-panel');

        if (panel.is(':visible')) {
            panel.slideUp(200, () => {
                panelRow.removeClass('googlemeet-ai-panel-row-open');
            });
            button.removeClass('expanded').attr('aria-expanded', 'false');
            return;
        }

        if (hasAi && !loadedTranscripts[recordingid]) {
            loadTranscript(recordingid);
        }
        panelRow.addClass('googlemeet-ai-panel-row-open');
        panel.slideDown(200);
        button.addClass('expanded').attr('aria-expanded', 'true');
    });

    $(document).on('click', '.googlemeet-ai-generate-btn', function() {
        const recordingid = $(this).attr('data-id');
        const content = $('.googlemeet-ai-content[data-recordingid="' + recordingid + '"]');
        const hasAi = content.attr('data-hasai') === '1';
        generateAiAnalysis(recordingid, hasAi);
    });

    $(document).on('click', '.googlemeet-ai-refresh-btn', function() {
        checkAnalysisStatus($(this).attr('data-id'));
    });

    $(document).on('click', '.googlemeet-ai-force-video-btn', function() {
        const button = this;
        const recordingid = $(button).attr('data-id');
        Notification.saveCancelPromise(
            strings.ai_transcribe_from_video,
            strings.ai_transcribe_from_video_confirm,
            strings.ai_transcribe_from_video,
            {triggerElement: button}
        ).then(() => {
            generateAiAnalysis(recordingid, true, true);
        }).catch(() => {
            return;
        });
    });

    $(document).on('click', '.googlemeet-ai-copy-btn', function(event) {
        event.stopPropagation();
        const button = $(this);
        const recordingid = button.attr('data-id');
        const transcriptText = $('.googlemeet-ai-transcript-text[data-recordingid="' + recordingid + '"]').text();

        navigator.clipboard.writeText(transcriptText).then(() => {
            const originalHtml = button.html();
            button.html('&#10003; ' + escapeHtml(strings.ai_copied));
            setTimeout(() => {
                button.html(originalHtml);
            }, 2000);
        });
    });
};

/**
 * Bind the manual AI-edit modal events.
 *
 * @returns {void}
 */
const bindManualEditEvents = () => {
    $(document).on('click', '.googlemeet-ai-edit-btn', function(event) {
        event.stopPropagation();
        const recordingid = $(this).attr('data-id');
        const recordingname = $(this).attr('data-recordingname') || '';

        $('#googlemeet-ai-edit-recordingid').val(recordingid);
        $('#googlemeet-ai-edit-summary').val('');
        $('#googlemeet-ai-edit-keypoints').val('');
        $('#googlemeet-ai-edit-topics').val('');
        $('#googlemeet-ai-edit-transcript').val('');
        $('#googlemeet-ai-edit-modal-title').text(strings.ai_edit_manual_title + ': ' + recordingname);

        call('mod_googlemeet_get_ai_analysis', {
            recordingid: recordingid,
            coursemoduleid: settings.cmid,
        }).then(response => {
            if (response.found && response.status === 'completed') {
                $('#googlemeet-ai-edit-summary').val(response.summary || '');
                $('#googlemeet-ai-edit-keypoints').val((response.keypoints || []).join('\n'));
                $('#googlemeet-ai-edit-topics').val((response.topics || []).join(', '));
                $('#googlemeet-ai-edit-transcript').val(response.transcript || '');
            }
        });

        $('#googlemeet-ai-edit-modal').modal('show');
    });

    $('#googlemeet-ai-analyze-transcript').on('click', function() {
        const button = $(this);
        const recordingid = $('#googlemeet-ai-edit-recordingid').val();
        const transcript = $('#googlemeet-ai-edit-transcript').val();

        if (!transcript.trim()) {
            Notification.addNotification({
                message: strings.ai_analyze_empty_transcript,
                type: 'warning',
            });
            return;
        }

        const originalText = button.html();
        button.prop('disabled', true)
            .html('<span class="spinner-border spinner-border-sm me-1"></span> ' + escapeHtml(strings.ai_analyzing));

        call('mod_googlemeet_analyze_transcript', {
            transcript: transcript,
            recordingid: parseInt(recordingid, 10),
            coursemoduleid: settings.cmid,
        }, 120000).then(response => {
            button.prop('disabled', false).html(originalText);
            if (response.success) {
                $('#googlemeet-ai-edit-summary').val(response.summary || '');
                $('#googlemeet-ai-edit-keypoints').val((response.keypoints || []).join('\n'));
                $('#googlemeet-ai-edit-topics').val((response.topics || []).join(', '));
                Notification.addNotification({
                    message: strings.ai_analyze_success,
                    type: 'success',
                });
            } else {
                Notification.addNotification({
                    message: response.error || strings.ai_error_unknown,
                    type: 'error',
                });
            }
        }).fail(ex => {
            button.prop('disabled', false).html(originalText);
            Notification.exception(ex);
        });
    });

    $('#googlemeet-ai-edit-save').on('click', function() {
        const button = $(this);
        const recordingid = $('#googlemeet-ai-edit-recordingid').val();

        button.prop('disabled', true).text(strings.ai_edit_saving);
        call('mod_googlemeet_save_ai_analysis', {
            recordingid: parseInt(recordingid, 10),
            coursemoduleid: settings.cmid,
            summary: $('#googlemeet-ai-edit-summary').val(),
            keypoints: $('#googlemeet-ai-edit-keypoints').val(),
            topics: $('#googlemeet-ai-edit-topics').val(),
            transcript: $('#googlemeet-ai-edit-transcript').val(),
        }).then(response => {
            button.prop('disabled', false).text(strings.ai_edit_save);
            if (response.success) {
                $('#googlemeet-ai-edit-modal').modal('hide');
                Notification.addNotification({
                    message: strings.ai_edit_saved,
                    type: 'success',
                });
                setTimeout(() => {
                    window.location.reload();
                }, 1000);
            }
        }).fail(ex => {
            button.prop('disabled', false).text(strings.ai_edit_save);
            Notification.exception(ex);
        });
    });
};

/**
 * Initialise AI panel behaviours.
 *
 * @param {Object} config Module configuration.
 * @param {number} config.cmid Course module ID.
 * @param {boolean} config.canedit Whether the current user can edit recordings.
 * @param {boolean} config.aienabled Whether AI features are enabled.
 * @param {boolean} config.cangenerateai Whether the current user can generate AI.
 * @returns {void}
 */
export const init = config => {
    if (initialised) {
        return;
    }
    initialised = true;
    settings = {
        cmid: parseInt(config.cmid, 10),
        canedit: !!config.canedit,
        aienabled: !!config.aienabled,
        cangenerateai: !!config.cangenerateai,
    };

    loadStrings().then(() => {
        $(document).ready(() => {
            if (settings.aienabled) {
                bindPanelEvents();
            }
            if (settings.canedit) {
                bindManualEditEvents();
            }
        });
    }).catch(Notification.exception);
};
