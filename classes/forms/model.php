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

        // Provider field.
        $provideroptions = [
            'openai' => get_string('provideropenai', 'mod_harpiasurvey'),
            'openrouter' => get_string('provideropenrouter', 'mod_harpiasurvey'),
            'azure_openai' => get_string('providerazureopenai', 'mod_harpiasurvey'),
            'anthropic' => get_string('provideranthropic', 'mod_harpiasurvey'),
            'gemini' => get_string('providergemini', 'mod_harpiasurvey'),
            'ollama' => get_string('providerollama', 'mod_harpiasurvey'),
            'custom' => get_string('providercustom', 'mod_harpiasurvey')
        ];
        $mform->addElement('select', 'provider', get_string('provider', 'mod_harpiasurvey'), $provideroptions);
        $mform->addRule('provider', get_string('required'), 'required', null, 'client');
        $mform->setType('provider', PARAM_TEXT);
        $mform->setDefault('provider', 'openai');
        if (isset($this->_customdata->provider)) {
            $mform->setDefault('provider', $this->_customdata->provider);
        }
        $mform->addHelpButton('provider', 'provider', 'mod_harpiasurvey');

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
        $mform->addRule('model', get_string('maximumchars', '', 255), 'maxlength', 255, 'client');
        $mform->setType('model', PARAM_TEXT);
        if (isset($this->_customdata->model)) {
            $mform->setDefault('model', $this->_customdata->model);
        }
        $mform->addHelpButton('model', 'modelidentifier', 'mod_harpiasurvey');
        $mform->addElement('static', 'model_help', '', '<div class="form-text text-muted">' . 
            get_string('modelidentifier_help', 'mod_harpiasurvey') . '</div>');
        $mform->hideIf('model', 'provider', 'eq', 'azure_openai');
        $mform->hideIf('model_help', 'provider', 'eq', 'azure_openai');

        // Base URL field.
        $mform->addElement('text', 'endpoint', get_string('baseurl', 'mod_harpiasurvey'), ['size' => '64']);
        $mform->addRule('endpoint', get_string('maximumchars', '', 500), 'maxlength', 500, 'client');
        $mform->setType('endpoint', PARAM_URL);
        if (isset($this->_customdata->endpoint)) {
            $mform->setDefault('endpoint', $this->_customdata->endpoint);
        }
        $mform->addHelpButton('endpoint', 'baseurl', 'mod_harpiasurvey');
        $mform->hideIf('endpoint', 'provider', 'eq', 'azure_openai');

        // API Key field (password).
        $mform->addElement('passwordunmask', 'apikey', get_string('apikey', 'mod_harpiasurvey'), ['size' => '64']);
        $mform->addRule('apikey', get_string('maximumchars', '', 255), 'maxlength', 255, 'client');
        $mform->setType('apikey', PARAM_TEXT);
        if (isset($this->_customdata->apikey)) {
            $mform->setDefault('apikey', $this->_customdata->apikey);
        }
        $mform->addHelpButton('apikey', 'apikey', 'mod_harpiasurvey');
        $mform->hideIf('apikey', 'provider', 'eq', 'ollama');

        // Azure OpenAI fields.
        $mform->addElement('text', 'azure_resource', get_string('azureresource', 'mod_harpiasurvey'), ['size' => '64']);
        $mform->addRule('azure_resource', get_string('maximumchars', '', 255), 'maxlength', 255, 'client');
        $mform->setType('azure_resource', PARAM_TEXT);
        if (isset($this->_customdata->azure_resource)) {
            $mform->setDefault('azure_resource', $this->_customdata->azure_resource);
        }
        $mform->addHelpButton('azure_resource', 'azureresource', 'mod_harpiasurvey');
        $mform->hideIf('azure_resource', 'provider', 'neq', 'azure_openai');

        $mform->addElement('text', 'azure_deployment', get_string('azuredeployment', 'mod_harpiasurvey'), ['size' => '64']);
        $mform->addRule('azure_deployment', get_string('maximumchars', '', 255), 'maxlength', 255, 'client');
        $mform->setType('azure_deployment', PARAM_TEXT);
        if (isset($this->_customdata->azure_deployment)) {
            $mform->setDefault('azure_deployment', $this->_customdata->azure_deployment);
        }
        $mform->addHelpButton('azure_deployment', 'azuredeployment', 'mod_harpiasurvey');
        $mform->hideIf('azure_deployment', 'provider', 'neq', 'azure_openai');

        $mform->addElement('text', 'azure_api_version', get_string('azureapiversion', 'mod_harpiasurvey'), ['size' => '32']);
        $mform->addRule('azure_api_version', get_string('maximumchars', '', 50), 'maxlength', 50, 'client');
        $mform->setType('azure_api_version', PARAM_TEXT);
        $mform->setDefault('azure_api_version', '2024-02-15-preview');
        if (isset($this->_customdata->azure_api_version)) {
            $mform->setDefault('azure_api_version', $this->_customdata->azure_api_version);
        }
        $mform->addHelpButton('azure_api_version', 'azureapiversion', 'mod_harpiasurvey');
        $mform->hideIf('azure_api_version', 'provider', 'neq', 'azure_openai');

        // Anthropic API version field.
        $mform->addElement('text', 'anthropic_version', get_string('anthropicversion', 'mod_harpiasurvey'), ['size' => '32']);
        $mform->addRule('anthropic_version', get_string('maximumchars', '', 50), 'maxlength', 50, 'client');
        $mform->setType('anthropic_version', PARAM_TEXT);
        $mform->setDefault('anthropic_version', '2023-06-01');
        if (isset($this->_customdata->anthropic_version)) {
            $mform->setDefault('anthropic_version', $this->_customdata->anthropic_version);
        }
        $mform->addHelpButton('anthropic_version', 'anthropicversion', 'mod_harpiasurvey');
        $mform->hideIf('anthropic_version', 'provider', 'neq', 'anthropic');

        // System prompt field.
        $mform->addElement('textarea', 'systemprompt', get_string('systemprompt', 'mod_harpiasurvey'), [
            'rows' => 6,
            'cols' => 80,
            'wrap' => 'virtual',
            'placeholder' => get_string('systemprompt_placeholder', 'mod_harpiasurvey')
        ]);
        $mform->setType('systemprompt', PARAM_RAW);
        if (isset($this->_customdata->systemprompt)) {
            $mform->setDefault('systemprompt', $this->_customdata->systemprompt);
        }
        $mform->addHelpButton('systemprompt', 'systemprompt', 'mod_harpiasurvey');

        // Custom headers for custom provider.
        $mform->addElement('textarea', 'customheaders', get_string('customheaders', 'mod_harpiasurvey'), [
            'rows' => 5,
            'cols' => 80,
            'wrap' => 'off',
            'spellcheck' => 'false',
            'class' => 'json-textarea',
            'style' => 'font-family: monospace; white-space: pre;',
            'placeholder' => get_string('customheaders_placeholder', 'mod_harpiasurvey')
        ]);
        $mform->setType('customheaders', PARAM_RAW);
        if (isset($this->_customdata->customheaders)) {
            $mform->setDefault('customheaders', $this->_customdata->customheaders);
        }
        $mform->addHelpButton('customheaders', 'customheaders', 'mod_harpiasurvey');
        $mform->hideIf('customheaders', 'provider', 'neq', 'custom');

        $mform->addElement('text', 'responsepath', get_string('responsepath', 'mod_harpiasurvey'), ['size' => '64']);
        $mform->addRule('responsepath', get_string('maximumchars', '', 255), 'maxlength', 255, 'client');
        $mform->setType('responsepath', PARAM_TEXT);
        if (isset($this->_customdata->responsepath)) {
            $mform->setDefault('responsepath', $this->_customdata->responsepath);
        }
        $mform->addHelpButton('responsepath', 'responsepath', 'mod_harpiasurvey');
        $mform->hideIf('responsepath', 'provider', 'neq', 'custom');

        // Extra fields (JSON textarea) - preserves formatting.
        $mform->addElement('textarea', 'extrafields', get_string('extrafields', 'mod_harpiasurvey'), [
            'rows' => 10,
            'cols' => 80,
            'wrap' => 'off',
            'spellcheck' => 'false',
            'class' => 'json-textarea',
            'style' => 'font-family: monospace; white-space: pre;',
            'placeholder' => get_string('extrafields_placeholder', 'mod_harpiasurvey')
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
        $provider = $data['provider'] ?? 'custom';
        $provider = is_string($provider) ? $provider : 'custom';

        // Validate base URL format.
        if (!empty($data['endpoint']) && !filter_var($data['endpoint'], FILTER_VALIDATE_URL)) {
            $errors['endpoint'] = get_string('invalidurl', 'mod_harpiasurvey');
        }

        // Provider-specific required fields.
        if ($provider === 'azure_openai') {
            if (empty($data['azure_resource'])) {
                $errors['azure_resource'] = get_string('required');
            }
            if (empty($data['azure_deployment'])) {
                $errors['azure_deployment'] = get_string('required');
            }
            if (empty($data['azure_api_version'])) {
                $errors['azure_api_version'] = get_string('required');
            }
            if (empty($data['apikey'])) {
                $errors['apikey'] = get_string('required');
            }
        } else {
            if (empty($data['model'])) {
                $errors['model'] = get_string('required');
            }
            if ($provider !== 'ollama' && empty($data['apikey'])) {
                $errors['apikey'] = get_string('required');
            }
            if (empty($data['endpoint']) && !in_array($provider, ['openai', 'openrouter', 'anthropic', 'gemini', 'ollama'], true)) {
                $errors['endpoint'] = get_string('required');
            }
        }

        // Validate JSON format for extra fields if provided.
        if (!empty($data['extrafields'])) {
            $decoded = json_decode($data['extrafields'], true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $errors['extrafields'] = get_string('invalidjson', 'mod_harpiasurvey') . ': ' . json_last_error_msg();
            }
        }

        if (!empty($data['customheaders'])) {
            $decoded = json_decode($data['customheaders'], true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $errors['customheaders'] = get_string('invalidjson', 'mod_harpiasurvey') . ': ' . json_last_error_msg();
            }
        }

        return $errors;
    }
}
