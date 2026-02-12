// Entry point that initializes per-behavior modules.
// Use conditional loading to avoid loading turns.js (and fancytree) for continuous mode
import $ from 'jquery';

export const init = () => {
    // Check for multi-model containers first (they have different class)
    // Check for multi-model turns mode
    const multiModelTurnsContainers = $('.multi-model-chat-container[data-behavior="turns"]');
    if (multiModelTurnsContainers.length > 0) {
        // Use require to conditionally load multi_model_turns (AMD style)
        // eslint-disable-next-line no-undef
        require(['mod_harpiasurvey/multi_model_turns'], (module) => {
            module.init();
        });
        return; // Early return to avoid checking other containers
    }

    // Check for multi-model Q&A mode
    const multiModelQaContainers = $('.multi-model-chat-container[data-behavior="qa"]');
    if (multiModelQaContainers.length > 0) {
        // eslint-disable-next-line no-undef
        require(['mod_harpiasurvey/multi_model_qa'], (module) => {
            module.init();
        });
        return; // Early return to avoid checking other containers
    }
    
    // Check for multi-model continuous mode
    const multiModelContainers = $('.multi-model-chat-container[data-behavior="continuous"]');
    if (multiModelContainers.length > 0) {
        // Use require to conditionally load multi_model_continuous (AMD style)
        // eslint-disable-next-line no-undef
        require(['mod_harpiasurvey/multi_model_continuous'], (module) => {
            module.init();
        });
        return; // Early return to avoid checking other containers
    }

    // Then check for regular containers
    const containers = $('.ai-conversation-container');
    if (containers.length === 0) {
        return;
    }
    const hasTurns = containers.filter('[data-behavior="turns"]').length > 0;
    const hasContinuous = containers.filter('[data-behavior="continuous"]').length > 0;
    const hasQa = containers.filter('[data-behavior="qa"]').length > 0;
    
    // Only load turns if needed (turns mode uses fancytree)
    if (hasTurns) {
        // eslint-disable-next-line no-undef
        require(['mod_harpiasurvey/turns'], (module) => {
            module.init();
        });
    }
    
    // Only load continuous if needed (continuous mode uses simple list, no fancytree)
    if (hasContinuous) {
        // eslint-disable-next-line no-undef
        require(['mod_harpiasurvey/continuous'], (module) => {
            module.init();
        });
    }

    if (hasQa) {
        // eslint-disable-next-line no-undef
        require(['mod_harpiasurvey/qa'], (module) => {
            module.init();
        });
    }
};

// Export functions for direct access if needed (e.g., for testing)
export const initTurns = () => {
    return new Promise((resolve) => {
        // eslint-disable-next-line no-undef
        require(['mod_harpiasurvey/turns'], (module) => {
            module.init();
            resolve();
        });
    });
};

export const initContinuous = () => {
    return new Promise((resolve) => {
        // eslint-disable-next-line no-undef
        require(['mod_harpiasurvey/continuous'], (module) => {
            module.init();
            resolve();
        });
    });
};

export const initMultiModelContinuous = () => {
    return new Promise((resolve) => {
        // eslint-disable-next-line no-undef
        require(['mod_harpiasurvey/multi_model_continuous'], (module) => {
            module.init();
            resolve();
        });
    });
};

export const initMultiModelTurns = () => {
    return new Promise((resolve) => {
        // eslint-disable-next-line no-undef
        require(['mod_harpiasurvey/multi_model_turns'], (module) => {
            module.init();
            resolve();
        });
    });
};

export const initMultiModelQa = () => {
    return new Promise((resolve) => {
        // eslint-disable-next-line no-undef
        require(['mod_harpiasurvey/multi_model_qa'], (module) => {
            module.init();
            resolve();
        });
    });
};
