<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

global $DB;

list($options, $unrecognized) = cli_get_params(
    [
        'since' => 0,
        'output' => '',
        'help' => false,
    ],
    [
        'h' => 'help',
    ]
);

if (!empty($unrecognized)) {
    cli_error('Unknown options: ' . implode(' ', $unrecognized));
}

if (!empty($options['help'])) {
    $help = "Export Moodle logstore entries as JSON lines.\n\n";
    $help .= "Options:\n";
    $help .= "  --since=<timestamp>   Unix timestamp (seconds) to filter logs from.\n";
    $help .= "  --output=<file>       Output file path (default: stdout).\n";
    $help .= "  -h, --help            Print this help.\n";
    echo $help;
    exit(0);
}

$since = (int)$options['since'];
$output = $options['output'];

$recordset = $DB->get_recordset_sql(
    "SELECT * FROM {logstore_standard_log} WHERE timecreated >= :since ORDER BY timecreated ASC",
    ['since' => $since]
);

$handle = null;
if (!empty($output)) {
    $handle = fopen($output, 'w');
    if (!$handle) {
        cli_error('Unable to open output file: ' . $output);
    }
}

foreach ($recordset as $record) {
    $line = json_encode($record, JSON_UNESCAPED_SLASHES);
    if ($handle) {
        fwrite($handle, $line . PHP_EOL);
    } else {
        echo $line . PHP_EOL;
    }
}
$recordset->close();

if ($handle) {
    fclose($handle);
}
