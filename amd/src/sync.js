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
};
