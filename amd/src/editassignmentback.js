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
 * Dynamic assignments form.
 *
 * @module     local_taskflow/editassignmentform
 * @copyright  2025 Wunderbyte GmbH
 * @author     Jacob Viertel
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define([], function() {

    const getMultiblockHash = () => {
        const currentHash = (window.location.hash || '').replace('#', '');
        if (currentHash.startsWith('multiblock-')) {
            return currentHash;
        }

        const params = new URLSearchParams(window.location.search);
        const queryHash = params.get('taskflow_multiblock') || '';
        if (queryHash.startsWith('multiblock-')) {
            return queryHash;
        }

        return '';
    };

    const buildReturnUrl = () => {
        const source = document.querySelector('[data-returnurl]');
        const params = new URLSearchParams(window.location.search);
        const rawReturnUrl = (source && source.getAttribute('data-returnurl')) || params.get('returnurl');
        if (!rawReturnUrl) {
            return '';
        }

        let decodedReturnUrl = rawReturnUrl;
        try {
            decodedReturnUrl = decodeURIComponent(rawReturnUrl);
        } catch (e) {
            decodedReturnUrl = rawReturnUrl;
        }

        try {
            const url = new URL(decodedReturnUrl, window.location.origin);
            const multiblockHash = getMultiblockHash();
            if (multiblockHash) {
                url.hash = multiblockHash;
            }
            return url.toString();
        } catch (e) {
            return decodedReturnUrl;
        }
    };

    const init = (selector) => {
        const btn = document.querySelector(selector);
        if (!btn) {
            return;
        }

        btn.addEventListener('click', (e) => {
            e.preventDefault();
            const returnurl = buildReturnUrl();
            if (returnurl) {
                window.location.assign(returnurl);
                return;
            }
            // Last fallback.
            window.history.back();
        });
    };

    return {init};
});
