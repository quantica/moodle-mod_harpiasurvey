// Shared utilities and state for harpiasurvey chat modules.
import Templates from 'core/templates';
import Notification from 'core/notification';
import Config from 'core/config';
import {get_string as getString} from 'core/str';
import $ from 'jquery';

// Shared state
const currentTurns = {};
const conversationTrees = {};

// Expose shared dependencies
export {Templates, Notification, Config, getString, $};
export {currentTurns, conversationTrees};

// Helper: scroll to bottom
export const scrollToBottom = (pageid) => {
    const messagesContainer = $(`#chat-messages-page-${pageid}`);
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

// Helper: add a notification
export const addError = (message) => {
    Notification.addNotification({message, type: 'error'});
};

// Helper: get cmid for a page
export const getCmid = (pageid) => {
    const container = $(`#chat-messages-page-${pageid}`).closest('.ai-conversation-container');
    return container.data('cmid');
};

// Helper: find root node containing a turn
export const findRootForTurn = (roots, turnId) => {
    const findInNode = (node, targetId) => {
        if (parseInt(node.turn_id, 10) === parseInt(targetId, 10)) {
            return true;
        }
        if (node.direct_branches) {
            for (const db of node.direct_branches) {
                if (parseInt(db.turn_id, 10) === parseInt(targetId, 10)) {
                    return true;
                }
                if (db.children) {
                    for (const child of db.children) {
                        if (findInNode(child, targetId)) {
                            return true;
                        }
                    }
                }
            }
        }
        if (node.children) {
            for (const child of node.children) {
                if (findInNode(child, targetId)) {
                    return true;
                }
            }
        }
        return false;
    };
    for (const root of roots) {
        if (findInNode(root, turnId)) {
            return root;
        }
    }
    return null;
};

// Helper: count nodes (including direct branches)
export const countNodes = (node) => {
    let count = 1;
    if (node.direct_branches) {
        node.direct_branches.forEach(branch => {
            count += countNodes(branch);
        });
    }
    if (node.children) {
        node.children.forEach(child => {
            count += countNodes(child);
        });
    }
    return count;
};

// Helper: calculate hierarchical turn number
export const calculateTurnNumber = (node, turnIndex, parentNumber) => {
    if (node.is_root || node.is_direct_branch) {
        return String(turnIndex + 1);
    }
    const branchIndex = turnIndex + 1;
    return parentNumber ? `${parentNumber}.${branchIndex}` : String(branchIndex);
};

// Helper: render conversation list
export const renderConversationList = (pageid, roots) => {
    const treeContainer = $(`#conversation-tree-${pageid}`);
    const rootsWithCount = roots.map((root, idx) => ({
        ...root,
        conversation_id: root.conversation_id || root.turn_id,
        turn_count: countNodes(root),
        conversation_number: root.conversation_number || idx + 1
    }));
    Templates.render('mod_harpiasurvey/conversation_list', {
        roots: rootsWithCount,
        pageid: pageid
    }).then((html) => {
        treeContainer.html(html);
    }).catch((error) => {
        // eslint-disable-next-line no-console
        console.error('Error rendering conversation list:', error);
    });
};
