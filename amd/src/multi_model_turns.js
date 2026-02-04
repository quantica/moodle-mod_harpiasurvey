// Multi-model turns conversation logic for harpiasurvey chat.
// Combines multi-model tabs with turn-based navigation and branching.
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

let initialized = false;
let handlersRegistered = false;
let treeHandlersRegistered = false;
let turnHandlersRegistered = false;

// Store turn state per model: {pageid: {modelid: turnNumber}}
const modelTurns = {};

export const init = () => initialize();

/**
 * Initialize multi-model turns-mode chat containers.
 */
const initialize = () => {
    if (initialized) {
        return;
    }
    // Register handlers first, before initializing pages
    registerHandlers();
    registerTreeHandlers();
    registerTurnHandlers();
    const containers = $('.multi-model-chat-container[data-behavior="turns"]');
    if (containers.length === 0) {
        initialized = true;
        return;
    }
    containers.each(function() {
        const pageid = parseInt($(this).data('pageid'), 10);
        if (!pageid) {
            return;
        }
        initMultiModelTurnsPage(pageid);
    });
    initialized = true;
};

/**
 * Initialize a specific multi-model turns chat page.
 *
 * @param {number} pageid Page ID
 */
const initMultiModelTurnsPage = (pageid) => {
    const container = $(`.multi-model-chat-container[data-pageid="${pageid}"]`);
    const tabPanes = container.find('.tab-pane[data-model-id]');
    
    // Initialize turn state for each model
    if (!modelTurns[pageid]) {
        modelTurns[pageid] = {};
    }
    
    // Get URL params once
    const urlParams = new URLSearchParams(window.location.search);
    const urlTurn = urlParams.get('turn');
    const urlModel = urlParams.get('model');
    
    // First, ensure all messages from backend have correct data attributes
    tabPanes.each(function() {
        const modelid = parseInt($(this).data('model-id'), 10);
        const messagesContainer = $(`#chat-messages-model-${modelid}-${pageid}`);
        
        // Add data-model-id to all messages that don't have it
        // Messages might be wrapped in a div with data-model-id, or directly in the container
        messagesContainer.find('.message, [data-model-id] .message').each(function() {
            const $msg = $(this);
            // Check if message is inside a wrapper div with data-model-id
            const wrapper = $msg.closest('[data-model-id]');
            const wrapperModelId = wrapper.length ? parseInt(wrapper.data('model-id'), 10) : null;
            
            if (!$msg.attr('data-model-id')) {
                // Use wrapper's model-id if available, otherwise use tab's modelid
                $msg.attr('data-model-id', wrapperModelId || modelid);
            }
            
            // Also ensure the message element itself has the attribute (not just wrapper)
            if ($msg.attr('data-model-id') !== String(modelid) && wrapperModelId !== modelid) {
                $msg.attr('data-model-id', modelid);
            }
        });
        
        // Calculate initial turn for this model based on messages
        let maxTurn = 0;
        messagesContainer.find('[data-turn-id]').each(function() {
            const turnId = parseInt($(this).data('turn-id'), 10);
            if (turnId && turnId > maxTurn) {
                maxTurn = turnId;
            }
        });
        const calculatedCurrentTurn = maxTurn > 0 ? maxTurn : 1;
        
        if (!modelTurns[pageid][modelid]) {
            modelTurns[pageid][modelid] = calculatedCurrentTurn;
        }
        
        // Get initial viewing turn from URL or use calculated
        let initialViewingTurn = modelTurns[pageid][modelid];
        
        // If URL has model param, only apply turn to that model
        // Otherwise, apply turn to all models (for backward compatibility)
        if (urlTurn !== null && urlTurn !== '') {
            const parsedTurn = parseInt(urlTurn, 10);
            if (!isNaN(parsedTurn) && parsedTurn > 0) {
                if (urlModel === null || urlModel === '' || parseInt(urlModel, 10) === modelid) {
                    initialViewingTurn = parsedTurn;
                }
            }
        }
        
        setViewingTurnForModel(pageid, modelid, initialViewingTurn);
        updateTurnDisplayForModel(pageid, modelid);
        updateChatLockStateForModel(pageid, modelid);
    });
    
    // Activate the model tab from URL if specified
    if (urlModel !== null && urlModel !== '') {
        const modelIdFromUrl = parseInt(urlModel, 10);
        if (!isNaN(modelIdFromUrl)) {
            const tabButton = $(`#model-tab-${modelIdFromUrl}-${pageid}`);
            if (tabButton.length > 0) {
                tabButton.tab('show');
            }
        }
    }
    
    // Load conversation tree (shared across models for turns mode)
    const sidebar = $(`#conversation-tree-sidebar-${pageid}`);
    if (sidebar.length > 0) {
        sidebar.show();
        const firstModel = tabPanes.first().data('model-id');
        $(`.toggle-tree-btn[data-pageid="${pageid}"][data-model-id="${firstModel}"]`).addClass('active');
        loadConversationTree(pageid).then(() => {
            // Filter messages for all models after tree loads
            tabPanes.each(function() {
                const modelid = parseInt($(this).data('model-id'), 10);
                const viewingTurn = getViewingTurnForModel(pageid, modelid);
                filterMessagesByTurnForModel(pageid, modelid, viewingTurn);
            });
        }).catch(() => {
            // If tree loading fails, still filter
            tabPanes.each(function() {
                const modelid = parseInt($(this).data('model-id'), 10);
                const viewingTurn = getViewingTurnForModel(pageid, modelid);
                filterMessagesByTurnForModel(pageid, modelid, viewingTurn);
            });
        });
    } else {
        // No sidebar - filter immediately
        tabPanes.each(function() {
            const modelid = parseInt($(this).data('model-id'), 10);
            const viewingTurn = getViewingTurnForModel(pageid, modelid);
            filterMessagesByTurnForModel(pageid, modelid, viewingTurn);
        });
    }
    
    // Load turn evaluation questions
    setTimeout(() => {
        tabPanes.each(function() {
            const modelid = parseInt($(this).data('model-id'), 10);
            const viewingTurn = getViewingTurnForModel(pageid, modelid);
            ensureTurnEvaluationQuestionsRenderedForModel(pageid, modelid, viewingTurn).then(() => {
                loadTurnEvaluationResponsesForModel(pageid, modelid, viewingTurn);
            }).catch((error) => {
                // eslint-disable-next-line no-console
                console.error('Error loading turn evaluation questions on init:', error);
            });
        });
    }, 100);
};

