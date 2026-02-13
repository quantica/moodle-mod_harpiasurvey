// Multi-model continuous conversation logic for harpiasurvey chat.
import {
    Templates,
    Notification,
    Config,
    getString,
    $,
    addError,
    conversationTrees
} from './common_chat';

let initialized = false;
let handlersRegistered = false;
let treeHandlersRegistered = false;
let editLabel = 'Edit';

const lockQuestionItem = (questionItem) => {
    questionItem.attr('data-response-locked', '1');
    questionItem.removeAttr('data-response-editing');
    questionItem.find('input, select, textarea').prop('disabled', true);
};

const ensureEditButton = (questionItem) => {
    let controls = questionItem.find('.question-edit-controls');
    if (controls.length === 0) {
        controls = $('<div class="mt-2 question-edit-controls"></div>');
        questionItem.append(controls);
    }
    let button = controls.find('.question-edit-btn');
    if (button.length === 0) {
        button = $('<button type="button" class="btn btn-sm btn-outline-secondary question-edit-btn"></button>');
        controls.append(button);
    }
    button.text(editLabel);
    button.show();
};

const clearEvaluationQuestion = (questionItem) => {
    questionItem.removeAttr('data-response-locked');
    questionItem.removeAttr('data-response-editing');
    questionItem.find('.saved-response-message').remove();
    questionItem.find('.answer-history').remove();
    questionItem.find('.question-edit-controls').remove();

    const questionType = questionItem.data('questiontype');
    if (questionType === 'singlechoice' || questionType === 'likert') {
        questionItem.find('input[type="radio"]').prop('checked', false);
    } else if (questionType === 'multiplechoice') {
        questionItem.find('input[type="checkbox"]').prop('checked', false);
    } else if (questionType === 'select') {
        questionItem.find('select').prop('selectedIndex', 0);
    } else if (questionType === 'number' || questionType === 'shorttext') {
        questionItem.find('input[type="number"], input[type="text"]').val('');
    } else if (questionType === 'longtext') {
        questionItem.find('textarea').val('');
    }
    questionItem.find('input, select, textarea').prop('disabled', false);
};

