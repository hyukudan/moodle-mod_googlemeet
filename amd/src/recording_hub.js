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
    {key: 'practice_round_review', component: COMPONENT},
    {key: 'practice_right_option', component: COMPONENT},
    {key: 'practice_your_answer', component: COMPONENT},
    {key: 'practice_live_score', component: COMPONENT},
    {key: 'recording_mark_viewed', component: COMPONENT},
    {key: 'recording_progress_aria', component: COMPONENT},
    {key: 'recording_progress_completed', component: COMPONENT},
    {key: 'recording_progress_partial', component: COMPONENT},
    {key: 'recording_progress_saving', component: COMPONENT},
    {key: 'recording_progress_unseen', component: COMPONENT},
    {key: 'chapter_timestamp_copied', component: COMPONENT},
    {key: 'chapter_timestamp_reference', component: COMPONENT},
    {key: 'chapter_transcript_highlighted', component: COMPONENT},
    {key: 'chapter_seek_announce', component: COMPONENT},
    {key: 'timestamp_seek_aria', component: COMPONENT},
    {key: 'chapter_link_copied', component: COMPONENT},
    {key: 'chapter_link_copy_manual', component: COMPONENT},
    {key: 'transcript_search_count', component: COMPONENT},
    {key: 'transcript_search_none', component: COMPONENT},
    {key: 'transcript_search_match_aria', component: COMPONENT},
    {key: 'name', component: 'core'},
    {key: 'cancel', component: 'core'},
    {key: 'savechanges', component: 'core'},
];

let initialised = false;
let settings = {};
let strings = {};
let progress = {
    completed: false,
    watchedseconds: 0,
    thresholdseconds: 480,
    pendingseconds: 0,
    visibleSince: 0,
    timer: null,
};

const HEARTBEAT_INTERVAL_MS = 30000;

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
 * Update a recording visibility action after the AJAX toggle response.
 *
 * @param {JQuery} button Visibility button.
 * @param {boolean} visible Whether the recording is now visible to students.
 * @returns {void}
 */
const updateVisibilityButton = (button, visible) => {
    const label = button.attr(visible ? 'data-label-hide' : 'data-label-show') || '';
    if (!label) {
        return;
    }

    if (button.text().trim()) {
        button.text(label);
    }
    if (button.attr('title')) {
        button.attr('title', label);
    }
    if (button.attr('aria-label')) {
        button.attr('aria-label', label);
    }
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
 * Whether the page is currently visible enough to count heartbeat seconds.
 *
 * @returns {boolean}
 */
const pageIsVisible = () => !document.visibilityState || document.visibilityState === 'visible';

/**
 * Label and percent for the current progress state.
 *
 * @returns {Object}
 */
const currentProgressState = () => {
    let label = strings.recording_progress_unseen;
    let percent = 0;

    if (progress.completed) {
        label = strings.recording_progress_completed;
        percent = 100;
    } else if (progress.watchedseconds > 0) {
        label = strings.recording_progress_partial;
        percent = Math.min(99, Math.max(1, Math.floor((progress.watchedseconds / progress.thresholdseconds) * 100)));
    }

    return {label: label, percent: percent};
};

/**
 * Update the hub progress UI after a web service response.
 *
 * @returns {void}
 */
const refreshProgressUi = () => {
    const state = currentProgressState();
    const badge = $('.googlemeet-hub-progress .googlemeet-progress-badge');
    const meter = $('.googlemeet-hub-progress .googlemeet-progress-meter');
    const bar = meter.find('.progress-bar');
    const button = $('.googlemeet-mark-viewed');
    const aria = (strings.recording_progress_aria || '{$a}').replace('{$a}', state.label);

    badge
        .removeClass('googlemeet-progress-badge-completed googlemeet-progress-badge-partial googlemeet-progress-badge-unseen')
        .addClass(progress.completed
            ? 'googlemeet-progress-badge-completed'
            : (progress.watchedseconds > 0 ? 'googlemeet-progress-badge-partial' : 'googlemeet-progress-badge-unseen'))
        .attr('aria-label', aria);
    badge.find('[aria-hidden="true"]').text(progress.completed ? '\u2713' : '\u25cb');
    badge.find('.googlemeet-progress-status-text').text(state.label);
    meter.attr('aria-valuenow', state.percent);
    bar.css('width', state.percent + '%');

    if (progress.completed) {
        button.prop('disabled', true).text(strings.recording_progress_completed)
            .removeClass('btn-primary').addClass('btn-outline-success');
    } else {
        button.prop('disabled', false).text(strings.recording_mark_viewed)
            .removeClass('btn-outline-success').addClass('btn-primary');
    }
};

/**
 * Add visible elapsed seconds to the pending heartbeat buffer.
 *
 * @returns {void}
 */
const collectVisibleSeconds = () => {
    if (progress.completed || !progress.visibleSince) {
        return;
    }

    const now = Date.now();
    const elapsed = Math.floor((now - progress.visibleSince) / 1000);
    if (elapsed <= 0) {
        return;
    }

    progress.pendingseconds += elapsed;
    progress.visibleSince += elapsed * 1000;
};

/**
 * Persist one progress heartbeat.
 *
 * @param {number} delta Seconds to add.
 * @param {boolean} completed Whether to mark completed.
 * @param {boolean} silent Whether errors should stay silent.
 * @returns {Promise}
 */
const sendProgress = (delta, completed, silent) => {
    delta = Math.max(0, Math.min(60, parseInt(delta, 10) || 0));
    if (!delta && !completed) {
        return Promise.resolve();
    }

    return Ajax.call([{
        methodname: 'mod_googlemeet_mark_recording_progress',
        args: {
            recordingid: settings.recordingid,
            coursemoduleid: settings.cmid,
            watchedsecondsdelta: delta,
            completed: !!completed,
        },
    }])[0].then(response => {
        progress.watchedseconds = parseInt(response.watchedseconds, 10) || 0;
        progress.completed = !!response.completed;
        refreshProgressUi();
    }).fail(error => {
        progress.pendingseconds += delta;
        if (!silent) {
            refreshProgressUi();
            Notification.exception(error);
        }
    });
};

/**
 * Flush pending heartbeat seconds when enough visible time has accumulated.
 *
 * @param {boolean} force Send even when below the normal interval.
 * @returns {void}
 */
const flushProgressHeartbeat = force => {
    if (progress.completed) {
        return;
    }

    collectVisibleSeconds();
    if (!force && progress.pendingseconds < 30) {
        return;
    }

    const delta = Math.min(progress.pendingseconds, 60);
    progress.pendingseconds -= delta;
    sendProgress(delta, false, true);
};

/**
 * Bind recording progress heartbeat and manual completion.
 *
 * @returns {void}
 */
const bindRecordingProgress = () => {
    const root = $('.googlemeet-hub-progress');
    if (!root.length) {
        return;
    }

    progress.completed = !!settings.progresscompleted;
    progress.watchedseconds = Math.max(0, parseInt(settings.progresswatchedseconds, 10) || 0);
    progress.thresholdseconds = Math.max(30, parseInt(settings.progressthresholdseconds, 10) || 480);
    progress.pendingseconds = 0;
    progress.visibleSince = (!progress.completed && pageIsVisible()) ? Date.now() : 0;
    refreshProgressUi();

    $('.googlemeet-mark-viewed').on('click', function() {
        if (progress.completed) {
            return;
        }

        const button = $(this);
        collectVisibleSeconds();
        const delta = Math.min(progress.pendingseconds, 60);
        progress.pendingseconds -= delta;
        button.prop('disabled', true).text(strings.recording_progress_saving);
        sendProgress(delta, true, false).then(() => {
            refreshProgressUi();
        });
    });

    document.addEventListener('visibilitychange', () => {
        if (progress.completed) {
            return;
        }

        if (pageIsVisible()) {
            progress.visibleSince = Date.now();
        } else {
            flushProgressHeartbeat(true);
            progress.visibleSince = 0;
        }
    });

    progress.timer = window.setInterval(() => {
        if (!pageIsVisible()) {
            return;
        }
        flushProgressHeartbeat(false);
    }, HEARTBEAT_INTERVAL_MS);
};

/**
 * Show the transcript tab for teacher chapter navigation.
 *
 * @returns {void}
 */
const showTranscriptTab = () => {
    const tab = document.getElementById('googlemeet-transcript-tab');
    if (!tab) {
        return;
    }
    if (window.bootstrap && window.bootstrap.Tab) {
        window.bootstrap.Tab.getOrCreateInstance(tab).show();
    } else if (typeof $(tab).tab === 'function') {
        $(tab).tab('show');
    }
};

/**
 * Highlight a timestamp in the teacher-only transcript panel.
 *
 * @param {string} timestamp Timestamp text.
 * @returns {boolean} Whether a matching timestamp was found.
 */
const highlightTranscriptTimestamp = timestamp => {
    const root = $('.googlemeet-ai-transcript-text').get(0);
    if (!root || !timestamp) {
        return false;
    }

    $('.googlemeet-transcript-highlight').removeClass('googlemeet-transcript-highlight');
    const existing = $(root).find('[data-chapter-timestamp]').filter(function() {
        return $(this).attr('data-chapter-timestamp') === timestamp;
    }).get(0);
    if (existing) {
        existing.classList.add('googlemeet-transcript-highlight');
        existing.scrollIntoView({block: 'center', behavior: 'smooth'});
        return true;
    }

    const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);
    let node = walker.nextNode();
    while (node) {
        const index = node.nodeValue.indexOf(timestamp);
        if (index !== -1) {
            const parent = node.parentNode;
            const before = node.nodeValue.slice(0, index);
            const match = node.nodeValue.slice(index, index + timestamp.length);
            const after = node.nodeValue.slice(index + timestamp.length);
            const highlight = document.createElement('span');
            highlight.className = 'googlemeet-transcript-highlight';
            highlight.setAttribute('data-chapter-timestamp', timestamp);
            highlight.textContent = match;

            if (before !== '') {
                parent.insertBefore(document.createTextNode(before), node);
            }
            parent.insertBefore(highlight, node);
            node.nodeValue = after;
            highlight.scrollIntoView({block: 'center', behavior: 'smooth'});
            return true;
        }
        node = walker.nextNode();
    }

    return false;
};

