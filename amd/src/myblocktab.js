// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/*
 * @package    local_taskflow
 * @author     Jacob Viertel
 * @copyright  Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Dynamic url renderer for back button.
 *
 * @module     local_taskflow/myblocktab
 * @copyright  2025 Wunderbyte GmbH
 * @author     Jacob Viertel
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

export const init = () => {
    /**
     * Updates all links with `taskflow_multiblock` to match current hash
     */
    const updateLinks = () => {
        const hash = window.location.hash.replace('#', '');
        if (!hash) {
            return;
        }

        document.querySelectorAll('a[href*="taskflow_multiblock"]').forEach(link => {
            try {
                const url = new URL(link.href, window.location.origin);
                url.searchParams.set('taskflow_multiblock', hash);
                link.href = url.toString();
            } catch (e) {
                // eslint-disable-next-line no-console
                console.warn('Could not update link', link, e);
            }
        });
    };

    /**
     * Updates all links with `taskflow_multiblock` to match current hash
     */
    const startObserver = () => {
        const target = document.body;
        const observer = new MutationObserver((mutations) => {
            for (const mutation of mutations) {
                if (mutation.addedNodes.length > 0) {
                    updateLinks();
                    break;
                }
            }
        });
        observer.observe(target, {childList: true, subtree: true});
    };

    /**
     * Dispatch own event locationchange.
     */
    const hookHistory = () => {
        ["pushState", "replaceState"].forEach(method => {
            const original = history[method];
            history[method] = function() {
                const result = original.apply(this, arguments);
                window.dispatchEvent(new Event("locationchange"));
                return result;
            };
        });

        window.addEventListener("popstate", () => {
            window.dispatchEvent(new Event("locationchange"));
        });

        window.addEventListener("hashchange", () => {
            window.dispatchEvent(new Event("locationchange"));
        });
    };

    /**
     * Init function calls.
     */
    hookHistory();
    startObserver();
    updateLinks();
    window.addEventListener("locationchange", () => {
        updateLinks();
    });

};