const loadConversationEvaluationResponsesForModel = (pageid, modelid, conversationId) => {
    const container = $(`.multi-model-chat-container[data-pageid="${pageid}"]`);
    const cmid = parseInt(container.attr('data-cmid') || container.data('cmid'), 10);
    if (!cmid) {
        return;
    }
    const pane = $(`#model-pane-${modelid}-${pageid}`);
    if (pane.length === 0) {
        return;
    }
    const evaluationContainer = pane.find('.page-questions');
    if (evaluationContainer.length === 0) {
        return;
    }

    const questionItems = evaluationContainer.find('.question-item[data-questionid]');
    questionItems.each(function() {
        clearEvaluationQuestion($(this));
    });

    if (!conversationId) {
        return;
    }

    const params = new URLSearchParams({
        action: 'get_turn_responses',
        cmid: cmid,
        pageid: pageid,
        turn_id: conversationId,
        sesskey: Config.sesskey
    });

    fetch(Config.wwwroot + '/mod/harpiasurvey/ajax.php?' + params.toString(), {
        method: 'GET',
        headers: {
            'Content-Type': 'application/json'
        }
    })
    .then((response) => {
        if (!response.ok) {
            throw new Error('HTTP error! status: ' + response.status);
        }
        return response.json();
    })
    .then((data) => {
        if (!data.success || !data.responses) {
            return;
        }

        Object.keys(data.responses).forEach((questionId) => {
            const responseData = data.responses[questionId];
            const questionItem = evaluationContainer.find(`.question-item[data-questionid="${questionId}"]`);
            if (questionItem.length === 0) {
                return;
            }

            const questionType = questionItem.data('questiontype');
            const value = responseData.response;

            if (questionType === 'singlechoice' || questionType === 'likert') {
                questionItem.find('input[type="radio"]').prop('checked', false);
                questionItem.find(`input[type="radio"][value="${value}"]`).prop('checked', true);
            } else if (questionType === 'multiplechoice') {
                questionItem.find('input[type="checkbox"]').prop('checked', false);
                try {
                    const values = JSON.parse(value);
                    if (Array.isArray(values)) {
                        values.forEach((val) => {
                            questionItem.find(`input[type="checkbox"][value="${val}"]`).prop('checked', true);
                        });
                    }
                } catch (e) {
                    // ignore invalid JSON
                }
            } else if (questionType === 'select') {
                questionItem.find('select').val(value);
            } else if (questionType === 'number') {
                questionItem.find('input[type="number"]').val(value);
            } else if (questionType === 'shorttext') {
                questionItem.find('input[type="text"]').val(value);
            } else if (questionType === 'longtext') {
                questionItem.find('textarea').val(value);
            }

            if (responseData.saved_datetime) {
                getString('saved', 'mod_harpiasurvey').then((savedText) => {
                    getString('on', 'mod_harpiasurvey').then((onText) => {
                        const icon = '<i class="fa fa-check-circle" aria-hidden="true"></i>';
                        questionItem.append(
                            '<div class="mt-2 small text-muted saved-response-message">' +
                            `${icon} ${savedText} ${onText} ${responseData.saved_datetime}</div>`
                        );
                    });
                });
            }

            getString('answerhistory', 'mod_harpiasurvey').then((historyText) => {
                if ((responseData.history_count || 0) > 1) {
                    const details = $('<details class="answer-history mt-1"></details>');
                    details.append(`<summary>${historyText} (${responseData.history_count})</summary>`);
                    const list = $('<ul class="list-unstyled mt-2 mb-0"></ul>');
                    (responseData.history_items || []).forEach((item) => {
                        list.append(`<li class="mb-1"><span class="text-muted">${item.time}</span> — ${item.response}</li>`);
                    });
                    details.append(list);
                    questionItem.append(details);
                }
            });

            lockQuestionItem(questionItem);
            ensureEditButton(questionItem);
        });
    })
    .catch((error) => {
        // eslint-disable-next-line no-console
        console.error('Error loading multi-model continuous evaluation responses:', error);
    });
};

export const init = () => initialize();

/**
 * Ensure one model tab is active (fallback for inconsistent initial markup/bootstrap state).
 *
 * @param {Object} container jQuery container
 */
const ensureFirstTabActive = (container) => {
    if (container.find('.nav-link.active').length > 0 && container.find('.tab-pane.active').length > 0) {
        return;
    }

    const firstTab = container.find('.nav-link[data-model-id]').first();
    const firstPane = container.find('.tab-pane[data-model-id]').first();
    if (firstTab.length === 0 || firstPane.length === 0) {
        return;
    }

    container.find('.nav-link[data-model-id]').removeClass('active').attr('aria-selected', 'false');
    container.find('.tab-pane[data-model-id]').removeClass('show active');
    firstTab.addClass('active').attr('aria-selected', 'true');
    firstPane.addClass('show active');
};

/**
 * Initialize multi-model continuous-mode chat containers.
 */
const initialize = () => {
    if (initialized) {
        return;
    }
    getString('edit', 'moodle').then((str) => {
        editLabel = str;
    }).catch(() => {
        editLabel = 'Edit';
    });
    // Register handlers first, before initializing pages
    registerHandlers();
    registerTreeHandlers();
    const containers = $('.multi-model-chat-container[data-behavior="continuous"]');
    if (containers.length === 0) {
        initialized = true;
        return;
    }
    containers.each(function() {
        const pageid = parseInt($(this).data('pageid'), 10);
        if (!pageid) {
            return;
        }
        initMultiModelPage(pageid);
    });
    initialized = true;
};

/**
 * Initialize a specific multi-model continuous chat page.
 *
 * @param {number} pageid Page ID
 */
