<?php
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
 * Find (and optionally delete) data left behind by recordings or activities that no longer exist (DAT-01).
 *
 * @package     mod_googlemeet
 * @copyright   2026 PreparaOposiciones
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use mod_googlemeet\local\recording_cleanup;

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

list($options, $unrecognized) = cli_get_params([
    'dry-run' => false,
    'execute' => false,
    'verbose' => false,
    'help' => false,
], [
    'd' => 'dry-run',
    'e' => 'execute',
    'v' => 'verbose',
    'h' => 'help',
]);

if ($unrecognized) {
    $unrecognized = implode("\n  ", $unrecognized);
    cli_error("Unrecognised options:\n  {$unrecognized}");
}

$help = "Find data of mod_googlemeet whose recording or activity no longer exists.

Looks for: recordings of deleted activities, AI analyses, viewing progress, practice attempts,
material files, per-recording user preferences and practice questions tagged googlemeet-rec-<id>
whose recording is gone, plus subscriptions, events, holidays, cancelled dates and notification
bookkeeping of deleted activities. Questions still used elsewhere (e.g. in a quiz) are hidden
instead of deleted.

Options:
  -d, --dry-run   List what would be removed (default when no option is given).
  -e, --execute   Remove it.
  -v, --verbose   Print every id / preference name.
  -h, --help      Print this help.

Example:
  php mod/googlemeet/cli/cleanup_orphans.php --dry-run
  php mod/googlemeet/cli/cleanup_orphans.php --execute
";

if (!empty($options['help'])) {
    cli_writeln($help);
    exit(0);
}
if (!empty($options['dry-run']) && !empty($options['execute'])) {
    cli_error('Use either --dry-run or --execute, not both.');
}
$execute = !empty($options['execute']);

\core\session\manager::set_user(get_admin());

$orphans = recording_cleanup::find_orphans();
$total = 0;
cli_heading($execute ? 'mod_googlemeet orphan cleanup' : 'mod_googlemeet orphan report (dry run)');
foreach ($orphans as $kind => $items) {
    $count = count($items);
    $total += $count;
    cli_writeln(sprintf('  %-14s %d', $kind, $count));
    if (!empty($options['verbose']) && $count) {
        $list = $kind === 'questions' ? array_keys($items) : $items;
        cli_writeln('    ' . implode(', ', $list));
    }
}

if (!$total) {
    cli_writeln('Nothing to clean.');
    exit(0);
}
if (!$execute) {
    cli_writeln("Dry run: nothing deleted. Run again with --execute to remove {$total} item(s).");
    exit(0);
}

$result = recording_cleanup::delete_orphans($orphans);
cli_heading('Removed');
foreach ($result as $kind => $count) {
    cli_writeln(sprintf('  %-16s %d', $kind, $count));
}
exit(0);
