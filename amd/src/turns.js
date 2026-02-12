// Turns-only logic for harpiasurvey chat interface.
//
// Extracted from the previous monolithic core_chat.js. Handles turn navigation,
// branching, locking, tree rendering, and send pipeline for turns behavior.

import {
    Templates,
    Notification,
    Config,
    getString,
    $,
    currentTurns,
    conversationTrees,
    scrollToBottom,
    addError,
    getCmid,
    findRootForTurn,
    countNodes,
    calculateTurnNumber,
    renderConversationList
} from './common_chat';

let initializedTurns = false;
let commonHandlersRegistered = false;
let turnHandlersRegistered = false;
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

/**
 * Initialize turns-mode chat for all containers on the page.
 */
const initialize = () => {
    if (initializedTurns) {
        return;
    }

    getString('edit', 'moodle').then((str) => {
        editLabel = str;
    }).catch(() => {
        editLabel = 'Edit';
    });

    const containers = $('.ai-conversation-container[data-behavior="turns"]');
    if (containers.length === 0) {
        return;
    }

    containers.each(function() {
        const pageid = parseInt($(this).data('pageid'), 10);

        if (!pageid) {
            return;
        }

        const messagesContainer = $(`#chat-messages-page-${pageid}`);
        let maxTurn = 0;
        messagesContainer.find('[data-turn-id]').each(function() {
            const turnId = parseInt($(this).data('turn-id'), 10);
            if (turnId && turnId > maxTurn) {
                maxTurn = turnId;
            }
        });
        const calculatedCurrentTurn = maxTurn > 0 ? maxTurn : 1;
        if (!currentTurns[pageid]) {
            currentTurns[pageid] = calculatedCurrentTurn;
        }

        const urlParams = new URLSearchParams(window.location.search);
        const urlTurn = urlParams.get('turn');

        let initialViewingTurn = currentTurns[pageid];
        if (urlTurn !== null && urlTurn !== '') {
            const parsedTurn = parseInt(urlTurn, 10);
            if (!isNaN(parsedTurn) && parsedTurn > 0) {
                initialViewingTurn = parsedTurn;
            }
        }

        setViewingTurn(pageid, initialViewingTurn);
        updateTurnDisplay(pageid);
        updateChatLockState(pageid);
        
        // Load conversation tree first, then filter messages (tree is needed to determine pathway).
        const sidebar = $(`#conversation-tree-sidebar-${pageid}`);
        if (sidebar.length > 0) {
            sidebar.show();
            $(`.toggle-tree-btn[data-pageid="${pageid}"]`).addClass('active');
            // Load tree first, then filter messages once tree is loaded.
            loadConversationTree(pageid).then(() => {
                // Tree loaded - now filter messages based on the pathway.
                filterMessagesByTurn(pageid, initialViewingTurn);
            }).catch(() => {
                // If tree loading fails, still filter with just the current turn.
                filterMessagesByTurn(pageid, initialViewingTurn);
            });
        } else {
            // No sidebar - filter immediately (will only show current turn if tree not available).
            filterMessagesByTurn(pageid, initialViewingTurn);
        }
        
        updateSubpageVisibility(pageid);
        setTimeout(() => {
            ensureTurnEvaluationQuestionsRendered(pageid, initialViewingTurn).then(() => {
                loadTurnEvaluationResponses(pageid, initialViewingTurn);
            }).catch((error) => {
                // eslint-disable-next-line no-console
                console.error('Error loading turn evaluation questions on init:', error);
            });
        }, 100);
    });

    registerCommonHandlers();
    registerTreeHandlers();
    registerTurnHandlers();
    initializedTurns = true;
};

export const init = () => initialize();
export const initTurns = () => initialize();

/**
 * Register common chat handlers (send/enter).
 */
function registerCommonHandlers() {
    if (commonHandlersRegistered) {
        return;
    }

    // Handle send button clicks.
    $(document).on('click', '.chat-send-btn', function(e) {
        e.preventDefault();
        const button = $(this);
        const cmid = parseInt(button.data('cmid'), 10);
        const pageid = parseInt(button.data('pageid'), 10);
        const container = button.closest('.ai-conversation-container');
        if (container.data('behavior') !== 'turns') {
            return;
        }

        if (!cmid || !pageid) {
            Notification.addNotification({
                message: 'Missing cmid or pageid',
                type: 'error'
            });
            return;
        }

        const input = $(`#chat-input-page-${pageid}`);
        if (input.length === 0) {
            Notification.addNotification({
                message: 'Chat input not found',
                type: 'error'
            });
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

        // Check if chat is locked (viewing a past turn).
        const viewingTurn = getViewingTurn(pageid);
        const currentTurn = getCurrentTurn(pageid);
        // Ensure both are numbers for comparison.
        const viewingTurnNum = parseInt(viewingTurn, 10);
        const currentTurnNum = parseInt(currentTurn, 10);

        // Allow sending if viewing turn >= current turn.
        // If viewing turn > current turn, backend will create the next turn.
        // Only block if viewing a past turn (viewingTurn < currentTurn).
        if (viewingTurnNum < currentTurnNum) {
            // Viewing a past turn - block it.
            Notification.addNotification({
                message: 'Cannot send messages in a locked turn. ' +
                    'Navigate to the current turn first.',
                type: 'error'
            });
            return;
        }
        // If viewingTurn >= currentTurn, allow it - backend will handle creating the right turn.

        // Check max turns limit.
        const maxTurns = container.data('max-turns');
        if (maxTurns !== undefined && maxTurns !== null) {
            const maxTurnsNum = parseInt(maxTurns, 10);
            const turnCount = getTurnCountForConversation(pageid, viewingTurnNum);
            // Only block when the active conversation already reached the limit and the turn is complete
            // (sending would create a new turn).
            if (turnCount !== null && turnCount >= maxTurnsNum &&
                isTurnComplete(pageid, viewingTurnNum)) {
                getString('maxturnsreached', 'mod_harpiasurvey').then((str) => {
                    Notification.addNotification({
                        message: str,
                        type: 'error'
                    });
                });
                return;
            }
        }

        // Get model from container data attribute (first available model).
        const modelsdata = container.data('models');
        let modelid = null;

        if (modelsdata) {
            // modelsdata is a comma-separated string, get first one.
            const modelids = modelsdata.split(',');
            if (modelids.length > 0) {
                modelid = parseInt(modelids[0], 10);
            }
        }

        if (!modelid) {
            Notification.addNotification({
                message: 'No model available',
                type: 'error'
            });
            return;
        }

        // Disable input and button.
        input.prop('disabled', true);
        button.prop('disabled', true);

        // Clear input.
        input.val('');

        // Display user message with temporary ID (will be updated with real ID from server).
        const tempId = 'temp-user-' + Date.now() + '-' + Math.random().toString(36).substr(2, 9);
        displayUserMessage(pageid, message, tempId);

        // Show loading indicator.
        const loading = $(`#chat-loading-page-${pageid}`);
        loading.show();

        // Send message to AI.
        sendMessage(cmid, pageid, message, modelid, button, input, loading);
    });

    // Handle Enter key in textarea (Shift+Enter for new line, Enter to send).
    $(document).on('keydown', '.chat-input', function(e) {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            $(this).closest('.ai-conversation-container').find('.chat-send-btn').click();
        }
    });

    // Toggle visibility of previous turn messages in the current path.
    $(document).on('click', '.toggle-previous-messages-btn', function(e) {
        e.preventDefault();
        const btn = $(this);
        const pageid = parseInt(btn.data('pageid'), 10);
        if (!pageid) {
            return;
        }
        const container = $(`#chat-messages-page-${pageid}`);
        const prevMessages = container.find('.previous-turn-message');
        if (prevMessages.length === 0) {
            return;
        }
        const currentlyVisible = container.data('show-previous') === true;
        setPreviousMessagesVisibility(pageid, !currentlyVisible);
    });

    commonHandlersRegistered = true;
}

