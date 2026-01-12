// Lazy loader for Fancytree assets (JS + CSS) for the turns sidebar.
import $ from 'jquery';
import Config from 'core/config';

let loadPromise = null;

const injectCssOnce = () => {
    const href = Config.wwwroot + '/mod/harpiasurvey/amd/vendor/fancytree/skin-win8.min.css';
    if (document.querySelector(`link[data-fancytree-css="${href}"]`)) {
        return;
    }
    const link = document.createElement('link');
    link.rel = 'stylesheet';
    link.href = href;
    link.dataset.fancytreeCss = href;
    document.head.appendChild(link);
};

/**
 * Load Fancytree JS (all deps bundle) and CSS. Returns a promise that resolves to $.ui.fancytree.
 *
 * @return {Promise} Resolves when Fancytree is available
 */
export const ensureFancytree = () => {
    if (loadPromise) {
        return loadPromise;
    }

    injectCssOnce();

    loadPromise = new Promise((resolve, reject) => {
        if ($.ui && $.ui.fancytree) {
            resolve($.ui.fancytree);
            return;
        }

        // Temporarily disable AMD define to avoid mismatched anonymous define errors
        // when loading the UMD bundle directly.
        const previousDefine = window.define;
        window.define = undefined;

        const script = document.createElement('script');
        // all-deps bundle includes the minimal jQuery UI pieces Fancytree needs.
        script.src = Config.wwwroot + '/mod/harpiasurvey/amd/vendor/fancytree/jquery.fancytree.min.js';
        script.async = true;
        script.onload = () => {
            window.define = previousDefine;
            if ($.ui && $.ui.fancytree) {
                resolve($.ui.fancytree);
            } else {
                reject(new Error('Fancytree failed to load'));
            }
        };
        script.onerror = () => {
            window.define = previousDefine;
            reject(new Error('Failed to load Fancytree script'));
        };
        document.head.appendChild(script);
    });

    return loadPromise;
};

export default {
    ensureFancytree
};