const initMultiModelPage = (pageid) => {
    // Initialize each model tab's conversation state
    const container = $(`.multi-model-chat-container[data-pageid="${pageid}"]`);
    ensureFirstTabActive(container);
    const tabPanes = container.find('.tab-pane[data-model-id]');

    // Build a global parent map from ALL messages across ALL models
    // This is needed to find the shared conversation root
    const globalParentMap = new Map();
    tabPanes.each(function() {
        const modelId = parseInt($(this).data('model-id'), 10);
        const messagesContainer = $(`#chat-messages-model-${modelId}-${pageid}`);
        messagesContainer.find('.message').each(function() {
            const msgId = parseInt($(this).data('messageid'), 10);
            const parentId = parseInt($(this).data('parentid'), 10);
            if (msgId && !isNaN(msgId)) {
                globalParentMap.set(msgId, parentId && !isNaN(parentId) ? parentId : null);
            }
        });
    });

    // Find the shared conversation root by checking all messages
    let sharedRootId = null;
    tabPanes.each(function() {
        const modelId = parseInt($(this).data('model-id'), 10);
        const messagesContainer = $(`#chat-messages-model-${modelId}-${pageid}`);
        const messages = messagesContainer.find('.message');
        if (messages.length > 0) {
            const lastMessage = messages.last();
            const lastMessageId = parseInt(lastMessage.data('messageid'), 10);
            if (lastMessageId && !isNaN(lastMessageId)) {
                // Find root conversation by traversing parent chain using global map
                let rootId = lastMessageId;
                let currentId = lastMessageId;
                let found = false;
                
                while (currentId && !found) {
                    const parentId = globalParentMap.get(currentId);
                    if (!parentId) {
                        rootId = currentId;
                        found = true;
                    } else {
                        currentId = parentId;
                    }
                }

                if (found) {
                    // Store model-specific conversation
                    container.data(`viewing-conversation-${modelId}`, rootId);
                    // Set shared conversation root only once (first discovered),
                    // so initial selection is stable and does not jump to the newest conversation.
                    if (!sharedRootId) {
                        sharedRootId = rootId;
                    }
                }
            }
        }
    });

    // Set the shared conversation root for all models
    if (sharedRootId) {
        container.data('viewing-conversation', sharedRootId);
        container.attr('data-viewing-conversation', sharedRootId);
    }

    // Scroll to bottom for active tab
    const activeTab = container.find('.tab-pane.active');
    if (activeTab.length > 0) {
        const activeModelId = parseInt(activeTab.data('model-id'), 10);
        setTimeout(() => {
            scrollToBottomForModel(pageid, activeModelId);
        }, 100);
    }

    // Initialize sidebar for conversation tree
    const sidebar = $(`#conversation-tree-sidebar-${pageid}`);
    if (sidebar.length > 0) {
        sidebar.show();
        loadConversationTreeForMultiModel(pageid).then(() => {
            const selectedConversation = container.data('viewing-conversation') ||
                parseInt(container.attr('data-viewing-conversation'), 10);
            tabPanes.each(function() {
                const modelId = parseInt($(this).data('model-id'), 10);
                filterMessagesByConversationForModel(pageid, modelId, selectedConversation);
                loadConversationEvaluationResponsesForModel(pageid, modelId, selectedConversation || null);
            });
        });
    }
};

/**
 * Register event handlers for multi-model chat.
 */