/**
 * Register handlers shared for turn tree/navigation.
 */
function registerTreeHandlers() {
    if (treeHandlersRegistered) {
        return;
    }

    // Handle tree node navigation clicks.
    $(document).on('click', '.tree-node-content[data-action="navigate"]', function(e) {
        e.preventDefault();
        const node = $(this);
        const turnId = parseInt(node.data('turn-id'), 10);
        const pageid = node.closest('.conversation-tree-sidebar').attr('id').replace('conversation-tree-sidebar-', '');
        if (!pageid) {
            return;
        }
        if (turnId) {
            navigateToTurn(parseInt(pageid, 10), turnId);
        }
    });

    // Handle conversation item click (in list view).
    $(document).on('click', '.conversation-item', function(e) {
        e.preventDefault();
        const item = $(this);
        const rootTurnId = parseInt(item.data('root-turn-id'), 10);
        const sidebar = item.closest('.conversation-tree-sidebar');
        const pageid = parseInt(sidebar.attr('id').replace('conversation-tree-sidebar-', ''), 10);

        if (!pageid) {
            return;
        }

        if (rootTurnId) {
            sidebar.data('sidebar-view', 'detail');
            navigateToTurn(pageid, rootTurnId);
        }
    });

    // Handle back to list button click.
    $(document).on('click', '.back-to-list-btn', function(e) {
        e.preventDefault();
        const button = $(this);
        const pageid = parseInt(button.data('pageid'), 10);
        if (!pageid) {
            return;
        }

        // Render the list view.
        const sidebar = $(`#conversation-tree-sidebar-${pageid}`);
        sidebar.data('sidebar-view', 'list'); // Set to list view
        sidebar.data('branch-root', null);
        sidebar.data('branch-stack', []);

        const tree = conversationTrees[pageid];
        if (tree) {
            renderConversationList(pageid, tree.roots);
        } else {
            loadConversationTree(pageid);
        }
    });

    // Handle back to parent branch button click.
    $(document).on('click', '.back-to-parent-branch-btn', function(e) {
        e.preventDefault();
        const button = $(this);
        const pageid = parseInt(button.data('pageid'), 10);
        if (!pageid) {
            return;
        }
        const sidebar = $(`#conversation-tree-sidebar-${pageid}`);
        let stack = sidebar.data('branch-stack');
        if (!Array.isArray(stack) || stack.length === 0) {
            sidebar.data('branch-root', null);
            sidebar.data('branch-stack', []);
            loadConversationTree(pageid);
            return;
        }
        const previousRoot = stack.pop();
        sidebar.data('branch-stack', stack);
        sidebar.data('branch-root', previousRoot || null);
        sidebar.data('sidebar-view', 'detail');
        loadConversationTree(pageid);
    });

    // Handle enter branch (subtree) button click.
    $(document).on('click', '.enter-branch-btn', function(e) {
        e.preventDefault();
        e.stopPropagation();
        const button = $(this);
        const pageid = parseInt(button.data('pageid'), 10);
        const turnId = parseInt(button.data('turn-id'), 10);
        if (!pageid || !turnId) {
            return;
        }
        const tree = conversationTrees[pageid];
        if (!tree || !tree.roots) {
            return;
        }
        const targetNode = findNodeByTurnId(tree.roots, turnId);
        if (!targetNode || !targetNode.children || targetNode.children.length === 0) {
            return;
        }
        const sidebar = $(`#conversation-tree-sidebar-${pageid}`);
        let stack = sidebar.data('branch-stack');
        if (!Array.isArray(stack)) {
            stack = [];
        }
        const currentRoot = sidebar.data('branch-root');
        if (currentRoot) {
            stack.push(currentRoot);
        }
        sidebar.data('branch-stack', stack);
        sidebar.data('branch-root', turnId);
        sidebar.data('sidebar-view', 'detail');
        navigateToTurn(pageid, turnId);
    });

    // Handle create branch button clicks (from tree).
    $(document).on('click', '.create-branch-from-tree-btn', function(e) {
        e.preventDefault();
        e.stopPropagation();
        const button = $(this);
        const pageid = parseInt(button.data('pageid'), 10);
        const cmid = parseInt(button.data('cmid'), 10);
        const turnId = parseInt(button.data('turn-id'), 10);
        const branchMode = button.data('branch-mode');

        if (!pageid || !turnId) {
            Notification.addNotification({
                message: 'Missing required data to create turn',
                type: 'error'
            });
            return;
        }
        if (hasUnsavedTurnEvaluation(pageid, turnId)) {
            getString('turnrequiresave', 'mod_harpiasurvey').then((message) => {
                Notification.addNotification({
                    message: message,
                    type: 'warning'
                });
            }).catch(() => {
                Notification.addNotification({
                    message: 'Please save the evaluation answers before creating a new turn.',
                    type: 'warning'
                });
            });
            return;
        }
        if (branchMode === 'enter') {
            const sidebar = $(`#conversation-tree-sidebar-${pageid}`);
            let stack = sidebar.data('branch-stack');
            if (!Array.isArray(stack)) {
                stack = [];
            }
            const currentRoot = sidebar.data('branch-root');
            if (currentRoot) {
                stack.push(currentRoot);
            }
            sidebar.data('branch-stack', stack);
            sidebar.data('branch-root', turnId);
            sidebar.data('sidebar-view', 'detail');
            navigateToTurn(pageid, turnId);
            return;
        }
        if (!cmid) {
            Notification.addNotification({
                message: 'Missing required data to create turn',
                type: 'error'
            });
            return;
        }
        createBranchFromTurn(cmid, pageid, turnId, button);
    });

    // Ensure clicks on the label/icon also trigger branch creation.
    $(document).on('click', '.create-branch-from-tree-btn .branch-label, .create-branch-from-tree-btn i', function(e) {
        e.preventDefault();
        e.stopPropagation();
        const btn = $(this).closest('.create-branch-from-tree-btn');
        if (btn.length) {
            btn.trigger('click');
        }
    });

    // Handle "create direct branch" button clicks (from sidebar footer or inline).
    $(document).on('click', '.create-direct-branch-btn', function(e) {
        e.preventDefault();
        const button = $(this);
        const pageid = parseInt(button.data('pageid'), 10);
        const cmid = parseInt(button.data('cmid'), 10);
        // Prefer explicit root-turn-id on the button (sidebar detail footer).
        let parentTurnId = parseInt(button.data('root-turn-id'), 10);
        if (!parentTurnId || isNaN(parentTurnId)) {
            // Fallback to current viewing turn.
            parentTurnId = getViewingTurn(pageid);
        }

        if (!pageid || !cmid || !parentTurnId) {
            Notification.addNotification({
                message: 'Missing required data to create branch',
                type: 'error'
            });
            return;
        }
        if (hasUnsavedTurnEvaluation(pageid, parentTurnId)) {
            getString('turnrequiresave', 'mod_harpiasurvey').then((message) => {
                Notification.addNotification({
                    message: message,
                    type: 'warning'
                });
            }).catch(() => {
                Notification.addNotification({
                    message: 'Please save the evaluation answers before creating a new turn.',
                    type: 'warning'
                });
            });
            return;
        }

        createBranchFromTurn(cmid, pageid, parentTurnId, button);
    });

    // Handle create new root button clicks.
    $(document).on('click', '.create-root-btn', function(e) {
        e.preventDefault();
        const button = $(this);
        const pageid = parseInt(button.data('pageid'), 10);
        const cmid = parseInt(button.data('cmid'), 10);
        if (!pageid || !cmid) {
            Notification.addNotification({
                message: 'Missing required data to create root',
                type: 'error'
            });
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
            Notification.addNotification({
                message: 'Missing required data to export conversation',
                type: 'error'
            });
            return;
        }
        
        // Get current viewing turn (for turns mode)
        const viewingTurn = getViewingTurn(pageid);
        const container = $(`.ai-conversation-container[data-pageid="${pageid}"]`);
        const behavior = container.data('behavior') || 'continuous';
        
        // Build export URL
        const params = new URLSearchParams();
        params.append('action', 'export_conversation');
        params.append('cmid', cmid);
        params.append('pageid', pageid);
        params.append('sesskey', Config.sesskey);
        
        if (behavior === 'turns' && viewingTurn) {
            params.append('turn_id', viewingTurn);
        } else if (behavior === 'continuous') {
            // For continuous mode, try to get conversation ID from container
            const conversationId = container.data('viewing-conversation');
            if (conversationId) {
                params.append('conversation_id', conversationId);
            }
        }
        
        // Open export URL in new window/tab to trigger download
        window.open(Config.wwwroot + '/mod/harpiasurvey/ajax.php?' + params.toString(), '_blank');
    });

    treeHandlersRegistered = true;
}

