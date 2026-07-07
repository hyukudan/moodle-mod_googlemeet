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
 * Recording sync helpers.
 *
 * @module     mod_googlemeet/sync
 * @copyright  2026 Eduardo Kraus
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import $ from 'jquery';

let initialised = false;
let countdownTimer = null;

/**
 * Format seconds remaining as a compact countdown.
 *
 * @param {number} seconds Seconds remaining.
 * @returns {string}
 */
const formatCountdown = seconds => {
    seconds = Math.max(0, Math.floor(seconds));
    if (seconds < 3600) {
        const minutes = Math.floor(seconds / 60);
        const secs = seconds % 60;
        return minutes + ':' + String(secs).padStart(2, '0');
    }
    if (seconds < 86400) {
        const hours = Math.floor(seconds / 3600);
        const minutes = Math.floor((seconds % 3600) / 60);
        return hours + 'h ' + minutes + 'm';
    }
    const days = Math.floor(seconds / 86400);
    const hours = Math.floor((seconds % 86400) / 3600);
    return days + 'd ' + hours + 'h';
};

/**
 * Promote a room CTA from soon to live when its countdown reaches zero.
 *
 * @param {jQuery} countdown Countdown element inside the CTA.
 * @returns {void}
 */
const refreshRoomCtaOnLive = countdown => {
    const cta = countdown.closest('.googlemeet-room-cta');
    if (!cta.length || cta.attr('data-googlemeet-room-live') === '1') {
        return;
    }

    const liveLabel = cta.attr('data-live-label') || '';
    if (liveLabel !== '') {
        cta.find('.googlemeet-room-cta-label').text(liveLabel);
    }
    cta.removeClass('googlemeet-room-cta-soon').addClass('googlemeet-room-cta-live');
    if (!cta.find('.googlemeet-pulse').length) {
        $('<span>').addClass('googlemeet-pulse').attr('aria-hidden', 'true').prependTo(cta);
    }
    countdown.text('').attr('hidden', 'hidden');
    cta.attr('data-googlemeet-room-live', '1');
};

/**
 * Refresh all countdown elements on the page.
 *
 * @returns {void}
 */
const refreshCountdowns = () => {
    $('[data-googlemeet-countdown]').each(function() {
        const element = $(this);
        const target = parseInt(element.attr('data-target-ts'), 10);
        if (!target) {
            return;
        }

        const remaining = target - Math.floor(Date.now() / 1000);
        if (remaining <= 0) {
            if (element.hasClass('googlemeet-room-cta-countdown')) {
                refreshRoomCtaOnLive(element);
                return;
            }

            const expired = element.attr('data-countdown-expired') || '';
            if (expired !== '') {
                element.text(expired);
            }
            return;
        }

        const prefix = element.attr('data-countdown-prefix') || '';
        const value = formatCountdown(remaining);
        element.text(prefix !== '' ? prefix + ' ' + value : value);
    });
};

/**
 * Initialise live countdown labels for room and upcoming-event states.
 *
 * @returns {void}
 */
const initCountdowns = () => {
    if (!$('[data-googlemeet-countdown]').length) {
        return;
    }

    refreshCountdowns();
    if (countdownTimer === null) {
        countdownTimer = window.setInterval(refreshCountdowns, 1000);
    }
};

/**
 * Initialise sync overlay and topic filter behaviours.
 *
 * @returns {void}
 */
export const init = () => {
    if (initialised) {
        return;
    }
    initialised = true;

    $(document).on('submit', 'form', event => {
        const form = $(event.currentTarget);
        const method = (form.attr('method') || '').toLowerCase();
        const action = form.attr('action') || '';
        const isSyncForm = method === 'post' && (
            form.find('input[name="sync"]').length || action.indexOf('sync=') !== -1
        );

        if (!isSyncForm) {
            return;
        }

        if (form.data('googlemeetSyncSubmitting')) {
            event.preventDefault();
            return false;
        }

        form.data('googlemeetSyncSubmitting', true);
        $('#googlemeet_syncimg').css('display', 'flex');
        form.find('input[type="submit"], button[type="submit"], button:not([type])').prop('disabled', true);

        return;
    });

    $('#googlemeet-topic-select').on('change', function() {
        this.form.submit();
    });

    initCountdowns();
};
