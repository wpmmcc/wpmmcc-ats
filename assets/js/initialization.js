/**
 * WPTSALL Initialization Page JavaScript
 */
(function($) {
    'use strict';

    var InitPage = {
        config: window.wptsallInitConfig || {},
        progressTimer: null,

        init: function() {
            this.cacheElements();
            this.bindEvents();
            this.updateStartButton();
        },

        cacheElements: function() {
            this.$startBtn = $('#wptsall-start-init-btn');
            this.$skipBtn = $('#wptsall-skip-init-btn');
            this.$selectAllBtn = $('#wptsall-select-all-btn');
            this.$deselectAllBtn = $('#wptsall-deselect-all-btn');
            this.$pluginCheckboxes = $('input[name="wptsall_init_plugins[]"]');
            this.$progress = $('#wptsall-init-progress');
            this.$result = $('#wptsall-init-result');
            this.$progressFill = $('.wptsall-progress-fill');
            this.$progressText = $('.wptsall-progress-text');
            this.$rescanBtn = $('#wptsall-rescan-btn');
            this.$v4ScanBtn = $('#wptsall-v4-scan-btn');
            this.$grantConsentBtn = $('#wptsall-grant-consent-btn');
            this.$skipConsentBtn = $('#wptsall-skip-consent-btn');
        },

        t: function(key, fallback) {
            if (this.config.i18n && this.config.i18n[key]) {
                return this.config.i18n[key];
            }
            return fallback || '';
        },

        getRestUrl: function(path) {
            var base = (this.config.restUrl || '').replace(/\/$/, '');
            return base + path;
        },

        request: function(path, data) {
            var url = this.getRestUrl(path);
            var headers = {
                'Content-Type': 'application/json'
            };

            if (this.config.restNonce) {
                headers['X-WP-Nonce'] = this.config.restNonce;
            }

            var options = {
                method: 'POST',
                headers: headers
            };

            if (data) {
                options.body = JSON.stringify(data);
            }

            return fetch(url, options).then(function(response) {
                return response.json().then(function(payload) {
                    if (!response.ok) {
                        var message = payload && payload.message ? payload.message : 'Request failed';
                        throw new Error(message);
                    }
                    return payload;
                });
            });
        },

        bindEvents: function() {
            var self = this;

            if (this.$selectAllBtn.length) {
                this.$selectAllBtn.on('click', function() {
                    self.selectAll();
                });
            }

            if (this.$deselectAllBtn.length) {
                this.$deselectAllBtn.on('click', function() {
                    self.deselectAll();
                });
            }

            if (this.$pluginCheckboxes.length) {
                this.$pluginCheckboxes.on('change', function() {
                    self.updateStartButton();
                });
            }

            if (this.$startBtn.length) {
                this.$startBtn.on('click', function() {
                    self.startScan();
                });
            }

            if (this.$skipBtn.length) {
                this.$skipBtn.on('click', function() {
                    self.skipInitialization();
                });
            }

            if (this.$rescanBtn.length) {
                this.$rescanBtn.on('click', function() {
                    if (confirm(self.t('confirmRescan', 'Rescan all plugins?'))) {
                        self.rescanAll();
                    }
                });
            }

            if (this.$grantConsentBtn.length) {
                this.$grantConsentBtn.on('click', function() {
                    self.grantConsentAndScan();
                });
            }

            if (this.$skipConsentBtn.length) {
                this.$skipConsentBtn.on('click', function() {
                    window.location.href = self.config.homeUrl || window.location.href;
                });
            }

            if (this.$v4ScanBtn.length) {
                this.$v4ScanBtn.on('click', function() {
                    self.runV4Scan();
                });
            }
        },

        updateStartButton: function() {
            if (!this.$startBtn.length) {
                return;
            }
            var selected = this.getSelectedPlugins();
            this.$startBtn.prop('disabled', selected.length === 0);
        },

        getSelectedPlugins: function() {
            var plugins = [];
            this.$pluginCheckboxes.filter(':checked').each(function() {
                plugins.push($(this).val());
            });
            return plugins;
        },

        selectAll: function() {
            this.$pluginCheckboxes.prop('checked', true);
            this.updateStartButton();
        },

        deselectAll: function() {
            this.$pluginCheckboxes.prop('checked', false);
            this.updateStartButton();
        },

        animateProgress: function() {
            var self = this;
            var progress = 0;
            this.stopProgress();
            this.progressTimer = setInterval(function() {
                progress += Math.random() * 15;
                if (progress > 90) {
                    progress = 90;
                }
                self.$progressFill.css('width', progress + '%');
            }, 200);
        },

        stopProgress: function() {
            if (this.progressTimer) {
                clearInterval(this.progressTimer);
                this.progressTimer = null;
            }
        },

        showProgress: function(text) {
            if (this.$progress.length) {
                this.$progress.show();
            }
            if (this.$result.length) {
                this.$result.hide();
            }
            if (this.$progressText.length) {
                this.$progressText.text(text || this.t('scanning', 'Scanning...'));
            }
            if (this.$progressFill.length) {
                this.$progressFill.css('width', '0');
            }
            this.animateProgress();
        },

        showResults: function(stats) {
            if (!this.$result.length) {
                return;
            }

            var html = '<p><strong>' + this.t('scanSummary', 'Scan Results:') + '</strong></p>';
            html += '<p>' + this.t('totalScanned', 'Plugins Scanned: ') + (stats.total_scanned || 0) + '</p>';
            html += '<p>' + this.t('contentPlugins', 'Content Plugins: ') + (stats.content_plugins || 0) + '</p>';
            html += '<p>' + this.t('savedMappings', 'Saved Mappings: ') + (stats.saved || 0) + '</p>';
            $('.wptsall-init-stats').html(html);

            this.$result.show();
        },

        startScan: function() {
            var self = this;
            var selected = this.getSelectedPlugins();

            if (!selected.length) {
                alert(this.t('noPluginsSelected', 'Select at least one plugin.'));
                return;
            }

            this.$startBtn.prop('disabled', true);
            if (this.$skipBtn.length) {
                this.$skipBtn.prop('disabled', true);
            }
            this.showProgress(this.t('scanning', 'Scanning...'));

            this.request('/plugins/scan-all', { plugins: selected })
                .then(function(response) {
                    self.stopProgress();
                    if (self.$progressFill.length) {
                        self.$progressFill.css('width', '100%');
                    }
                    if (self.$progressText.length) {
                        self.$progressText.text(self.t('completed', 'Completed!'));
                    }

                    return self.request('/admin/complete-initialization').then(function() {
                        self.showResults(response);
                        setTimeout(function() {
                            window.location.href = self.config.redirectUrl || window.location.href;
                        }, 800);
                    });
                })
                .catch(function(error) {
                    self.stopProgress();
                    if (self.$progressText.length) {
                        self.$progressText.text(self.t('scanFailed', 'Scan failed: ') + (error.message || 'Unknown error'));
                    }
                    self.$startBtn.prop('disabled', false);
                    if (self.$skipBtn.length) {
                        self.$skipBtn.prop('disabled', false);
                    }
                });
        },

        skipInitialization: function() {
            var self = this;
            this.$skipBtn.prop('disabled', true).text(this.t('processing', 'Processing...'));

            this.request('/admin/complete-initialization')
                .then(function() {
                    window.location.href = self.config.redirectUrl || window.location.href;
                })
                .catch(function(error) {
                    alert(self.t('skipFailed', 'Request failed: ') + (error.message || 'Unknown error'));
                    self.$skipBtn.prop('disabled', false).text(self.t('skipInit', 'Skip Initialization'));
                });
        },

        rescanAll: function() {
            var self = this;
            if (this.$rescanBtn.length) {
                this.$rescanBtn.prop('disabled', true);
            }
            this.showProgress(this.t('scanning', 'Scanning...'));

            this.request('/plugins/scan-all')
                .then(function(response) {
                    self.stopProgress();
                    if (self.$progressFill.length) {
                        self.$progressFill.css('width', '100%');
                    }
                    if (self.$progressText.length) {
                        self.$progressText.text(self.t('completed', 'Completed!'));
                    }
                    self.showResults(response);
                    if (self.$rescanBtn.length) {
                        self.$rescanBtn.prop('disabled', false);
                    }
                })
                .catch(function(error) {
                    self.stopProgress();
                    if (self.$progressText.length) {
                        self.$progressText.text(self.t('scanFailed', 'Scan failed: ') + (error.message || 'Unknown error'));
                    }
                    if (self.$rescanBtn.length) {
                        self.$rescanBtn.prop('disabled', false);
                    }
                });
        },

        grantConsentAndScan: function() {
            var self = this;
            var selectedPlugins = [];
            $('input[name="consent_plugins[]"]:checked').each(function() {
                selectedPlugins.push($(this).val());
            });

            if (!selectedPlugins.length) {
                alert(this.t('noPluginsSelected', 'Select at least one plugin.'));
                return;
            }

            this.$grantConsentBtn.prop('disabled', true).text(this.t('processing', 'Processing...'));

            this.request('/plugin-mappings/consent', { plugins: selectedPlugins })
                .then(function() {
                    return self.request('/plugin-mappings/v4-scan');
                })
                .then(function() {
                    alert(self.t('v4ScanCompleted', 'Deep scan completed!'));
                    window.location.reload();
                })
                .catch(function(error) {
                    alert(self.t('skipFailed', 'Request failed: ') + (error.message || 'Unknown error'));
                    self.$grantConsentBtn.prop('disabled', false).text(self.t('grantConsent', 'Authorize & Scan'));
                });
        },

        runV4Scan: function() {
            var self = this;
            this.$v4ScanBtn.prop('disabled', true).text(this.t('scanning', 'Scanning...'));

            this.request('/plugin-mappings/v4-scan')
                .then(function(response) {
                    var msg = self.t('completed', 'Completed!') + '\n';
                    msg += self.t('v4Scanned', 'Scan successful: ') + (response.scanned || 0) + '\n';
                    msg += self.t('v4Skipped', 'Skipped: ') + (response.skipped || 0) + '\n';
                    if (response.failed > 0) {
                        msg += self.t('v4Failed', 'Failed: ') + response.failed;
                    }
                    alert(msg);
                    window.location.reload();
                })
                .catch(function(error) {
                    alert(self.t('scanFailed', 'Scan failed: ') + (error.message || 'Unknown error'));
                    self.$v4ScanBtn.prop('disabled', false).text(self.t('v4Scan', 'Start Deep Scan'));
                });
        }
    };

    $(document).ready(function() {
        InitPage.init();
    });
})(jQuery);