/**
 * Register any extra turns-specific handlers (placeholder).
 */
function registerTurnHandlers() {
    if (turnHandlersRegistered) {
        return;
    }

    $(document).on(
        'click',
        '.ai-conversation-container[data-behavior="turns"] .turn-evaluation-questions .save-turn-evaluation-questions-btn',
        function(e) {
            e.preventDefault();
            e.stopPropagation();

            const button = $(this);
            const turnId = parseInt(button.data('turn-id'), 10);
            const pageid = parseInt(button.data('pageid'), 10);
            const cmid = parseInt(button.data('cmid'), 10);

            if (!turnId || !pageid || !cmid) {
                Notification.addNotification({
                    message: 'Missing required data to save responses.',
                    type: 'error'
                });
                return;
            }

            const evaluationContainer = button.closest('.turn-evaluation-questions');
            if (evaluationContainer.length === 0) {
                return;
            }

            const responses = collectTurnEvaluationResponses(evaluationContainer);
            if (Object.keys(responses).length === 0) {
                Notification.addNotification({
                    message: 'No editable questions to save. Click Edit on a question first.',
                    type: 'info'
                });
                return;
            }

            const originalText = button.text();
            button.prop('disabled', true);
            button.text('Saving...');
            saveTurnEvaluationResponses(cmid, pageid, turnId, responses, button, originalText);
        }
    );

    turnHandlersRegistered = true;
}

const collectTurnEvaluationResponses = (evaluationContainer) => {
    const responses = {};

    evaluationContainer.find('[data-questionid]').each(function() {
        const item = $(this);
        const isLocked = String(item.attr('data-response-locked')) === '1';
        const isEditing = String(item.attr('data-response-editing')) === '1';
        if (isLocked && !isEditing) {
            return;
        }

        const questionId = item.data('questionid');
        const questionType = item.data('questiontype');
        let responseValue = null;

        if (questionType === 'multiplechoice') {
            const checked = item.find(`input[name="question_${questionId}_turn[]"]:checked`);
            const values = [];
            checked.each(function() {
                values.push($(this).val());
            });
            responseValue = values.length > 0 ? JSON.stringify(values) : null;
        } else if (questionType === 'select') {
            const selected = item.find(`select[name="question_${questionId}_turn"]`);
            responseValue = selected.length > 0 ? selected.val() : null;
        } else if (questionType === 'singlechoice' || questionType === 'likert') {
            const selected = item.find(`input[name="question_${questionId}_turn"]:checked`);
            responseValue = selected.length > 0 ? selected.val() : null;
        } else if (questionType === 'number' || questionType === 'shorttext') {
            const input = item.find(`input[name="question_${questionId}_turn"]`);
            responseValue = input.length > 0 ? input.val() : null;
        } else if (questionType === 'longtext') {
            const textarea = item.find(`textarea[name="question_${questionId}_turn"]`);
            responseValue = textarea.length > 0 ? textarea.val() : null;
        }

        if (responseValue !== null && responseValue !== '') {
            responses[questionId] = responseValue;
        }
    });

    return responses;
};

const saveTurnEvaluationResponses = (cmid, pageid, turnId, responses, button, originalText) => {
    const entries = Object.entries(responses);
    let failedCount = 0;

    let promiseChain = Promise.resolve();
    entries.forEach(([questionId, response]) => {
        promiseChain = promiseChain.then(() => {
            const params = new URLSearchParams({
                action: 'save_response',
                cmid: cmid,
                pageid: pageid,
                questionid: questionId,
                response: response,
                turn_id: turnId,
                sesskey: Config.sesskey
            });

            return fetch(Config.wwwroot + '/mod/harpiasurvey/ajax.php?' + params.toString(), {
                method: 'GET',
                headers: {
                    'Content-Type': 'application/json'
                }
            })
            .then((res) => res.json())
            .then((data) => {
                if (!data.success) {
                    failedCount++;
                }
            })
            .catch(() => {
                failedCount++;
            });
        });
    });

    promiseChain.then(() => {
        button.prop('disabled', false);
        button.text(originalText);

        if (failedCount === 0) {
            Notification.addNotification({
                message: 'Responses saved.',
                type: 'success'
            });
        } else {
            Notification.addNotification({
                message: 'Some responses failed to save.',
                type: 'warning'
            });
        }

        loadTurnEvaluationResponses(pageid, turnId);
    });
};

/**
 * Get the current turn for a page (highest turn with messages).
 *
 * @param {number} pageid Page ID
 * @return {number} Current turn number
 */
const getCurrentTurn = (pageid) => {
    return currentTurns[pageid] || 1;
};

/**
 * Get the viewing turn for a page (which turn is being displayed).
 *
 * @param {number} pageid Page ID
 * @return {number} Viewing turn number
 */
const getViewingTurn = (pageid) => {
    const container = $(`#chat-messages-page-${pageid}`).closest('.ai-conversation-container');
    return parseInt(container.data('viewing-turn') || getCurrentTurn(pageid), 10);
};

/**
 * Set the viewing turn (and update URL param).
 *
 * @param {number} pageid Page ID
 * @param {number} turn Turn number
 */
const setViewingTurn = (pageid, turn) => {
    const container = $(`#chat-messages-page-${pageid}`).closest('.ai-conversation-container');
    container.data('viewing-turn', turn);
    container.attr('data-viewing-turn', turn);
};

/**
 * Update current turn display in UI.
 *
 * @param {number} pageid Page ID
 */
const updateTurnDisplay = (pageid) => {
    const viewingTurn = getViewingTurn(pageid);
    const label = $(`#current-turn-label-${pageid}`);
    if (label.length > 0) {
        label.text(viewingTurn);
    }
};

/**
 * Check if a turn has any messages.
 *
 * @param {number} pageid Page ID
 * @param {number} turnId Turn ID
 * @return {boolean} True if turn has at least one message
 */
const turnHasMessages = (pageid, turnId) => {
    const messagesContainer = $(`#chat-messages-page-${pageid}`);
    return messagesContainer.find(`.message[data-turn-id="${turnId}"]`).length > 0;
};

/**
 * Check if the current turn is complete (last message from AI).
 *
 * @param {number} pageid Page ID
 * @param {number} turnId Turn ID
 * @return {boolean} True if turn is complete
 */
const isTurnComplete = (pageid, turnId) => {
    const messagesContainer = $(`#chat-messages-page-${pageid}`);
    const turnMessages = messagesContainer.find(`.message[data-turn-id="${turnId}"]`);
    if (turnMessages.length === 0) {
        return false;
    }
    const lastMessage = turnMessages.last();
    const role = lastMessage.data('role');
    return role === 'assistant' || role === 'ai';
};

/**
 * Get the number of turns in the conversation that contains the given turn.
 *
 * @param {number} pageid Page ID
 * @param {number} turnId Turn ID (or conversation root ID)
 * @return {number|null} Count of turns or null if unknown
 */
const getTurnCountForConversation = (pageid, turnId) => {
    const tree = conversationTrees[pageid];
    if (!tree || !tree.roots || !turnId) {
        return null;
    }
    const root = findRootForTurn(tree.roots, turnId);
    if (!root) {
        return null;
    }
    return countNodes(root);
};