/**
 * Copy a timestamp to the clipboard when possible.
 *
 * @param {string} timestamp Timestamp text.
 * @returns {Promise<void>}
 */
const copyTimestamp = timestamp => {
    const feedback = $('[data-region="chapter-feedback"]');
    const reference = (strings.chapter_timestamp_reference || '{$a}').replace('{$a}', timestamp);
    if (!navigator.clipboard || !navigator.clipboard.writeText) {
        feedback.text(reference);
        return Promise.resolve();
    }

    return navigator.clipboard.writeText(timestamp).then(() => {
        feedback.text((strings.chapter_timestamp_copied || '{$a}').replace('{$a}', timestamp));
    }).catch(() => {
        feedback.text(reference);
    });
};

/**
 * Whether the user asked the browser to reduce motion.
 *
 * @returns {boolean}
 */
const prefersReducedMotion = () => !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);

/**
 * Format seconds as m:ss or h:mm:ss.
 *
 * @param {number} total Seconds.
 * @returns {string}
 */
const formatSeconds = total => {
    const value = Math.max(0, Math.floor(total || 0));
    const hours = Math.floor(value / 3600);
    const minutes = Math.floor((value % 3600) / 60);
    const seconds = value % 60;
    const pad = number => String(number).padStart(2, '0');
    return hours > 0 ? hours + ':' + pad(minutes) + ':' + pad(seconds) : minutes + ':' + pad(seconds);
};

/**
 * Get the embedded Drive preview iframe, if any.
 *
 * @returns {HTMLIFrameElement|null}
 */
const getPlayerIframe = () => document.querySelector('#googlemeet-recording-hub [data-region="recording-player-iframe"]');

/**
 * Build a Drive /preview URL that starts at the given second.
 *
 * Drive's preview player honours `?t=<seconds>`; any existing query or hash is stripped first.
 *
 * @param {string} base Embed URL.
 * @param {number} seconds Start offset.
 * @returns {string}
 */
