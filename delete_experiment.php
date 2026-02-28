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

require(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

$id = required_param('id', PARAM_INT);
$experimentid = required_param('experiment', PARAM_INT);
$confirm = optional_param('confirm', 0, PARAM_INT);

list($course, $cm) = get_course_and_cm_from_cmid($id, 'harpiasurvey');
$harpiasurvey = $DB->get_record('harpiasurvey', ['id' => $cm->instance], '*', MUST_EXIST);
$experiment = $DB->get_record('harpiasurvey_experiments', [
    'id' => $experimentid,
    'harpiasurveyid' => $harpiasurvey->id
], '*', MUST_EXIST);

require_login($course, true, $cm);
require_capability('mod/harpiasurvey:manageexperiments', $cm->context);

$context = $cm->context;

if ($confirm && confirm_sesskey()) {
    $pages = $DB->get_records('harpiasurvey_pages', ['experimentid' => $experiment->id], '', 'id');
    $pageids = array_keys($pages);

    if (!empty($pageids)) {
        // Subpages and subpage-question mappings.
        if ($DB->get_manager()->table_exists('harpiasurvey_subpages')) {
            $subpages = $DB->get_records_list('harpiasurvey_subpages', 'pageid', $pageids, '', 'id');
            $subpageids = array_keys($subpages);
            if (!empty($subpageids) && $DB->get_manager()->table_exists('harpiasurvey_subpage_questions')) {
                $DB->delete_records_list('harpiasurvey_subpage_questions', 'subpageid', $subpageids);
            }
            if (!empty($subpageids)) {
                $DB->delete_records_list('harpiasurvey_subpages', 'id', $subpageids);
            }
        }

        // Page associations and runtime data.
        $DB->delete_records_list('harpiasurvey_page_questions', 'pageid', $pageids);
        if ($DB->get_manager()->table_exists('harpiasurvey_page_models')) {
            $DB->delete_records_list('harpiasurvey_page_models', 'pageid', $pageids);
        }
        if ($DB->get_manager()->table_exists('harpiasurvey_responses')) {
            $DB->delete_records_list('harpiasurvey_responses', 'pageid', $pageids);
        }
        if ($DB->get_manager()->table_exists('harpiasurvey_response_history')) {
            $DB->delete_records_list('harpiasurvey_response_history', 'pageid', $pageids);
        }
        if ($DB->get_manager()->table_exists('harpiasurvey_conversations')) {
            $DB->delete_records_list('harpiasurvey_conversations', 'pageid', $pageids);
        }
        if ($DB->get_manager()->table_exists('harpiasurvey_turn_branches')) {
            $DB->delete_records_list('harpiasurvey_turn_branches', 'pageid', $pageids);
        }
        if ($DB->get_manager()->table_exists('harpiasurvey_review_targets')) {
            $DB->delete_records_list('harpiasurvey_review_targets', 'pageid', $pageids);
        }
        if ($DB->get_manager()->table_exists('harpiasurvey_review_datasets')) {
            $datasets = $DB->get_records_list('harpiasurvey_review_datasets', 'pageid', $pageids, '', 'id');
            $datasetids = array_keys($datasets);
            if (!empty($datasetids)) {
                if ($DB->get_manager()->table_exists('harpiasurvey_review_message_threads')) {
                    $DB->delete_records_list('harpiasurvey_review_message_threads', 'datasetid', $datasetids);
                }
                if ($DB->get_manager()->table_exists('harpiasurvey_review_messages')) {
                    $DB->delete_records_list('harpiasurvey_review_messages', 'datasetid', $datasetids);
                }
                if ($DB->get_manager()->table_exists('harpiasurvey_review_threads')) {
                    $DB->delete_records_list('harpiasurvey_review_threads', 'datasetid', $datasetids);
                }
                $DB->delete_records_list('harpiasurvey_review_datasets', 'id', $datasetids);
            }
        }
        $fs = get_file_storage();
        foreach ($pageids as $pid) {
            $fs->delete_area_files($context->id, 'mod_harpiasurvey', 'reviewcsv', $pid);
        }
    }

    // Experiment-level associations.
    if ($DB->get_manager()->table_exists('harpiasurvey_experiment_finalizations')) {
        $DB->delete_records('harpiasurvey_experiment_finalizations', ['experimentid' => $experiment->id]);
    }
    $DB->delete_records('harpiasurvey_experiment_models', ['experimentid' => $experiment->id]);

    // Pages and experiment itself.
    if (!empty($pageids)) {
        $DB->delete_records_list('harpiasurvey_pages', 'id', $pageids);
    }
    $DB->delete_records('harpiasurvey_experiments', ['id' => $experiment->id]);

    redirect(
        new moodle_url('/mod/harpiasurvey/view.php', ['id' => $cm->id]),
        get_string('deleted', 'moodle'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

$url = new moodle_url('/mod/harpiasurvey/delete_experiment.php', [
    'id' => $cm->id,
    'experiment' => $experiment->id
]);
$PAGE->set_url($url);
$PAGE->set_title(get_string('delete'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);

$PAGE->navbar->add(format_string($harpiasurvey->name), new moodle_url('/mod/harpiasurvey/view.php', ['id' => $cm->id]));
$PAGE->navbar->add(format_string($experiment->name), new moodle_url('/mod/harpiasurvey/view_experiment.php', [
    'id' => $cm->id,
    'experiment' => $experiment->id
]));
$PAGE->navbar->add(get_string('delete'));

$pagecount = (int)$DB->count_records('harpiasurvey_pages', ['experimentid' => $experiment->id]);
$responsecount = (int)$DB->count_records_sql(
    "SELECT COUNT(1)
       FROM {harpiasurvey_responses} r
       JOIN {harpiasurvey_pages} p ON p.id = r.pageid
      WHERE p.experimentid = ?",
    [$experiment->id]
);
$conversationcount = (int)$DB->count_records_sql(
    "SELECT COUNT(1)
       FROM {harpiasurvey_conversations} c
       JOIN {harpiasurvey_pages} p ON p.id = c.pageid
      WHERE p.experimentid = ?",
    [$experiment->id]
);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('delete'));
echo html_writer::tag('p', format_string($experiment->name), ['class' => 'font-weight-bold']);
echo html_writer::alist([
    'Pages: ' . $pagecount,
    'Responses: ' . $responsecount,
    'Conversations: ' . $conversationcount,
], ['class' => 'mb-3']);

echo $OUTPUT->confirm(
    get_string('deletecheckfull', '', format_string($experiment->name)),
    new moodle_url('/mod/harpiasurvey/delete_experiment.php', [
        'id' => $cm->id,
        'experiment' => $experiment->id,
        'confirm' => 1,
        'sesskey' => sesskey()
    ]),
    new moodle_url('/mod/harpiasurvey/view.php', ['id' => $cm->id])
);

echo $OUTPUT->footer();