/**
 * Filter messages by turn - shows only messages from the current pathway.
 * All messages from the current pathway remain in the DOM, but only the current turn is visible by default.
 * Previous turns are hidden by default and can be shown via the "Show previous" button.
 *
 * @param {number} pageid Page ID
 * @param {number} turnId Turn ID to show
 */
const filterMessagesByTurn = (pageid, turnId) => {
    const messagesContainer = $(`#chat-messages-page-${pageid}`);
    // Default collapsed unless user toggled to show.
    const shouldShowPrevious = messagesContainer.data('show-previous') === true;
    
    // Build set of allowed turn IDs (current pathway from root to current turn).
    const tree = conversationTrees[pageid];
    const root = tree ? findRootForTurn(tree.roots || [], turnId) : null;
    const allowedTurnIds = new Set();
    allowedTurnIds.add(turnId);
    
    if (root) {
        // Recursively find the path from root to the target turn.
        const addPath = (node, targetId, path) => {
            if (parseInt(node.turn_id, 10) === parseInt(targetId, 10)) {
                // Found the target - add all turns in the path.
                path.forEach(id => allowedTurnIds.add(id));
                allowedTurnIds.add(parseInt(node.turn_id, 10));
                return true;
            }
            // Check direct branches (same level as root).
            if (node.direct_branches) {
                for (const db of node.direct_branches) {
                    if (addPath(db, targetId, [...path, parseInt(node.turn_id, 10)])) {
                        return true;
                    }
                }
            }
            // Check children (nested branches).
            if (node.children) {
                for (const child of node.children) {
                    if (addPath(child, targetId, [...path, parseInt(node.turn_id, 10)])) {
                        return true;
                    }
                }
            }
            return false;
        };
        addPath(root, turnId, []);
    } else {
        // If tree not loaded yet, just allow the current turn.
        // The tree will be loaded and filtering will be re-applied.
        allowedTurnIds.add(turnId);
    }

    // Process all messages in the container.
    // Messages from the current pathway remain in DOM but are shown/hidden based on turn.
    // Messages from other pathways are hidden.
    messagesContainer.find('.message').each(function() {
        const $msg = $(this);
        const messageTurnId = parseInt($msg.data('turn-id'), 10);
        
        if (!messageTurnId || isNaN(messageTurnId)) {
            // Message without turn_id - hide it (shouldn't happen in turns mode).
            $msg.removeClass('previous-turn-message').hide();
            return;
        }
        
        const isCurrentTurn = messageTurnId === parseInt(turnId, 10);
        const isInPathway = allowedTurnIds.has(messageTurnId);
        
        if (isInPathway) {
            // Message is in the current pathway - keep in DOM.
            if (isCurrentTurn) {
                // Current turn - always visible.
                $msg.removeClass('previous-turn-message').show();
            } else {
                // Previous turn in pathway - hide by default, show if toggle is on.
                $msg.addClass('previous-turn-message');
                if (shouldShowPrevious) {
                    $msg.show();
                } else {
                    $msg.hide();
                }
            }
        } else {
            // Message is from a different pathway - hide it.
            $msg.removeClass('previous-turn-message').hide();
        }
    });
    
    // Also handle turn separators - show/hide them based on pathway.
    messagesContainer.find('.turn-separator').each(function() {
        const $separator = $(this);
        const separatorTurnId = parseInt($separator.data('turn-separator'), 10);
        
        if (!separatorTurnId || isNaN(separatorTurnId)) {
            $separator.hide();
            return;
        }
        
        const isInPathway = allowedTurnIds.has(separatorTurnId);
        if (isInPathway) {
            $separator.show();
        } else {
            $separator.hide();
        }
    });

    // Update toggle button state based on whether there are previous messages.
    const toggleBtn = $(`.toggle-previous-messages-btn[data-pageid="${pageid}"]`);
    if (toggleBtn.length > 0) {
        const hasPreviousMessages = messagesContainer.find('.previous-turn-message').length > 0;
        if (hasPreviousMessages) {
            toggleBtn.show();
            toggleBtn.prop('disabled', false);
            const firstPrevious = messagesContainer.find('.previous-turn-message').first();
            const isVisible = firstPrevious.is(':visible');
            updatePreviousToggleLabel(toggleBtn, isVisible);
        } else {
            // No previous messages - hide the button.
            toggleBtn.hide();
        }
    }

    // Scroll to bottom after filtering.
    setTimeout(() => {
        scrollToBottom(pageid);
    }, 50);
};

/**
 * Update chat lock state (disable input if viewing past turn).
 *
 * @param {number} pageid Page ID
 */
const updateChatLockState = (pageid) => {
    const viewingTurn = getViewingTurn(pageid);
    const currentTurn = getCurrentTurn(pageid);
    // Ensure both are numbers for comparison.
    const viewingTurnNum = parseInt(viewingTurn, 10);
    const currentTurnNum = parseInt(currentTurn, 10);
    const input = $(`#chat-input-page-${pageid}`);
    const sendBtn = $(`.chat-send-btn[data-pageid="${pageid}"]`);
    const lockMessage = $(`#turn-locked-message-${pageid}`);
    const container = input.closest('.ai-conversation-container');
    const maxTurns = container.data('max-turns');
    const maxTurnsNum = (maxTurns !== undefined && maxTurns !== null) ? parseInt(maxTurns, 10) : null;
    const conversationTurnCount = getTurnCountForConversation(pageid, viewingTurnNum);
    const hasReachedTurnLimit = maxTurnsNum !== null &&
        conversationTurnCount !== null &&
        conversationTurnCount >= maxTurnsNum;

    if (viewingTurnNum < currentTurnNum) {
        // Viewing a past turn - lock chat.
        input.prop('disabled', true);
        sendBtn.prop('disabled', true);
        if (lockMessage.length > 0) {
            lockMessage.show();
        }
        // Hide direct branch button when viewing past turns.
        const directBranchContainer = $(`#direct-branch-container-${pageid}`);
        if (directBranchContainer.length > 0) {
            directBranchContainer.hide();
        }
    } else if (viewingTurnNum === currentTurnNum && isTurnComplete(pageid, viewingTurnNum)) {
        // Viewing current turn and it's complete - check max turns limit.
        // Only lock if turn has messages AND max turns is reached.
        if (hasReachedTurnLimit && turnHasMessages(pageid, viewingTurnNum)) {
            // Max turns reached - lock chat permanently.
            input.prop('disabled', true);
            sendBtn.prop('disabled', true);
            input.val(''); // Clear input.
            if (lockMessage.length > 0) {
                getString('maxturnsreached', 'mod_harpiasurvey').then((str) => {
                    lockMessage.text(str).show();
                });
            }
            // Hide direct branch button when max turns reached.
            const directBranchContainer = $(`#direct-branch-container-${pageid}`);
            if (directBranchContainer.length > 0) {
                directBranchContainer.hide();
            }
        } else {
            // Turn complete but not at max - lock chat (user must create next turn).
            input.prop('disabled', true);
            sendBtn.prop('disabled', true);
            input.val(''); // Clear input to focus on next turn.
            if (lockMessage.length > 0) {
                lockMessage.hide(); // Don't show "locked" message, just disable input.
            }
            // Show direct branch button.
            const directBranchContainer = $(`#direct-branch-container-${pageid}`);
            if (directBranchContainer.length > 0) {
                directBranchContainer.show();
            }
        }
    } else if (viewingTurnNum === currentTurnNum && !isTurnComplete(pageid, viewingTurnNum)) {
        // Viewing current turn and it's not complete - check max turns and if we're waiting for AI response.
        // Check max turns limit first, but only if turn has messages.
        if (hasReachedTurnLimit && turnHasMessages(pageid, viewingTurnNum)) {
            // Max turns reached - lock chat permanently.
            input.prop('disabled', true);
            sendBtn.prop('disabled', true);
            input.val(''); // Clear input.
            if (lockMessage.length > 0) {
                getString('maxturnsreached', 'mod_harpiasurvey').then((str) => {
                    lockMessage.text(str).show();
                });
            }
            // Hide direct branch button when max turns reached.
            const directBranchContainer = $(`#direct-branch-container-${pageid}`);
            if (directBranchContainer.length > 0) {
                directBranchContainer.hide();
            }
            return;
        }
        // If we just sent a message, input should stay disabled until AI responds.
        // We'll check if there's a loading indicator to determine this.
        const loading = $(`#chat-loading-page-${pageid}`);
        if (loading.is(':visible')) {
            // Waiting for AI response - keep input disabled.
            input.prop('disabled', true);
            sendBtn.prop('disabled', true);
        } else {
            // Not waiting for response - unlock chat.
            input.prop('disabled', false);
            sendBtn.prop('disabled', false);
        }
        // Hide direct branch button when turn is not complete.
        const directBranchContainer = $(`#direct-branch-container-${pageid}`);
        if (directBranchContainer.length > 0) {
            directBranchContainer.hide();
        }
        if (lockMessage.length > 0) {
            lockMessage.hide();
        }
    } else {
        // Viewing future turn (shouldn't happen, but unlock just in case).
        input.prop('disabled', false);
        sendBtn.prop('disabled', false);
        // Hide direct branch button when viewing future turns.
        const directBranchContainer = $(`#direct-branch-container-${pageid}`);
        if (directBranchContainer.length > 0) {
            directBranchContainer.hide();
        }
        if (lockMessage.length > 0) {
            lockMessage.hide();
        }
    }
};