function registerHandlers() {
    if (handlersRegistered) {
        return;
    }

    // Handle send button clicks for multi-model tabs
    $(document).on('click', '.multi-model-chat-container .chat-send-btn', function(e) {
        e.preventDefault();
        const button = $(this);
        const container = button.closest('.multi-model-chat-container');

        // Get cmid and pageid from container (more reliable than button)
        const cmid = parseInt(container.attr('data-cmid') || container.data('cmid'), 10);
        const pageid = parseInt(container.attr('data-pageid') || container.data('pageid'), 10);
        // Get modelid from button
        const modelid = parseInt(button.attr('data-model-id') || button.data('modelId') || button.data('model-id'), 10);

        if (!cmid || !pageid || !modelid) {
            // eslint-disable-next-line no-console
            console.error('Missing data attributes:', {
                cmid: container.attr('data-cmid'),
                pageid: container.attr('data-pageid'),
                modelid: button.attr('data-model-id'),
                containerHtml: container[0]?.outerHTML?.substring(0, 200),
                buttonHtml: button[0]?.outerHTML?.substring(0, 200)
            });
            addError('Missing cmid, pageid, or model-id');
            return;
        }

        // Try to find input by ID first
        let input = $(`#chat-input-model-${modelid}-${pageid}`);
        
        // If not found, try to find by data attributes (fallback)
        if (input.length === 0) {
            input = container.find(`textarea.chat-input[data-model-id="${modelid}"]`);
        }

        // If still not found, try to find in the active tab pane
        if (input.length === 0) {
            const activePane = container.find('.tab-pane.active');
            input = activePane.find(`textarea.chat-input[data-model-id="${modelid}"]`);
        }
        
        if (input.length === 0) {
            // eslint-disable-next-line no-console
            console.error('Chat input not found:', {
                modelid: modelid,
                pageid: pageid,
                expectedId: `chat-input-model-${modelid}-${pageid}`,
                foundInputs: container.find('textarea.chat-input').map(function() {
                    const $el = $(this);
                    return {
                        id: $el.attr('id'),
                        modelId: $el.attr('data-model-id'),
                        pageId: $el.attr('data-pageid')
                    };
                }).get(),
                allTextareas: container.find('textarea').length
            });
            addError('Chat input not found for model ' + modelid);
            return;
        }

        const inputValue = input.val();
        if (!inputValue || !inputValue.trim()) {
            return;
        }

        const message = inputValue.trim();
        input.prop('disabled', true);
        button.prop('disabled', true);
        input.val('');

        const tempId = 'temp-user-' + Date.now() + '-' + Math.random().toString(36).substr(2, 9);
        displayUserMessage(pageid, modelid, message, tempId);

        const loading = $(`#chat-loading-model-${modelid}-${pageid}`);
        loading.show();

        sendMessage(cmid, pageid, message, modelid, button, input, loading);
    });

    // Handle Enter key in textarea
    $(document).on('keydown', '.multi-model-chat-container .chat-input', function(e) {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            const input = $(this);
            const container = input.closest('.multi-model-chat-container');
            const pageid = parseInt(container.attr('data-pageid') || container.data('pageid'), 10);
            const modelid = parseInt(input.attr('data-model-id') || input.data('modelId') || input.data('model-id'), 10);
            $(`#send-message-btn-model-${modelid}-${pageid}`).click();
        }
    });

    // Handle tab switching to scroll to bottom
    $(document).on('shown.bs.tab', '.multi-model-chat-container .nav-link[data-bs-toggle="tab"]', function() {
        const tab = $(this);
        const targetId = tab.data('bs-target');
        const pane = $(targetId);
        const modelid = parseInt(pane.data('model-id'), 10);
        const pageid = parseInt(tab.closest('.multi-model-chat-container').data('pageid'), 10);

        if (modelid && pageid) {
            setTimeout(() => {
                scrollToBottomForModel(pageid, modelid);
            }, 100);
        }
    });

    handlersRegistered = true;
}