/**
 * Get viewing turn for a specific model.
 *
 * @param {number} pageid Page ID
 * @param {number} modelid Model ID
 * @return {number} Viewing turn number
 */
const getViewingTurnForModel = (pageid, modelid) => {
    const container = $(`.multi-model-chat-container[data-pageid="${pageid}"]`);
    const key = `viewing-turn-${modelid}`;
    return parseInt(container.data(key) || modelTurns[pageid]?.[modelid] || 1, 10);
};

/**
 * Set viewing turn for a specific model.
 *
 * @param {number} pageid Page ID
 * @param {number} modelid Model ID
 * @param {number} turn Turn number
 */
const setViewingTurnForModel = (pageid, modelid, turn) => {
    const container = $(`.multi-model-chat-container[data-pageid="${pageid}"]`);
    const key = `viewing-turn-${modelid}`;
    container.data(key, turn);
    if (!modelTurns[pageid]) {
        modelTurns[pageid] = {};
    }
    modelTurns[pageid][modelid] = turn;
    
    // Update URL with turn and model parameters
    const urlParams = new URLSearchParams(window.location.search);
    urlParams.set('turn', turn);
    urlParams.set('model', modelid);
    const newUrl = window.location.pathname + '?' + urlParams.toString();
    window.history.replaceState({}, '', newUrl);
    
    // Activate the correct model tab
    const tabButton = $(`#model-tab-${modelid}-${pageid}`);
    if (tabButton.length > 0) {
        tabButton.tab('show');
    }
};

/**
 * Get current turn for a specific model.
 *
 * @param {number} pageid Page ID
 * @param {number} modelid Model ID
 * @return {number} Current turn number
 */
const getCurrentTurnForModel = (pageid, modelid) => {
    return modelTurns[pageid]?.[modelid] || 1;
};

/**
 * Update turn display for a specific model.
 *
 * @param {number} pageid Page ID
 * @param {number} modelid Model ID
 */
const updateTurnDisplayForModel = (pageid, modelid) => {
    const viewingTurn = getViewingTurnForModel(pageid, modelid);
    const badge = $(`#current-turn-badge-model-${modelid}-${pageid}`);
    const numberSpan = $(`#current-turn-number-model-${modelid}-${pageid}`);
    if (badge.length && numberSpan.length) {
        getString('turn', 'mod_harpiasurvey').then((turnStr) => {
            numberSpan.text(`${turnStr} ${viewingTurn}`);
        });
    }
};

/**
 * Update chat lock state for a specific model.
 *
 * @param {number} pageid Page ID
 * @param {number} modelid Model ID
 */
const updateChatLockStateForModel = (pageid, modelid) => {
    const viewingTurn = getViewingTurnForModel(pageid, modelid);
    const currentTurn = getCurrentTurnForModel(pageid, modelid);
    const input = $(`#chat-input-model-${modelid}-${pageid}`);
    const button = $(`#send-message-btn-model-${modelid}-${pageid}`);
    const container = $(`.multi-model-chat-container[data-pageid="${pageid}"]`);
    
    // Check max turns
    const maxTurns = container.data('max-turns');
    const minTurns = container.data('min-turns');
    
    let shouldLock = false;
    if (viewingTurn < currentTurn) {
        // Viewing a past turn - lock it
        shouldLock = true;
    } else if (maxTurns !== undefined && maxTurns !== null) {
        const maxTurnsNum = parseInt(maxTurns, 10);
        const turnCount = getTurnCountForConversationForModel(pageid, modelid, viewingTurn);
        if (turnCount !== null && turnCount >= maxTurnsNum) {
            // Check if current turn is complete
            if (isTurnCompleteForModel(pageid, modelid, viewingTurn)) {
                shouldLock = true;
            }
        }
    }
    
    if (shouldLock) {
        input.prop('disabled', true);
        button.prop('disabled', true);
        getString('chatlocked', 'mod_harpiasurvey').then((str) => {
            input.attr('placeholder', str);
        });
    } else {
        input.prop('disabled', false);
        button.prop('disabled', false);
        getString('typeyourmessage', 'mod_harpiasurvey').then((str) => {
            input.attr('placeholder', str);
        });
    }
};