/**
 * Send message to AI via AJAX (turns mode).
 *
 * @param {number} cmid Course module ID
 * @param {number} pageid Page ID
 * @param {string} message Message content
 * @param {number} modelid Model ID
 * @param {jQuery} button Send button element
 * @param {jQuery} input Input textarea element
 * @param {jQuery} loading Loading indicator element
 */
const sendMessage = (cmid, pageid, message, modelid, button, input, loading) => {
    // Get current viewing turn (for turns mode).
    const viewingTurn = getViewingTurn(pageid);

    const params = new URLSearchParams({
        action: 'send_ai_message',
        cmid: cmid,
        pageid: pageid,
        message: message,
        modelid: modelid,
        sesskey: Config.sesskey
    });

    // For turns mode, send the viewing turn so backend can use it.
    if (viewingTurn) {
        params.append('turn_id', viewingTurn);
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
            // Display AI response (check for duplicates first).
            if (data.messageid && data.content) {
                // displayAIMessage is async, so we need to wait for it to complete
                // before checking if the turn is complete and updating lock state.
                displayAIMessage(pageid, data.content, data.messageid, data.turn_id).then(() => {
                    // Update current turn if a new turn was created.
                    if (data.turn_id) {
                        const currentTurn = getCurrentTurn(pageid);
                        if (data.turn_id > currentTurn) {
                            currentTurns[pageid] = data.turn_id;
                            updateTurnDisplay(pageid);
                            // Update viewing turn to match the new current turn.
                            setViewingTurn(pageid, data.turn_id);
                        } else if (data.turn_id === currentTurn + 1) {
                            // Backend created next turn - update current turn.
                            currentTurns[pageid] = data.turn_id;
                            updateTurnDisplay(pageid);
                            // Update viewing turn to match the new current turn.
                            setViewingTurn(pageid, data.turn_id);
                        }
                    }

                    // Update chat lock state after message is fully rendered in DOM.
                    // Use a small delay to ensure DOM is completely updated and isTurnComplete can detect the new message.
                    setTimeout(() => {
                        updateChatLockState(pageid);
                    }, 100);
                }).catch(() => {
                    // If displayAIMessage fails, still try to update lock state.
                    setTimeout(() => {
                        updateChatLockState(pageid);
                    }, 100);
                });
            } else {
                Notification.addNotification({
                    message: 'Received response but missing message ID or content',
                    type: 'error'
                });
                // Re-enable input on error.
                input.prop('disabled', false);
                button.prop('disabled', false);
                input.focus();
            }
        } else {
            Notification.addNotification({
                message: data.message || 'Error sending message',
                type: 'error'
            });
            // Re-enable input on error.
            input.prop('disabled', false);
            button.prop('disabled', false);
            input.focus();
        }
    })
    .catch(error => {
        loading.hide();
        // eslint-disable-next-line no-console
        console.error('Error sending message:', error);
        Notification.addNotification({
            message: 'Error sending message: ' + error.message,
            type: 'error'
        });
        input.prop('disabled', false);
        button.prop('disabled', false);
        input.focus();
    });
};

/**
 * Display user message in the chat.
 *
 * @param {number} pageid Page ID
 * @param {string} message Message content
 * @param {string} messageid Temp/real message ID
 */
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

/**
 * Display AI message in the chat.
 *
 * @param {number} pageid Page ID
 * @param {string} content Message content
 * @param {string} messageid Message ID
 * @param {number} turnId Turn ID
 * @return {Promise} Promise that resolves when rendered
 */
const displayAIMessage = (pageid, content, messageid, turnId) => {
    const messagesContainer = $(`#chat-messages-page-${pageid}`);
    if (messagesContainer.length === 0) {
        return Promise.resolve();
    }
    return Templates.render('mod_harpiasurvey/chat_ai_message', {
        id: messageid,
        content: content,
        timecreated: Math.floor(Date.now() / 1000),
        turn_id: turnId
    }).then((html) => {
        messagesContainer.append(html);
        scrollToBottom(pageid);
        return html;
    }).catch(Notification.exception);
};

/**
 * Scroll chat to bottom.
 *
 * @param {number} pageid Page ID
 */
const scrollToBottomLocal = (pageid) => scrollToBottom(pageid);

/**
 * Ensure turn evaluation questions are rendered for a specific turn.
 * This will render them if they don't exist, or do nothing if they already exist.
 *
 * @param {number} pageid Page ID
 * @param {number} turnId Turn ID
 * @return {Promise} Promise that resolves when questions are rendered
 */
const ensureTurnEvaluationQuestionsRendered = (pageid, turnId) => {
    // Check if questions are already rendered for this turn.
    // Look in the container below the chat, not inside messages.
    const containerWrapper = $(`#turn-evaluation-questions-container-${pageid}`);
    if (containerWrapper.length === 0) {
        // Container wrapper doesn't exist - not in turns mode or page not loaded yet.
        return Promise.resolve();
    }

    const existingContainer = containerWrapper.find(`.turn-evaluation-questions[data-turn-id="${turnId}"]`);
    if (existingContainer.length > 0 && existingContainer.find('.question-item').length > 0) {
        // Questions already rendered, return resolved promise.
        return Promise.resolve();
    }

    // Render questions for this turn.
    return getString('loading', 'moodle').then((loadingStr) => {
        // Show loading indicator.
        containerWrapper.html('<div class="text-center py-3 text-muted"><i class="fa fa-spinner fa-spin"></i> ' +
            loadingStr + '</div>');

        const params = new URLSearchParams({
            action: 'get_turn_questions',
            pageid: pageid,
            turn_id: turnId,
            cmid: $(`#chat-messages-page-${pageid}`).closest('.ai-conversation-container').data('cmid'),
            sesskey: Config.sesskey
        });

        return fetch(Config.wwwroot + '/mod/harpiasurvey/ajax.php?' + params.toString(), {
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
            if (data.success && data.html) {
                containerWrapper.html(data.html);
            } else {
                containerWrapper.html('<div class="text-center text-muted py-3">' +
                    (data.message || 'No questions available for this turn.') + '</div>');
            }
        })
        .catch((error) => {
            // eslint-disable-next-line no-console
            console.error('Error loading turn evaluation questions:', error);
            containerWrapper.html('<div class="text-danger text-center py-3">' +
                'Error loading questions: ' + error.message + '</div>');
        });
    });
};

/**
 * Load saved responses for a turn.
 *
 * @param {number} pageid Page ID
 * @param {number} turnId Turn ID
 */