const buildSeekUrl = (base, seconds) => {
    const clean = String(base || '').split(/[?#]/)[0];
    return seconds > 0 ? clean + '?t=' + seconds : clean;
};

/**
 * Keep a chapter visible inside the scrollable chapters panel without scrolling the page.
 *
 * @param {HTMLElement} button Chapter button.
 * @returns {void}
 */
const scrollChapterIntoPanel = button => {
    const panel = button.closest('.googlemeet-chapters-panel');
    if (!panel || panel.scrollHeight <= panel.clientHeight) {
        return;
    }
    const panelRect = panel.getBoundingClientRect();
    const rect = button.getBoundingClientRect();
    if (rect.top < panelRect.top || rect.bottom > panelRect.bottom) {
        panel.scrollTop += rect.top - panelRect.top - (panel.clientHeight / 3);
    }
};

/**
 * Bind the mobile collapse toggle of the chapters panel.
 *
 * On small screens (<= 576px) the list starts collapsed under the player and the toggle
 * shows/hides it (aria-expanded + the hidden attribute on the list). On larger screens the
 * toggle is hidden by CSS and the list is always expanded.
 *
 * @returns {void}
 */
const bindChaptersToggle = () => {
    const panel = document.querySelector('#googlemeet-recording-hub .googlemeet-chapters-panel');
    const toggle = panel ? panel.querySelector('.googlemeet-chapters-toggle') : null;
    const body = panel ? panel.querySelector('#googlemeet-chapters-body') : null;
    if (!toggle || !body) {
        return;
    }
    const setExpanded = expanded => {
        panel.classList.toggle('googlemeet-chapters-collapsed', !expanded);
        body.hidden = !expanded;
        toggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
        toggle.textContent = toggle.getAttribute(expanded ? 'data-label-hide' : 'data-label-show');
    };
    const small = window.matchMedia ? window.matchMedia('(max-width: 575.98px)') : null;
    if (small && small.matches) {
        setExpanded(false);
    }
    if (small) {
        const onChange = event => {
            // Leaving the phone layout: the toggle disappears, so never leave the list hidden.
            if (!event.matches) {
                setExpanded(true);
            }
        };
        if (small.addEventListener) {
            small.addEventListener('change', onChange);
        } else if (small.addListener) {
            small.addListener(onChange);
        }
    }
    toggle.addEventListener('click', () => {
        setExpanded(toggle.getAttribute('aria-expanded') !== 'true');
    });
};

/**
 * Mark the chapter that contains the given second as the current one.
 *
 * @param {number} seconds Playback offset.
 * @returns {void}
 */
const markActiveChapter = seconds => {
    let active = null;
    $('.googlemeet-chapter-button').each(function() {
        const start = parseInt(this.getAttribute('data-start-seconds'), 10);
        if (!Number.isNaN(start) && start <= seconds) {
            active = this;
        }
    }).removeAttr('aria-current').removeClass('googlemeet-chapter-active');
    if (active) {
        active.setAttribute('aria-current', 'true');
        active.classList.add('googlemeet-chapter-active');
        scrollChapterIntoPanel(active);
    }
};

/**
 * Remember the last point the user jumped to (approximate resume, not playback position).
 *
 * @param {number} seconds Offset in seconds.
 * @returns {void}
 */
const rememberLastJump = seconds => {
    if (!settings.recordingid || settings.lastjump === seconds) {
        return;
    }
    settings.lastjump = seconds;
    Ajax.call([{
        methodname: 'core_user_set_user_preferences',
        args: {preferences: [{name: 'mod_googlemeet_lastjump_' + settings.recordingid, value: String(seconds)}]},
    }])[0].catch(() => {
        // Guests or restricted sessions cannot store preferences; resuming is best effort.
        return;
    });
};

/**
 * Seek the recording player to a second.
 *
 * A native <video> is seeked in place; the Drive /preview iframe is reloaded with `?t=<seconds>`.
 *
 * @param {number} rawseconds Offset in seconds.
 * @param {object} [options] Options.
 * @param {boolean} [options.scroll=true] Scroll the player into view.
 * @param {boolean} [options.announce=true] Announce the jump to assistive technology.
 * @param {boolean} [options.remember=true] Store it as the user's last jump point for this recording.
 * @returns {boolean} Whether a player was found and seeked.
 */
const seekTo = (rawseconds, {scroll = true, announce = true, remember = true} = {}) => {
    const seconds = Math.max(0, Math.floor(Number(rawseconds) || 0));
    const nativeVideo = $('#googlemeet-recording-hub video').get(0);
    const iframe = nativeVideo ? null : getPlayerIframe();
    if (!nativeVideo && !iframe) {
        return false;
    }

    if (nativeVideo) {
        nativeVideo.currentTime = seconds;
    } else {
        iframe.src = buildSeekUrl(iframe.getAttribute('data-embed-base') || iframe.src, seconds);
    }

    markActiveChapter(seconds);
    if (remember) {
        rememberLastJump(seconds);
    }

    if (scroll) {
        const player = (nativeVideo || iframe).closest('.googlemeet-recording-player') || nativeVideo || iframe;
        const rect = player.getBoundingClientRect();
        if (rect.top < 0 || rect.bottom > (window.innerHeight || document.documentElement.clientHeight)) {
            player.scrollIntoView({block: 'start', behavior: prefersReducedMotion() ? 'auto' : 'smooth'});
        }
    }

    if (announce) {
        $('[data-region="seek-feedback"]').text(
            (strings.chapter_seek_announce || '{$a}').replace('{$a}', formatSeconds(seconds))
        );
    }

    return true;
};

/**
 * Build the hub deep link that opens the player at a given second.
 *
 * @param {number} seconds Offset in seconds.
 * @returns {string}
 */
const buildMomentLink = seconds => {
    const hub = document.getElementById('googlemeet-recording-hub');
    const base = hub ? hub.getAttribute('data-hub-url') : '';
    if (!base) {
        return '';
    }
    const url = new URL(base, window.location.href);
    if (seconds > 0) {
        url.searchParams.set('t', String(seconds));
    } else {
        url.searchParams.delete('t');
    }
    return url.toString();
};

/**
 * Copy a deep link to a chapter moment.
 *
 * @param {number} seconds Offset in seconds.
 * @param {string} timestamp Timestamp label.
 * @returns {Promise<void>}
 */
const copyMomentLink = (seconds, timestamp) => {
    const feedback = $('[data-region="chapter-feedback"]');
    const link = buildMomentLink(seconds);
    const manual = () => feedback.text((strings.chapter_link_copy_manual || '{$a}').replace('{$a}', link));
    if (!link) {
        return Promise.resolve();
    }
    if (!navigator.clipboard || !navigator.clipboard.writeText) {
        manual();
        return Promise.resolve();
    }
    return navigator.clipboard.writeText(link).then(() => {
        feedback.text((strings.chapter_link_copied || '{$a}').replace('{$a}', timestamp));
    }).catch(manual);
};

/**
 * Reflect a deep-link start time (?t=) rendered by the server in the chapter list.
 *
 * @returns {void}
 */
const applyInitialStart = () => {
    const hub = document.getElementById('googlemeet-recording-hub');
    const start = hub ? parseInt(hub.getAttribute('data-start-seconds'), 10) : 0;
    if (start > 0 && getPlayerIframe()) {
        markActiveChapter(start);
    }
};

/**
 * Bind timestamped chapter interactions.
 *
 * @returns {void}
 */
const bindChapters = () => {
    $('.googlemeet-chapter-button').on('click', function() {
        const button = $(this);
        const timestamp = button.attr('data-chapter-timestamp') || '';
        const seconds = parseInt(button.attr('data-start-seconds'), 10);
        const feedback = $('[data-region="chapter-feedback"]');

        if (!Number.isNaN(seconds) && seconds >= 0 && seekTo(seconds)) {
            return;
        }

        if (settings.caneditrecording && $('#googlemeet-transcript-tab').length) {
            showTranscriptTab();
            window.setTimeout(() => {
                if (highlightTranscriptTimestamp(timestamp)) {
                    feedback.text((strings.chapter_transcript_highlighted || '{$a}').replace('{$a}', timestamp));
                    return;
                }
                copyTimestamp(timestamp);
            }, 160);
            return;
        }

        copyTimestamp(timestamp);
    });

    $('.googlemeet-chapter-copylink').on('click', function() {
        const seconds = Math.max(0, parseInt(this.getAttribute('data-start-seconds'), 10) || 0);
        copyMomentLink(seconds, this.getAttribute('data-chapter-timestamp') || '');
    });

    applyInitialStart();

    $('.googlemeet-resume-button').on('click', function() {
        seekTo(parseInt(this.getAttribute('data-seek-seconds'), 10));
    });

    bindChaptersToggle();
};

/**
 * Containers whose AI-generated text may contain mm:ss / h:mm:ss timestamps.
 *
 * @type {string}
 */
const TIMESTAMP_CONTAINERS = [
    '.googlemeet-ai-summary',
    '.googlemeet-ai-keypoints',
    '.googlemeet-ai-topics',
    '.googlemeet-ai-transcript-text',
].join(',');

/**
 * Matches m:ss, mm:ss, h:mm:ss and hh:mm:ss not glued to other digits or colons.
 *
 * @type {RegExp}
 */
const TIMESTAMP_PATTERN = /(^|[^\d:])((?:\d{1,2}:)?[0-5]?\d:[0-5]\d)(?![\d:])/g;

/**
 * Convert a timestamp to seconds.
 *
 * @param {string} timestamp m:ss or h:mm:ss.
 * @returns {number} Seconds, or -1 when invalid.
 */
const timestampToSeconds = timestamp => {
    const parts = String(timestamp || '').split(':').map(part => parseInt(part, 10));
    if (parts.length < 2 || parts.length > 3 || parts.some(part => Number.isNaN(part))) {
        return -1;
    }
    const seconds = parts.pop();
    const minutes = parts.pop();
    const hours = parts.length ? parts.pop() : 0;
    if (seconds > 59 || minutes > 59) {
        return -1;
    }
    return hours * 3600 + minutes * 60 + seconds;
};

/**
 * Build a keyboard-accessible seek button for a timestamp.
 *
 * @param {string} timestamp Timestamp text.
 * @param {number} seconds Offset in seconds.
 * @returns {HTMLButtonElement}
 */
const createTimestampLink = (timestamp, seconds) => {
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'googlemeet-timestamp-link';
    button.setAttribute('data-seek-seconds', String(seconds));
    button.setAttribute('data-chapter-timestamp', timestamp);
    button.setAttribute('aria-label', (strings.timestamp_seek_aria || '{$a}').replace('{$a}', timestamp));
    button.textContent = timestamp;
    return button;
};

/**
 * Turn plain-text timestamps inside an element into seek buttons.
 *
 * @param {Element} root Container.
 * @param {number} maxseconds Recording duration in seconds (0 when unknown).
 * @returns {void}
 */
const linkifyTimestampsIn = (root, maxseconds) => {
    const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, {
        acceptNode: node => {
            if (!node.nodeValue || node.nodeValue.indexOf(':') === -1) {
                return NodeFilter.FILTER_REJECT;
            }
            const parent = node.parentElement;
            if (parent && parent.closest('a, button, input, textarea, select, .googlemeet-timestamp-link')) {
                return NodeFilter.FILTER_REJECT;
            }
            return NodeFilter.FILTER_ACCEPT;
        },
    });
    const nodes = [];
    let current = walker.nextNode();
    while (current) {
        nodes.push(current);
        current = walker.nextNode();
    }

    nodes.forEach(node => {
        const text = node.nodeValue;
        const fragment = document.createDocumentFragment();
        let last = 0;
        let changed = false;
        TIMESTAMP_PATTERN.lastIndex = 0;
        let match = TIMESTAMP_PATTERN.exec(text);
        while (match) {
            const timestamp = match[2];
            const start = match.index + match[1].length;
            const seconds = timestampToSeconds(timestamp);
            if (seconds >= 0 && (!maxseconds || seconds <= maxseconds)) {
                fragment.appendChild(document.createTextNode(text.slice(last, start)));
                fragment.appendChild(createTimestampLink(timestamp, seconds));
                last = start + timestamp.length;
                changed = true;
            }
            match = TIMESTAMP_PATTERN.exec(text);
        }
        if (!changed) {
            return;
        }
        fragment.appendChild(document.createTextNode(text.slice(last)));
        node.parentNode.replaceChild(fragment, node);
    });
};

/**
 * Make every timestamp rendered in the hub AI output seek the player.
 *
 * Only runs when there is a player to seek; otherwise timestamps stay plain text.
 *
 * @returns {void}
 */
const bindTimestampLinks = () => {
    const hub = document.getElementById('googlemeet-recording-hub');
    if (!hub || (!getPlayerIframe() && !hub.querySelector('video'))) {
        return;
    }
    const maxseconds = parseInt(hub.getAttribute('data-duration-seconds'), 10) || 0;
    hub.querySelectorAll(TIMESTAMP_CONTAINERS).forEach(root => linkifyTimestampsIn(root, maxseconds));

    $(hub).on('click', '.googlemeet-timestamp-link', function(e) {
        e.preventDefault();
        seekTo(parseInt(this.getAttribute('data-seek-seconds'), 10));
    });
};

/**
 * Fold text for accent/case-insensitive search, keeping a map back to the original offsets.
 *
 * @param {string} text Original text.
 * @returns {{folded: string, map: number[]}}
 */
const foldText = text => {
    let folded = '';
    const map = [];
    for (let index = 0; index < text.length; index++) {
        const chunk = text[index].normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
        for (let offset = 0; offset < chunk.length; offset++) {
            folded += chunk[offset];
            map.push(index);
        }
    }
    return {folded, map};
};

/**
 * Remove previous transcript search highlights.
 *
 * @param {Element} root Transcript container.
 * @returns {void}
 */
const clearTranscriptMatches = root => {
    root.querySelectorAll('mark.googlemeet-transcript-match').forEach(mark => {
        mark.replaceWith(document.createTextNode(mark.textContent));
    });
    root.normalize();
};

/**
 * Find the transcript timestamp that precedes a node.
 *
 * @param {Element} root Transcript container.
 * @param {Node} node Node inside the transcript.
 * @returns {HTMLElement|null}
 */
const precedingTimestamp = (root, node) => {
    let found = null;
    root.querySelectorAll('.googlemeet-timestamp-link').forEach(link => {
        // eslint-disable-next-line no-bitwise
        if (link.compareDocumentPosition(node) & Node.DOCUMENT_POSITION_FOLLOWING) {
            found = link;
        }
    });
    return found;
};

/**
 * Highlight every match of a query inside the transcript.
 *
 * @param {Element} root Transcript container.
 * @param {string} query Search text.
 * @returns {HTMLElement[]} Marks in document order.
 */
const highlightTranscriptMatches = (root, query) => {
    clearTranscriptMatches(root);
    const needle = foldText(query.trim()).folded;
    if (needle.length < 2) {
        return [];
    }

    const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, {
        acceptNode: node => (node.parentElement && node.parentElement.closest('.googlemeet-timestamp-link'))
            ? NodeFilter.FILTER_REJECT : NodeFilter.FILTER_ACCEPT,
    });
    const nodes = [];
    let current = walker.nextNode();
    while (current) {
        nodes.push(current);
        current = walker.nextNode();
    }

    const marks = [];
    nodes.forEach(node => {
        const text = node.nodeValue;
        const {folded, map} = foldText(text);
        let from = folded.indexOf(needle);
        if (from === -1) {
            return;
        }
        const fragment = document.createDocumentFragment();
        let last = 0;
        while (from !== -1) {
            const start = map[from];
            const end = map[from + needle.length - 1] + 1;
            if (start >= last) {
                fragment.appendChild(document.createTextNode(text.slice(last, start)));
                const mark = document.createElement('mark');
                mark.className = 'googlemeet-transcript-match';
                mark.textContent = text.slice(start, end);
                fragment.appendChild(mark);
                marks.push(mark);
                last = end;
            }
            from = folded.indexOf(needle, from + needle.length);
        }
        fragment.appendChild(document.createTextNode(text.slice(last)));
        node.parentNode.replaceChild(fragment, node);
    });

    marks.forEach(mark => {
        const stamp = precedingTimestamp(root, mark);
        if (!stamp) {
            return;
        }
        mark.setAttribute('role', 'button');
        mark.setAttribute('tabindex', '0');
        mark.setAttribute('data-seek-seconds', stamp.getAttribute('data-seek-seconds'));
        mark.setAttribute('aria-label', mark.textContent + ' - ' +
            (strings.transcript_search_match_aria || '{$a}').replace('{$a}', stamp.textContent));
    });

    return marks;
};

