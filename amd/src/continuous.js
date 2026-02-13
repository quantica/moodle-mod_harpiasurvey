// Continuous-only logic for harpiasurvey chat.
import {
    Templates,
    Notification,
    Config,
    getString,
    $,
    conversationTrees,
    scrollToBottom,
    addError,
    getCmid
} from './common_chat';

let initialized = false;
let commonHandlersRegistered = false;
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

const loadConversationEvaluationResponses = (pageid, conversationId) => {
    const cmid = getCmid(pageid);
    if (!cmid) {
        return;
    }
    let evaluationContainer = $(
        `.save-all-responses-btn[data-pageid="${pageid}"]`
    ).first().closest('.page-questions');
    if (evaluationContainer.length === 0) {
        evaluationContainer = $(`.page-questions`).first();
    }
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
        console.error('Error loading continuous evaluation responses:', error);
    });
};

export const init = () => initialize();

/**
 * Initialize continuous-mode chat containers.
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
    const containers = $('.ai-conversation-container[data-behavior="continuous"]');
    if (containers.length === 0) {
        return;
    }
    containers.each(function() {
        const pageid = parseInt($(this).data('pageid'), 10);
        if (!pageid) {
            return;
        }
        initContinuousPage(pageid);
    });
    registerCommonHandlers();
    registerTreeHandlers();
    initialized = true;
};

/**
 * Initialize a specific continuous chat page.
 *
 * @param {number} pageid Page ID
 */
const initContinuousPage = (pageid) => {
    const container = $(`.ai-conversation-container[data-pageid="${pageid}"]`);
    const messagesContainer = $(`#chat-messages-page-${pageid}`);
    const allMessages = messagesContainer.find('.message');
    if (allMessages.length > 0) {
        const lastMessage = allMessages.last();
        const lastMessageId = parseInt(lastMessage.data('messageid'), 10);
        if (lastMessageId && !isNaN(lastMessageId)) {
            let rootId = lastMessageId;
            let currentId = lastMessageId;
            let found = false;
            const parentMap = new Map();
            allMessages.each(function() {
                const msgId = parseInt($(this).data('messageid'), 10);
                const parentId = parseInt($(this).data('parentid'), 10);
                if (msgId && !isNaN(msgId)) {
                    parentMap.set(msgId, parentId && !isNaN(parentId) ? parentId : null);
                }
            });
            while (currentId && !found) {
                const parentId = parentMap.get(currentId);
                if (!parentId) {
                    rootId = currentId;
                    found = true;
                } else {
                    currentId = parentId;
                }
            }
            if (found) {
                container.data('viewing-conversation', rootId);
                container.attr('data-viewing-conversation', rootId);
                filterMessagesByConversation(pageid, rootId);
                loadConversationEvaluationResponses(pageid, rootId);
            }
        }
    }
    if (!allMessages.length) {
        loadConversationEvaluationResponses(pageid, null);
    }
    const sidebar = $(`#conversation-tree-sidebar-${pageid}`);
    if (sidebar.length > 0) {
        sidebar.show();
        loadConversationTree(pageid);
    }
};

function registerCommonHandlers() {
    if (commonHandlersRegistered) {
        return;
    }
    $(document).on('click', '.chat-send-btn', function(e) {
        e.preventDefault();
        const button = $(this);
        const cmid = parseInt(button.data('cmid'), 10);
        const pageid = parseInt(button.data('pageid'), 10);
        if (!cmid || !pageid) {
            addError('Missing cmid or pageid');
            return;
        }
        const input = $(`#chat-input-page-${pageid}`);
        if (input.length === 0) {
            addError('Chat input not found');
            return;
        }
        const inputValue = input.val();
        if (!inputValue) {
            return;
        }
        const message = inputValue.trim();
        if (!message) {
            return;
        }
        const container = button.closest('.ai-conversation-container');
        const modelsdata = container.data('models');
        let modelid = null;
        if (modelsdata) {
            const modelids = modelsdata.split(',');
            if (modelids.length > 0) {
                modelid = parseInt(modelids[0], 10);
            }
        }
        if (!modelid) {
            addError('No model available');
            return;
        }
        input.prop('disabled', true);
        button.prop('disabled', true);
        input.val('');
        const tempId = 'temp-user-' + Date.now() + '-' + Math.random().toString(36).substr(2, 9);
        displayUserMessage(pageid, message, tempId);
        const loading = $(`#chat-loading-page-${pageid}`);
        loading.show();
        sendMessage(cmid, pageid, message, modelid, button, input, loading);
    });

    $(document).on('keydown', '.chat-input', function(e) {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            $(this).closest('.ai-conversation-container').find('.chat-send-btn').click();
        }
    });

    commonHandlersRegistered = true;
}

