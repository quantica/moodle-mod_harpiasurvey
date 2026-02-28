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

/**
 * Library of interface functions and constants.
 *
 * @package     mod_harpiasurvey
 * @copyright   2025 Your Name
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Return if the plugin supports $feature.
 *
 * @param string $feature Constant representing the feature.
 * @return true | null | string True if the feature is supported, null otherwise.
 */
function harpiasurvey_supports($feature) {
    switch ($feature) {
        case FEATURE_MOD_INTRO:
        case FEATURE_SHOW_DESCRIPTION:
        case FEATURE_BACKUP_MOODLE2:
        case FEATURE_COMPLETION_TRACKS_VIEWS:
        case FEATURE_COMPLETION_HAS_RULES:
            return true;
        case FEATURE_MOD_ARCHETYPE:
            return MOD_ARCHETYPE_ASSIGNMENT;
        case FEATURE_MOD_PURPOSE:
            return MOD_PURPOSE_INTERACTIVECONTENT;
        default:
            return null;
    }
}

/**
 * File serving callback for mod_harpiasurvey editor content.
 *
 * @param stdClass $course Course object
 * @param cm_info|stdClass $cm Course module
 * @param context $context Context
 * @param string $filearea File area
 * @param array $args Remaining file path args
 * @param bool $forcedownload Force download
 * @param array $options Additional options
 * @return bool
 */
function harpiasurvey_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload, array $options = []) {
    global $DB;

    if ($context->contextlevel !== CONTEXT_MODULE) {
        return false;
    }

    require_login($course, true, $cm);

    if (!in_array($filearea, ['page', 'reviewcsv'], true)) {
        return false;
    }

    if (empty($args)) {
        return false;
    }

    $itemid = (int)array_shift($args);
    if ($itemid <= 0) {
        return false;
    }

    if ($filearea === 'page' || $filearea === 'reviewcsv') {
        // Ensure the page belongs to this activity instance.
        $sql = "SELECT p.id
                  FROM {harpiasurvey_pages} p
                  JOIN {harpiasurvey_experiments} e ON e.id = p.experimentid
                 WHERE p.id = :pageid AND e.harpiasurveyid = :harpiasurveyid";
        $pageok = $DB->record_exists_sql($sql, [
            'pageid' => $itemid,
            'harpiasurveyid' => $cm->instance
        ]);
        if (!$pageok) {
            return false;
        }
    }

    $filename = array_pop($args);
    $filepath = $args ? '/' . implode('/', $args) . '/' : '/';

    $fs = get_file_storage();
    $file = $fs->get_file($context->id, 'mod_harpiasurvey', $filearea, $itemid, $filepath, $filename);
    if (!$file || $file->is_directory()) {
        return false;
    }

    send_stored_file($file, null, 0, $forcedownload, $options);
}

/**
 * Saves a new instance of the mod_harpiasurvey into the database.
 *
 * Given an object containing all the necessary data, (defined by the form
 * in mod_form.php) this function will create a new instance and return the id
 * number of the instance.
 *
 * @param object $moduleinstance An object from the form.
 * @param mod_harpiasurvey_mod_form $mform The form.
 * @return int The id of the newly inserted record.
 */
function harpiasurvey_add_instance($moduleinstance, $mform = null) {
    global $DB;

    $moduleinstance->timecreated = time();
    $moduleinstance->timemodified = time();

    $id = $DB->insert_record('harpiasurvey', $moduleinstance);

    return $id;
}

/**
 * Updates an instance of the mod_harpiasurvey in the database.
 *
 * Given an object containing all the necessary data (defined in mod_form.php),
 * this function will update an existing instance with new data.
 *
 * @param object $moduleinstance An object from the form in mod_form.php.
 * @param mod_harpiasurvey_mod_form $mform The form.
 * @return bool True if successful, false otherwise.
 */
function harpiasurvey_update_instance($moduleinstance, $mform = null) {
    global $DB;

    $moduleinstance->timemodified = time();
    $moduleinstance->id = $moduleinstance->instance;

    return $DB->update_record('harpiasurvey', $moduleinstance);
}

/**
 * Removes an instance of the mod_harpiasurvey from the database.
 *
 * @param int $id Id of the module instance.
 * @return bool True if successful, false on failure.
 */
