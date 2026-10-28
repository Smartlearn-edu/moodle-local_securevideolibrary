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
    let currentPlaylistUrl = null;

    /**
     * Fallback to CDN if local bundle is not present.
     *
     * @param {Function} resolve
     * @param {Function} reject
     */
    function loadCdnFallback(resolve, reject) {
        if (window.Hls) {
            resolve(window.Hls);
            return;
        }

        const script = document.createElement('script');
        script.src = 'https://cdn.jsdelivr.net/npm/hls.js@1.5.17/dist/hls.min.js';
        script.onload = function() {
            console.log('[SecureVideo] HLS.js loaded via CDN.');
            resolve(window.Hls);
        };
        script.onerror = function(err) {
            console.error('[SecureVideo] CDN script load failed:', err);
            reject(err);
        };
        document.head.appendChild(script);
    }

    /**
     * Load HLS.js library locally via AMD or fallback to CDN.
     *
     * @return {Promise<window.Hls>}
     */
    function loadHls() {
        return new Promise(function(resolve, reject) {
            if (window.Hls) {
                resolve(window.Hls);
                return;
            }

            // Attempt loading local bundled AMD module first.
            require(['local_securevideolibrary/hls'], function(HlsModule) {
                const Hls = HlsModule || window.Hls;
                if (Hls) {
                    console.log('[SecureVideo] HLS.js loaded via local AMD bundle.');
                    resolve(Hls);
                    return;
                }
                loadCdnFallback(resolve, reject);
            }, function() {
                loadCdnFallback(resolve, reject);
            });
        });
    }

    /**
     * Open the preview player modal and start encrypted HLS stream.
     *
     * @param {string} title Video title.
     * @param {string} playlistUrl Playlist M3U8 URL.
     */
    function openPreview(title, playlistUrl) {
        console.log('[SecureVideo] openPreview called:', {title: title, playlistUrl: playlistUrl});

        const modalEl = document.getElementById('videoPreviewModal');
        const modalTitle = document.getElementById('videoPreviewModalLabel');
        const videoEl = document.getElementById('previewPlayerElement');

        if (!modalEl || !videoEl) {
            console.error('[SecureVideo] videoPreviewModal or previewPlayerElement not found in DOM.');
            return;
        }

        if (title && modalTitle) {
            modalTitle.textContent = title;
        }

        if (!playlistUrl) {
            console.error('[SecureVideo] Empty playlist URL supplied to openPreview.');
            return;
        }

        // Prevent duplicate re-initialization if already active for this URL.
        if (currentPlaylistUrl === playlistUrl && hlsInstance) {
            console.log('[SecureVideo] Video already active for URL:', playlistUrl);
            return;
        }
        currentPlaylistUrl = playlistUrl;

        // Reset player element.
        if (hlsInstance) {
            hlsInstance.destroy();
            hlsInstance = null;
        }
        videoEl.pause();
        videoEl.removeAttribute('src');
        videoEl.load();

        loadHls().then(function(Hls) {
            console.log('[SecureVideo] Initializing player with Hls support:', Hls.isSupported());

            if (Hls.isSupported()) {
                hlsInstance = new Hls({
                    debug: false,
                    enableWorker: true,
                    xhrSetup: function(xhr, url) {
                        xhr.withCredentials = true; // Crucial for Moodle session cookies.
                        if (url && url.indexOf('key.php') !== -1) {
                            try {
                                const urlObj = new URL(url, window.location.href);
                                const token = urlObj.searchParams.get('token');
                                if (token) {
                                    xhr.setRequestHeader('X-Moodle-Player-Token', token);
                                }
                            } catch (e) {
                                const match = /[?&]token=([a-fA-F0-9]+)/.exec(url);
                                if (match) {
                                    xhr.setRequestHeader('X-Moodle-Player-Token', match[1]);
                                }
                            }
                            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
                        }
                    }
                });

                hlsInstance.loadSource(playlistUrl);
                hlsInstance.attachMedia(videoEl);

                hlsInstance.on(Hls.Events.MANIFEST_PARSED, function(event, data) {
                    console.log('[SecureVideo] HLS Manifest parsed. Quality levels:', data.levels.length);
                    videoEl.play().catch(function(err) {
                        console.warn('[SecureVideo] Browser autoplay prevented, waiting for user click:', err);
                    });
                });

                hlsInstance.on(Hls.Events.ERROR, function(event, data) {
                    console.error('[SecureVideo] HLS Error encountered:', data.type, data.details, data);
                    if (data.fatal) {
                        switch (data.type) {
                            case Hls.ErrorTypes.NETWORK_ERROR:
                                console.warn('[SecureVideo] Network error recovery attempt...');
                                hlsInstance.startLoad();
                                break;
                            case Hls.ErrorTypes.MEDIA_ERROR:
                                console.warn('[SecureVideo] Media error recovery attempt...');
                                hlsInstance.recoverMediaError();
                                break;
                            default:
                                console.error('[SecureVideo] Fatal unrecoverable HLS error.');
                                hlsInstance.destroy();
                                break;
                        }
                    }
                });
            } else if (videoEl.canPlayType('application/vnd.apple.mpegurl')) {
                // Native Safari HLS.
                console.log('[SecureVideo] Using native Safari HLS player.');
                videoEl.src = playlistUrl;
                videoEl.play().catch(function(err) {
                    console.warn('[SecureVideo] Safari autoplay prevented:', err);
                });
            } else {
                console.error('[SecureVideo] Neither HLS.js nor native HLS supported.');
            }
        }).catch(function(err) {
            console.error('[SecureVideo] Failed to load HLS:', err);
            Notification.exception(err);
        });

        // Ensure modal display.
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
            console.log('[SecureVideo] Stopping preview playback and destroying HLS instance.');
            currentPlaylistUrl = null;
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
                const target = event.relatedTarget;
                const button = target ? target.closest('.action-preview-video') : null;
                if (button) {
                    const title = button.getAttribute('data-title') || '';
                    const playlistUrl = button.getAttribute('data-playlisturl') || '';
                    openPreview(title, playlistUrl);
                }
            });
        }

        // Global capture-phase listener to catch preview button clicks immediately.
        document.addEventListener('click', function(e) {
            const previewBtn = e.target.closest('.action-preview-video');
            if (!previewBtn) {
                return;
            }

            const title = previewBtn.getAttribute('data-title') || '';
            const playlistUrl = previewBtn.getAttribute('data-playlisturl') || '';
            openPreview(title, playlistUrl);
        }, true);
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
            console.log('[SecureVideo] Initialized with hasProcessing:', hasProcessing);
            setupModalCloseHandler();
            setupPreviewHandlers();
            setupDeleteHandlers();
            setupStatusPolling(hasProcessing);
        }
    };
});