/**
 * Bind the teacher transcript search: highlight matches, Enter cycles, clicking a match seeks.
 *
 * @returns {void}
 */
const bindTranscriptSearch = () => {
    const input = document.querySelector('#googlemeet-recording-hub .googlemeet-transcript-search-input');
    const root = document.querySelector('#googlemeet-recording-hub .googlemeet-ai-transcript-text');
    if (!input || !root) {
        return;
    }
    const status = document.getElementById('googlemeet-transcript-search-status');
    let marks = [];
    let cursor = -1;
    let timer = null;

    const run = () => {
        marks = highlightTranscriptMatches(root, input.value);
        cursor = -1;
        if (!status) {
            return;
        }
        if (input.value.trim().length < 2) {
            status.textContent = '';
        } else if (marks.length) {
            status.textContent = (strings.transcript_search_count || '{$a}').replace('{$a}', marks.length);
        } else {
            status.textContent = strings.transcript_search_none || '';
        }
    };

    input.addEventListener('input', () => {
        window.clearTimeout(timer);
        timer = window.setTimeout(run, 200);
    });
    input.addEventListener('keydown', e => {
        if (e.key !== 'Enter') {
            return;
        }
        e.preventDefault();
        window.clearTimeout(timer);
        if (cursor === -1 && !marks.length) {
            run();
        }
        if (!marks.length) {
            return;
        }
        if (marks[cursor]) {
            marks[cursor].classList.remove('googlemeet-transcript-match-current');
        }
        cursor = (cursor + 1) % marks.length;
        marks[cursor].classList.add('googlemeet-transcript-match-current');
        marks[cursor].scrollIntoView({block: 'center', behavior: prefersReducedMotion() ? 'auto' : 'smooth'});
    });

    const activate = mark => {
        const seconds = parseInt(mark.getAttribute('data-seek-seconds'), 10);
        if (!Number.isNaN(seconds)) {
            seekTo(seconds);
        }
    };
    $(root).on('click', 'mark.googlemeet-transcript-match[data-seek-seconds]', function() {
        activate(this);
    });
    $(root).on('keydown', 'mark.googlemeet-transcript-match[data-seek-seconds]', function(e) {
        if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            activate(this);
        }
    });
};

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
    $('.recordinghowhide').on('click', function() {
        const button = $(this);
        Ajax.call([{
            methodname: 'mod_googlemeet_showhide_recording',
            args: {
                recordingid: settings.recordingid,
                coursemoduleid: settings.cmid,
            },
        }])[0].then(response => {
            const visible = response.visible === true || response.visible === 1 ||
                response.visible === '1' || response.visible === 'true';
            updateVisibilityButton(button, visible);
        }).fail(Notification.exception);
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
 * Update the practice header (position, live score and progress bar).
 *
 * @param {Object} practice Practice state.
 * @param {JQuery} player Practice player.
 * @param {boolean} answered Whether the current question has been answered.
 * @returns {void}
 */
const updatePracticeHeader = (practice, player, answered) => {
    const total = practice.queue.length;
    const current = Math.min(practice.index + 1, total);
    const done = practice.index + (answered ? 1 : 0);
    const label = (player.attr('data-progress-tpl') || '{$a->current} / {$a->total}')
        .replace('{$a->current}', current)
        .replace('{$a->total}', total);
    let roundLabel = '';
    if (practice.reviewing) {
        roundLabel = strings.practice_round_review + ' · ';
    }
    $('.googlemeet-practice-progress').text(roundLabel + label);
    const live = $('.googlemeet-practice-livescore');
    live.removeClass('d-none').text((live.attr('data-template') || '{$a}').replace('{$a}', practice.correct));
    const pct = total ? Math.round((done / total) * 100) : 0;
    $('.googlemeet-practice-meter').removeClass('d-none').attr('aria-valuenow', pct)
        .find('.progress-bar').css('width', pct + '%');
};

/**
 * Render the current practice question.
 *
 * @param {Object} practice Practice state.
 * @param {JQuery} player Practice player.
 * @returns {void}
 */
const renderPracticeQuestion = (practice, player) => {
    const question = practice.queue[practice.index];
    const letters = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H'];
    let optionsHtml = '';

    practice.checked = false;
    $('.googlemeet-practice-loading, .googlemeet-practice-complete, .googlemeet-practice-error').addClass('d-none');
    $('.googlemeet-practice-question').removeClass('d-none');
    updatePracticeHeader(practice, player, false);
    // Question stem/options/explanation arrive pre-sanitised: the WS builds them
    // with format_text() (HTMLPurifier) in question_service, the standard Moodle
    // boundary for question content. Do not client-escape — it would break
    // legitimate formatting (bold, sub/sup) that questions rely on.
    $('.googlemeet-practice-stem').html(question.stem);
    $('.googlemeet-practice-feedback').addClass('d-none').removeClass('alert alert-success alert-warning').empty();
    $('.googlemeet-practice-check').prop('disabled', true).removeClass('d-none');
    $('.googlemeet-practice-next').addClass('d-none');

    question.options.forEach((option, index) => {
        const inputid = 'googlemeet-practice-' + question.questionid + '-' + option.answerid;
        optionsHtml += '<label class="googlemeet-practice-option" for="' + inputid + '">' +
            '<input class="form-check-input googlemeet-practice-answer" type="radio" ' +
            'name="googlemeet-practice-answer" id="' + inputid + '" value="' + option.answerid + '">' +
            '<span class="googlemeet-practice-letter" aria-hidden="true">' + (letters[index] || index + 1) + '</span>' +
            '<span class="googlemeet-practice-option-text">' + option.text + '</span>' +
            '<span class="googlemeet-practice-option-state"></span>' +
            '</label>';
    });
    $('.googlemeet-practice-options').html(optionsHtml);
};

/**
 * Show the practice completion state with the score of the round just finished.
 *
 * @param {Object} practice Practice state.
 * @param {JQuery} player Practice player.
 * @returns {void}
 */
const showPracticeComplete = (practice, player) => {
    const total = practice.queue.length;
    const pct = total ? Math.round((practice.correct / total) * 100) : 0;
    const complete = $('.googlemeet-practice-complete');
    const message = complete.find('.googlemeet-practice-message');
    const scoreText = complete.find('.googlemeet-practice-score-text');
    let level = 'low';
    if (pct >= 80) {
        level = 'great';
    } else if (pct >= 50) {
        level = 'good';
    }

    updatePracticeHeader(practice, player, true);
    $('.googlemeet-practice-question, .googlemeet-practice-loading, .googlemeet-practice-error').addClass('d-none');
    complete.removeClass('d-none googlemeet-practice-level-great googlemeet-practice-level-good googlemeet-practice-level-low')
        .addClass('googlemeet-practice-level-' + level);
    complete.find('.googlemeet-practice-score-value').text(pct + '%');
    scoreText.text((scoreText.attr('data-template') || '{$a->correct}/{$a->total}')
        .replace('{$a->correct}', practice.correct)
        .replace('{$a->total}', total));
    message.text(message.attr('data-' + level) || '');

    const review = complete.find('.googlemeet-practice-review');
    review.toggleClass('d-none', practice.wrong.length === 0)
        .text((review.attr('data-template') || '{$a}').replace('{$a}', practice.wrong.length));
    complete.trigger('focus');
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
        queue: [],
        index: 0,
        checked: false,
        correct: 0,
        wrong: [],
        reviewing: false,
    };

    const startRound = (queue, reviewing) => {
        practice.queue = queue.slice();
        practice.index = 0;
        practice.correct = 0;
        practice.wrong = [];
        practice.reviewing = reviewing;
        renderPracticeQuestion(practice, player);
        $('.googlemeet-practice-stem').attr('tabindex', '-1').trigger('focus');
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
            if (!practice.questions.length) {
                $('.googlemeet-practice-loading')
                    .removeClass('alert-info')
                    .addClass('alert-secondary')
                    .text(strings.question_empty_student);
                return;
            }
            practice.queue = practice.questions.slice();
            practice.index = 0;
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

    player.on('change', '.googlemeet-practice-answer', function() {
        player.find('.googlemeet-practice-option').removeClass('googlemeet-practice-option-selected');
        $(this).closest('.googlemeet-practice-option').addClass('googlemeet-practice-option-selected');
        $('.googlemeet-practice-check').prop('disabled', false);
    });

    $('.googlemeet-practice-check').on('click', () => {
        if (practice.checked) {
            return;
        }
        const question = practice.queue[practice.index];
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
            if (response.correct) {
                practice.correct++;
            } else {
                practice.wrong.push(question);
            }
            $('.googlemeet-practice-answer').prop('disabled', true);
            player.find('.googlemeet-practice-options').addClass('googlemeet-practice-options-answered');
            $('.googlemeet-practice-answer').each(function() {
                const option = $(this).closest('.googlemeet-practice-option');
                const state = option.find('.googlemeet-practice-option-state');
                if (parseInt($(this).val(), 10) === parseInt(response.correctanswerid, 10)) {
                    option.addClass('googlemeet-practice-option-correct');
                    state.html('<span aria-hidden="true">&#10003;</span><span class="visually-hidden">' +
                        escapeHtml(strings.practice_right_option) + '</span>');
                } else if ($(this).is(':checked')) {
                    option.addClass('googlemeet-practice-option-incorrect');
                    state.html('<span aria-hidden="true">&#10007;</span><span class="visually-hidden">' +
                        escapeHtml(strings.practice_your_answer) + '</span>');
                }
            });

            const correctOption = findCorrectOption(question, response.correctanswerid);
            const correctText = correctOption ? correctOption.text : '';
            const feedbackClass = response.correct ? 'alert-success' : 'alert-warning';
            const statusText = response.correct ? strings.practice_correct : strings.practice_incorrect;
            const icon = response.correct ? '&#10003;' : '&#10007;';
            $('.googlemeet-practice-feedback')
                .removeClass('d-none alert-success alert-warning')
                .addClass('alert ' + feedbackClass)
                .html('<div class="fw-bold"><span aria-hidden="true">' + icon + '</span> ' +
                    escapeHtml(statusText) + '</div>' +
                    (response.correct ? '' : '<div class="mt-2"><strong>' + escapeHtml(strings.practice_correct_answer) +
                        '</strong> ' + correctText + '</div>') +
                    (response.explanation ? '<div class="mt-2">' + response.explanation + '</div>' : ''));
            updatePracticeHeader(practice, player, true);
            $('.googlemeet-practice-check').addClass('d-none');
            $('.googlemeet-practice-next').removeClass('d-none').text(
                practice.index + 1 >= practice.queue.length ? strings.practice_finish : strings.practice_next
            ).trigger('focus');
        }).fail(error => {
            $('.googlemeet-practice-check').prop('disabled', false);
            $('.googlemeet-practice-error')
                .removeClass('d-none')
                .text(error.message || strings.ai_error_unknown);
        });
    });

    $('.googlemeet-practice-next').on('click', () => {
        practice.index++;
        if (practice.index >= practice.queue.length) {
            showPracticeComplete(practice, player);
        } else {
            renderPracticeQuestion(practice, player);
            $('.googlemeet-practice-stem').attr('tabindex', '-1').trigger('focus');
        }
    });

    $('.googlemeet-practice-review').on('click', () => startRound(practice.wrong, true));
    $('.googlemeet-practice-retry').on('click', () => startRound(practice.questions, false));
};

