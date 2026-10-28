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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Management UI interactions, status polling, and HLS preview player.
 *
 * @module     local_securevideolibrary/manage
 * @copyright  2025 Mohammad Nabil <mohammad@smartlearn.education>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define(['core/notification', 'core/str'], function(Notification, Str) {
    'use strict';

    let hlsInstance = null;
    let pollInterval = null;

    /**
     * Dynamically load HLS.js library if not already present.
     *
     * @return {Promise<window.Hls>}
     */
    function loadHls() {
        return new Promise(function(resolve, reject) {
            if (window.Hls) {
                resolve(window.Hls);
                return;
            }

            const script = document.createElement('script');
            script.src = 'https://cdn.jsdelivr.net/npm/hls.js@1.5.17/dist/hls.min.js';
            script.onload = function() {
                resolve(window.Hls);
            };
            script.onerror = function(err) {
                reject(err);
            };
            document.head.appendChild(script);
        });
    }

    /**
     * Open the preview player modal and start encrypted HLS stream.
     *
     * @param {string} title Video title.
     * @param {string} playlistUrl Playlist M3U8 URL.
     */
    function openPreview(title, playlistUrl) {
        const modalEl = document.getElementById('videoPreviewModal');
        const modalTitle = document.getElementById('videoPreviewModalLabel');
        const videoEl = document.getElementById('previewPlayerElement');

        if (!modalEl || !videoEl) {
            return;
        }

        if (title && modalTitle) {
            modalTitle.textContent = title;
        }

        // Cleanup existing player instance.
        if (hlsInstance) {
            hlsInstance.destroy();
            hlsInstance = null;
        }
        videoEl.pause();
        videoEl.removeAttribute('src');
        videoEl.load();

        if (playlistUrl) {
            loadHls().then(function(Hls) {
                if (Hls.isSupported()) {
                    hlsInstance = new Hls({
                        xhrSetup: function(xhr) {
                            xhr.withCredentials = true; // Crucial for Moodle session cookie delivery.
                        }
                    });

                    hlsInstance.loadSource(playlistUrl);
                    hlsInstance.attachMedia(videoEl);
                    hlsInstance.on(Hls.Events.MANIFEST_PARSED, function() {
                        videoEl.play().catch(function() {
                            // Autoplay policy prevented playback.
                        });
                    });
                } else if (videoEl.canPlayType('application/vnd.apple.mpegurl')) {
                    // Native Safari HLS.
                    videoEl.src = playlistUrl;
                    videoEl.play().catch(function() {
                        // Browser autoplay policy.
                    });
                }
            }).catch(function(err) {
                Notification.exception(err);
            });
        }

        // Ensure modal display even if data attributes failed.
        if (!modalEl.classList.contains('show')) {
            if (window.bootstrap && window.bootstrap.Modal) {
                const bsModal = window.bootstrap.Modal.getOrCreateInstance(modalEl);
                bsModal.show();
            } else if (window.jQuery && typeof window.jQuery(modalEl).modal === 'function') {
                window.jQuery(modalEl).modal('show');
            } else {
                modalEl.style.display = 'block';
                modalEl.classList.add('show');
                document.body.classList.add('modal-open');
            }
        }
    }

    /**
     * Stop preview playback on modal close.
     */
    function setupModalCloseHandler() {
        const modalEl = document.getElementById('videoPreviewModal');
        const videoEl = document.getElementById('previewPlayerElement');
        if (!modalEl || !videoEl) {
            return;
        }

        const stopPlayback = function() {
            videoEl.pause();
            if (hlsInstance) {
                hlsInstance.destroy();
                hlsInstance = null;
            }
            videoEl.removeAttribute('src');
            videoEl.load();
            modalEl.style.display = '';
            modalEl.classList.remove('show');
            document.body.classList.remove('modal-open');
        };

        modalEl.addEventListener('hidden.bs.modal', stopPlayback);
        if (window.jQuery) {
            window.jQuery(modalEl).on('hidden.bs.modal', stopPlayback);
        }

        const closeButtons = modalEl.querySelectorAll('[data-bs-dismiss="modal"], [data-dismiss="modal"], .btn-close');
        closeButtons.forEach(function(btn) {
            btn.addEventListener('click', stopPlayback);
        });
    }

    /**
     * Setup delete confirmation dialogs.
     */
    function setupDeleteHandlers() {
        document.addEventListener('click', function(e) {
            const deleteBtn = e.target.closest('.action-delete-video');
            if (!deleteBtn) {
                return;
            }

            e.preventDefault();
            const targetUrl = deleteBtn.getAttribute('href');
            const videoTitle = deleteBtn.dataset.title || '';

            Str.get_strings([
                {key: 'confirmdelete', component: 'local_securevideolibrary', param: videoTitle},
                {key: 'delete', component: 'local_securevideolibrary'},
                {key: 'cancel', component: 'moodle'}
            ]).done(function(strings) {
                Notification.confirm(
                    strings[1],
                    strings[0],
                    strings[1],
                    strings[2],
                    function() {
                        window.location.href = targetUrl;
                    }
                );
            }).fail(Notification.exception);
        });
    }

    /**
     * Setup preview click and modal show handlers.
     */
    function setupPreviewHandlers() {
        const modalEl = document.getElementById('videoPreviewModal');
        if (modalEl) {
            modalEl.addEventListener('show.bs.modal', function(event) {
                const button = event.relatedTarget;
                if (!button) {
                    return;
                }
                const title = button.getAttribute('data-title') || '';
                const playlistUrl = button.getAttribute('data-playlisturl') || '';
                openPreview(title, playlistUrl);
            });
        }

        document.addEventListener('click', function(e) {
            const previewBtn = e.target.closest('.action-preview-video');
            if (!previewBtn) {
                return;
            }

            const title = previewBtn.getAttribute('data-title') || '';
            const playlistUrl = previewBtn.getAttribute('data-playlisturl') || '';
            openPreview(title, playlistUrl);
        });
    }

    /**
     * Setup polling to refresh page when videos finish background transcoding.
     *
     * @param {boolean} hasProcessing Whether any media items are queued or transcoding.
     */
    function setupStatusPolling(hasProcessing) {
        if (!hasProcessing) {
            return;
        }

        pollInterval = setInterval(function() {
            window.location.reload();
        }, 6000);
    }

    return {
        /**
         * Initialize module.
         *
         * @param {boolean} hasProcessing
         */
        init: function(hasProcessing) {
            setupModalCloseHandler();
            setupPreviewHandlers();
            setupDeleteHandlers();
            setupStatusPolling(hasProcessing);
        }
    };
});
