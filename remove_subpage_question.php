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

require(__DIR__.'/../../config.php');
require_once(__DIR__.'/lib.php');

$id = required_param('id', PARAM_INT);
$pageid = required_param('page', PARAM_INT);
$subpageid = required_param('subpage', PARAM_INT);
$questionid = required_param('question', PARAM_INT);

require_sesskey();

list($course, $cm) = get_course_and_cm_from_cmid($id, 'harpiasurvey');
$harpiasurvey = $DB->get_record('harpiasurvey', ['id' => $cm->instance], '*', MUST_EXIST);

$page = $DB->get_record('harpiasurvey_pages', ['id' => $pageid], '*', MUST_EXIST);
$experiment = $DB->get_record('harpiasurvey_experiments', ['id' => $page->experimentid, 'harpiasurveyid' => $harpiasurvey->id], '*', MUST_EXIST);
$subpage = $DB->get_record('harpiasurvey_subpages', ['id' => $subpageid, 'pageid' => $pageid], '*', MUST_EXIST);

require_login($course, true, $cm);
require_capability('mod/harpiasurvey:manageexperiments', $cm->context);

// Remove question from subpage.
$DB->delete_records('harpiasurvey_subpage_questions', [
    'subpageid' => $subpageid,
    'questionid' => $questionid
]);

// Redirect back to subpage edit.
redirect(new moodle_url('/mod/harpiasurvey/edit_subpage.php', [
    'id' => $cm->id,
    'page' => $pageid,
    'subpage' => $subpageid
]), get_string('questionremovedfromsubpage', 'mod_harpiasurvey'), null, \core\output\notification::NOTIFY_SUCCESS);

