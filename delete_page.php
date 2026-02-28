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
$experimentid = required_param('experiment', PARAM_INT);
$pageid = required_param('page', PARAM_INT);
$confirm = optional_param('confirm', 0, PARAM_INT);

list($course, $cm) = get_course_and_cm_from_cmid($id, 'harpiasurvey');
$harpiasurvey = $DB->get_record('harpiasurvey', ['id' => $cm->instance], '*', MUST_EXIST);
$experiment = $DB->get_record('harpiasurvey_experiments', ['id' => $experimentid, 'harpiasurveyid' => $harpiasurvey->id], '*', MUST_EXIST);
$page = $DB->get_record('harpiasurvey_pages', ['id' => $pageid, 'experimentid' => $experiment->id], '*', MUST_EXIST);

require_login($course, true, $cm);
require_capability('mod/harpiasurvey:manageexperiments', $cm->context);

$context = $cm->context;

// Handle deletion.
if ($confirm && confirm_sesskey()) {
    // Delete review-conversation dataset and targets for this page.
    if ($DB->get_manager()->table_exists('harpiasurvey_review_targets')) {
        $DB->delete_records('harpiasurvey_review_targets', ['pageid' => $pageid]);
    }
    if ($DB->get_manager()->table_exists('harpiasurvey_review_datasets')) {
        $dataset = $DB->get_record('harpiasurvey_review_datasets', ['pageid' => $pageid]);
        if ($dataset) {
            if ($DB->get_manager()->table_exists('harpiasurvey_review_message_threads')) {
                $DB->delete_records('harpiasurvey_review_message_threads', ['datasetid' => $dataset->id]);
            }
            if ($DB->get_manager()->table_exists('harpiasurvey_review_messages')) {
                $DB->delete_records('harpiasurvey_review_messages', ['datasetid' => $dataset->id]);
            }
            if ($DB->get_manager()->table_exists('harpiasurvey_review_threads')) {
                $DB->delete_records('harpiasurvey_review_threads', ['datasetid' => $dataset->id]);
            }
            $DB->delete_records('harpiasurvey_review_datasets', ['id' => $dataset->id]);
        }
    }
    get_file_storage()->delete_area_files($context->id, 'mod_harpiasurvey', 'reviewcsv', $pageid);

    // Delete all page-question associations first.
    $DB->delete_records('harpiasurvey_page_questions', ['pageid' => $pageid]);
    
    // Delete all responses for this page.
    $DB->delete_records('harpiasurvey_responses', ['pageid' => $pageid]);
    
    // Delete all conversations for this page.
    $DB->delete_records('harpiasurvey_conversations', ['pageid' => $pageid]);
    
    // Delete the page.
    $DB->delete_records('harpiasurvey_pages', ['id' => $pageid]);
    
    // Redirect back to experiment view.
    redirect(new moodle_url('/mod/harpiasurvey/view_experiment.php', [
        'id' => $cm->id,
        'experiment' => $experiment->id
    ]), get_string('pagedeleted', 'mod_harpiasurvey'), null, \core\output\notification::NOTIFY_SUCCESS);
}

// Display confirmation page.
$url = new moodle_url('/mod/harpiasurvey/delete_page.php', [
    'id' => $cm->id,
    'experiment' => $experiment->id,
    'page' => $pageid
]);
$PAGE->set_url($url);
$PAGE->set_title(get_string('deletepage', 'mod_harpiasurvey'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);

// Add breadcrumb.
$PAGE->navbar->add(format_string($harpiasurvey->name), new moodle_url('/mod/harpiasurvey/view.php', ['id' => $cm->id]));
$PAGE->navbar->add(format_string($experiment->name), new moodle_url('/mod/harpiasurvey/view_experiment.php', ['id' => $cm->id, 'experiment' => $experiment->id]));
$PAGE->navbar->add(get_string('deletepage', 'mod_harpiasurvey'));

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('deletepage', 'mod_harpiasurvey'));

// Count related records.
$questioncount = $DB->count_records('harpiasurvey_page_questions', ['pageid' => $pageid]);
$responsecount = $DB->count_records('harpiasurvey_responses', ['pageid' => $pageid]);
$conversationcount = $DB->count_records('harpiasurvey_conversations', ['pageid' => $pageid]);

echo $OUTPUT->confirm(
    get_string('deletepageconfirm', 'mod_harpiasurvey', [
        'title' => format_string($page->title),
        'questions' => $questioncount,
        'responses' => $responsecount,
        'conversations' => $conversationcount
    ]),
    new moodle_url('/mod/harpiasurvey/delete_page.php', [
        'id' => $cm->id,
        'experiment' => $experiment->id,
        'page' => $pageid,
        'confirm' => 1,
        'sesskey' => sesskey()
    ]),
    new moodle_url('/mod/harpiasurvey/view_experiment.php', [
        'id' => $cm->id,
        'experiment' => $experiment->id
    ])
);

echo $OUTPUT->footer();