/**
 * Show a hub tab button through Bootstrap (or the jQuery bridge when only that exists).
 *
 * @param {HTMLElement} tab Tab button.
 * @returns {void}
 */
const showTab = tab => {
    if (window.bootstrap && window.bootstrap.Tab) {
        window.bootstrap.Tab.getOrCreateInstance(tab).show();
    } else if (typeof $(tab).tab === 'function') {
        $(tab).tab('show');
    }
};

/**
 * Deep link to a tab with #summary, #questions, #materials, #transcript or #notes,
 * keep the hash in sync when switching, and scroll the active tab into view on mobile.
 *
 * @returns {void}
 */
const bindTabHash = () => {
    const hub = document.getElementById('googlemeet-recording-hub');
    if (!hub) {
        return;
    }
    const tabs = Array.from(hub.querySelectorAll('[data-hub-tab]'));
    const byName = name => tabs.find(tab => tab.getAttribute('data-hub-tab') === name);
    const reveal = tab => {
        const strip = tab.closest('.googlemeet-hub-tabs');
        if (strip && strip.scrollWidth > strip.clientWidth) {
            strip.scrollLeft = Math.max(0, tab.offsetLeft - (strip.clientWidth - tab.offsetWidth) / 2);
        }
    };
    const fromHash = () => {
        const name = (window.location.hash || '').replace(/^#/, '');
        const tab = name ? byName(name) : null;
        if (tab && !tab.classList.contains('active')) {
            showTab(tab);
        }
        return tab;
    };

    const initial = fromHash();
    if (initial) {
        reveal(initial);
        hub.querySelector('.googlemeet-hub-tabs').scrollIntoView({block: 'start'});
    }
    window.addEventListener('hashchange', fromHash);
    // Buttons that jump to another tab (study sidebar, end of practice).
    $(hub).on('click', '[data-hub-goto]', function() {
        const tab = byName(this.getAttribute('data-hub-goto'));
        if (tab) {
            showTab(tab);
            reveal(tab);
            tab.focus();
        }
    });
    $(tabs).on('shown.bs.tab', function() {
        reveal(this);
        if (window.history && window.history.replaceState) {
            const url = new URL(window.location.href);
            url.hash = this.getAttribute('data-hub-tab');
            window.history.replaceState(window.history.state, '', url.toString());
        }
    });
};

/**
 * Show an edge fade on the tab row only while there are more tabs to scroll to in that direction.
 *
 * @returns {void}
 */
const bindTabsOverflow = () => {
    const wrap = document.querySelector('#googlemeet-recording-hub .googlemeet-hub-tabs-wrap');
    const strip = wrap ? wrap.querySelector('.googlemeet-hub-tabs') : null;
    if (!strip) {
        return;
    }
    const update = () => {
        const max = strip.scrollWidth - strip.clientWidth;
        wrap.classList.toggle('googlemeet-tabs-more-start', max > 1 && strip.scrollLeft > 1);
        wrap.classList.toggle('googlemeet-tabs-more-end', max > 1 && strip.scrollLeft < max - 1);
    };
    strip.addEventListener('scroll', update, {passive: true});
    window.addEventListener('resize', update);
    if (window.ResizeObserver) {
        new window.ResizeObserver(update).observe(strip);
    }
    update();
};

/**
 * Stable storage key for a key point, independent of its position (text prefix, whitespace-normalised).
 *
 * @param {string} text Key point text.
 * @returns {string}
 */
const keypointKey = text => String(text || '').replace(/\s+/g, ' ').trim().slice(0, 120);

/**
 * Read a JSON value from localStorage (per-viewer convenience only; failures are ignored).
 *
 * @param {string} key Storage key.
 * @param {*} fallback Value when missing or unavailable.
 * @returns {*}
 */
const readLocal = (key, fallback) => {
    try {
        const raw = window.localStorage.getItem(key);
        return raw ? JSON.parse(raw) : fallback;
    } catch {
        return fallback;
    }
};

/**
 * Write a JSON value to localStorage, ignoring storage failures.
 *
 * @param {string} key Storage key.
 * @param {*} value Value.
 * @returns {void}
 */
const writeLocal = (key, value) => {
    try {
        window.localStorage.setItem(key, JSON.stringify(value));
    } catch {
        return;
    }
};

/**
 * Collapse a long summary behind a "Read more" toggle (only when it really overflows).
 *
 * @returns {void}
 */
const bindReadMore = () => {
    const body = document.getElementById('googlemeet-ai-summary-body');
    const toggle = document.querySelector('#googlemeet-recording-hub .googlemeet-readmore');
    if (!body || !toggle) {
        return;
    }
    const measure = () => {
        body.classList.add('googlemeet-collapsed');
        // Only collapse when at least ~30% more text is hidden; hiding two lines is just annoying.
        const overflow = body.scrollHeight > body.clientHeight * 1.3;
        if (!overflow) {
            body.classList.remove('googlemeet-collapsed');
            toggle.classList.add('d-none');
            return false;
        }
        toggle.classList.remove('d-none');
        return true;
    };
    const panel = document.getElementById('googlemeet-summary-panel');
    const ready = () => {
        if (body.getAttribute('data-measured') === '1' || (panel && !panel.classList.contains('active'))) {
            return;
        }
        body.setAttribute('data-measured', '1');
        measure();
    };
    ready();
    $('#googlemeet-summary-tab').on('shown.bs.tab', ready);
    toggle.addEventListener('click', () => {
        const expanded = toggle.getAttribute('aria-expanded') === 'true';
        body.classList.toggle('googlemeet-collapsed', expanded);
        toggle.setAttribute('aria-expanded', expanded ? 'false' : 'true');
        toggle.textContent = toggle.getAttribute(expanded ? 'data-label-more' : 'data-label-less');
        if (expanded) {
            body.closest('section').scrollIntoView({block: 'nearest', behavior: prefersReducedMotion() ? 'auto' : 'smooth'});
        }
    });
};

/**
 * "Imprimir resumen": print the lesson notes (print stylesheet hides player, tabs and actions).
 *
 * @returns {void}
 */
const bindPrintSummary = () => {
    const button = document.querySelector('#googlemeet-recording-hub .googlemeet-print-summary');
    if (!button || typeof window.print !== 'function') {
        return;
    }
    button.classList.remove('d-none');
    const body = document.getElementById('googlemeet-ai-summary-body');
    let wasCollapsed = false;
    window.addEventListener('beforeprint', () => {
        wasCollapsed = !!body && body.classList.contains('googlemeet-collapsed');
        if (wasCollapsed) {
            body.classList.remove('googlemeet-collapsed');
        }
    });
    window.addEventListener('afterprint', () => {
        if (wasCollapsed) {
            body.classList.add('googlemeet-collapsed');
        }
    });
    button.addEventListener('click', () => window.print());
};

/**
 * Key points as a personal review checklist, remembered per recording in this browser.
 *
 * @returns {void}
 */
const bindKeypointChecklist = () => {
    const section = document.querySelector('#googlemeet-recording-hub .googlemeet-ai-keypoints');
    if (!section) {
        return;
    }
    const items = Array.from(section.querySelectorAll('.googlemeet-keypoint'));
    const counter = section.querySelector('.googlemeet-keypoints-progress');
    const storageKey = 'mod_googlemeet_keypoints_' + settings.recordingid;
    const done = new Set(readLocal(storageKey, []));
    const keyOf = item => keypointKey(item.querySelector('.googlemeet-keypoint-text').textContent);

    const refresh = () => {
        let count = 0;
        items.forEach(item => {
            const checked = item.querySelector('.googlemeet-keypoint-check').checked;
            item.classList.toggle('googlemeet-keypoint-done', checked);
            count += checked ? 1 : 0;
        });
        counter.textContent = (counter.getAttribute('data-template') || '{$a->done}/{$a->total}')
            .replace('{$a->done}', count).replace('{$a->total}', items.length);
        counter.classList.toggle('googlemeet-keypoints-complete', count === items.length);
    };

    items.forEach(item => {
        const box = item.querySelector('.googlemeet-keypoint-check');
        box.checked = done.has(keyOf(item));
        box.classList.remove('d-none');
        box.addEventListener('change', () => {
            if (box.checked) {
                done.add(keyOf(item));
            } else {
                done.delete(keyOf(item));
            }
            writeLocal(storageKey, Array.from(done));
            refresh();
        });
    });
    section.classList.add('googlemeet-keypoints-interactive');
    counter.classList.remove('d-none');
    section.querySelector('.googlemeet-keypoints-hint').classList.remove('d-none');
    refresh();
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
 * @param {boolean} config.progresscompleted Whether this user already completed the recording.
 * @param {number} config.progresswatchedseconds Accumulated heartbeat seconds.
 * @param {number} config.progressthresholdseconds Completion threshold in seconds.
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
        progresscompleted: !!config.progresscompleted,
        progresswatchedseconds: parseInt(config.progresswatchedseconds, 10) || 0,
        progressthresholdseconds: parseInt(config.progressthresholdseconds, 10) || 480,
    };
    settings.hubStateKey = 'mod_googlemeet_hub_state_' + settings.cmid + '_' + settings.recordingid;

    loadStrings().then(() => {
        $(document).ready(() => {
            restoreHubState();
            bindTabHash();
            bindTabsOverflow();
            bindQuestionManagement();
            if (settings.caneditrecording) {
                bindRecordingManagement();
            }
            bindRecordingProgress();
            bindChapters();
            bindTimestampLinks();
            bindTranscriptSearch();
            bindReadMore();
            bindKeypointChecklist();
            bindPrintSummary();
            bindPracticePlayer();
        });
    }).catch(Notification.exception);
};