function harpiasurvey_delete_instance($id) {
    global $DB;

    $exists = $DB->get_record('harpiasurvey', ['id' => $id]);
    if (!$exists) {
        return false;
    }

    // Delete experiment-model associations, pages, and questions.
    $experiments = $DB->get_records('harpiasurvey_experiments', ['harpiasurveyid' => $id], '', 'id');
    if (!empty($experiments)) {
        $experimentids = array_keys($experiments);
        $DB->delete_records_list('harpiasurvey_experiment_models', 'experimentid', $experimentids);
        if ($DB->get_manager()->table_exists('harpiasurvey_experiment_finalizations')) {
            $DB->delete_records_list('harpiasurvey_experiment_finalizations', 'experimentid', $experimentids);
        }
        
        // Get page IDs before deleting pages.
        $pageids = $DB->get_records_list('harpiasurvey_pages', 'experimentid', $experimentids, '', 'id');
        if (!empty($pageids)) {
            $pageidlist = array_keys($pageids);
            $DB->delete_records_list('harpiasurvey_page_questions', 'pageid', $pageidlist);
            if ($DB->get_manager()->table_exists('harpiasurvey_review_targets')) {
                $DB->delete_records_list('harpiasurvey_review_targets', 'pageid', $pageidlist);
            }
            if ($DB->get_manager()->table_exists('harpiasurvey_review_datasets')) {
                $datasets = $DB->get_records_list('harpiasurvey_review_datasets', 'pageid', $pageidlist, '', 'id');
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

            $cmid = $DB->get_field_sql(
                "SELECT cm.id
                   FROM {course_modules} cm
                   JOIN {modules} m ON m.id = cm.module
                  WHERE cm.instance = :instanceid AND m.name = 'harpiasurvey'",
                ['instanceid' => $id]
            );
            if ($cmid) {
                $cmcontext = \context_module::instance($cmid);
                $fs = get_file_storage();
                foreach ($pageidlist as $pid) {
                    $fs->delete_area_files($cmcontext->id, 'mod_harpiasurvey', 'reviewcsv', $pid);
                }
            }
        }
        $DB->delete_records_list('harpiasurvey_pages', 'experimentid', $experimentids);
    }
    
    // Delete questions and their options.
    $questions = $DB->get_records('harpiasurvey_questions', ['harpiasurveyid' => $id], '', 'id');
    if (!empty($questions)) {
        $questionids = array_keys($questions);
        $DB->delete_records_list('harpiasurvey_question_options', 'questionid', $questionids);
        $DB->delete_records_list('harpiasurvey_questions', 'id', $questionids);
    }

    // Delete experiments.
    $DB->delete_records('harpiasurvey_experiments', ['harpiasurveyid' => $id]);

    // Delete models.
    $DB->delete_records('harpiasurvey_models', ['harpiasurveyid' => $id]);

    // Delete the main instance.
    $DB->delete_records('harpiasurvey', ['id' => $id]);

    return true;
}

/**
 * Returns the information on whether the module supports a feature.
 *
 * @param string $feature FEATURE_xx constant for requested feature.
 * @return mixed True if the feature is supported, null if unknown.
 */
function harpiasurvey_get_extra_capabilities() {
    return [];
}

/**
 * Given a course_module object, this function returns any
 * "extra" information that may be needed when printing
 * this activity in a course listing.
 *
 * @param stdClass $coursemodule The course module object.
 * @return cached_cm_info An object on information that the courses
 *                        will know about (most noticeably, an icon).
 */
function harpiasurvey_get_coursemodule_info($coursemodule) {
    global $DB;

    $dbrecord = $DB->get_record('harpiasurvey', ['id' => $coursemodule->instance],
        'id, name, intro, introformat', MUST_EXIST);

    $info = new cached_cm_info();
    $info->name = $dbrecord->name;

    if ($coursemodule->showdescription) {
        // Convert intro to html. Do not filter cached version, filters run at display time.
        $info->content = format_module_intro('harpiasurvey', $dbrecord, $coursemodule->id, false);
    }

    return $info;
}

/**
 * Whether the activity is branded.
 *
 * This information is used to decide if a filter should be applied to the icon or not.
 * Since the logo has a specific blue color, we mark it as branded to preserve the original colors.
 *
 * @return bool True if the activity is branded, false otherwise.
 */
function harpiasurvey_is_branded(): bool {
    return true;
}

/**
 * Sets dynamic information about a course module.
 *
 * This function is called from cm_info when displaying the module.
 * Can be used to modify name, visibility, URL, etc.
 *
 * @param cm_info $cm Course-module object
 */
function harpiasurvey_cm_info_dynamic(cm_info $cm) {
    // Dynamic data can be set here if needed.
    // For example: $cm->set_name(), $cm->set_no_view_link(), etc.
}

/**
 * Sets the content to display on the course page.
 *
 * This function is called when the course page is rendered and allows
 * the module to display custom content (like a table of experiments) directly on the course page.
 *
 * @param cm_info $cm Course-module object
 */
function harpiasurvey_cm_info_view(cm_info $cm) {
    global $DB, $PAGE, $OUTPUT, $USER;

    // Only display content if user can view the module.
    if (!$cm->uservisible) {
        return;
    }

    // Get the harpiasurvey instance.
    $harpiasurvey = $DB->get_record('harpiasurvey', ['id' => $cm->instance], '*', IGNORE_MISSING);
    if (!$harpiasurvey) {
        return;
    }

    // Query experiments for this harpiasurvey instance.
    $experiments = $DB->get_records('harpiasurvey_experiments', ['harpiasurveyid' => $harpiasurvey->id], 'name ASC');

    // Check if user can manage experiments.
    $canmanage = has_capability('mod/harpiasurvey:manageexperiments', $cm->context);

    // Create and render experiments table using Mustache template.
    require_once(__DIR__.'/classes/output/experiments_table.php');
    $experimentstable = new \mod_harpiasurvey\output\experiments_table($experiments, $cm->context, $cm->id, $canmanage);
    $renderer = $PAGE->get_renderer('mod_harpiasurvey');
    $tablehtml = $renderer->render_experiments_table($experimentstable);

    // Add JavaScript for sortable table functionality.
    // Since we can't use js_call_amd in cm_info_view, we add an inline script.
    // The script will initialize when the page loads.
    $tablehtml = str_replace(
        'class="table table-hover table-bordered harpiasurvey-sortable-table"',
        'class="table table-hover table-bordered harpiasurvey-sortable-table" data-sortable="true"',
        $tablehtml
    );
    
    // Add inline script to initialize sortable table on course page.
    $tablehtml .= '<script>
        require(["mod_harpiasurvey/sortable_table"], function(SortableTable) {
            SortableTable.init();
        });
    </script>';

    // Set the content to display on the course page.
    // The second parameter 'true' indicates the content is already formatted HTML.
    $cm->set_content($tablehtml, true);
}

/**
 * Extends the settings navigation with the mod_harpiasurvey settings.
 *
 * This function is called when the context for the page is a mod_harpiasurvey module.
 * This is not called by AJAX so it is safe to rely on the $PAGE.
 *
 * @param settings_navigation $settingsnav {@see settings_navigation}
 * @param navigation_node $harpiasurveynode {@see navigation_node}
 */
function harpiasurvey_extend_settings_navigation($settingsnav, $harpiasurveynode = null) {
    global $PAGE, $DB;
    
    // Only show for users who can manage experiments.
    if (!has_capability('mod/harpiasurvey:manageexperiments', $PAGE->cm->context)) {
        return;
    }
    
    // Get the harpiasurvey instance to find experiments.
    $harpiasurvey = $DB->get_record('harpiasurvey', ['id' => $PAGE->cm->instance], '*', IGNORE_MISSING);
    if (!$harpiasurvey) {
        return;
    }
    
    // Get the first experiment (questions are shared across experiments, so any experiment works).
    // Use get_records() since there can be multiple experiments, then take the first one.
    $experiments = $DB->get_records('harpiasurvey_experiments', ['harpiasurveyid' => $harpiasurvey->id], 'id ASC', 'id', 0, 1);
    if (!empty($experiments)) {
        $experiment = reset($experiments);
        $questionbanknode = navigation_node::create(
            get_string('questionbank', 'mod_harpiasurvey'),
            new moodle_url('/mod/harpiasurvey/question_bank.php', ['id' => $PAGE->cm->id, 'experiment' => $experiment->id]),
            navigation_node::TYPE_SETTING,
            null,
            'mod_harpiasurvey_questionbank',
            new pix_icon('t/edit', '')
        );
        $harpiasurveynode->add_node($questionbanknode);
        
        // Add Models node next to Question Bank.
        // Only mark as active if we're actually on the models tab.
        $modelsurl = new moodle_url('/mod/harpiasurvey/view_experiment.php', ['id' => $PAGE->cm->id, 'experiment' => $experiment->id, 'tab' => 'models']);
        $modelsnode = navigation_node::create(
            get_string('models', 'mod_harpiasurvey'),
            $modelsurl,
            navigation_node::TYPE_SETTING,
            null,
            'mod_harpiasurvey_models',
            new pix_icon('i/settings', '')
        );
        // Only make active if current URL matches exactly (including tab parameter).
        $currenturl = $PAGE->url;
        // Check if we're on the models tab by comparing the 'tab' parameter.
        $currenttab = $currenturl->param('tab');
        if ($currenttab === 'models') {
            $modelsnode->make_active();
        }
        $harpiasurveynode->add_node($modelsnode);
    }
}
