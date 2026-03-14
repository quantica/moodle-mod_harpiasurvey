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

    if ($oldversion < 202501280028) {
        // Add systemprompt field to harpiasurvey_models table.
        $table = new xmldb_table('harpiasurvey_models');

        $field = new xmldb_field('systemprompt', XMLDB_TYPE_TEXT, null, null, null, null, null, 'extrafields');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_mod_savepoint(true, 202501280028, 'harpiasurvey');
    }

    if ($oldversion < 202501280029) {
        // Add provider fields to harpiasurvey_models table.
        $table = new xmldb_table('harpiasurvey_models');

        $field = new xmldb_field('provider', XMLDB_TYPE_CHAR, '50', null, null, null, null, 'model');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('azure_resource', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'endpoint');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('azure_deployment', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'azure_resource');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('azure_api_version', XMLDB_TYPE_CHAR, '50', null, null, null, null, 'azure_deployment');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('anthropic_version', XMLDB_TYPE_CHAR, '50', null, null, null, null, 'azure_api_version');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('customheaders', XMLDB_TYPE_TEXT, null, null, null, null, null, 'anthropic_version');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('responsepath', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'customheaders');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_mod_savepoint(true, 202501280029, 'harpiasurvey');
    }

    if ($oldversion < 202501280030) {
        // Drop unused type field from harpiasurvey_experiments table.
        $table = new xmldb_table('harpiasurvey_experiments');
        $field = new xmldb_field('type');
        if ($dbman->field_exists($table, $field)) {
            $dbman->drop_field($table, $field);
        }

        upgrade_mod_savepoint(true, 202501280030, 'harpiasurvey');
    }

    if ($oldversion < 202501280031) {
        // Add createdby field to harpiasurvey_experiments table.
        $table = new xmldb_table('harpiasurvey_experiments');
        $field = new xmldb_field('createdby', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'descriptionformat');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_mod_savepoint(true, 202501280031, 'harpiasurvey');
    }

    if ($oldversion < 202501280032) {
        // Add required field to page and subpage question mappings.
        $table = new xmldb_table('harpiasurvey_page_questions');
        $field = new xmldb_field('required', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, 1, 'enabled');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $table = new xmldb_table('harpiasurvey_subpage_questions');
        $field = new xmldb_field('required', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, 1, 'enabled');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Default existing questions to required to preserve current behavior.
        $DB->execute("UPDATE {harpiasurvey_page_questions} SET required = 1 WHERE required IS NULL");
        $DB->execute("UPDATE {harpiasurvey_subpage_questions} SET required = 1 WHERE required IS NULL");

        upgrade_mod_savepoint(true, 202501280032, 'harpiasurvey');
    }

    if ($oldversion < 202501280033) {
        // Create response history table.
        $table = new xmldb_table('harpiasurvey_response_history');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('responseid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
            $table->add_field('pageid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('questionid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('response', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('turn_id', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, 0);

            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_key('fk_page', XMLDB_KEY_FOREIGN, ['pageid'], 'harpiasurvey_pages', ['id']);
            $table->add_key('fk_question', XMLDB_KEY_FOREIGN, ['questionid'], 'harpiasurvey_questions', ['id']);
            $table->add_key('fk_user', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);
            $table->add_key('fk_response', XMLDB_KEY_FOREIGN, ['responseid'], 'harpiasurvey_responses', ['id']);
            $table->add_index('idx_page_user_question_turn', XMLDB_INDEX_NOTUNIQUE, ['pageid', 'userid', 'questionid', 'turn_id']);

            $dbman->create_table($table);
        }

        upgrade_mod_savepoint(true, 202501280033, 'harpiasurvey');
    }

    if ($oldversion < 202501280034) {
        // Add evaluation title/description fields to pages.
        $table = new xmldb_table('harpiasurvey_pages');

        $field = new xmldb_field('evaluationtitle', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'descriptionformat');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('evaluationdescription', XMLDB_TYPE_TEXT, null, null, null, null, null, 'evaluationtitle');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_mod_savepoint(true, 202501280034, 'harpiasurvey');
    }

    if ($oldversion < 202501280035) {
        // Add Q&A min/max question limit fields to pages.
        $table = new xmldb_table('harpiasurvey_pages');

        $field = new xmldb_field('min_qa_questions', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'max_turns');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('max_qa_questions', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'min_qa_questions');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_mod_savepoint(true, 202501280035, 'harpiasurvey');
    }

    if ($oldversion < 202501280036) {
        $table = new xmldb_table('harpiasurvey_experiment_finalizations');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('experimentid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('certified', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, 1);
            $table->add_field('summaryjson', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, 0);
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, 0);

            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_key('fk_experiment', XMLDB_KEY_FOREIGN, ['experimentid'], 'harpiasurvey_experiments', ['id']);
            $table->add_key('fk_user', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);
            $table->add_index('uniq_experiment_user', XMLDB_INDEX_UNIQUE, ['experimentid', 'userid']);

            $dbman->create_table($table);
        }

        upgrade_mod_savepoint(true, 202501280036, 'harpiasurvey');
    }

    if ($oldversion < 202501280037) {
        $table = new xmldb_table('harpiasurvey_review_datasets');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('pageid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('uploadedby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('filename', XMLDB_TYPE_CHAR, '255', null, null, null, null);
            $table->add_field('contenthash', XMLDB_TYPE_CHAR, '64', null, null, null, null);
            $table->add_field('status', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'ready');
            $table->add_field('errormessage', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, 0);
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, 0);

            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_key('fk_page', XMLDB_KEY_FOREIGN, ['pageid'], 'harpiasurvey_pages', ['id']);
            $table->add_key('fk_user', XMLDB_KEY_FOREIGN, ['uploadedby'], 'user', ['id']);
            $table->add_key('uniq_page', XMLDB_KEY_UNIQUE, ['pageid']);

            $dbman->create_table($table);
        }

        $table = new xmldb_table('harpiasurvey_review_messages');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('datasetid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('source_message_id', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
            $table->add_field('source_parent_id', XMLDB_TYPE_CHAR, '255', null, null, null, null);
            $table->add_field('source_turn_id', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
            $table->add_field('source_model_id', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
            $table->add_field('role', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'other');
            $table->add_field('content', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('sourcets', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
            $table->add_field('sortindex', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, 0);

            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_key('fk_dataset', XMLDB_KEY_FOREIGN, ['datasetid'], 'harpiasurvey_review_datasets', ['id']);
            $table->add_index('uniq_dataset_source_msg', XMLDB_INDEX_UNIQUE, ['datasetid', 'source_message_id']);
            $table->add_index('idx_dataset_turn', XMLDB_INDEX_NOTUNIQUE, ['datasetid', 'source_turn_id']);
            $table->add_index('idx_dataset_parent', XMLDB_INDEX_NOTUNIQUE, ['datasetid', 'source_parent_id']);

            $dbman->create_table($table);
        }

        $table = new xmldb_table('harpiasurvey_review_threads');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('datasetid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('thread_key', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
            $table->add_field('root_source_message_id', XMLDB_TYPE_CHAR, '255', null, null, null, null);
            $table->add_field('thread_order', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, 0);
            $table->add_field('label', XMLDB_TYPE_CHAR, '255', null, null, null, null);

            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_key('fk_dataset', XMLDB_KEY_FOREIGN, ['datasetid'], 'harpiasurvey_review_datasets', ['id']);
            $table->add_index('uniq_dataset_threadkey', XMLDB_INDEX_UNIQUE, ['datasetid', 'thread_key']);

            $dbman->create_table($table);
        }

        $table = new xmldb_table('harpiasurvey_review_message_threads');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('datasetid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('reviewmessageid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('threadid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);

            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_key('fk_dataset', XMLDB_KEY_FOREIGN, ['datasetid'], 'harpiasurvey_review_datasets', ['id']);
            $table->add_key('fk_reviewmessage', XMLDB_KEY_FOREIGN, ['reviewmessageid'], 'harpiasurvey_review_messages', ['id']);
            $table->add_key('fk_thread', XMLDB_KEY_FOREIGN, ['threadid'], 'harpiasurvey_review_threads', ['id']);
            $table->add_index('uniq_msg_thread', XMLDB_INDEX_UNIQUE, ['reviewmessageid', 'threadid']);
            $table->add_index('idx_thread_msg', XMLDB_INDEX_NOTUNIQUE, ['threadid', 'reviewmessageid']);

            $dbman->create_table($table);
        }

        $table = new xmldb_table('harpiasurvey_review_targets');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('pageid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('threadid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, 0);
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, 0);

            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_key('fk_page', XMLDB_KEY_FOREIGN, ['pageid'], 'harpiasurvey_pages', ['id']);
            $table->add_key('fk_user', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);
            $table->add_key('fk_thread', XMLDB_KEY_FOREIGN, ['threadid'], 'harpiasurvey_review_threads', ['id']);
            $table->add_index('uniq_page_user_thread', XMLDB_INDEX_UNIQUE, ['pageid', 'userid', 'threadid']);

            $dbman->create_table($table);
        }

        upgrade_mod_savepoint(true, 202501280037, 'harpiasurvey');
    }

    if ($oldversion < 202603140001) {
        $table = new xmldb_table('harpiasurvey_conversations');
        $field = new xmldb_field('iteration_type', XMLDB_TYPE_CHAR, '30', null, null, null, null, 'turn_id');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $field = new xmldb_field('iteration_id', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'iteration_type');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $index = new xmldb_index('idx_conversation_iteration', XMLDB_INDEX_NOTUNIQUE, ['pageid', 'userid', 'iteration_type', 'iteration_id', 'modelid']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        $table = new xmldb_table('harpiasurvey_turn_branches');
        $field = new xmldb_field('modelid', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'userid');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $key = new xmldb_key('fk_model', XMLDB_KEY_FOREIGN, ['modelid'], 'harpiasurvey_models', ['id']);
        try {
            $dbman->add_key($table, $key);
        } catch (Exception $e) {
            // Ignore if the key already exists.
        }
        $index = new xmldb_index('idx_branch_model', XMLDB_INDEX_NOTUNIQUE, ['pageid', 'userid', 'modelid', 'parent_turn_id', 'child_turn_id']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        $table = new xmldb_table('harpiasurvey_responses');
        $field = new xmldb_field('modelid', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'userid');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $field = new xmldb_field('iteration_type', XMLDB_TYPE_CHAR, '30', null, null, null, null, 'turn_id');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $field = new xmldb_field('iteration_id', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'iteration_type');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $key = new xmldb_key('fk_model', XMLDB_KEY_FOREIGN, ['modelid'], 'harpiasurvey_models', ['id']);
        try {
            $dbman->add_key($table, $key);
        } catch (Exception $e) {
            // Ignore if the key already exists.
        }
        $index = new xmldb_index('idx_response_iteration', XMLDB_INDEX_NOTUNIQUE, ['pageid', 'userid', 'iteration_type', 'iteration_id', 'modelid']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }
        $oldunique = new xmldb_index('unique_user_page_question', XMLDB_INDEX_UNIQUE, ['userid', 'pageid', 'questionid', 'turn_id']);
        if ($dbman->index_exists($table, $oldunique)) {
            $dbman->drop_index($table, $oldunique);
        }
        $newunique = new xmldb_index('unique_user_page_question', XMLDB_INDEX_UNIQUE, ['userid', 'pageid', 'questionid', 'turn_id', 'modelid']);
        if (!$dbman->index_exists($table, $newunique)) {
            $dbman->add_index($table, $newunique);
        }

        $table = new xmldb_table('harpiasurvey_response_history');
        $field = new xmldb_field('modelid', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'userid');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $field = new xmldb_field('iteration_type', XMLDB_TYPE_CHAR, '30', null, null, null, null, 'turn_id');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $field = new xmldb_field('iteration_id', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'iteration_type');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $key = new xmldb_key('fk_model', XMLDB_KEY_FOREIGN, ['modelid'], 'harpiasurvey_models', ['id']);
        try {
            $dbman->add_key($table, $key);
        } catch (Exception $e) {
            // Ignore if the key already exists.
        }
        $index = new xmldb_index('idx_history_iteration', XMLDB_INDEX_NOTUNIQUE, ['pageid', 'userid', 'iteration_type', 'iteration_id', 'modelid']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        $pagemeta = $DB->get_records_sql(
            "SELECT id, type, behavior
               FROM {harpiasurvey_pages}"
        );
        $pagesbyid = [];
        foreach ($pagemeta as $meta) {
            $pagesbyid[(int)$meta->id] = $meta;
        }

        $conversationrows = $DB->get_records_sql(
            "SELECT id, pageid, userid, modelid, parentid, turn_id
               FROM {harpiasurvey_conversations}
           ORDER BY id ASC"
        );
        $conversationmap = [];
        foreach ($conversationrows as $row) {
            $conversationmap[(int)$row->id] = $row;
        }
        $rootcache = [];
        $findconversationroot = function(int $messageid) use (&$conversationmap, &$rootcache): ?int {
            if (isset($rootcache[$messageid])) {
                return $rootcache[$messageid];
            }
            $currentid = $messageid;
            $visited = [];
            while (!empty($currentid) && isset($conversationmap[$currentid]) && !in_array($currentid, $visited, true)) {
                $visited[] = $currentid;
                $parentid = !empty($conversationmap[$currentid]->parentid) ? (int)$conversationmap[$currentid]->parentid : null;
                if (empty($parentid) || !isset($conversationmap[$parentid])) {
                    foreach ($visited as $visitedid) {
                        $rootcache[$visitedid] = $currentid;
                    }
                    return $currentid;
                }
                $currentid = $parentid;
            }
            return null;
        };

        foreach ($conversationrows as $row) {
            $page = $pagesbyid[(int)$row->pageid] ?? null;
            if (!$page || ($page->type ?? '') !== 'aichat') {
                continue;
            }
            $updaterecord = (object)['id' => $row->id];
            $shouldupdate = false;
            $behavior = $page->behavior ?? 'continuous';

            if ($behavior === 'turns' && !empty($row->turn_id)) {
                $updaterecord->iteration_type = 'turn';
                $updaterecord->iteration_id = (int)$row->turn_id;
                $shouldupdate = true;
            } else if ($behavior === 'qa' && !empty($row->turn_id)) {
                $updaterecord->iteration_type = 'qa';
                $updaterecord->iteration_id = (int)$row->turn_id;
                $shouldupdate = true;
            } else if ($behavior === 'continuous') {
                $rootid = $findconversationroot((int)$row->id);
                if (!empty($rootid)) {
                    $updaterecord->iteration_type = 'conversation';
                    $updaterecord->iteration_id = $rootid;
                    $shouldupdate = true;
                }
            }

            if ($shouldupdate) {
                $DB->update_record('harpiasurvey_conversations', $updaterecord);
            }
        }

        $branches = $DB->get_records('harpiasurvey_turn_branches');
        foreach ($branches as $branch) {
            if (!empty($branch->modelid)) {
                continue;
            }
            $resolvedmodelid = $DB->get_field_sql(
                "SELECT modelid
                   FROM {harpiasurvey_conversations}
                  WHERE pageid = ? AND userid = ? AND turn_id = ? AND modelid IS NOT NULL
               ORDER BY id ASC",
                [(int)$branch->pageid, (int)$branch->userid, (int)$branch->child_turn_id],
                IGNORE_MULTIPLE
            );
            if (empty($resolvedmodelid) && !empty($branch->parent_turn_id)) {
                $resolvedmodelid = $DB->get_field_sql(
                    "SELECT modelid
                       FROM {harpiasurvey_conversations}
                      WHERE pageid = ? AND userid = ? AND turn_id = ? AND modelid IS NOT NULL
                   ORDER BY id ASC",
                    [(int)$branch->pageid, (int)$branch->userid, (int)$branch->parent_turn_id],
                    IGNORE_MULTIPLE
                );
            }
            if (!empty($resolvedmodelid)) {
                $DB->set_field('harpiasurvey_turn_branches', 'modelid', (int)$resolvedmodelid, ['id' => $branch->id]);
            }
        }

        $resolveevaluationmeta = function(stdClass $page, int $userid, ?int $turnid, ?int $questionid = null) use ($DB, &$pagesbyid): array {
            $meta = [
                'modelid' => null,
                'iteration_type' => null,
                'iteration_id' => null,
            ];
            if (($page->type ?? '') !== 'aichat' || empty($turnid)) {
                return $meta;
            }
            $behavior = $page->behavior ?? 'continuous';
            if ($behavior === 'turns') {
                $meta['iteration_type'] = 'turn';
                $meta['iteration_id'] = (int)$turnid;
                if (!empty($questionid)) {
                    $showonlymodel = $DB->get_field('harpiasurvey_page_questions', 'show_only_model', [
                        'pageid' => (int)$page->id,
                        'questionid' => (int)$questionid
                    ]);
                    if (!empty($showonlymodel)) {
                        $meta['modelid'] = (int)$showonlymodel;
                    }
                }
                if (empty($meta['modelid'])) {
                    $meta['modelid'] = $DB->get_field_sql(
                        "SELECT modelid
                           FROM {harpiasurvey_conversations}
                          WHERE pageid = ? AND userid = ? AND turn_id = ? AND modelid IS NOT NULL
                       ORDER BY id ASC",
                        [(int)$page->id, $userid, (int)$turnid],
                        IGNORE_MULTIPLE
                    ) ?: $DB->get_field_sql(
                        "SELECT modelid
                           FROM {harpiasurvey_turn_branches}
                          WHERE pageid = ? AND userid = ? AND child_turn_id = ? AND modelid IS NOT NULL
                       ORDER BY id ASC",
                        [(int)$page->id, $userid, (int)$turnid],
                        IGNORE_MULTIPLE
                    );
                }
                return $meta;
            }
            if ($behavior === 'qa') {
                $meta['iteration_type'] = 'qa';
                $meta['iteration_id'] = (int)$turnid;
                $meta['modelid'] = $DB->get_field_sql(
                    "SELECT modelid
                       FROM {harpiasurvey_conversations}
                      WHERE pageid = ? AND userid = ? AND turn_id = ? AND modelid IS NOT NULL
                   ORDER BY id ASC",
                    [(int)$page->id, $userid, (int)$turnid],
                    IGNORE_MULTIPLE
                );
                return $meta;
            }
            if ($behavior === 'continuous') {
                $meta['iteration_type'] = 'conversation';
                $meta['iteration_id'] = (int)$turnid;
                $meta['modelid'] = $DB->get_field_sql(
                    "SELECT modelid
                       FROM {harpiasurvey_conversations}
                      WHERE id = ? AND pageid = ? AND userid = ? AND modelid IS NOT NULL
                   ORDER BY id ASC",
                    [(int)$turnid, (int)$page->id, $userid],
                    IGNORE_MULTIPLE
                );
                return $meta;
            }
            if ($behavior === 'review_conversation') {
                $meta['iteration_type'] = 'review_conversation';
                $meta['iteration_id'] = (int)$turnid;
                return $meta;
            }
            return $meta;
        };

        $responses = $DB->get_records_sql(
            "SELECT id, pageid, questionid, userid, turn_id
               FROM {harpiasurvey_responses}
           ORDER BY id ASC"
        );
        foreach ($responses as $response) {
            $page = $pagesbyid[(int)$response->pageid] ?? null;
            if (!$page) {
                continue;
            }
            $meta = $resolveevaluationmeta($page, (int)$response->userid, isset($response->turn_id) ? (int)$response->turn_id : null,
                (int)$response->questionid);
            $updaterecord = (object)['id' => $response->id];
            $updaterecord->modelid = $meta['modelid'];
            $updaterecord->iteration_type = $meta['iteration_type'];
            $updaterecord->iteration_id = $meta['iteration_id'];
            $DB->update_record('harpiasurvey_responses', $updaterecord);
        }

        if ($dbman->table_exists(new xmldb_table('harpiasurvey_response_history'))) {
            $responsemetabyid = [];
            $responses = $DB->get_records('harpiasurvey_responses', null, '', 'id, modelid, iteration_type, iteration_id');
            foreach ($responses as $response) {
                $responsemetabyid[(int)$response->id] = $response;
            }

            $historyrecords = $DB->get_records_sql(
                "SELECT id, responseid, pageid, questionid, userid, turn_id
                   FROM {harpiasurvey_response_history}
               ORDER BY id ASC"
            );
            foreach ($historyrecords as $history) {
                $updaterecord = (object)['id' => $history->id];
                if (!empty($history->responseid) && isset($responsemetabyid[(int)$history->responseid])) {
                    $responsemeta = $responsemetabyid[(int)$history->responseid];
                    $updaterecord->modelid = $responsemeta->modelid;
                    $updaterecord->iteration_type = $responsemeta->iteration_type;
                    $updaterecord->iteration_id = $responsemeta->iteration_id;
                } else {
                    $page = $pagesbyid[(int)$history->pageid] ?? null;
                    if (!$page) {
                        continue;
                    }
                    $meta = $resolveevaluationmeta($page, (int)$history->userid, isset($history->turn_id) ? (int)$history->turn_id : null,
                        (int)$history->questionid);
                    $updaterecord->modelid = $meta['modelid'];
                    $updaterecord->iteration_type = $meta['iteration_type'];
                    $updaterecord->iteration_id = $meta['iteration_id'];
                }
                $DB->update_record('harpiasurvey_response_history', $updaterecord);
            }
        }

        upgrade_mod_savepoint(true, 202603140001, 'harpiasurvey');
    }

    return true;
}
