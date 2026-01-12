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
 * Upgrade code for the harpiasurvey module.
 *
 * @package     mod_harpiasurvey
 * @copyright   2025 Your Name
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Upgrade harpiasurvey module.
 *
 * @param int $oldversion The version we are upgrading from.
 * @return bool Result.
 */
function xmldb_harpiasurvey_upgrade($oldversion) {
    global $DB, $CFG;

    // If this is a fresh install (oldversion is 0), install.xml handles everything.
    // No need to run upgrades.
    if ($oldversion == 0) {
        return true;
    }

    $dbman = $DB->get_manager();

    // Automatically generated Moodle v5.1.0 release upgrade line. Do not remove.
    if ($oldversion < 2025010710) {
        // Create harpiasurvey_conversations table.
        $installfile = $CFG->dirroot . '/mod/harpiasurvey/db/install.xml';
        if (!$dbman->table_exists('harpiasurvey_conversations')) {
            $dbman->install_one_table_from_xmldb_file($installfile, 'harpiasurvey_conversations');
        }
        upgrade_mod_savepoint(true, 2025010710, 'harpiasurvey');
    }
    if ($oldversion < 2025010709) {
        // Create harpiasurvey_responses table.
        $installfile = $CFG->dirroot . '/mod/harpiasurvey/db/install.xml';
        if (!$dbman->table_exists('harpiasurvey_responses')) {
            $dbman->install_one_table_from_xmldb_file($installfile, 'harpiasurvey_responses');
        }
        upgrade_mod_savepoint(true, 2025010709, 'harpiasurvey');
    }
    
    if ($oldversion < 2025010708) {
        // Add settings field to harpiasurvey_questions table.
        $table = new xmldb_table('harpiasurvey_questions');
        $field = new xmldb_field('settings', XMLDB_TYPE_TEXT, null, null, null, null, null, 'type');
        
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        
        upgrade_mod_savepoint(true, 2025010708, 'harpiasurvey');
    }
    
    if ($oldversion < 2025010700) {
        // Define tables to be created.
        $installfile = $CFG->dirroot . '/mod/harpiasurvey/db/install.xml';

        // Create harpiasurvey_models table.
        if (!$dbman->table_exists('harpiasurvey_models')) {
            $dbman->install_one_table_from_xmldb_file($installfile, 'harpiasurvey_models');
        }

        // Create harpiasurvey_experiments table.
        if (!$dbman->table_exists('harpiasurvey_experiments')) {
            $dbman->install_one_table_from_xmldb_file($installfile, 'harpiasurvey_experiments');
        }

        // Create harpiasurvey_experiment_models table.
        if (!$dbman->table_exists('harpiasurvey_experiment_models')) {
            $dbman->install_one_table_from_xmldb_file($installfile, 'harpiasurvey_experiment_models');
        }

        // Harpiasurvey savepoint reached.
        upgrade_mod_savepoint(true, 2025010700, 'harpiasurvey');
    }

    if ($oldversion < 2025010702) {
        // Add new fields to harpiasurvey_models table.
        $table = new xmldb_table('harpiasurvey_models');

        // Add 'model' field (model identifier).
        $field = new xmldb_field('model', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null, 'name');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Add 'extrafields' field (JSON).
        $field = new xmldb_field('extrafields', XMLDB_TYPE_TEXT, null, null, null, null, null, 'endpoint');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Add 'enabled' field.
        $field = new xmldb_field('enabled', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1', 'extrafields');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Harpiasurvey savepoint reached.
        upgrade_mod_savepoint(true, 2025010702, 'harpiasurvey');
    }

    if ($oldversion < 2025010703) {
        // Add maxparticipants field to harpiasurvey_experiments table.
        $table = new xmldb_table('harpiasurvey_experiments');

        // Add 'maxparticipants' field.
        $field = new xmldb_field('maxparticipants', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'participants');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Harpiasurvey savepoint reached.
        upgrade_mod_savepoint(true, 2025010703, 'harpiasurvey');
    }

    if ($oldversion < 2025010704) {
        // Create harpiasurvey_pages table.
        $installfile = $CFG->dirroot . '/mod/harpiasurvey/db/install.xml';
        if (!$dbman->table_exists('harpiasurvey_pages')) {
            $dbman->install_one_table_from_xmldb_file($installfile, 'harpiasurvey_pages');
        }

        // Harpiasurvey savepoint reached.
        upgrade_mod_savepoint(true, 2025010704, 'harpiasurvey');
    }

    if ($oldversion < 2025010705) {
        // Create question-related tables.
        $installfile = $CFG->dirroot . '/mod/harpiasurvey/db/install.xml';
        if (!$dbman->table_exists('harpiasurvey_questions')) {
            $dbman->install_one_table_from_xmldb_file($installfile, 'harpiasurvey_questions');
        }
        if (!$dbman->table_exists('harpiasurvey_question_options')) {
            $dbman->install_one_table_from_xmldb_file($installfile, 'harpiasurvey_question_options');
        }
        if (!$dbman->table_exists('harpiasurvey_page_questions')) {
            $dbman->install_one_table_from_xmldb_file($installfile, 'harpiasurvey_page_questions');
        }

        // Harpiasurvey savepoint reached.
        upgrade_mod_savepoint(true, 2025010705, 'harpiasurvey');
    }

    if ($oldversion < 2025010706) {
        // Add enabled field to harpiasurvey_page_questions table.
        $table = new xmldb_table('harpiasurvey_page_questions');

        // Add 'enabled' field.
        $field = new xmldb_field('enabled', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1', 'questionid');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Harpiasurvey savepoint reached.
        upgrade_mod_savepoint(true, 2025010706, 'harpiasurvey');
    }

    if ($oldversion < 202501280011) {
        // Added new page type 'aichat' for AI chat evaluation pages.
        // AI conversation questions are now restricted to aichat pages only.
        // No database schema changes needed - the type field already supports any value.
        
        upgrade_mod_savepoint(true, 202501280011, 'harpiasurvey');
    }

    if ($oldversion < 202501280012) {
        // Add evaluates_conversation_id field to harpiasurvey_page_questions table.
        $table = new xmldb_table('harpiasurvey_page_questions');
        
        // Add 'evaluates_conversation_id' field.
        $field = new xmldb_field('evaluates_conversation_id', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'sortorder');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        
        // Add foreign key constraint.
        $key = new xmldb_key('fk_evaluates_conversation', XMLDB_KEY_FOREIGN, ['evaluates_conversation_id'], 'harpiasurvey_questions', ['id']);
        $dbman->add_key($table, $key);
        
        upgrade_mod_savepoint(true, 202501280012, 'harpiasurvey');
    }

    if ($oldversion < 202501280013) {
        // Add behavior field to harpiasurvey_pages table for turn-based navigation.
        $table = new xmldb_table('harpiasurvey_pages');
        
        // Add 'behavior' field (continuous, turns, multi_model).
        $field = new xmldb_field('behavior', XMLDB_TYPE_CHAR, '50', null, null, null, 'continuous', 'type');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        
        // Add turn_id field to harpiasurvey_conversations table for grouping messages into turns.
        $table = new xmldb_table('harpiasurvey_conversations');
        
        // Add 'turn_id' field (NULL for continuous mode, set for turns mode).
        $field = new xmldb_field('turn_id', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'parentid');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        
        upgrade_mod_savepoint(true, 202501280013, 'harpiasurvey');
    }

    if ($oldversion < 202501280014) {
        // Create harpiasurvey_page_models table for page-model associations.
        $installfile = $CFG->dirroot . '/mod/harpiasurvey/db/install.xml';
        if (!$dbman->table_exists('harpiasurvey_page_models')) {
            $dbman->install_one_table_from_xmldb_file($installfile, 'harpiasurvey_page_models');
        }
        
        // Update harpiasurvey_conversations table to allow NULL questionid.
        $table = new xmldb_table('harpiasurvey_conversations');
        $field = new xmldb_field('questionid');
        if ($dbman->field_exists($table, $field)) {
            // Change field to allow NULL by setting NOTNULL to null (false).
            // We need to define the field with all its attributes, but with NOTNULL = null.
            $field->set_attributes(XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'pageid');
            $dbman->change_field_notnull($table, $field);
        }
        
        // Drop foreign key for questionid if it exists (since it can now be NULL).
        // Use find_key_name() to check if the key exists before dropping it.
        $key = new xmldb_key('fk_question', XMLDB_KEY_FOREIGN, ['questionid'], 'harpiasurvey_questions', ['id']);
        if ($dbman->find_key_name($table, $key)) {
            $dbman->drop_key($table, $key);
        }
        
        // Drop old index and create new one.
        $index = new xmldb_index('idx_user_question', XMLDB_INDEX_NOTUNIQUE, ['userid', 'questionid']);
        if ($dbman->index_exists($table, $index)) {
            $dbman->drop_index($table, $index);
        }
        
        $index = new xmldb_index('idx_user_page', XMLDB_INDEX_NOTUNIQUE, ['userid', 'pageid']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }
        
        upgrade_mod_savepoint(true, 202501280014, 'harpiasurvey');
    }

    if ($oldversion < 202501280015) {
        // Fix: Update harpiasurvey_conversations table to allow NULL questionid (retry if previous upgrade failed).
        $table = new xmldb_table('harpiasurvey_conversations');
        
        // First, drop the old index that depends on questionid (if it exists).
        $oldindex = new xmldb_index('idx_user_question', XMLDB_INDEX_NOTUNIQUE, ['userid', 'questionid']);
        if ($dbman->index_exists($table, $oldindex)) {
            $dbman->drop_index($table, $oldindex);
        }
        
        // Also check for the unique index that might use questionid.
        $uniqueindex = new xmldb_index('unique_user_page_question', XMLDB_INDEX_UNIQUE, ['userid', 'pageid', 'questionid']);
        if ($dbman->index_exists($table, $uniqueindex)) {
            $dbman->drop_index($table, $uniqueindex);
        }
        
        // Now we can change the field to allow NULL.
        $field = new xmldb_field('questionid');
        if ($dbman->field_exists($table, $field)) {
            // Change field to allow NULL by setting NOTNULL to null (false).
            // We need to define the field with all its attributes, but with NOTNULL = null.
            $field->set_attributes(XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'pageid');
            $dbman->change_field_notnull($table, $field);
        }
        
        upgrade_mod_savepoint(true, 202501280015, 'harpiasurvey');
    }

    if ($oldversion < 202501280016) {
        // Create harpiasurvey_turn_evaluations table for turn-based evaluations.
        $installfile = $CFG->dirroot . '/mod/harpiasurvey/db/install.xml';
        if (!$dbman->table_exists('harpiasurvey_turn_evaluations')) {
            $dbman->install_one_table_from_xmldb_file($installfile, 'harpiasurvey_turn_evaluations');
        }
        
        upgrade_mod_savepoint(true, 202501280016, 'harpiasurvey');
    }

    if ($oldversion < 202501280017) {
        // Add min_turn field to harpiasurvey_page_questions for turn-based question display.
        $table = new xmldb_table('harpiasurvey_page_questions');
        $field = new xmldb_field('min_turn', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'evaluates_conversation_id');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Add turn_id field to harpiasurvey_responses for turn-based responses.
        $table = new xmldb_table('harpiasurvey_responses');
        $field = new xmldb_field('turn_id', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'response');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Drop old unique index and create new one with turn_id.
        $table = new xmldb_table('harpiasurvey_responses');
        $oldindex = new xmldb_index('unique_user_page_question', XMLDB_INDEX_UNIQUE, ['userid', 'pageid', 'questionid']);
        if ($dbman->index_exists($table, $oldindex)) {
            $dbman->drop_index($table, $oldindex);
        }
        $newindex = new xmldb_index('unique_user_page_question_turn', XMLDB_INDEX_UNIQUE, ['userid', 'pageid', 'questionid', 'turn_id']);
        if (!$dbman->index_exists($table, $newindex)) {
            $dbman->add_index($table, $newindex);
        }

        upgrade_mod_savepoint(true, 202501280017, 'harpiasurvey');
    }

    if ($oldversion < 202501280018) {
        // Remove evaluates_conversation_id field and foreign key.
        // This field is no longer needed since each aichat page has only one chat,
        // and all questions on that page evaluate that chat.
        $table = new xmldb_table('harpiasurvey_page_questions');

        // Drop foreign key first (if it exists).
        // Use find_key_name() to check if the key exists before dropping it.
        $key = new xmldb_key('fk_evaluates_conversation', XMLDB_KEY_FOREIGN, ['evaluates_conversation_id'], 'harpiasurvey_questions', ['id']);
        if ($dbman->find_key_name($table, $key)) {
            $dbman->drop_key($table, $key);
        }

        // Drop the field.
        $field = new xmldb_field('evaluates_conversation_id');
        if ($dbman->field_exists($table, $field)) {
            $dbman->drop_field($table, $field);
        }

        upgrade_mod_savepoint(true, 202501280018, 'harpiasurvey');
    }

    if ($oldversion < 202501280019) {
        // Create harpiasurvey_turn_branches table for conversation tree structure.
        $table = new xmldb_table('harpiasurvey_turn_branches');
        if (!$dbman->table_exists($table)) {
            $installfile = $CFG->dirroot . '/mod/harpiasurvey/db/install.xml';
            $dbman->install_one_table_from_xmldb_file($installfile, 'harpiasurvey_turn_branches');
        }

        upgrade_mod_savepoint(true, 202501280019, 'harpiasurvey');
    }

    if ($oldversion < 202501280020) {
        // Add navigation_mode field to harpiasurvey_experiments table.
        $table = new xmldb_table('harpiasurvey_experiments');
        
        // Add 'navigation_mode' field (free_navigation, only_forward).
        $field = new xmldb_field('navigation_mode', XMLDB_TYPE_CHAR, '50', null, null, null, 'free_navigation', 'status');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        
        upgrade_mod_savepoint(true, 202501280020, 'harpiasurvey');
    }

    if ($oldversion < 202501280022) {
        // Create harpiasurvey_subpages table for subpages within aichat pages with turns mode.
        $table = new xmldb_table('harpiasurvey_subpages');
        if (!$dbman->table_exists($table)) {
            $installfile = $CFG->dirroot . '/mod/harpiasurvey/db/install.xml';
            $dbman->install_one_table_from_xmldb_file($installfile, 'harpiasurvey_subpages');
        }

        upgrade_mod_savepoint(true, 202501280022, 'harpiasurvey');
    }

    if ($oldversion < 202501280023) {
        // Create harpiasurvey_subpage_questions table for subpage questions.
        $table = new xmldb_table('harpiasurvey_subpage_questions');
        if (!$dbman->table_exists($table)) {
            $installfile = $CFG->dirroot . '/mod/harpiasurvey/db/install.xml';
            $dbman->install_one_table_from_xmldb_file($installfile, 'harpiasurvey_subpage_questions');
        }

        upgrade_mod_savepoint(true, 202501280023, 'harpiasurvey');
    }

    if ($oldversion < 202501280024) {
        // Add turn visibility fields to harpiasurvey_subpage_questions table.
        $table = new xmldb_table('harpiasurvey_subpage_questions');
        
        $field = new xmldb_field('turn_visibility_type', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, 'all_turns', 'enabled');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        
        $field = new xmldb_field('turn_number', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'turn_visibility_type');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_mod_savepoint(true, 202501280024, 'harpiasurvey');
    }

    if ($oldversion < 202501280025) {
        // Add min_turns and max_turns fields to harpiasurvey_pages table for turn limits.
        $table = new xmldb_table('harpiasurvey_pages');
        
        // Add 'min_turns' field.
        $field = new xmldb_field('min_turns', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'behavior');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        
        // Add 'max_turns' field.
        $field = new xmldb_field('max_turns', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'min_turns');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        
        upgrade_mod_savepoint(true, 202501280025, 'harpiasurvey');
    }

    if ($oldversion < 202501280026) {
        // Add show_only_turn and hide_on_turn fields to harpiasurvey_page_questions table.
        $table = new xmldb_table('harpiasurvey_page_questions');

        $field = new xmldb_field('show_only_turn', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'min_turn');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('hide_on_turn', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'show_only_turn');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_mod_savepoint(true, 202501280026, 'harpiasurvey');
    }

    if ($oldversion < 202501280027) {
        // Add show_only_model and hide_on_model fields to harpiasurvey_page_questions table.
        $table = new xmldb_table('harpiasurvey_page_questions');

        $field = new xmldb_field('show_only_model', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'hide_on_turn');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('hide_on_model', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'show_only_model');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_mod_savepoint(true, 202501280027, 'harpiasurvey');
    }

    return true;
}

