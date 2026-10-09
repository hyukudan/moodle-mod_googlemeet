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
 * IA-04 / F-8 teacher actions: publish reviewed AI summaries and retry a stuck analysis.
 *
 * @module     mod_googlemeet/ai_review
 * @copyright  2026 PreparaOposiciones
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import * as Notification from 'core/notification';
import {getString} from 'core/str';

let initialised = false;

/**
 * Disable a button while its request runs.
 *
 * @param {HTMLButtonElement} button Button.
 * @param {boolean} busy Busy state.
 */
const setBusy = (button, busy) => {
    button.disabled = busy;
    button.setAttribute('aria-busy', busy ? 'true' : 'false');
};

/**
 * Publish one recording's summary (recordingid > 0) or every reviewable summary (recordingid = 0).
 *
 * @param {HTMLButtonElement} button Trigger.
 * @param {number} cmid Course module id.
 * @param {number} recordingid Recording id or 0.
 * @returns {Promise}
 */
const publish = (button, cmid, recordingid) => {
    setBusy(button, true);
    return Ajax.call([{
        methodname: 'mod_googlemeet_review_ai_analysis',
        args: {coursemoduleid: cmid, recordingid: recordingid},
    }])[0].then(result => {
        if (!result.success) {
            setBusy(button, false);
            return getString('aireview_publish_failed', 'mod_googlemeet').then(Notification.alert);
        }
        // The page shows (or hides) content according to the review state: reload to reflect it.
        window.location.reload();
        return result;
    }).catch(error => {
        setBusy(button, false);
        Notification.exception(error);
    });
};

/**
 * F-8: re-queue a stuck analysis (the server accepts regeneration of a row stuck in "processing").
 *
 * @param {HTMLButtonElement} button Trigger.
 * @param {number} cmid Course module id.
 * @param {number} recordingid Recording id.
 * @returns {Promise}
 */
const retry = (button, cmid, recordingid) => {
    setBusy(button, true);
    return Ajax.call([{
        methodname: 'mod_googlemeet_generate_ai_analysis',
        args: {recordingid: recordingid, coursemoduleid: cmid, regenerate: true, forcedownload: false},
    }])[0].then(() => {
        window.location.reload();
        return null;
    }).catch(error => {
        setBusy(button, false);
        Notification.exception(error);
    });
};

/**
 * Bind the delegated click handlers once per page.
 */
export const init = () => {
    if (initialised) {
        return;
    }
    initialised = true;

    document.addEventListener('click', e => {
        const button = e.target.closest('[data-action^="ai-review-"], [data-action="ai-stuck-retry"]');
        if (!button || button.disabled) {
            return;
        }
        const cmid = parseInt(button.dataset.cmid, 10);
        const action = button.dataset.action;
        if (action === 'ai-review-publish') {
            e.preventDefault();
            publish(button, cmid, parseInt(button.dataset.recordingid, 10));
        } else if (action === 'ai-review-publish-all') {
            e.preventDefault();
            getString('aireview_publish_all_confirm', 'mod_googlemeet', button.dataset.count)
                .then(message => Notification.saveCancelPromise(
                    getString('aireview_publish_all', 'mod_googlemeet'),
                    message,
                    getString('aireview_publish_all_button', 'mod_googlemeet'),
                    {triggerElement: button}
                ))
                .then(() => publish(button, cmid, 0))
                .catch(() => null);
        } else if (action === 'ai-stuck-retry') {
            e.preventDefault();
            retry(button, cmid, parseInt(button.dataset.recordingid, 10));
        }
    });
};
