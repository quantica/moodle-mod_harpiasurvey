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

namespace mod_harpiasurvey\forms;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot.'/lib/formslib.php');

/**
 * Model form class.
 *
 * @package     mod_harpiasurvey
 * @copyright   2025 Your Name
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class model extends \moodleform {
    /**
     * The context for this form.
     *
     * @var \context
     */
    protected $context;

    /**
     * Class constructor
     *
     * @param moodle_url|string $url
     * @param $formdata
     * @param $context
     */
    public function __construct($url, $formdata, $context) {
        $this->context = $context;
        // Ensure URL is a moodle_url object to preserve parameters.
        if (is_string($url)) {
            $url = new \moodle_url($url);
        }
        parent::__construct($url, $formdata);
    }

    /**
     * The form definition.
     *
     * @throws \coding_exception
     */
    public function definition() {
        $mform = $this->_form;

        // Hidden fields.
        $mform->addElement('hidden', 'id');
        $mform->setType('id', PARAM_INT);
        if (isset($this->_customdata->id)) {
            $mform->setDefault('id', $this->_customdata->id);
        }

        $mform->addElement('hidden', 'cmid');
        $mform->setType('cmid', PARAM_INT);
        $mform->setDefault('cmid', $this->_customdata->cmid);
        $mform->setConstant('cmid', $this->_customdata->cmid);

        $mform->addElement('hidden', 'form_id');
        $mform->setType('form_id', PARAM_INT);
        $mform->setDefault('form_id', $this->_customdata->cmid);
        $mform->setConstant('form_id', $this->_customdata->cmid);

        $mform->addElement('hidden', 'harpiasurveyid');
        $mform->setType('harpiasurveyid', PARAM_INT);
        $mform->setDefault('harpiasurveyid', $this->_customdata->harpiasurveyid);
        $mform->setConstant('harpiasurveyid', $this->_customdata->harpiasurveyid);

        // Add experiment ID as hidden field if provided.
        if (isset($this->_customdata->experimentid)) {
            $mform->addElement('hidden', 'experiment');
            $mform->setType('experiment', PARAM_INT);
            $mform->setDefault('experiment', $this->_customdata->experimentid);
            $mform->setConstant('experiment', $this->_customdata->experimentid);
        }

        // General section header.
        $mform->addElement('header', 'general', get_string('general', 'form'));

        // Name field.
        $mform->addElement('text', 'name', get_string('modelname', 'mod_harpiasurvey'), ['size' => '64']);
        $mform->addRule('name', get_string('required'), 'required', null, 'client');
        $mform->addRule('name', get_string('maximumchars', '', 255), 'maxlength', 255, 'client');
        $mform->setType('name', PARAM_TEXT);
        if (isset($this->_customdata->name)) {
            $mform->setDefault('name', $this->_customdata->name);
        }

        // Model identifier field (the actual model ID used in API requests, e.g., "gpt-4o", "gpt-3.5-turbo").
        $mform->addElement('text', 'model', get_string('modelidentifier', 'mod_harpiasurvey'), ['size' => '64']);
        $mform->addRule('model', get_string('required'), 'required', null, 'client');
        $mform->addRule('model', get_string('maximumchars', '', 255), 'maxlength', 255, 'client');
        $mform->setType('model', PARAM_TEXT);
        if (isset($this->_customdata->model)) {
            $mform->setDefault('model', $this->_customdata->model);
        }
        $mform->addHelpButton('model', 'modelidentifier', 'mod_harpiasurvey');
        $mform->addElement('static', 'model_help', '', '<div class="form-text text-muted">' . 
            get_string('modelidentifier_help', 'mod_harpiasurvey') . '</div>');

        // Base URL field.
        $mform->addElement('text', 'endpoint', get_string('baseurl', 'mod_harpiasurvey'), ['size' => '64']);
        $mform->addRule('endpoint', get_string('required'), 'required', null, 'client');
        $mform->addRule('endpoint', get_string('maximumchars', '', 500), 'maxlength', 500, 'client');
        $mform->setType('endpoint', PARAM_URL);
        if (isset($this->_customdata->endpoint)) {
            $mform->setDefault('endpoint', $this->_customdata->endpoint);
        }
        $mform->addHelpButton('endpoint', 'baseurl', 'mod_harpiasurvey');

        // API Key field (password).
        $mform->addElement('passwordunmask', 'apikey', get_string('apikey', 'mod_harpiasurvey'), ['size' => '64']);
        $mform->addRule('apikey', get_string('required'), 'required', null, 'client');
        $mform->addRule('apikey', get_string('maximumchars', '', 255), 'maxlength', 255, 'client');
        $mform->setType('apikey', PARAM_TEXT);
        if (isset($this->_customdata->apikey)) {
            $mform->setDefault('apikey', $this->_customdata->apikey);
        }
        $mform->addHelpButton('apikey', 'apikey', 'mod_harpiasurvey');

        // Extra fields (JSON textarea) - preserves formatting.
        $mform->addElement('textarea', 'extrafields', get_string('extrafields', 'mod_harpiasurvey'), [
            'rows' => 10,
            'cols' => 80,
            'wrap' => 'off',
            'spellcheck' => 'false',
            'class' => 'json-textarea',
            'style' => 'font-family: monospace; white-space: pre;'
        ]);
        $mform->setType('extrafields', PARAM_RAW);
        if (isset($this->_customdata->extrafields)) {
            $mform->setDefault('extrafields', $this->_customdata->extrafields);
        }
        $mform->addHelpButton('extrafields', 'extrafields', 'mod_harpiasurvey');

        // Enabled field (checkbox/toggle).
        $mform->addElement('advcheckbox', 'enabled', get_string('enabled', 'mod_harpiasurvey'));
        $mform->setType('enabled', PARAM_BOOL);
        $mform->setDefault('enabled', 1);
        if (isset($this->_customdata->enabled)) {
            $mform->setDefault('enabled', $this->_customdata->enabled ? 1 : 0);
        }

        // Add action buttons.
        $this->add_action_buttons(true, get_string('addmodel', 'mod_harpiasurvey'));
    }

    /**
     * Form validation.
     *
     * @param array $data
     * @param array $files
     * @return array
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);

        // Validate base URL format.
        if (!empty($data['endpoint']) && !filter_var($data['endpoint'], FILTER_VALIDATE_URL)) {
            $errors['endpoint'] = get_string('invalidurl', 'mod_harpiasurvey');
        }

        // Validate JSON format for extra fields if provided.
        if (!empty($data['extrafields'])) {
            $decoded = json_decode($data['extrafields'], true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $errors['extrafields'] = get_string('invalidjson', 'mod_harpiasurvey') . ': ' . json_last_error_msg();
            }
        }

        return $errors;
    }
}