const loadTurnEvaluationResponses = (pageid, turnId) => {
    const cmid = getCmid(pageid);
    if (!cmid) {
        return;
    }

    const containerWrapper = $(`#turn-evaluation-questions-container-${pageid}`);
    if (containerWrapper.length === 0) {
        return;
    }
    let container = containerWrapper.find(`.turn-evaluation-questions[data-turn-id="${turnId}"]`);
    if (container.length === 0) {
        container = containerWrapper;
    }

    const params = new URLSearchParams({
        action: 'get_turn_responses',
        cmid: cmid,
        pageid: pageid,
        turn_id: turnId,
        sesskey: Config.sesskey
    });

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
        if (!data.success || !data.responses) {
            return;
        }
        Object.keys(data.responses).forEach((questionId) => {
            const responseData = data.responses[questionId];
            const questionItem = container.find(`.question-item[data-questionid="${questionId}"]`);
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
                        let savedMessage = questionItem.find('.saved-response-message');
                        if (savedMessage.length === 0) {
                            const messageHtml = '<div class="mt-2 small text-muted saved-response-message">' +
                                `${icon} ${savedText} ${onText} ${responseData.saved_datetime}</div>`;
                            questionItem.append(messageHtml);
                        } else {
                            savedMessage.html(`${icon} ${savedText} ${onText} ${responseData.saved_datetime}`);
                        }
                    });
                });
            }

            lockQuestionItem(questionItem);
            ensureEditButton(questionItem);

            getString('answerhistory', 'mod_harpiasurvey').then((historyText) => {
                let history = questionItem.find('.answer-history');
                if (!history.length && responseData.history_count > 1) {
                    const details = $('<details class="answer-history mt-1"></details>');
                    details.append(`<summary>${historyText} (${responseData.history_count})</summary>`);
                    details.append('<ul class="list-unstyled mt-2 mb-0"></ul>');
                    questionItem.append(details);
                    history = details;
                }
                if (history.length) {
                    const list = history.find('ul');
                    list.empty();
                    (responseData.history_items || []).forEach((item) => {
                        list.append(`<li class="mb-1"><span class="text-muted">${item.time}</span> — ${item.response}</li>`);
                    });
                    history.find('summary').text(`${historyText} (${responseData.history_count || 0})`);
                    if ((responseData.history_count || 0) > 1) {
                        history.show();
                    } else {
                        history.hide();
                    }
                }
            });
        });
    })
    .catch((error) => {
        // eslint-disable-next-line no-console
        console.error('Error loading turn responses:', error);
    });
};

/**
 * Clear turn evaluation form (placeholder).
 *
 * @param {number} pageid Page ID
 * @param {number} turnId Turn ID
 */
const clearTurnEvaluationForm = (pageid, turnId) => {
    // Placeholder for clearing any form state if needed.
};

const hasUnsavedTurnEvaluation = (pageid, turnId) => {
    const containerWrapper = $(`#turn-evaluation-questions-container-${pageid}`);
    if (containerWrapper.length === 0) {
        return false;
    }
    const container = containerWrapper.find(`.turn-evaluation-questions[data-turn-id="${turnId}"]`);
    if (container.length === 0) {
        return false;
    }
    const requiredQuestions = container.find('.question-item[data-required="1"]');
    if (requiredQuestions.length === 0) {
        return false;
    }
    const savedRequired = requiredQuestions.filter(function() {
        return $(this).find('.saved-response-message').length > 0;
    }).length;
    return savedRequired !== requiredQuestions.length;
};

/**
 * Filter messages by conversation ID (no-op in turns).
 */
const filterMessagesByConversation = () => {};

/**
 * Navigate to a specific turn in the conversation.
 *
 * @param {number} pageid Page ID
 * @param {number} turnId Turn ID to navigate to
 * @param {boolean} setAsCurrent Whether to set this turn as the current turn (for new branches)
 */
const navigateToTurn = (pageid, turnId, setAsCurrent = false) => {
    // If this is a new turn (like a branch), set it as current FIRST so chat is unlocked.
    if (setAsCurrent) {
        currentTurns[pageid] = turnId;
    }
    // Then set viewing turn.
    setViewingTurn(pageid, turnId);
    // Update display and lock state.
    updateTurnDisplay(pageid);
    updateChatLockState(pageid);

    // Collapse previous separators when navigating forward.
    const messagesContainer = $(`#chat-messages-page-${pageid}`);
    messagesContainer.find('.turn-separator').each(function() {
        const separatorTurnId = $(this).data('turn-separator');
        if (separatorTurnId) {
            if (parseInt(separatorTurnId, 10) < turnId) {
                $(this).data('collapsed', true);
                const toggleIcon = $(this).find('.toggle-turn-btn').find('.toggle-turn-icon');
                if (toggleIcon.length > 0) {
                    toggleIcon.removeClass('fa-chevron-up').addClass('fa-chevron-down');
                }
            }
        }
    });

    filterMessagesByTurn(pageid, turnId);
    updateSubpageVisibility(pageid);
    ensureTurnEvaluationQuestionsRendered(pageid, turnId).then(() => {
        loadTurnEvaluationResponses(pageid, turnId);
    });
    const sidebar = $(`#conversation-tree-sidebar-${pageid}`);
    if (sidebar.length) {
        sidebar.data('sidebar-view', 'detail');
        loadConversationTree(pageid);
    }
};

/**
 * Update subpage visibility for turns mode.
 *
 * @param {number} pageid Page ID
 */
const updateSubpageVisibility = (pageid) => {
    const viewingTurn = getViewingTurn(pageid);
    const subpagesContainer = $(`#subpages-container-${pageid}`);

    if (subpagesContainer.length === 0) {
        return; // No subpages container found.
    }

    // Update subpage visibility.
    subpagesContainer.find('.subpage-item').each(function() {
        const $subpage = $(this);
        const visibilityType = $subpage.data('visibility-type');
        const turnNumber = $subpage.data('turn-number');

        let shouldShow = false;

        switch (visibilityType) {
            case 'all_turns':
                shouldShow = true;
                break;
            case 'first_turn':
                shouldShow = (viewingTurn === 1);
                break;
            case 'specific_turn':
                shouldShow = (viewingTurn === turnNumber);
                break;
            default:
                shouldShow = false;
        }

        if (shouldShow) {
            $subpage.slideDown(200);
            // Update question visibility within this subpage.
            updateSubpageQuestionVisibility($subpage, viewingTurn);
        } else {
            $subpage.slideUp(200);
        }
    });
};

/**
 * Update question visibility within a subpage based on current turn.
 *
 * @param {jQuery} $subpage Subpage jQuery element
 * @param {number} viewingTurn Current viewing turn
 */
const updateSubpageQuestionVisibility = ($subpage, viewingTurn) => {
    $subpage.find('.question-item').each(function() {
        const $question = $(this);
        const visibilityType = $question.data('visibility-type') || 'all_turns';
        const turnNumber = $question.data('turn-number');

        let shouldShow = false;

        switch (visibilityType) {
            case 'all_turns':
                shouldShow = true;
                break;
            case 'first_turn':
                shouldShow = (viewingTurn === 1);
                break;
            case 'specific_turn':
                shouldShow = (viewingTurn === turnNumber);
                break;
            default:
                shouldShow = true; // Default to showing if not set.
        }

        if (shouldShow) {
            $question.slideDown(200);
        } else {
            $question.slideUp(200);
        }
    });
};

/**
 * Load conversation tree from server and render it.
 *
 * @param {number} pageid Page ID
 * @return {Promise} Promise that resolves when tree is loaded
 */