/**
 * Filter messages by turn for a specific model.
 * Shows only messages from the current pathway (conversation tree).
 * All messages from the current pathway remain in the DOM, but only the current turn is visible by default.
 * Previous turns are hidden by default and can be shown via the "Show previous" button.
 *
 * @param {number} pageid Page ID
 * @param {number} modelid Model ID
 * @param {number} viewingTurn Turn ID to show
 */
const filterMessagesByTurnForModel = (pageid, modelid, viewingTurn) => {
    const messagesContainer = $(`#chat-messages-model-${modelid}-${pageid}`);
    if (messagesContainer.length === 0) {
        return;
    }
    
    // Default collapsed unless user toggled to show.
    const shouldShowPrevious = messagesContainer.data('show-previous') === true;
    
    // Build set of allowed turn IDs (current pathway from root to current turn).
    const tree = conversationTrees[pageid];
    const root = tree ? findRootForTurn(tree.roots || [], viewingTurn) : null;
    const allowedTurnIds = new Set();
    allowedTurnIds.add(parseInt(viewingTurn, 10));
    
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
        addPath(root, viewingTurn, []);
    } else {
        // If tree not loaded yet, just allow the current turn.
        // The tree will be loaded and filtering will be re-applied.
        allowedTurnIds.add(parseInt(viewingTurn, 10));
    }

    // Process all messages in the container.
    // Messages from the current pathway remain in DOM but are shown/hidden based on turn.
    // Messages from other pathways are hidden.
    messagesContainer.find('.message').each(function() {
        const $msg = $(this);
        const messageTurnId = parseInt($msg.data('turn-id'), 10);
        const msgModelId = parseInt($msg.data('model-id'), 10);
        
        // First check if message belongs to this model
        if (msgModelId !== modelid) {
            // Message from different model - hide it
            $msg.removeClass('previous-turn-message').hide();
            return;
        }
        
        if (!messageTurnId || isNaN(messageTurnId)) {
            // Message without turn_id - hide it (shouldn't happen in turns mode).
            $msg.removeClass('previous-turn-message').hide();
            return;
        }
        
        const isCurrentTurn = messageTurnId === parseInt(viewingTurn, 10);
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
    
    // Update toggle button state based on whether there are previous messages.
    const toggleBtn = $(`.toggle-previous-messages-btn[data-pageid="${pageid}"][data-model-id="${modelid}"]`);
    if (toggleBtn.length > 0) {
        const hasPreviousMessages = messagesContainer.find('.previous-turn-message').length > 0;
        if (hasPreviousMessages) {
            toggleBtn.show();
            toggleBtn.prop('disabled', false);
            const firstPrevious = messagesContainer.find('.previous-turn-message').first();
            const isVisible = firstPrevious.is(':visible');
            updatePreviousToggleLabelForModel(toggleBtn, isVisible);
        } else {
            // No previous messages - hide the button.
            toggleBtn.hide();
        }
    }

    // Scroll to bottom after filtering.
    setTimeout(() => {
        scrollToBottomForModel(pageid, modelid);
    }, 50);
};

/**
 * Update previous messages toggle button label for a model.
 *
 * @param {jQuery} button Toggle button element
 * @param {boolean} isVisible Whether previous messages are currently visible
 */
const updatePreviousToggleLabelForModel = (button, isVisible) => {
    if (isVisible) {
        getString('hideprevious', 'mod_harpiasurvey').then((str) => {
            button.html('<i class="fa fa-eye-slash" aria-hidden="true"></i> ' + str);
        });
    } else {
        getString('showprevious', 'mod_harpiasurvey').then((str) => {
            button.html('<i class="fa fa-history" aria-hidden="true"></i> ' + str);
        });
    }
};

/**
 * Scroll to bottom for a specific model.
 *
 * @param {number} pageid Page ID
 * @param {number} modelid Model ID
 */
const scrollToBottomForModel = (pageid, modelid) => {
    const messagesContainer = $(`#chat-messages-model-${modelid}-${pageid}`);
    if (messagesContainer.length === 0) {
        return;
    }
    requestAnimationFrame(() => {
        const container = messagesContainer[0];
        if (container) {
            container.scrollTop = container.scrollHeight;
        }
    });
};

/**
 * Get turn count for conversation for a specific model.
 *
 * @param {number} pageid Page ID
 * @param {number} modelid Model ID
 * @param {number} turnId Turn ID
 * @return {number|null} Turn count or null
 */
const getTurnCountForConversationForModel = (pageid, modelid, turnId) => {
    const tree = conversationTrees[pageid];
    if (!tree || !tree.roots) {
        return null;
    }
    const root = findRootForTurn(tree.roots, turnId);
    if (!root) {
        return null;
    }
    return countNodes(root);
};

/**
 * Check if turn is complete for a specific model.
 *
 * @param {number} pageid Page ID
 * @param {number} modelid Model ID
 * @param {number} turnId Turn ID
 * @return {boolean} True if turn is complete
 */
const isTurnCompleteForModel = (pageid, modelid, turnId) => {
    const messagesContainer = $(`#chat-messages-model-${modelid}-${pageid}`);
    let hasUser = false;
    let hasAssistant = false;
    
    messagesContainer.find(`.message[data-turn-id="${turnId}"]`).each(function() {
        const role = $(this).data('role');
        if (role === 'user') {
            hasUser = true;
        } else if (role === 'assistant') {
            hasAssistant = true;
        }
    });
    
    return hasUser && hasAssistant;
};

/**
 * Ensure turn evaluation questions are rendered for a specific model.
 *
 * @param {number} pageid Page ID
 * @param {number} modelid Model ID
 * @param {number} turn Turn number
 * @return {Promise} Promise that resolves when questions are rendered
 */
const ensureTurnEvaluationQuestionsRenderedForModel = (pageid, modelid, turn) => {
    const scriptEl = $(`#turn-evaluation-questions-data-${pageid}`);
    if (scriptEl.length === 0) {
        return Promise.resolve();
    }
    
    try {
        const questionsData = JSON.parse(scriptEl.text());
        const tabPane = $(`#model-pane-${modelid}-${pageid}`);
        const questionsContainer = tabPane.find('.turn-evaluation-questions');
        
        if (questionsContainer.length === 0) {
            // Create container if it doesn't exist
            const container = $('<div class="turn-evaluation-questions mt-4"></div>');
            tabPane.append(container);
            questionsContainer = container;
        }
        
        // Filter questions for this turn
        const filteredQuestions = questionsData.filter(q => {
            if (q.show_only_turn !== null && q.show_only_turn !== undefined) {
                return parseInt(q.show_only_turn, 10) === turn;
            }
            if (q.hide_on_turn !== null && q.hide_on_turn !== undefined) {
                return parseInt(q.hide_on_turn, 10) !== turn;
            }
            return true;
        });
        
        if (filteredQuestions.length === 0) {
            questionsContainer.empty();
            return Promise.resolve();
        }
        
        // Render questions (similar to turns.js logic)
        return Templates.render('mod_harpiasurvey/turn_evaluation_questions', {
            questions: filteredQuestions,
            pageid: pageid,
            modelid: modelid,
            turn_id: turn,
            cmid: getCmid(pageid)
        }).then((html) => {
            questionsContainer.html(html);
        });
    } catch (error) {
        // eslint-disable-next-line no-console
        console.error('Error parsing turn evaluation questions:', error);
        return Promise.resolve();
    }
};

/**
 * Load turn evaluation responses for a specific model.
 *
 * @param {number} pageid Page ID
 * @param {number} modelid Model ID
 * @param {number} turn Turn number
 */
const loadTurnEvaluationResponsesForModel = (pageid, modelid, turn) => {
    const cmid = getCmid(pageid);
    if (!cmid) {
        return;
    }
    const tabPane = $(`#model-pane-${modelid}-${pageid}`);
    if (tabPane.length === 0) {
        return;
    }
    let questionsContainer = tabPane.find('.turn-evaluation-questions');
    if (questionsContainer.length === 0) {
        questionsContainer = tabPane.find('.turn-evaluation-questions-content').closest('.turn-evaluation-questions');
    }
    if (questionsContainer.length === 0) {
        return;
    }

    const params = new URLSearchParams({
        action: 'get_turn_responses',
        cmid: cmid,
        pageid: pageid,
        turn_id: turn,
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
            const questionItem = questionsContainer.find(`.question-item[data-questionid="${questionId}"]`);
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
 * Register common handlers (send/enter).
 */
function registerHandlers() {
    if (handlersRegistered) {
        return;
    }
    
    // Handle send button clicks for multi-model turns
    $(document).on('click', '.multi-model-chat-container[data-behavior="turns"] .chat-send-btn', function(e) {
        e.preventDefault();
        const button = $(this);
        const cmid = parseInt(button.data('cmid'), 10);
        const pageid = parseInt(button.data('pageid'), 10);
        const modelid = parseInt(button.data('model-id'), 10);
        const container = button.closest('.multi-model-chat-container');
        
        if (container.data('behavior') !== 'turns') {
            return;
        }
        
        if (!cmid || !pageid || !modelid) {
            addError('Missing cmid, pageid, or model-id');
            return;
        }
        
        const input = $(`#chat-input-model-${modelid}-${pageid}`);
        if (input.length === 0) {
            addError('Chat input not found');
            return;
        }
        
        const inputValue = input.val();
        if (!inputValue || !inputValue.trim()) {
            return;
        }
        
        const message = inputValue.trim();
        
        // Check if chat is locked
        const viewingTurn = getViewingTurnForModel(pageid, modelid);
        const currentTurn = getCurrentTurnForModel(pageid, modelid);
        const viewingTurnNum = parseInt(viewingTurn, 10);
        const currentTurnNum = parseInt(currentTurn, 10);
        
        if (viewingTurnNum < currentTurnNum) {
            getString('chatlocked', 'mod_harpiasurvey').then((str) => {
                Notification.addNotification({
                    message: str + ' Navigate to the current turn first.',
                    type: 'error'
                });
            });
            return;
        }
        
        // Check max turns
        const maxTurns = container.data('max-turns');
        if (maxTurns !== undefined && maxTurns !== null) {
            const maxTurnsNum = parseInt(maxTurns, 10);
            const turnCount = getTurnCountForConversationForModel(pageid, modelid, viewingTurnNum);
            if (turnCount !== null && turnCount >= maxTurnsNum &&
                isTurnCompleteForModel(pageid, modelid, viewingTurnNum)) {
                getString('maxturnsreached', 'mod_harpiasurvey').then((str) => {
                    Notification.addNotification({
                        message: str,
                        type: 'error'
                    });
                });
                return;
            }
        }
        
        // Disable input and button
        input.prop('disabled', true);
        button.prop('disabled', true);
        input.val('');
        
        // Display user message
        const tempId = 'temp-user-' + Date.now() + '-' + Math.random().toString(36).substr(2, 9);
        displayUserMessageForModel(pageid, modelid, message, tempId);
        
        const loading = $(`#chat-loading-model-${modelid}-${pageid}`);
        loading.show();
        
        sendMessageForModel(cmid, pageid, message, modelid, button, input, loading);
    });
    
    // Handle Enter key
    $(document).on('keydown', '.multi-model-chat-container[data-behavior="turns"] .chat-input', function(e) {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            const input = $(this);
            const modelid = parseInt(input.data('model-id'), 10);
            const pageid = parseInt(input.data('pageid'), 10);
            $(`#send-message-btn-model-${modelid}-${pageid}`).click();
        }
    });
    
    handlersRegistered = true;
}

/**
 * Display user message for a specific model.
 *
 * @param {number} pageid Page ID
 * @param {number} modelid Model ID
 * @param {string} message Message content
 * @param {number} messageid Message ID
 */
const displayUserMessageForModel = (pageid, modelid, message, messageid) => {
    const messagesContainer = $(`#chat-messages-model-${modelid}-${pageid}`);
    if (messagesContainer.length === 0) {
        return;
    }
    messagesContainer.find('.text-center.text-muted').remove();
    const viewingTurn = getViewingTurnForModel(pageid, modelid);
    Templates.render('mod_harpiasurvey/chat_user_message', {
        id: messageid,
        content: message,
        timecreated: Math.floor(Date.now() / 1000)
    }).then((html) => {
        const $html = $(html);
        // Ensure the message has the correct data attributes
        $html.attr('data-messageid', messageid);
        $html.attr('data-model-id', modelid);
        if (viewingTurn) {
            $html.attr('data-turn-id', viewingTurn);
        }
        $html.attr('data-role', 'user');
        messagesContainer.append($html);
        scrollToBottomForModel(pageid, modelid);
    }).catch(Notification.exception);
};

/**
 * Send message for a specific model.
 *
 * @param {number} cmid Course module ID
 * @param {number} pageid Page ID
 * @param {string} message Message content
 * @param {number} modelid Model ID
 * @param {jQuery} button Send button
 * @param {jQuery} input Input field
 * @param {jQuery} loading Loading indicator
 */
const sendMessageForModel = (cmid, pageid, message, modelid, button, input, loading) => {
    const container = $(`.multi-model-chat-container[data-pageid="${pageid}"]`);
    const viewingTurn = getViewingTurnForModel(pageid, modelid);
    const messagesContainer = $(`#chat-messages-model-${modelid}-${pageid}`);
    
    let parentid = null;
    const visibleMessages = messagesContainer.find('.message:visible');
    if (visibleMessages.length > 0) {
        const lastMessage = visibleMessages.last();
        const lastMessageId = parseInt(lastMessage.data('messageid'), 10);
        if (lastMessageId && !isNaN(lastMessageId)) {
            parentid = lastMessageId;
        }
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
    
    // Add turn_id if we're in a specific turn
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
            if (data.messageid && data.content) {
                // Update turn state if new turn was created (before displaying message)
                const newTurnId = data.turn_id ? parseInt(data.turn_id, 10) : viewingTurn;
                if (newTurnId > viewingTurn) {
                    setViewingTurnForModel(pageid, modelid, newTurnId);
                    modelTurns[pageid][modelid] = newTurnId;
                    updateTurnDisplayForModel(pageid, modelid);
                }
                
                // Display AI message with correct turn_id
                displayAIMessageForModel(pageid, modelid, data.content, data.messageid, newTurnId).then(() => {
                    // Reload tree and filter messages - use the turn_id from response
                    const turnToView = newTurnId || viewingTurn;
                    loadConversationTree(pageid).then(() => {
                        // After tree loads, filter messages for the correct turn
                        filterMessagesByTurnForModel(pageid, modelid, turnToView);
                        updateChatLockStateForModel(pageid, modelid);
                        setTimeout(() => {
                            scrollToBottomForModel(pageid, modelid);
                        }, 100);
                    }).catch(() => {
                        // If tree reload fails, still filter and scroll
                        filterMessagesByTurnForModel(pageid, modelid, turnToView);
                        updateChatLockStateForModel(pageid, modelid);
                        setTimeout(() => {
                            scrollToBottomForModel(pageid, modelid);
                        }, 100);
                    });
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

/**
 * Display AI message for a specific model.
 *
 * @param {number} pageid Page ID
 * @param {number} modelid Model ID
 * @param {string} content Message content
 * @param {number} messageid Message ID
 * @param {number} turnId Turn ID
 * @return {Promise} Promise that resolves when message is displayed
 */
const displayAIMessageForModel = (pageid, modelid, content, messageid, turnId) => {
    const messagesContainer = $(`#chat-messages-model-${modelid}-${pageid}`);
    if (messagesContainer.length === 0) {
        return Promise.resolve();
    }
    return Templates.render('mod_harpiasurvey/chat_ai_message', {
        id: messageid,
        content: content,
        timecreated: Math.floor(Date.now() / 1000),
        turn_id: turnId,
        modelid: modelid
    }).then((html) => {
        const $html = $(html);
        // Ensure the message has the correct data attributes
        $html.attr('data-messageid', messageid);
        $html.attr('data-model-id', modelid);
        if (turnId) {
            $html.attr('data-turn-id', turnId);
        }
        $html.attr('data-role', 'assistant');
        messagesContainer.append($html);
        scrollToBottomForModel(pageid, modelid);
        return $html[0].outerHTML;
    }).catch(Notification.exception);
};

/**
 * Load conversation tree (shared across models for turns mode).
 *
 * @param {number} pageid Page ID
 * @return {Promise} Promise that resolves when tree is loaded
 */
const loadConversationTree = (pageid) => {
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
            
            // Get viewing turn from the active model tab (or first model)
            // Note: container is already declared at the top of this function (line 879)
            const activeTab = container.find('.tab-pane.active');
            let activeModelId = parseInt(activeTab.data('model-id'), 10);
            if (!activeModelId || isNaN(activeModelId)) {
                activeModelId = parseInt(container.find('.tab-pane[data-model-id]').first().data('model-id'), 10);
            }
            const viewingTurn = activeModelId ? getViewingTurnForModel(pageid, activeModelId) : 
                (data.tree.roots && data.tree.roots.length > 0 ? parseInt(data.tree.roots[0].turn_id, 10) : 1);
            // Check if we're in list view or detail view
            const sidebar = $(`#conversation-tree-sidebar-${pageid}`);
            const viewMode = sidebar.data('sidebar-view') || 'list';
            const branchRootId = sidebar.data('branch-root');
            
            if (viewMode === 'list') {
                // Render simple list
                renderConversationList(pageid, data.tree.roots);
            } else {
                const root = branchRootId ? findNodeByTurnId(data.tree.roots || [], branchRootId) :
                    findRootForTurn(data.tree.roots || [], viewingTurn);
                if (root) {
                    renderConversationDetail(pageid, root, viewingTurn);
                } else {
                    renderConversationList(pageid, data.tree.roots);
                }
            }
            
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
        conversation_id: node.conversation_id || node.turn_id,
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
        can_create_branch: true,
        children: (includeChildren && hasChildren) ? node.children.map((child, index) =>
            prepareNodeData(child, isDirectBranch ? level : level + 1, currentTurnId, pageid, cmid, index, turnNumber, includeChildren)) : []
    };
};

/**
 * Render conversation detail view (single root tree).
 */
const renderConversationDetail = (pageid, root, viewingTurn = null) => {
    const treeContainer = $(`#conversation-tree-${pageid}`);
    const container = $(`.multi-model-chat-container[data-pageid="${pageid}"]`);
    const cmid = getCmid(pageid);
    const sidebar = $(`#conversation-tree-sidebar-${pageid}`);

    let activeTurn = viewingTurn;
    if (!activeTurn) {
        const activeTab = container.find('.tab-pane.active');
        const activeModelId = parseInt(activeTab.data('model-id'), 10);
        if (activeModelId) {
            activeTurn = getViewingTurnForModel(pageid, activeModelId);
        }
    }

    if (!activeTurn) {
        activeTurn = root.turn_id;
    }

    const branchStack = sidebar.data('branch-stack');
    const showBranchBack = Array.isArray(branchStack) && branchStack.length > 0;
    const isBranchRoot = !root.is_root;

    sidebar.data('branch-root', root.turn_id);

    let turnCounter = 0;
    const rootNodeData = prepareNodeData(root, 0, activeTurn, pageid, cmid, turnCounter, null, false);
    turnCounter++;

    const directBranches = [];
    const childBranches = root.is_root ? (root.direct_branches || []) : (root.children || []);
    if (childBranches.length > 0) {
        const sortedDirectBranches = [...childBranches].sort((a, b) =>
            parseInt(a.turn_id, 10) - parseInt(b.turn_id, 10)
        );
        sortedDirectBranches.forEach((node) => {
            directBranches.push(prepareNodeData(node, 0, activeTurn, pageid, cmid, turnCounter, null, false));
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

const hasUnsavedTurnEvaluationForModel = (pageid, modelid, turnId) => {
    const tabPane = $(`#model-pane-${modelid}-${pageid}`);
    if (tabPane.length === 0) {
        return false;
    }
    const container = tabPane.find('.turn-evaluation-questions');
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
 * Navigate to a specific turn for a model.
 *
 * @param {number} pageid Page ID
 * @param {number} modelid Model ID
 * @param {number} turnId Turn ID
 */
const navigateToTurnForModel = (pageid, modelid, turnId) => {
    setViewingTurnForModel(pageid, modelid, turnId);
    updateTurnDisplayForModel(pageid, modelid);
    updateChatLockStateForModel(pageid, modelid);
    filterMessagesByTurnForModel(pageid, modelid, turnId);
    
    // Load turn evaluation questions
    ensureTurnEvaluationQuestionsRenderedForModel(pageid, modelid, turnId).then(() => {
        loadTurnEvaluationResponsesForModel(pageid, modelid, turnId);
    });
};

/**
 * Create branch from turn for a model.
 *
 * @param {number} cmid Course module ID
 * @param {number} pageid Page ID
 * @param {number} turnId Turn ID
 * @param {jQuery} button Button element
 */
const createBranchFromTurnForModel = (cmid, pageid, turnId, button) => {
    // Similar to turns.js createBranchFromTurn
    // Implementation would create a new branch from the specified turn
    // For now, use a simplified version
    const params = new URLSearchParams();
    params.append('action', 'create_branch');
    params.append('cmid', cmid);
    params.append('pageid', pageid);
    params.append('parent_turn_id', turnId);
    params.append('sesskey', Config.sesskey);
    
    fetch(Config.wwwroot + '/mod/harpiasurvey/ajax.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded'
        },
        body: params.toString()
    })
    .then(response => response.json())
    .then(data => {
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
                sidebar.data('branch-root', turnId);
                sidebar.data('sidebar-view', 'detail');
            }
            loadConversationTree(pageid);
            Notification.addNotification({
                message: data.message || 'Branch created successfully',
                type: 'success'
            });
        } else {
            addError(data.message || 'Failed to create branch');
        }
    })
    .catch(error => {
        // eslint-disable-next-line no-console
        console.error('Error creating branch:', error);
        addError('Error creating branch: ' + error.message);
    });
};

/**
 * Register tree handlers.
 */
function registerTreeHandlers() {
    if (treeHandlersRegistered) {
        return;
    }
    
    // Handle tree node navigation clicks (for list view).
    $(document).on('click', '.multi-model-chat-container[data-behavior="turns"] ~ .conversation-tree-sidebar .conversation-item, .conversation-tree-sidebar .conversation-item', function(e) {
        e.preventDefault();
        const item = $(this);
        // Try multiple possible ID fields
        let rootTurnId = parseInt(item.data('root-turn-id'), 10);
        if (!rootTurnId || isNaN(rootTurnId)) {
            rootTurnId = parseInt(item.data('conversation-id'), 10);
        }
        if (!rootTurnId || isNaN(rootTurnId)) {
            rootTurnId = parseInt(item.data('turn-id'), 10);
        }
        
        const sidebar = item.closest('.conversation-tree-sidebar');
        const pageid = parseInt(sidebar.attr('id').replace('conversation-tree-sidebar-', ''), 10);
        const container = $(`.multi-model-chat-container[data-pageid="${pageid}"]`);
        
        if (!pageid || container.length === 0) {
            return;
        }
        
        const activeTab = container.find('.tab-pane.active');
        const activeModelId = parseInt(activeTab.data('model-id'), 10);

        if (!activeModelId) {
            // If no active tab, use the first model
            const firstTab = container.find('.tab-pane[data-model-id]').first();
            const firstModelId = parseInt(firstTab.data('model-id'), 10);
            if (firstModelId) {
                if (rootTurnId) {
                    sidebar.data('sidebar-view', 'detail');
                    navigateToTurnForModel(pageid, firstModelId, rootTurnId);
                }
            }
            return;
        }

        if (rootTurnId) {
            sidebar.data('sidebar-view', 'detail');
            navigateToTurnForModel(pageid, activeModelId, rootTurnId);
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

        const sidebar = $(`#conversation-tree-sidebar-${pageid}`);
        sidebar.data('sidebar-view', 'list');
        sidebar.data('branch-root', null);
        sidebar.data('branch-stack', []);

        const tree = conversationTrees[pageid];
        if (tree) {
            renderConversationList(pageid, tree.roots);
        } else {
            loadConversationTree(pageid).then(() => {
                const tree = conversationTrees[pageid];
                if (tree) {
                    renderConversationList(pageid, tree.roots);
                }
            });
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

        const container = $(`.multi-model-chat-container[data-pageid="${pageid}"]`);
        const activeTab = container.find('.tab-pane.active');
        const activeModelId = parseInt(activeTab.data('model-id'), 10);
        if (activeModelId) {
            navigateToTurnForModel(pageid, activeModelId, turnId);
        } else {
            loadConversationTree(pageid);
        }
    });

    // Handle create branch button clicks.
    $(document).on('click', '.create-branch-from-tree-btn', function(e) {
        e.preventDefault();
        e.stopPropagation();
        const button = $(this);
        const pageid = parseInt(button.data('pageid') || button.closest('[data-pageid]').data('pageid'), 10);
        const cmid = parseInt(button.data('cmid') || button.closest('[data-cmid]').data('cmid'), 10);
        const turnId = parseInt(button.data('turn-id') || button.closest('[data-turn-id]').data('turn-id'), 10);
        const branchMode = button.data('branch-mode');

        if (!pageid || !turnId) {
            addError('Missing required data to create branch');
            return;
        }
        const container = $(`.multi-model-chat-container[data-pageid="${pageid}"]`);
        const activeTab = container.find('.tab-pane.active');
        const activeModelId = parseInt(activeTab.data('model-id'), 10);
        if (activeModelId && hasUnsavedTurnEvaluationForModel(pageid, activeModelId, turnId)) {
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

            const container = $(`.multi-model-chat-container[data-pageid="${pageid}"]`);
            const activeTab = container.find('.tab-pane.active');
            const activeModelId = parseInt(activeTab.data('model-id'), 10);
            if (activeModelId) {
                navigateToTurnForModel(pageid, activeModelId, turnId);
            } else {
                loadConversationTree(pageid);
            }
            return;
        }
        if (!cmid) {
            addError('Missing required data to create branch');
            return;
        }
        createBranchFromTurnForModel(cmid, pageid, turnId, button);
    });

    // Handle "create direct branch" button clicks.
    $(document).on('click', '.create-direct-branch-btn', function(e) {
        e.preventDefault();
        const button = $(this);
        const pageid = parseInt(button.data('pageid'), 10);
        const cmid = parseInt(button.data('cmid'), 10);
        let parentTurnId = parseInt(button.data('root-turn-id'), 10);
        if (!parentTurnId || isNaN(parentTurnId)) {
            const container = $(`.multi-model-chat-container[data-pageid="${pageid}"]`);
            const activeTab = container.find('.tab-pane.active');
            const activeModelId = parseInt(activeTab.data('model-id'), 10);
            parentTurnId = getViewingTurnForModel(pageid, activeModelId);
        }

        if (!pageid || !cmid || !parentTurnId) {
            addError('Missing required data to create branch');
            return;
        }
        const activeTab = $(`.multi-model-chat-container[data-pageid="${pageid}"]`).find('.tab-pane.active');
        const activeModelId = parseInt(activeTab.data('model-id'), 10);
        if (activeModelId && hasUnsavedTurnEvaluationForModel(pageid, activeModelId, parentTurnId)) {
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

        createBranchFromTurnForModel(cmid, pageid, parentTurnId, button);
    });

    // Handle create new root button clicks.
    $(document).on('click', '.create-root-btn', function(e) {
        e.preventDefault();
        const button = $(this);
        const pageid = parseInt(button.data('pageid'), 10);
        const cmid = parseInt(button.data('cmid'), 10);
        if (!pageid || !cmid) {
            addError('Missing required data to create root');
            return;
        }
        createNewRootForModel(cmid, pageid);
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
        
        // Get current viewing turn for the active model
        const container = $(`.multi-model-chat-container[data-pageid="${pageid}"]`);
        const activeTab = container.find('.tab-pane.active');
        const activeModelId = parseInt(activeTab.data('model-id'), 10);
        const viewingTurn = activeModelId ? getViewingTurnForModel(pageid, activeModelId) : null;
        
        // Build export URL
        const params = new URLSearchParams();
        params.append('action', 'export_conversation');
        params.append('cmid', cmid);
        params.append('pageid', pageid);
        params.append('sesskey', Config.sesskey);
        
        if (viewingTurn) {
            params.append('turn_id', viewingTurn);
        }
        
        // Open export URL in new window/tab to trigger download
        window.open(Config.wwwroot + '/mod/harpiasurvey/ajax.php?' + params.toString(), '_blank');
    });

    treeHandlersRegistered = true;
}

/**
 * Create new root conversation.
 * In multi-model turns, all models share the same conversation tree,
 * so creating a root should navigate all models to the new root.
 *
 * @param {number} cmid Course module ID
 * @param {number} pageid Page ID
 */
const createNewRootForModel = (cmid, pageid) => {
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
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            const container = $(`.multi-model-chat-container[data-pageid="${pageid}"]`);
            const activeTab = container.find('.tab-pane.active');
            const activeModelId = parseInt(activeTab.data('model-id'), 10);
            
            // Clear all message containers and show placeholder
            container.find('.tab-pane[data-model-id]').each(function() {
                const modelid = parseInt($(this).data('model-id'), 10);
                const messagesContainer = $(`#chat-messages-model-${modelid}-${pageid}`);
                messagesContainer.empty();
                messagesContainer.html('<div class="text-center text-muted py-4">' +
                    '<p>{{#str}}nomessagesyet, mod_harpiasurvey{{/str}}</p>' +
                    '</div>');
            });
            
            // Reload conversation tree
            loadConversationTree(pageid).then(() => {
                if (activeModelId && data.turn_id) {
                    // Navigate active model to the new root
                    navigateToTurnForModel(pageid, activeModelId, parseInt(data.turn_id, 10), true);
                } else {
                    // If no active model, navigate first model
                    const firstModel = container.find('.tab-pane[data-model-id]').first();
                    const firstModelId = parseInt(firstModel.data('model-id'), 10);
                    if (firstModelId && data.turn_id) {
                        navigateToTurnForModel(pageid, firstModelId, parseInt(data.turn_id, 10), true);
                    }
                }
            });
            Notification.addNotification({
                message: data.message || 'New conversation created successfully',
                type: 'success'
            });
        } else {
            addError(data.message || 'Failed to create new conversation');
        }
    })
    .catch(error => {
        // eslint-disable-next-line no-console
        console.error('Error creating new root:', error);
        addError('Error creating new root: ' + error.message);
    });
};

/**
 * Register turn handlers.
 */
function registerTurnHandlers() {
    if (turnHandlersRegistered) {
        return;
    }
    
    // Handle toggle previous messages button
    $(document).on('click', '.multi-model-chat-container[data-behavior="turns"] .toggle-previous-messages-btn', function(e) {
        e.preventDefault();
        const button = $(this);
        const pageid = parseInt(button.data('pageid'), 10);
        const modelid = parseInt(button.data('model-id'), 10);
        const messagesContainer = $(`#chat-messages-model-${modelid}-${pageid}`);
        
        const isShowing = messagesContainer.data('show-previous') === true;
        const newState = !isShowing;
        messagesContainer.data('show-previous', newState);
        
        if (newState) {
            messagesContainer.find('.previous-turn-message').show();
            updatePreviousToggleLabelForModel(button, true);
        } else {
            messagesContainer.find('.previous-turn-message').hide();
            updatePreviousToggleLabelForModel(button, false);
        }
    });
    
    // Handle toggle tree button
    $(document).on('click', '.multi-model-chat-container[data-behavior="turns"] .toggle-tree-btn', function(e) {
        e.preventDefault();
        const button = $(this);
        const pageid = parseInt(button.data('pageid'), 10);
        const sidebar = $(`#conversation-tree-sidebar-${pageid}`);
        
        if (sidebar.is(':visible')) {
            sidebar.hide();
            button.removeClass('active');
        } else {
            sidebar.show();
            button.addClass('active');
            loadConversationTree(pageid);
        }
    });
    
    turnHandlersRegistered = true;
}