function registerTreeHandlers() {
    if (treeHandlersRegistered) {
        return;
    }
    $(document).on('click', '.tree-node-content[data-action="navigate"]', function(e) {
        e.preventDefault();
        const node = $(this);
        const conversationId = parseInt(node.data('conversation-id'), 10);
        const pageid = node.closest('.conversation-tree-sidebar').attr('id').replace('conversation-tree-sidebar-', '');
        if (!pageid) {
            return;
        }
        navigateToConversation(parseInt(pageid, 10), conversationId);
    });

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
        
        // Get current viewing conversation
        const container = $(`.ai-conversation-container[data-pageid="${pageid}"]`);
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

const filterMessagesByConversation = (pageid, conversationId) => {
    const messagesContainer = $(`#chat-messages-page-${pageid}`);
    const conversationMessageIds = new Set();
    conversationMessageIds.add(conversationId);
    const messageParentMap = new Map();
    
    // Build parent map for ALL messages, including root messages (parentId = null)
    messagesContainer.find('.message').each(function() {
        const messageId = parseInt($(this).data('messageid'), 10);
        const parentId = parseInt($(this).data('parentid'), 10);
        if (messageId && !isNaN(messageId)) {
            // Include all messages in the map, even if parentId is null/undefined
            messageParentMap.set(messageId, (parentId && !isNaN(parentId)) ? parentId : null);
        }
    });
    
    // Traverse down from the root conversation ID to find all child messages
    let changed = true;
    while (changed) {
        changed = false;
        for (const [messageId, parentId] of messageParentMap.entries()) {
            // If this message's parent is in the conversation, add this message
            if (!conversationMessageIds.has(messageId) && parentId && conversationMessageIds.has(parentId)) {
                conversationMessageIds.add(messageId);
                changed = true;
            }
        }
    }
    
    // Also ensure the root message itself is included (in case it wasn't found in traversal)
    if (messageParentMap.has(conversationId)) {
        conversationMessageIds.add(conversationId);
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
    
    // Show placeholder if no messages are visible
    const placeholder = messagesContainer.find('.text-center.text-muted');
    if (!hasVisibleMessages) {
        if (placeholder.length === 0) {
            messagesContainer.prepend('<div class="text-center text-muted py-4"><p>' +
                'No messages yet. Send a message to start the conversation.</p></div>');
        } else {
            placeholder.show();
        }
    } else {
        placeholder.hide();
    }
    
    setTimeout(() => scrollToBottom(pageid), 50);
};

const sendMessage = (cmid, pageid, message, modelid, button, input, loading) => {
    const container = $(`#chat-messages-page-${pageid}`).closest('.ai-conversation-container');
    const viewingConversation = container.data('viewing-conversation');
    let parentid = null;
    const messagesContainer = $(`#chat-messages-page-${pageid}`);
    
    if (viewingConversation) {
        // Find the last message that belongs to this conversation
        // Build parent map to find all messages in this conversation
        const conversationMessageIds = new Set();
        conversationMessageIds.add(viewingConversation);
        const messageParentMap = new Map();
        
        // Build parent map for ALL messages
        messagesContainer.find('.message').each(function() {
            const messageId = parseInt($(this).data('messageid'), 10);
            const parentId = parseInt($(this).data('parentid'), 10);
            if (messageId && !isNaN(messageId)) {
                messageParentMap.set(messageId, (parentId && !isNaN(parentId)) ? parentId : null);
            }
        });
        
        // Traverse down from root to find all messages in this conversation
        let changed = true;
        while (changed) {
            changed = false;
            for (const [messageId, parentId] of messageParentMap.entries()) {
                if (!conversationMessageIds.has(messageId) && parentId && conversationMessageIds.has(parentId)) {
                    conversationMessageIds.add(messageId);
                    changed = true;
                }
            }
        }
        
        // Find the last message in this conversation (by timecreated or order in DOM)
        let lastMessageInConversation = null;
        let lastTimeCreated = 0;
        messagesContainer.find('.message').each(function() {
            const messageId = parseInt($(this).data('messageid'), 10);
            if (conversationMessageIds.has(messageId)) {
                const timeCreated = parseInt($(this).data('timecreated'), 10) || 0;
                if (timeCreated >= lastTimeCreated) {
                    lastTimeCreated = timeCreated;
                    lastMessageInConversation = $(this);
                }
            }
        });
        
        if (lastMessageInConversation && lastMessageInConversation.length > 0) {
            const lastMessageId = parseInt(lastMessageInConversation.data('messageid'), 10);
            if (lastMessageId && !isNaN(lastMessageId)) {
                parentid = lastMessageId;
            }
        } else {
            // If no messages found in DOM, use the conversation root as parent
            // This handles the case where a new conversation was just created and the placeholder isn't in the DOM yet
            parentid = viewingConversation;
        }
    } else {
        // No conversation selected - this shouldn't happen, but if it does, don't send a parentid
        // The backend will create a new conversation
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
    // Always send parentid if we have one (even if it's the placeholder/conversation root)
    // This ensures messages go to the correct conversation
    if (parentid && !isNaN(parentid) && parentid > 0) {
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
                loadConversationEvaluationResponses(pageid, data.root_conversation_id);
            }
            if (data.messageid && data.content) {
                displayAIMessage(pageid, data.content, data.messageid, data.turn_id).then(() => {
                    loadConversationTree(pageid);
                    setTimeout(() => {
                        scrollToBottom(pageid);
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

const displayUserMessage = (pageid, message, messageid) => {
    const messagesContainer = $(`#chat-messages-page-${pageid}`);
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
        scrollToBottom(pageid);
    }).catch(Notification.exception);
};

const displayAIMessage = (pageid, content, messageid) => {
    const messagesContainer = $(`#chat-messages-page-${pageid}`);
    if (messagesContainer.length === 0) {
        return Promise.resolve();
    }
    return Templates.render('mod_harpiasurvey/chat_ai_message', {
        id: messageid,
        content: content,
        timecreated: Math.floor(Date.now() / 1000)
    }).then((html) => {
        messagesContainer.append(html);
        scrollToBottom(pageid);
        return html;
    }).catch(Notification.exception);
};

/**
 * Render simple conversation list in sidebar (for continuous mode, no fancytree).
 *
 * @param {number} pageid Page ID
 * @param {Array} roots Array of root conversations
 */
const renderConversationListSimple = (pageid, roots) => {
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
    const container = $(`.ai-conversation-container[data-pageid="${pageid}"]`);
    const viewingConversation = container.data('viewing-conversation') || 
                                 parseInt(container.attr('data-viewing-conversation'), 10);
    
    let html = '<ul class="list-group list-group-flush">';
    roots.forEach((root) => {
        const conversationNumber = root.conversation_number || '';
        // Try multiple possible ID fields
        const conversationId = root.id || root.conversation_id || root.turn_id;
        const isActive = viewingConversation && parseInt(conversationId, 10) === parseInt(viewingConversation, 10);
        const activeClass = isActive ? 'active' : '';
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

const loadConversationTree = (pageid) => {
    const treeContainer = $(`#conversation-tree-${pageid}`);
    const sidebar = $(`#conversation-tree-sidebar-${pageid}`);
    if (treeContainer.length === 0) {
        return Promise.resolve(null);
    }
    const cmid = getCmid(pageid);
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
            renderConversationListSimple(pageid, data.tree.roots);
            // Note: updateMessageTurnLabels is for turns mode only, not needed for continuous
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

const navigateToConversation = (pageid, conversationId) => {
    const container = $(`#chat-messages-page-${pageid}`).closest('.ai-conversation-container');
    container.data('viewing-conversation', conversationId);
    container.attr('data-viewing-conversation', conversationId);
    filterMessagesByConversation(pageid, conversationId);
    loadConversationEvaluationResponses(pageid, conversationId);
    const sidebar = $(`#conversation-tree-sidebar-${pageid}`);
    if (sidebar.length) {
        sidebar.data('sidebar-view', 'list');
        // Reload tree to update active state
        loadConversationTree(pageid);
    }
};

const updateMessageTurnLabels = (pageid) => {
    const messagesContainer = $(`#chat-messages-page-${pageid}`);
    messagesContainer.find('.message').each(function() {
        const $msg = $(this);
        const turnId = parseInt($msg.data('turn-id'), 10);
        if (turnId) {
            const turnLabel = 'Turno ' + turnId;
            const $badge = $msg.find('.badge').filter(function() {
                return $(this).closest('.position-absolute').length > 0;
            });
            if ($badge.length > 0) {
                $badge.text(turnLabel);
            } else {
                const $firstBadge = $msg.find('.badge').first();
                if ($firstBadge.length > 0) {
                    $firstBadge.text(turnLabel);
                }
            }
        }
    });
};

const createNewRoot = (cmid, pageid) => {
    const container = $(`.ai-conversation-container[data-pageid="${pageid}"]`);
    const messagesContainer = $(`#chat-messages-page-${pageid}`);
    const params = new URLSearchParams();
    params.append('action', 'create_root');
    params.append('cmid', cmid);
    params.append('pageid', pageid);
    params.append('sesskey', Config.sesskey);
    fetch(Config.wwwroot + '/mod/harpiasurvey/ajax.php', {
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
            const newConversationId = parseInt(data.new_conversation_id || data.new_turn_id, 10);
            container.data('viewing-conversation', newConversationId);
            container.attr('data-viewing-conversation', newConversationId);
            loadConversationEvaluationResponses(pageid, newConversationId);
            messagesContainer.html('<div class="text-muted text-center small py-3">' +
                'New conversation started. Send a message to begin.</div>');
            const sidebar = $(`#conversation-tree-sidebar-${pageid}`);
            sidebar.data('sidebar-view', 'list');
            setTimeout(() => {
                loadConversationTree(pageid).then((tree) => {
                    if (tree && tree.roots) {
                        const root = tree.roots.find(r =>
                            parseInt((r.conversation_id || r.turn_id), 10) === newConversationId
                        );
                        if (root) {
                            navigateToConversation(pageid, root.conversation_id || root.turn_id);
                            return;
                        }
                    }
                    const treeData = conversationTrees[pageid] || {roots: []};
                    const nextNumber = (treeData.roots?.length || 0) + 1;
                    const newRoot = {
                        turn_id: newConversationId,
                        conversation_id: newConversationId,
                        is_root: true,
                        children: [],
                        parent_turn_id: null,
                        branch_label: null,
                        timecreated: Math.floor(Date.now() / 1000),
                        timecreated_str: new Date().toLocaleString(),
                        message_count: 1,
                        conversation_number: nextNumber
                    };
                    treeData.roots = [...(treeData.roots || []), newRoot];
                    conversationTrees[pageid] = treeData;
                    renderConversationListSimple(pageid, treeData.roots);
                    navigateToConversation(pageid, newConversationId);
                });
            }, 100);
            Notification.addNotification({
                message: data.message || 'New conversation created successfully',
                type: 'success'
            });
        } else {
            addError(data.message || 'Failed to create new root');
        }
    })
    .catch((error) => {
        // eslint-disable-next-line no-console
        console.error('Error creating new root:', error);
        addError('Error creating new root: ' + error.message);
    });
};