const sendMessage = (cmid, pageid, message, modelid, button, input, loading) => {
    const container = $(`.multi-model-chat-container[data-pageid="${pageid}"]`);
    // Use shared conversation ID across all models, not model-specific
    const viewingConversation = container.data('viewing-conversation');
    const messagesContainer = $(`#chat-messages-model-${modelid}-${pageid}`);

    let parentid = null;
    if (viewingConversation) {
        // For multi-model conversations, we want to continue the same conversation
        // Find the last message in this model's container that belongs to this conversation
        const visibleMessages = messagesContainer.find('.message:visible');
        if (visibleMessages.length > 0) {
            const lastMessage = visibleMessages.last();
            const lastMessageId = parseInt(lastMessage.data('messageid'), 10);
            if (lastMessageId && !isNaN(lastMessageId)) {
                parentid = lastMessageId;
            }
        } else {
            // If no visible messages in this model for the selected conversation,
            // anchor the new message to the selected conversation root.
            parentid = parseInt(viewingConversation, 10);
        }
    } else {
        // No conversation selected - do not chain to a previous message.
        // Let backend create a fresh root conversation.
        parentid = null;
    }

    const params = new URLSearchParams({
        action: 'send_ai_message',
        cmid: cmid,
        pageid: pageid,
        message: message,
        modelid: modelid,
        sesskey: Config.sesskey
    });

    if (parentid) {
        params.append('parentid', parentid);
    }

    fetch(Config.wwwroot + '/mod/harpiasurvey/ajax.php?' + params.toString(), {
        method: 'GET',
        headers: {
            'Content-Type': 'application/json'
        }
    })
    .then(response => {
        if (!response.ok) {
            throw new Error('HTTP error! status: ' + response.status);
        }
        return response.json();
    })
    .then(data => {
        loading.hide();
        if (data.success) {
            if (data.root_conversation_id) {
                container.data('viewing-conversation', data.root_conversation_id);
                container.attr('data-viewing-conversation', data.root_conversation_id);
                container.data(`viewing-conversation-${modelid}`, data.root_conversation_id);
                container.find('.tab-pane[data-model-id]').each(function() {
                    const targetModelId = parseInt($(this).data('model-id'), 10);
                    loadConversationEvaluationResponsesForModel(pageid, targetModelId, data.root_conversation_id);
                });
            }
            if (data.messageid && data.content) {
                displayAIMessage(pageid, modelid, data.content, data.messageid, data.turn_id).then(() => {
                    setTimeout(() => {
                        scrollToBottomForModel(pageid, modelid);
                    }, 100);
                    input.prop('disabled', false);
                    button.prop('disabled', false);
                    input.focus();
                });
            } else {
                addError('Received response but missing message ID or content');
                input.prop('disabled', false);
                button.prop('disabled', false);
                input.focus();
            }
        } else {
            addError(data.message || 'Error sending message');
            input.prop('disabled', false);
            button.prop('disabled', false);
            input.focus();
        }
    })
    .catch(error => {
        loading.hide();
        // eslint-disable-next-line no-console
        console.error('Error sending message:', error);
        addError('Error sending message: ' + error.message);
        input.prop('disabled', false);
        button.prop('disabled', false);
        input.focus();
    });
};

const displayUserMessage = (pageid, modelid, message, messageid) => {
    const messagesContainer = $(`#chat-messages-model-${modelid}-${pageid}`);
    if (messagesContainer.length === 0) {
        return;
    }
    messagesContainer.find('.text-center.text-muted').remove();
    Templates.render('mod_harpiasurvey/chat_user_message', {
        id: messageid,
        content: message,
        timecreated: Math.floor(Date.now() / 1000)
    }).then((html) => {
        messagesContainer.append(html);
        scrollToBottomForModel(pageid, modelid);
    }).catch(Notification.exception);
};

const displayAIMessage = (pageid, modelid, content, messageid, turnId) => {
    const messagesContainer = $(`#chat-messages-model-${modelid}-${pageid}`);
    if (messagesContainer.length === 0) {
        return Promise.resolve();
    }
    return Templates.render('mod_harpiasurvey/chat_ai_message', {
        id: messageid,
        content: content,
        turn_id: turnId || null,
        timecreated: Math.floor(Date.now() / 1000)
    }).then((html) => {
        messagesContainer.append(html);
        scrollToBottomForModel(pageid, modelid);
        return html;
    }).catch(Notification.exception);
};

const scrollToBottomForModel = (pageid, modelid) => {
    const messagesContainer = $(`#chat-messages-model-${modelid}-${pageid}`);
    if (messagesContainer.length > 0) {
        messagesContainer.scrollTop(messagesContainer[0].scrollHeight);
    }
};

/**
 * Load conversation tree from server and render it for multi-model pages.
 * Similar to continuous.js but adapted for multi-model context.
 *
 * @param {number} pageid Page ID
 * @return {Promise} Promise that resolves when tree is loaded
 */