const loadConversationTree = (pageid) => {
    const treeContainer = $(`#conversation-tree-${pageid}`);
    const sidebar = $(`#conversation-tree-sidebar-${pageid}`);

    if (treeContainer.length === 0) {
        // eslint-disable-next-line no-console
        console.warn('Tree container not found for pageid:', pageid);
        return Promise.resolve(null);
    }

    // Get cmid from the container.
    const cmid = getCmid(pageid);
    if (!cmid) {
        // eslint-disable-next-line no-console
        console.error('cmid not found for pageid:', pageid);
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
        // eslint-disable-next-line no-console
        console.log('Conversation tree response:', data);

        if (data.success && data.tree) {
            // Annotate roots with sequential conversation numbers for consistent labels.
            data.tree.roots = (data.tree.roots || []).map((root, idx) => ({
                ...root,
                conversation_number: idx + 1
            }));
            // Store tree for message filtering.
            conversationTrees[pageid] = data.tree;

            const viewingTurn = getViewingTurn(pageid);
            const viewMode = sidebar.data('sidebar-view');
            const branchRootId = sidebar.data('branch-root');
            if (!viewMode || viewMode === 'list') {
                renderConversationList(pageid, data.tree.roots);
            } else {
                const root = branchRootId ? findNodeByTurnId(data.tree.roots || [], branchRootId) :
                    findRootForTurn(data.tree.roots || [], viewingTurn);
                if (root) {
                    renderConversationDetail(pageid, root);
                } else {
                    renderConversationList(pageid, data.tree.roots);
                }
            }

            // Re-filter messages now that we have the tree, so previous-turn messages are correctly
            // marked and hidden by default, with the toggle controlling them.
            filterMessagesByTurn(pageid, viewingTurn);

            // Show create-root button only when in list view (outside a conversation).
            const rootBtn = sidebar.find('.create-root-btn');
            if (rootBtn.length) {
                const viewMode = sidebar.data('sidebar-view');
                if (!viewMode || viewMode === 'list') {
                    rootBtn.show();
                } else {
                    rootBtn.hide();
                }
            }

            return data.tree;
        } else {
            // Show message in tree container instead of notification.
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
 * Find the root node that contains the given turn ID.
 */
const getTurnNumberForId = (pageid, turnId) => {
    const tree = conversationTrees[pageid];
    if (!tree || !tree.roots) {
        return null;
    }

    const root = findRootForTurn(tree.roots, turnId);
    if (!root) {
        return null;
    }

    const findAndNumber = (node, targetId, parentNumber, turnIndex) => {
        const nodeNumber = calculateTurnNumber(node, turnIndex, parentNumber);

        if (parseInt(node.turn_id, 10) === parseInt(targetId, 10)) {
            return nodeNumber;
        }

        // Check direct branches if this is a root.
        if (node.direct_branches && node.direct_branches.length > 0) {
            // Sort direct branches by turn_id for consistent ordering.
            const sortedDB = [...node.direct_branches].sort((a, b) =>
                parseInt(a.turn_id, 10) - parseInt(b.turn_id, 10)
            );
            let dbCounter = turnIndex + 1; // Continue sequential numbering
            for (const db of sortedDB) {
                const dbNumber = calculateTurnNumber(db, dbCounter, null);
                if (parseInt(db.turn_id, 10) === parseInt(targetId, 10)) {
                    return dbNumber;
                }
                // Check children of direct branch (derived branches).
                if (db.children && db.children.length > 0) {
                    const sortedChildren = [...db.children].sort((a, b) =>
                        parseInt(a.turn_id, 10) - parseInt(b.turn_id, 10)
                    );
                    for (let i = 0; i < sortedChildren.length; i++) {
                        const found = findAndNumber(sortedChildren[i], targetId, dbNumber, i);
                        if (found) {
                            return found;
                        }
                    }
                }
                dbCounter++;
            }
        }

        // Check children (derived branches).
        if (node.children && node.children.length > 0) {
            const sortedChildren = [...node.children].sort((a, b) =>
                parseInt(a.turn_id, 10) - parseInt(b.turn_id, 10)
            );
            for (let i = 0; i < sortedChildren.length; i++) {
                const found = findAndNumber(sortedChildren[i], targetId, nodeNumber, i);
                if (found) {
                    return found;
                }
            }
        }

        return null;
    };

    // Start from root (turnIndex 0 = turn number 1).
    return findAndNumber(root, turnId, null, 0);
};

/**
 * Prepare node data for recursive rendering.
 */
const prepareNodeData = (node, level, currentTurnId, pageid, cmid, turnIndex = 0, parentNumber = null, includeChildren = true) => {
    const isCurrent = parseInt(node.turn_id, 10) === parseInt(currentTurnId, 10);
    const hasChildren = node.children && node.children.length > 0;
    const isDirectBranch = node.is_direct_branch || false;
    const isRoot = node.is_root || false;
    const turnNumber = calculateTurnNumber(node, turnIndex, parentNumber);

    return {
        turn_id: node.turn_id,
        conversation_id: node.conversation_id || node.turn_id, // Use conversation_id if available, fallback to turn_id
        turn_number: turnNumber,
        is_root: isRoot,
        is_direct_branch: isDirectBranch,
        is_current: isCurrent,
        has_children: hasChildren,
        branch_label: node.branch_label || null,
        level: level,
        expanded: true,
        pageid: pageid,
        cmid: cmid,
        can_create_branch: true, // Turns-only module
        children: (includeChildren && hasChildren) ? node.children.map((child, index) =>
            // Keep descendants at the same visual level as their branch root; only the bifurcation is indented.
            prepareNodeData(child, isDirectBranch ? level : level + 1, currentTurnId, pageid, cmid, index, turnNumber, includeChildren)) : []
    };
};

/**
 * Render conversation detail view (single root tree).
 */
const renderConversationDetail = (pageid, root) => {
    const treeContainer = $(`#conversation-tree-${pageid}`);
    const container = $(`#chat-messages-page-${pageid}`).closest('.ai-conversation-container');
    const cmid = container.data('cmid');
    const sidebar = $(`#conversation-tree-sidebar-${pageid}`);

    const viewingTurn = getViewingTurn(pageid);
    const branchStack = sidebar.data('branch-stack');
    const showBranchBack = Array.isArray(branchStack) && branchStack.length > 0;
    const isBranchRoot = !root.is_root;

    sidebar.data('branch-root', root.turn_id);

    // Calculate turn numbers: root is 1, then direct branches are 2, 3, 4...
    // All turns at root level (root + direct branches) are numbered sequentially.
    let turnCounter = 0; // Will be incremented to 1 for root, 2 for first direct branch, etc.
    const rootNodeData = prepareNodeData(root, 0, viewingTurn, pageid, cmid, turnCounter, null, false);
    turnCounter++;

    // Find direct branches of this root (branches where parent is this root).
    // Direct branches are stored in the root's direct_branches array.
    const directBranches = [];
    const childBranches = root.is_root ? (root.direct_branches || []) : (root.children || []);
    if (childBranches.length > 0) {
        const sortedDirectBranches = [...childBranches].sort((a, b) =>
            parseInt(a.turn_id, 10) - parseInt(b.turn_id, 10)
        );
        sortedDirectBranches.forEach((node) => {
            // Direct branches continue sequential numbering: 2, 3, 4...
            // Level 1 so they render visually indented under the root they forked from.
            directBranches.push(prepareNodeData(node, 0, viewingTurn, pageid, cmid, turnCounter, null, false));
            turnCounter++;
        });
    }

    Templates.render('mod_harpiasurvey/conversation_detail', {
        root_node: rootNodeData,
        root_turn_id: root.turn_id,
        direct_branches: directBranches,
        pageid: pageid,
        cmid: cmid,
        conversation_number: root.conversation_number || 1,
        show_branch_back: showBranchBack,
        branch_root_turn: isBranchRoot ? root.turn_id : null
    }).then((html) => {
        treeContainer.html(html);
        treeContainer.find('[data-toggle="popover"]').popover({
            trigger: 'hover',
            placement: 'top',
            container: 'body'
        });
    }).catch((error) => {
        // eslint-disable-next-line no-console
        console.error('Error rendering conversation detail:', error);
    });
};

const findNodeByTurnId = (roots, turnId) => {
    if (!roots || !turnId) {
        return null;
    }
    const target = parseInt(turnId, 10);
    const queue = [...roots];
    while (queue.length) {
        const node = queue.shift();
        if (!node) {
            continue;
        }
        if (parseInt(node.turn_id, 10) === target) {
            return node;
        }
        if (node.direct_branches && node.direct_branches.length) {
            queue.push(...node.direct_branches);
        }
        if (node.children && node.children.length) {
            queue.push(...node.children);
        }
    }
    return null;
};

/**
 * Update turn labels in existing messages (after tree is loaded).
 */
const updateMessageTurnLabels = (pageid) => {
    const messagesContainer = $(`#chat-messages-page-${pageid}`);
    messagesContainer.find('.message').each(function() {
        const $msg = $(this);
        const turnId = parseInt($msg.data('turn-id'), 10);
        if (turnId) {
            // Use hierarchical turn number.
            const turnNumber = getTurnNumberForId(pageid, turnId) || turnId;
            const turnLabel = 'Turno ' + turnNumber;
            // Update badge in message - find the badge that contains turn label.
            const $badge = $msg.find('.badge').filter(function() {
                // Find badge that's in the position-absolute div (turn label badge).
                return $(this).closest('.position-absolute').length > 0;
            });
            if ($badge.length > 0) {
                $badge.text(turnLabel);
            } else {
                // Fallback: update first badge if position-absolute not found.
                const $firstBadge = $msg.find('.badge').first();
                if ($firstBadge.length > 0) {
                    $firstBadge.text(turnLabel);
                }
            }
        }
    });
};

/**
 * Create a branch from a specific turn.
 */
const createBranchFromTurn = (cmid, pageid, parentTurnId, buttonEl = null) => {
    const btn = buttonEl ? $(buttonEl) : null;
    let originalHtml = null;
    if (btn && btn.length) {
        originalHtml = btn.html();
        btn.prop('disabled', true).addClass('branch-btn-loading');
        btn.html('<i class="fa fa-spinner fa-spin fa-xs" aria-hidden="true"></i>');
    }

    const params = new URLSearchParams();
    params.append('action', 'create_branch');
    params.append('cmid', cmid);
    params.append('pageid', pageid);
    params.append('parent_turn_id', parentTurnId);
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
        if (btn && btn.length) {
            btn.prop('disabled', false).removeClass('branch-btn-loading');
            if (originalHtml !== null) {
                btn.html(originalHtml);
            }
        }

        if (data.success) {
            const sidebar = $(`#conversation-tree-sidebar-${pageid}`);
            if (sidebar.length) {
                let stack = sidebar.data('branch-stack');
                if (!Array.isArray(stack)) {
                    stack = [];
                }
                const currentRoot = sidebar.data('branch-root');
                if (currentRoot) {
                    stack.push(currentRoot);
                }
                sidebar.data('branch-stack', stack);
                sidebar.data('branch-root', parentTurnId);
                sidebar.data('sidebar-view', 'detail');
            }

            // Navigate to the new turn and set it as current (this will unlock the chat).
            navigateToTurn(pageid, data.new_turn_id, true);

            // Optimistically update local tree for immediate visual feedback.
            addLocalBranchNode(pageid, parentTurnId, data.new_turn_id);

            // Reload conversation tree to show the new branch.
            loadConversationTree(pageid);

            getString('branchcreated', 'mod_harpiasurvey')
                .then((msg) => {
                    Notification.addNotification({
                        message: msg,
                        type: 'success'
                    });
                })
                .catch(() => {
                    Notification.addNotification({
                        message: 'Branch created successfully',
                        type: 'success'
                    });
                });
        } else {
            Notification.addNotification({
                message: data.message || 'Failed to create turn',
                type: 'error'
            });
        }
    })
    .catch((error) => {
        if (btn && btn.length) {
            btn.prop('disabled', false).removeClass('branch-btn-loading');
            if (originalHtml !== null) {
                btn.html(originalHtml);
            }
        }

        // eslint-disable-next-line no-console
        console.error('Error creating turn:', error);
        Notification.addNotification({
            message: 'Error creating turn: ' + error.message,
            type: 'error'
        });
    });
};

/**
 * Add a branch node locally so the tree shows immediate indentation before reload.
 */
const addLocalBranchNode = (pageid, parentTurnId, newTurnId) => {
    const tree = conversationTrees[pageid];
    if (!tree || !tree.roots) {
        return;
    }
    const root = findRootForTurn(tree.roots, parentTurnId);
    if (!root) {
        return;
    }

    const newNode = {
        turn_id: newTurnId,
        is_root: false,
        is_direct_branch: false,
        parent_turn_id: parentTurnId,
        branch_label: String(newTurnId),
        children: [],
        timecreated: Math.floor(Date.now() / 1000),
        timecreated_str: new Date().toLocaleString()
    };

    const attach = (node) => {
        if (parseInt(node.turn_id, 10) === parseInt(parentTurnId, 10)) {
            if (node.is_root || node.is_direct_branch) {
                newNode.is_direct_branch = true;
                if (!node.direct_branches) {
                    node.direct_branches = [];
                }
                node.direct_branches.push(newNode);
            } else {
                if (!node.children) {
                    node.children = [];
                }
                node.children.push(newNode);
            }
            return true;
        }
        if (node.direct_branches) {
            for (const db of node.direct_branches) {
                if (attach(db)) {
                    return true;
                }
            }
        }
        if (node.children) {
            for (const child of node.children) {
                if (attach(child)) {
                    return true;
                }
            }
        }
        return false;
    };

    attach(root);
};

/**
 * Create a new root conversation.
 */
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
            currentTurns[pageid] = data.new_turn_id;
            setViewingTurn(pageid, data.new_turn_id);
            updateTurnDisplay(pageid);
            updateChatLockState(pageid);
            messagesContainer.html('<div class="text-muted text-center small py-3">' +
                'New conversation started. Send a message to begin.</div>');
            $(`#turn-evaluation-questions-container-${pageid}`).empty();

            const sidebar = $(`#conversation-tree-sidebar-${pageid}`);
            sidebar.data('sidebar-view', 'detail');
            setTimeout(() => {
                loadConversationTree(pageid);
            }, 100);

            Notification.addNotification({
                message: data.message || 'New conversation created successfully',
                type: 'success'
            });
        } else {
            Notification.addNotification({
                message: data.message || 'Failed to create new root',
                type: 'error'
            });
        }
    })
    .catch((error) => {
        // eslint-disable-next-line no-console
        console.error('Error creating new root:', error);
        Notification.addNotification({
            message: 'Error creating new root: ' + error.message,
            type: 'error'
        });
    });
};

