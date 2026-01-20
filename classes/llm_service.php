<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace mod_harpiasurvey;

defined('MOODLE_INTERNAL') || die();

/**
 * Service class for interacting with LLM APIs.
 *
 * @package     mod_harpiasurvey
 * @copyright   2025 Your Name
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class llm_service {

    /**
     * @var object Model record from database
     */
    protected $model;

    /**
     * Constructor.
     *
     * @param object $model Model record from harpiasurvey_models table
     */
    public function __construct($model) {
        $this->model = $model;
    }

    /**
     * Send a message to the LLM API.
     *
     * @param array $messages Array of message objects with 'role' and 'content'
     * @return array Response array with 'success', 'content' (if success), or 'error' (if failure)
     */
    public function send_message(array $messages): array {
        global $CFG;

        require_once($CFG->libdir . '/filelib.php');

        $provider = $this->model->provider ?? 'custom';
        $provider = is_string($provider) ? strtolower($provider) : 'custom';

        $systemprompt = trim($this->model->systemprompt ?? '');
        $extrafields = $this->decode_json_field($this->model->extrafields ?? '');
        $customheaders = $this->decode_json_field($this->model->customheaders ?? '');

        $payload = [];
        $headers = [
            'Content-Type: application/json',
            'Accept: application/json'
        ];
        $endpoint = '';

        switch ($provider) {
            case 'azure_openai':
                $endpoint = $this->build_azure_openai_endpoint();
                if (empty($endpoint)) {
                    return [
                        'success' => false,
                        'error' => 'Azure OpenAI endpoint not configured'
                    ];
                }
                $payload = [
                    'messages' => $this->with_system_message($messages, $systemprompt)
                ];
                $payload = $this->merge_payload($payload, $extrafields);
                if (!empty($this->model->apikey)) {
                    $headers[] = 'api-key: ' . trim($this->model->apikey);
                }
                break;
            case 'anthropic':
                $endpoint = $this->model->endpoint ?: 'https://api.anthropic.com/v1/messages';
                if (strpos($endpoint, '/messages') === false) {
                    $endpoint = rtrim($endpoint, '/') . '/messages';
                }
                $payload = [
                    'model' => $this->model->model,
                    'messages' => $this->strip_system_messages($messages),
                    'max_tokens' => 1024
                ];
                if ($systemprompt !== '') {
                    $payload['system'] = $systemprompt;
                }
                $payload = $this->merge_payload($payload, $extrafields);
                if (!empty($this->model->apikey)) {
                    $headers[] = 'x-api-key: ' . trim($this->model->apikey);
                }
                $anthropicversion = trim($this->model->anthropic_version ?? '');
                if ($anthropicversion === '') {
                    $anthropicversion = '2023-06-01';
                }
                $headers[] = 'anthropic-version: ' . $anthropicversion;
                break;
            case 'gemini':
                $endpoint = $this->build_gemini_endpoint();
                if (empty($endpoint)) {
                    return [
                        'success' => false,
                        'error' => 'Gemini endpoint not configured'
                    ];
                }
                $payload = [
                    'contents' => $this->convert_gemini_messages($messages)
                ];
                if ($systemprompt !== '') {
                    $payload['systemInstruction'] = [
                        'parts' => [
                            ['text' => $systemprompt]
                        ]
                    ];
                }
                $payload = $this->merge_payload($payload, $extrafields);
                break;
            case 'ollama':
                $endpoint = $this->build_ollama_endpoint();
                if (empty($endpoint)) {
                    return [
                        'success' => false,
                        'error' => 'Ollama endpoint not configured'
                    ];
                }
                $payload = [
                    'model' => $this->model->model,
                    'messages' => $this->with_system_message($messages, $systemprompt)
                ];
                $payload = $this->merge_payload($payload, $extrafields);
                break;
            case 'openrouter':
                $endpoint = $this->build_openai_endpoint('https://openrouter.ai/api/v1');
                $payload = [
                    'model' => $this->model->model,
                    'messages' => $this->with_system_message($messages, $systemprompt)
                ];
                $payload = $this->merge_payload($payload, $extrafields);
                if (!empty($this->model->apikey)) {
                    $headers[] = 'Authorization: Bearer ' . trim($this->model->apikey);
                }
                break;
            case 'openai':
                $endpoint = $this->build_openai_endpoint('https://api.openai.com/v1');
                $payload = [
                    'model' => $this->model->model,
                    'messages' => $this->with_system_message($messages, $systemprompt)
                ];
                $payload = $this->merge_payload($payload, $extrafields);
                if (!empty($this->model->apikey)) {
                    $headers[] = 'Authorization: Bearer ' . trim($this->model->apikey);
                }
                break;
            case 'custom':
            default:
                $endpoint = $this->model->endpoint;
                if (empty($endpoint)) {
                    return [
                        'success' => false,
                        'error' => 'Custom endpoint not configured'
                    ];
                }
                $payload = [
                    'model' => $this->model->model,
                    'messages' => $this->with_system_message($messages, $systemprompt)
                ];
                $payload = $this->merge_payload($payload, $extrafields);
                if (!empty($this->model->apikey)) {
                    $headers[] = 'Authorization: Bearer ' . trim($this->model->apikey);
                }
                break;
        }

        // Merge custom headers if provided.
        if (is_array($customheaders)) {
            $headers = array_merge($headers, $this->format_custom_headers($customheaders));
        }

        // Make HTTP request.
        $ch = curl_init($endpoint);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);

        $response = curl_exec($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlerror = curl_error($ch);
        $curlerrno = curl_errno($ch);

        curl_close($ch);

        if ($curlerrno) {
            return [
                'success' => false,
                'error' => 'CURL error: ' . $curlerror
            ];
        }

        if ($httpcode >= 200 && $httpcode < 300) {
            $responsedata = json_decode($response, true);
            $content = $this->extract_content($responsedata, $provider);

            if ($content === '') {
                return [
                    'success' => false,
                    'error' => 'No content in API response'
                ];
            }

            return [
                'success' => true,
                'content' => $content
            ];
        }

        $errordata = json_decode($response, true);
        $errormessage = $errordata['error']['message'] ?? 'HTTP ' . $httpcode;

        return [
            'success' => false,
            'error' => $errormessage
        ];
    }

    /**
     * Decode JSON field into array.
     *
     * @param string $value
     * @return array|null
     */
    protected function decode_json_field(string $value): ?array {
        if ($value === '') {
            return null;
        }
        $decoded = json_decode($value, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            return null;
        }
        return $decoded;
    }

    /**
     * Merge payload with extra fields.
     *
     * @param array $payload
     * @param array|null $extrafields
     * @return array
     */
    protected function merge_payload(array $payload, ?array $extrafields): array {
        if (is_array($extrafields)) {
            return array_merge($payload, $extrafields);
        }
        return $payload;
    }

    /**
     * Prepend system message for chat-style providers.
     *
     * @param array $messages
     * @param string $systemprompt
     * @return array
     */
    protected function with_system_message(array $messages, string $systemprompt): array {
        if ($systemprompt !== '' && (empty($messages) || ($messages[0]['role'] ?? '') !== 'system')) {
            array_unshift($messages, [
                'role' => 'system',
                'content' => $systemprompt
            ]);
        }
        return $messages;
    }

    /**
     * Remove system messages for providers that use separate system fields.
     *
     * @param array $messages
     * @return array
     */
    protected function strip_system_messages(array $messages): array {
        return array_values(array_filter($messages, function($message) {
            return ($message['role'] ?? '') !== 'system';
        }));
    }

    /**
     * Build OpenAI-style endpoint.
     *
     * @param string $defaultbase
     * @return string
     */
    protected function build_openai_endpoint(string $defaultbase): string {
        $base = trim($this->model->endpoint ?? '');
        if ($base === '') {
            $base = $defaultbase;
        }
        if (strpos($base, '/chat/completions') !== false || strpos($base, '/responses') !== false) {
            return $base;
        }
        return rtrim($base, '/') . '/chat/completions';
    }

    /**
     * Build Azure OpenAI endpoint.
     *
     * @return string
     */
    protected function build_azure_openai_endpoint(): string {
        $resource = trim($this->model->azure_resource ?? '');
        $deployment = trim($this->model->azure_deployment ?? '');
        $version = trim($this->model->azure_api_version ?? '');

        if ($resource === '' || $deployment === '' || $version === '') {
            return '';
        }

        return 'https://' . $resource . '.openai.azure.com/openai/deployments/' .
            rawurlencode($deployment) . '/chat/completions?api-version=' . rawurlencode($version);
    }

    /**
     * Build Gemini endpoint.
     *
     * @return string
     */
    protected function build_gemini_endpoint(): string {
        $base = trim($this->model->endpoint ?? '');
        if ($base !== '' && strpos($base, ':generateContent') !== false) {
            $url = $base;
            $apikey = trim($this->model->apikey ?? '');
            if ($apikey !== '' && strpos($url, 'key=') === false) {
                $url .= (strpos($url, '?') === false ? '?' : '&') . 'key=' . rawurlencode($apikey);
            }
            return $url;
        }
        if ($base === '') {
            $base = 'https://generativelanguage.googleapis.com/v1beta';
        }
        $model = trim($this->model->model ?? '');
        if ($model === '') {
            return '';
        }
        if (strpos($model, 'models/') !== 0) {
            $model = 'models/' . $model;
        }
        $url = rtrim($base, '/') . '/' . $model . ':generateContent';
        $apikey = trim($this->model->apikey ?? '');
        if ($apikey !== '') {
            $url .= '?key=' . rawurlencode($apikey);
        }
        return $url;
    }

    /**
     * Build Ollama endpoint.
     *
     * @return string
     */
    protected function build_ollama_endpoint(): string {
        $base = trim($this->model->endpoint ?? '');
        if ($base === '') {
            $base = 'http://localhost:11434';
        }
        if (strpos($base, '/api/chat') !== false || strpos($base, '/api/generate') !== false) {
            return $base;
        }
        return rtrim($base, '/') . '/api/chat';
    }

    /**
     * Convert messages to Gemini contents array.
     *
     * @param array $messages
     * @return array
     */
    protected function convert_gemini_messages(array $messages): array {
        $contents = [];
        foreach ($messages as $message) {
            $role = $message['role'] ?? 'user';
            if ($role === 'assistant') {
                $role = 'model';
            } else if ($role !== 'user') {
                continue;
            }
            $contents[] = [
                'role' => $role,
                'parts' => [
                    ['text' => $message['content'] ?? '']
                ]
            ];
        }
        return $contents;
    }

    /**
     * Extract content from response payload.
     *
     * @param array|null $responsedata
     * @param string $provider
     * @return string
     */
    protected function extract_content(?array $responsedata, string $provider): string {
        if (!is_array($responsedata)) {
            return '';
        }

        if ($provider === 'gemini') {
            return $responsedata['candidates'][0]['content']['parts'][0]['text'] ?? '';
        }

        if ($provider === 'anthropic') {
            if (!empty($responsedata['content']) && is_array($responsedata['content'])) {
                return $responsedata['content'][0]['text'] ?? '';
            }
            return $responsedata['content'] ?? '';
        }

        if ($provider === 'ollama') {
            return $responsedata['message']['content'] ?? ($responsedata['response'] ?? '');
        }

        if ($provider === 'custom') {
            $path = trim($this->model->responsepath ?? '');
            if ($path !== '') {
                $value = $this->get_value_by_path($responsedata, $path);
                if (is_string($value)) {
                    return $value;
                }
            }
        }

        return $responsedata['choices'][0]['message']['content']
            ?? ($responsedata['content'] ?? ($responsedata['message'] ?? ''));
    }

    /**
     * Convert custom headers array to header strings.
     *
     * @param array $customheaders
     * @return array
     */
    protected function format_custom_headers(array $customheaders): array {
        $headers = [];
        foreach ($customheaders as $key => $value) {
            if (is_int($key) && is_string($value)) {
                $headers[] = $value;
            } else if (is_string($key)) {
                $headers[] = $key . ': ' . $value;
            }
        }
        return $headers;
    }

    /**
     * Resolve dot path in response data.
     *
     * @param array $data
     * @param string $path
     * @return mixed|null
     */
    protected function get_value_by_path(array $data, string $path) {
        $current = $data;
        $segments = explode('.', $path);
        foreach ($segments as $segment) {
            if (is_array($current)) {
                if (array_key_exists($segment, $current)) {
                    $current = $current[$segment];
                    continue;
                }
                if (ctype_digit($segment)) {
                    $index = (int)$segment;
                    if (array_key_exists($index, $current)) {
                        $current = $current[$index];
                        continue;
                    }
                }
            }
            return null;
        }
        return $current;
    }
}