const loadConversationTreeForMultiModel = (pageid) => {
    const treeContainer = $(`#conversation-tree-${pageid}`);
    const sidebar = $(`#conversation-tree-sidebar-${pageid}`);
    if (treeContainer.length === 0) {
        return Promise.resolve(null);
    }
    const container = $(`.multi-model-chat-container[data-pageid="${pageid}"]`);
    const cmid = parseInt(container.attr('data-cmid') || container.data('cmid'), 10);
    if (!cmid) {
        treeContainer.html('<div class="text-danger text-center small py-3">' +
            'Error: Course module ID not found. Please refresh the page.</div>');
        return Promise.resolve(null);
    }
    const params = new URLSearchParams();
    params.append('action', 'get_conversation_tree');
    params.append('cmid', cmid);
    params.append('pageid', pageid);
    params.append('sesskey', Config.sesskey);
    return fetch(Config.wwwroot + '/mod/harpiasurvey/ajax.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded'
        },
        body: params.toString()
    })
    .then((response) => {
        if (!response.ok) {
            throw new Error('HTTP error! status: ' + response.status);
        }
        return response.json();
    })
    .then((data) => {
        if (data.success && data.tree) {
            data.tree.roots = (data.tree.roots || []).map((root, idx) => ({
                ...root,
                conversation_number: idx + 1
            }));
            conversationTrees[pageid] = data.tree;
            sidebar.data('sidebar-view', 'list');
            renderConversationList(pageid, data.tree.roots);
            return data.tree;
        } else {
            treeContainer.html('<div class="text-muted text-center small py-3">' +
                (data.message || 'No conversation tree available yet.') + '</div>');
            return null;
        }
    })
    .catch((error) => {
        // eslint-disable-next-line no-console
        console.error('Error loading conversation tree:', error);
        treeContainer.html('<div class="text-danger text-center small py-3">' +
            'Error loading conversation tree: ' + error.message + '<br>' +
            'Please check the browser console for details and refresh the page.</div>');
        return null;
    });
};

/**
 * Render conversation list in sidebar.
 *
 * @param {number} pageid Page ID
 * @param {Array} roots Array of root conversations
 */
const renderConversationList = (pageid, roots) => {
    const treeContainer = $(`#conversation-tree-${pageid}`);
    if (treeContainer.length === 0) {
        return;
    }
    if (roots.length === 0) {
        treeContainer.html('<div class="text-muted text-center small py-3">' +
            'No conversations yet. Click "New conversation" to start.</div>');
        return;
    }
    
    // Get the current viewing conversation
    const container = $(`.multi-model-chat-container[data-pageid="${pageid}"]`);
    const viewingConversation = container.data('viewing-conversation') || 
                                 parseInt(container.attr('data-viewing-conversation'), 10);
    
    let html = '<ul class="list-group list-group-flush">';
    roots.forEach((root) => {
        const conversationNumber = root.conversation_number || '';
        // Try multiple possible ID fields
        const conversationId = root.id || root.conversation_id || root.turn_id;
        const isActive = viewingConversation && parseInt(conversationId, 10) === parseInt(viewingConversation, 10);
        const activeClass = isActive ? 'active bg-primary text-white' : '';
        html += `<li class="list-group-item conversation-item ${activeClass}" data-conversation-id="${conversationId}" style="cursor: pointer;">`;
        html += `<div class="d-flex justify-content-between align-items-center">`;
        html += `<span class="font-weight-bold">Conversation ${conversationNumber}</span>`;
        html += `<small class="${isActive ? 'text-white-50' : 'text-muted'}">${root.message_count || 0} messages</small>`;
        html += `</div>`;
        html += `</li>`;
    });
    html += '</ul>';
    treeContainer.html(html);
};

/**
 * Register tree handlers for sidebar navigation.
 *
 * @return {void}
 */