const setPreviousMessagesVisibility = (pageid, visible) => {
    const container = $(`#chat-messages-page-${pageid}`);
    container.data('show-previous', visible === true);
    const prevMessages = container.find('.previous-turn-message');
    if (visible) {
        prevMessages.show();
    } else {
        prevMessages.hide();
    }
    const toggleBtn = $(`.toggle-previous-messages-btn[data-pageid="${pageid}"]`);
    if (toggleBtn.length) {
        updatePreviousToggleLabel(toggleBtn, visible);
    }
};

const updatePreviousToggleLabel = (btn, isVisible) => {
    if (isVisible) {
        btn.html('<i class="fa fa-history" aria-hidden="true"></i> Ocultar anteriores');
        btn.attr('title', 'Ocultar conversas anteriores');
    } else {
        btn.html('<i class="fa fa-history" aria-hidden="true"></i> Mostrar anteriores');
        btn.attr('title', 'Mostrar conversas anteriores');
    }
};

export {
    updateTurnDisplay,
    getViewingTurn,
    getCurrentTurn,
    setViewingTurn,
    isTurnComplete,
    loadConversationTree,
    navigateToTurn,
    renderConversationDetail,
    prepareNodeData,
    updateMessageTurnLabels,
    setPreviousMessagesVisibility,
    scrollToBottom,
    createBranchFromTurn,
    createNewRoot
};

export default {
    init,
    initTurns
};
