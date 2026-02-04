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

require(__DIR__.'/../../../config.php');
require_once($CFG->libdir.'/clilib.php');

global $DB;

// Delete in order to respect foreign key constraints.
$tables = [
    'harpiasurvey_conversations',
    'harpiasurvey_response_history',
    'harpiasurvey_responses',
    'harpiasurvey_question_options',
    'harpiasurvey_questions',
];

echo "Clearing Harpia Survey data tables...\n\n";

foreach ($tables as $table) {
    try {
        $count = $DB->count_records($table);
        if ($count > 0) {
            $DB->delete_records($table);
            echo "✓ Deleted {$count} records from {$table}\n";
        } else {
            echo "  No records in {$table}\n";
        }
    } catch (Exception $e) {
        echo "✗ Error clearing {$table}: " . $e->getMessage() . "\n";
    }
}

echo "\nDone!\n";