function registerTreeHandlers() {
    if (treeHandlersRegistered) {
        return;
    }
    $(document).on('click', '.conversation-item', function(e) {
        e.preventDefault();
        const item = $(this);
        const conversationId = parseInt(item.data('conversation-id'), 10);
        const sidebarId = item.closest('.conversation-tree-sidebar').attr('id');
        const pageid = parseInt(sidebarId.replace('conversation-tree-sidebar-', ''), 10);
        if (!pageid) {
            return;
        }
        navigateToConversation(pageid, conversationId);
    });

    $(document).on('click', '.create-root-btn', function(e) {
        e.preventDefault();
        const button = $(this);
        const pageid = parseInt(button.data('pageid'), 10);
        const cmid = parseInt(button.data('cmid'), 10);
        if (!pageid || !cmid) {
            addError('Missing required data to create root');
            return;
        }
        createNewRoot(cmid, pageid);
    });
    
    // Handle export conversation button clicks.
    $(document).on('click', '.export-conversation-btn', function(e) {
        e.preventDefault();
        const button = $(this);
        const pageid = parseInt(button.data('pageid'), 10);
        const cmid = parseInt(button.data('cmid'), 10);
        if (!pageid || !cmid) {
            addError('Missing required data to export conversation');
            return;
        }
        
        // Get current viewing conversation (shared across all models)
        const container = $(`.multi-model-chat-container[data-pageid="${pageid}"]`);
        const conversationId = container.data('viewing-conversation') || 
                                parseInt(container.attr('data-viewing-conversation'), 10);
        
        // Build export URL
        const params = new URLSearchParams();
        params.append('action', 'export_conversation');
        params.append('cmid', cmid);
        params.append('pageid', pageid);
        params.append('sesskey', Config.sesskey);
        
        if (conversationId) {
            params.append('conversation_id', conversationId);
        }
        
        // Open export URL in new window/tab to trigger download
        window.open(Config.wwwroot + '/mod/harpiasurvey/ajax.php?' + params.toString(), '_blank');
    });

    treeHandlersRegistered = true;
}

/**
 * Navigate to a specific conversation.
 * For multi-model, we need to filter messages by conversation across all model tabs.
 *
 * @param {number} pageid Page ID
 * @param {number} conversationId Conversation ID (root message ID)
 */
const navigateToConversation = (pageid, conversationId) => {
    const container = $(`.multi-model-chat-container[data-pageid="${pageid}"]`);
    if (container.length === 0) {
        // eslint-disable-next-line no-console
        console.error('Container not found for pageid:', pageid);
        return;
    }
    
    // Store the viewing conversation ID
    container.data('viewing-conversation', conversationId);
    container.attr('data-viewing-conversation', conversationId);
    
    // Filter messages for each model tab
    const tabPanes = container.find('.tab-pane[data-model-id]');
    tabPanes.each(function() {
        const modelId = parseInt($(this).data('model-id'), 10);
        filterMessagesByConversationForModel(pageid, modelId, conversationId);
        loadConversationEvaluationResponsesForModel(pageid, modelId, conversationId);
    });
    
    // Reload tree to update active state highlighting
    loadConversationTreeForMultiModel(pageid);
};

/**
 * Filter messages by conversation for a specific model.
 * Since messages are already in model-specific containers, we just filter by conversation.
 * For multi-model conversations, we need to find messages that belong to the same conversation root,
 * even if they're from different models (they should all share the same root conversation ID).
 *
 * @param {number} pageid Page ID
 * @param {number} modelId Model ID
 * @param {number} conversationId Conversation ID (root message ID)
 */
