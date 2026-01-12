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
$confirm = optional_param('confirm', 0, PARAM_BOOL);

list($course, $cm) = get_course_and_cm_from_cmid($id, 'harpiasurvey');
$harpiasurvey = $DB->get_record('harpiasurvey', ['id' => $cm->instance], '*', MUST_EXIST);

$page = $DB->get_record('harpiasurvey_pages', ['id' => $pageid], '*', MUST_EXIST);
$experiment = $DB->get_record('harpiasurvey_experiments', ['id' => $page->experimentid, 'harpiasurveyid' => $harpiasurvey->id], '*', MUST_EXIST);
$subpage = $DB->get_record('harpiasurvey_subpages', ['id' => $subpageid, 'pageid' => $pageid], '*', MUST_EXIST);

require_login($course, true, $cm);
require_capability('mod/harpiasurvey:manageexperiments', $cm->context);
require_sesskey();

$context = $cm->context;

$url = new moodle_url('/mod/harpiasurvey/delete_subpage.php', ['id' => $id, 'page' => $pageid, 'subpage' => $subpageid]);
$PAGE->set_url($url);
$PAGE->set_title(get_string('deletesubpage', 'mod_harpiasurvey'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);

// Add breadcrumb.
$PAGE->navbar->add(format_string($harpiasurvey->name), new moodle_url('/mod/harpiasurvey/view.php', ['id' => $cm->id]));
$PAGE->navbar->add(format_string($experiment->name), new moodle_url('/mod/harpiasurvey/view_experiment.php', ['id' => $cm->id, 'experiment' => $experiment->id]));
$PAGE->navbar->add(format_string($page->title), new moodle_url('/mod/harpiasurvey/edit_page.php', ['id' => $cm->id, 'experiment' => $experiment->id, 'page' => $pageid]));
$PAGE->navbar->add(get_string('deletesubpage', 'mod_harpiasurvey'));

if ($confirm) {
    // Delete subpage questions first (if any exist in the future).
    // For now, subpages don't have questions directly, but we'll leave this for future expansion.
    
    // Delete the subpage.
    $DB->delete_records('harpiasurvey_subpages', ['id' => $subpageid]);
    
    redirect(new moodle_url('/mod/harpiasurvey/edit_page.php', ['id' => $cm->id, 'experiment' => $experiment->id, 'page' => $pageid]));
}

// Display confirmation page.
echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('deletesubpage', 'mod_harpiasurvey'));

$message = get_string('confirmdeletesubpage', 'mod_harpiasurvey', format_string($subpage->title));
$confirmurl = new moodle_url('/mod/harpiasurvey/delete_subpage.php', [
    'id' => $id,
    'page' => $pageid,
    'subpage' => $subpageid,
    'confirm' => 1,
    'sesskey' => sesskey()
]);
$cancelurl = new moodle_url('/mod/harpiasurvey/edit_page.php', ['id' => $cm->id, 'experiment' => $experiment->id, 'page' => $pageid]);

echo $OUTPUT->confirm($message, $confirmurl, $cancelurl);
echo $OUTPUT->footer();

