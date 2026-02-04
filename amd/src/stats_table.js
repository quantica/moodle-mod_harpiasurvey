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
 * Stats table functionality for expanding/collapsing long answers.
 *
 * @module     mod_harpiasurvey/stats_table
 * @copyright  2025 Your Name
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define(['jquery'], function($) {
    'use strict';

    /**
     * Render answer items as HTML tags.
     *
     * @param {Array} answerItems Array of answer item objects
     * @return {string} HTML string
     */
    function renderAnswerItems(answerItems) {
        let html = '';
        answerItems.forEach(function(item) {
            if (item.isoption) {
                html += '<span class="badge badge-secondary answer-tag mr-1 mb-1">';
                html += '<span class="answer-text">' + $('<div>').text(item.text).html() + '</span>';
                html += '</span>';
            } else {
                html += '<span class="answer-text-plain">' + $('<div>').text(item.text).html() + '</span>';
            }
        });
        return html;
    }

    /**
     * Initialize stats table functionality.
     */
    function init() {
        // Handle expand/collapse for conversations.
        $(document).on('click', '.expand-conversation-btn', function() {
            const button = $(this);
            const conversationId = button.data('conversation-id');
            const messagesDiv = $('.conversation-messages[data-conversation-id="' + conversationId + '"]');
            const expandText = button.find('.expand-text');
            const collapseText = button.find('.collapse-text');

            if (messagesDiv.is(':visible')) {
                // Collapse
                messagesDiv.slideUp();
                expandText.show();
                collapseText.hide();
            } else {
                // Expand
                messagesDiv.slideDown();
                expandText.hide();
                collapseText.show();
            }
        });

        // Handle expand/collapse button clicks for answers.
        $(document).on('click', '.expand-answer-btn', function() {
            const button = $(this);
            const responseId = button.data('response-id');
            const answerContainer = $('.answer-container[data-response-id="' + responseId + '"]');
            const answerItemsDiv = answerContainer.find('.answer-items');
            const expandText = button.find('.expand-text');
            const collapseText = button.find('.collapse-text');
            const answerItemsJson = answerContainer.data('answer-items');

            if (!answerItemsJson) {
                return;
            }

            let answerItems;
            try {
                answerItems = JSON.parse(answerItemsJson);
            } catch (e) {
                // eslint-disable-next-line no-console
                console.error('Error parsing answer items:', e);
                return;
            }

            if (answerContainer.data('expanded')) {
                // Collapse - show truncated version (first item only if multiple, or truncated text).
                let displayItems = [];
                let totalLength = 0;
                for (let i = 0; i < answerItems.length; i++) {
                    const item = answerItems[i];
                    const itemText = item.text || '';
                    if (totalLength + itemText.length > 100 && i > 0) {
                        break;
                    }
                    if (itemText.length > 100) {
                        // Truncate this item.
                        displayItems.push({
                            text: itemText.substring(0, 100) + '...',
                            number: item.number,
                            isoption: item.isoption
                        });
                        break;
                    } else {
                        displayItems.push(item);
                        totalLength += itemText.length;
                    }
                }
                answerItemsDiv.html(renderAnswerItems(displayItems));
                answerContainer.data('expanded', false);
                expandText.show();
                collapseText.hide();
            } else {
                // Expand - show all answer items.
                answerItemsDiv.html(renderAnswerItems(answerItems));
                answerContainer.data('expanded', true);
                expandText.hide();
                collapseText.show();
            }
        });

        // Handle modal for evaluation messages.
        $(document).on('click', '.view-eval-messages-btn', function() {
            const button = $(this);
            const messagesJson = button.attr('data-messages');
            let messages = [];
            if (messagesJson) {
                try {
                    messages = JSON.parse(messagesJson);
                } catch (e) {
                    // eslint-disable-next-line no-console
                    console.error('Error parsing messages JSON:', e);
                }
            }

            const modalBody = $('#harpiasurvey-messages-modal-body');
            if (messages.length === 0) {
                modalBody.html('<div class="text-muted text-center py-3">No messages yet.</div>');
            } else {
                let html = '<div class="border rounded p-3" style="background-color: #f8f9fa; max-height: 60vh; overflow-y: auto;">';
                messages.forEach(function(msg) {
                    const time = msg.timecreated || '';
                    const model = msg.modelname ? (' <small class="text-muted">(' + $('<div>').text(msg.modelname).html() + ')</small>') : '';
                    if (msg.role && msg.role.user) {
                        html += '<div class="message mb-3 text-right">';
                        html += '<div class="d-inline-block p-2 rounded bg-primary text-white" style="max-width: 80%;">';
                        html += '<div class="message-content">' + (msg.content || '') + '</div>';
                        html += '<small class="text-white-50 d-block mt-1">' + $('<div>').text(time).html() + model + '</small>';
                        html += '</div></div>';
                    } else if (msg.role && msg.role.assistant) {
                        html += '<div class="message mb-3 text-left">';
                        html += '<div class="d-inline-block p-2 rounded bg-light border" style="max-width: 80%;">';
                        html += '<div class="message-content">' + (msg.content || '') + '</div>';
                        html += '<small class="text-muted d-block mt-1">' + $('<div>').text(time).html() + model + '</small>';
                        html += '</div></div>';
                    }
                });
                html += '</div>';
                modalBody.html(html);
            }

            $('#harpiasurvey-messages-modal').modal('show');
        });
    }

    return {
        init: init
    };
});