const filterMessagesByConversationForModel = (pageid, modelId, conversationId) => {
    const messagesContainer = $(`#chat-messages-model-${modelId}-${pageid}`);
    if (messagesContainer.length === 0) {
        return;
    }
    
    const conversationMessageIds = new Set();
    conversationMessageIds.add(conversationId);
    const messageParentMap = new Map();
    
    // Build parent map from ALL messages in ALL model containers to find the full conversation tree
    // This is important for multi-model conversations where messages from different models
    // might be linked through the same conversation root
    const container = $(`.multi-model-chat-container[data-pageid="${pageid}"]`);
    container.find('.tab-pane[data-model-id]').each(function() {
        const modelContainer = $(this);
        const modelMessagesContainer = modelContainer.find('.chat-messages');
        modelMessagesContainer.find('.message').each(function() {
            const messageId = parseInt($(this).data('messageid'), 10);
            const parentId = parseInt($(this).data('parentid'), 10);
            if (messageId && !isNaN(messageId)) {
                messageParentMap.set(messageId, parentId && !isNaN(parentId) ? parentId : null);
            }
        });
    });
    
    // Find all messages in this conversation (traverse down from root across all models)
    const toProcess = [conversationId];
    while (toProcess.length > 0) {
        const currentId = toProcess.shift();
        messageParentMap.forEach((parentId, messageId) => {
            if (parentId === currentId) {
                conversationMessageIds.add(messageId);
                toProcess.push(messageId);
            }
        });
    }
    
    // Show/hide messages based on conversation membership
    let hasVisibleMessages = false;
    messagesContainer.find('.message').each(function() {
        const messageId = parseInt($(this).data('messageid'), 10);
        if (conversationMessageIds.has(messageId)) {
            $(this).show();
            hasVisibleMessages = true;
        } else {
            $(this).hide();
        }
    });
    
    // If no messages are visible, show the "no messages yet" placeholder
    const placeholder = messagesContainer.find('.text-center.text-muted');
    if (!hasVisibleMessages) {
        if (placeholder.length === 0) {
            // Add placeholder if it doesn't exist
            messagesContainer.prepend('<div class="text-center text-muted py-4"><p>' +
                'No messages yet. Send a message to start the conversation.</p></div>');
        } else {
            placeholder.show();
        }
    } else {
        placeholder.hide();
    }
    
    // Scroll to bottom after filtering
    setTimeout(() => {
        scrollToBottomForModel(pageid, modelId);
    }, 50);
};

/**
 * Create a new root conversation.
 *
 * @param {number} cmid Course module ID
 * @param {number} pageid Page ID
 */
const createNewRoot = (cmid, pageid) => {
    const params = new URLSearchParams();
    params.append('action', 'create_root');
    params.append('cmid', cmid);
    params.append('pageid', pageid);
    params.append('sesskey', Config.sesskey);
    
    return fetch(Config.wwwroot + '/mod/harpiasurvey/ajax.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded'
        },
        body: params.toString()
    })
    .then((response) => {
        if (!response.ok) {
            throw new Error('HTTP error! status: ' + response.status);
        }
        return response.json();
    })
    .then((data) => {
        if (data.success) {
            // Reload the conversation tree
            loadConversationTreeForMultiModel(pageid).then(() => {
                // Navigate to the new conversation
                if (data.root_id) {
                    // Clear all message containers and show "no messages" placeholder
                    const container = $(`.multi-model-chat-container[data-pageid="${pageid}"]`);
                    const tabPanes = container.find('.tab-pane[data-model-id]');
                    tabPanes.each(function() {
                        const modelId = parseInt($(this).data('model-id'), 10);
                        const messagesContainer = $(`#chat-messages-model-${modelId}-${pageid}`);
                        // Hide all messages
                        messagesContainer.find('.message').hide();
                        // Show placeholder
                        let placeholder = messagesContainer.find('.text-center.text-muted');
                        if (placeholder.length === 0) {
                            messagesContainer.prepend('<div class="text-center text-muted py-4"><p>' +
                                'No messages yet. Send a message to start the conversation.</p></div>');
                        } else {
                            placeholder.show();
                        }
                        loadConversationEvaluationResponsesForModel(pageid, modelId, data.root_id || null);
                    });
                    // Navigate to the new conversation
                    navigateToConversation(pageid, data.root_id);
                }
            });
        } else {
            addError(data.message || 'Error creating new conversation');
        }
    })
    .catch((error) => {
        // eslint-disable-next-line no-console
        console.error('Error creating new root:', error);
        addError('Error creating new conversation: ' + error.message);
    });
};
