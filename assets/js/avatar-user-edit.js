(function () {
    if (!window.ABAvatarAdminConfig || !window.ABAvatarAdminConfig.enabled) {
        return;
    }

    var cfg = window.ABAvatarAdminConfig;
    var state = {
        cropper: null,
        modal: null,
        cropImage: null,
        fileInput: null,
        msgBox: null,
        submitBtn: null,
        preview: null,
        retryTimer: null
    };

    function qs(selector, root) {
        return (root || document).querySelector(selector);
    }

    function qsa(selector, root) {
        return Array.prototype.slice.call((root || document).querySelectorAll(selector));
    }

    function ensureEntry() {
        var wrap = qs('.typecho-page-main[role="form"] .col-mb-12');
        if (!wrap) {
            return false;
        }

        var card = qs('#ab-user-avatar-entry', wrap);
        if (!card) {
            card = document.createElement('section');
            card.id = 'ab-user-avatar-entry';
            card.className = 'ab-user-avatar-entry';
            card.innerHTML = ''
                + '<h3 class="ab-user-avatar-entry-head">用户头像</h3>'
                + '<div class="ab-user-avatar-entry-body">'
                + '  <div class="ab-user-avatar-preview-wrap"><img class="ab-user-avatar-preview" id="ab-user-avatar-preview" alt="avatar" src=""></div>'
                + '  <div class="ab-user-avatar-meta">'
                + '    <div class="ab-avatar-actions">'
                + '      <button type="button" class="ab-avatar-btn ab-primary" data-role="open-editor">修改头像</button>'
                + '      <button type="button" class="ab-avatar-btn" data-role="restore-direct">恢复 Gravatar</button>'
                + '    </div>'
                + '  </div>'
                + '</div>';
            wrap.insertBefore(card, wrap.firstChild);

            qs('[data-role="open-editor"]', card).addEventListener('click', function () {
                openModal();
            });

            qs('[data-role="restore-direct"]', card).addEventListener('click', function () {
                if (!confirm('确认恢复该用户为 Gravatar 头像吗？')) {
                    return;
                }
                restoreAvatar();
            });
        }

        state.preview = qs('#ab-user-avatar-preview');
        if (state.preview) {
            state.preview.setAttribute('src', withTs(cfg.avatarUrl || ''));
        }

        return true;
    }

    function buildModal() {
        if (state.modal) {
            return;
        }

        var html = ''
            + '<div class="ab-avatar-modal" id="ab-admin-avatar-modal">'
            + '  <div class="ab-avatar-dialog">'
            + '    <div class="ab-avatar-dialog-head">'
            + '      <h3>修改用户头像</h3>'
            + '      <button type="button" class="ab-avatar-close" aria-label="关闭"><span class="material-icons-round">close</span></button>'
            + '    </div>'
            + '    <div class="ab-avatar-dialog-body">'
            + '      <div class="ab-avatar-note">可在此为该用户上传头像，或恢复为邮箱对应 Gravatar。</div>'
            + '      <div class="ab-avatar-actions">'
            + '        <button type="button" class="ab-avatar-btn" data-role="restore">恢复邮箱对应 Gravatar</button>'
            + '        <button type="button" class="ab-avatar-btn ab-primary" data-role="upload">上传自定义头像</button>'
            + '      </div>'
            + '      <div class="ab-avatar-note">支持 JPG/PNG/GIF/WEBP，上传后裁剪为 1:1 并自动压缩。</div>'
            + '      <div class="ab-avatar-msg" id="ab-admin-avatar-msg"></div>'
            + '      <div class="ab-avatar-crop" id="ab-admin-avatar-crop">'
            + '        <div class="ab-avatar-crop-wrap"><img id="ab-admin-avatar-crop-image" alt="crop"></div>'
            + '        <div class="ab-avatar-actions">'
            + '          <button type="button" class="ab-avatar-btn ab-primary" data-role="submit">保存头像</button>'
            + '          <button type="button" class="ab-avatar-btn" data-role="cancel-crop">取消裁剪</button>'
            + '        </div>'
            + '      </div>'
            + '    </div>'
            + '  </div>'
            + '</div>';

        var wrap = document.createElement('div');
        wrap.innerHTML = html;
        state.modal = wrap.firstChild;
        document.body.appendChild(state.modal);

        state.msgBox = qs('#ab-admin-avatar-msg', state.modal);
        state.cropImage = qs('#ab-admin-avatar-crop-image', state.modal);
        state.submitBtn = qs('[data-role="submit"]', state.modal);

        state.fileInput = document.createElement('input');
        state.fileInput.type = 'file';
        state.fileInput.accept = 'image/jpeg,image/png,image/gif,image/webp';
        state.fileInput.style.display = 'none';
        document.body.appendChild(state.fileInput);

        bindModalEvents();
    }

    function bindModalEvents() {
        var modal = state.modal;
        if (!modal) {
            return;
        }

        qs('.ab-avatar-close', modal).addEventListener('click', closeModal);
        modal.addEventListener('click', function (ev) {
            if (ev.target === modal) {
                closeModal();
            }
        });

        qs('[data-role="restore"]', modal).addEventListener('click', function () {
            restoreAvatar();
        });

        qs('[data-role="upload"]', modal).addEventListener('click', function () {
            state.fileInput.click();
        });

        qs('[data-role="cancel-crop"]', modal).addEventListener('click', function () {
            resetCropper();
        });

        state.submitBtn.addEventListener('click', function () {
            submitCroppedAvatar();
        });

        state.fileInput.addEventListener('change', function (ev) {
            var file = ev.target.files && ev.target.files[0] ? ev.target.files[0] : null;
            if (!file) {
                return;
            }
            var maxSize = cfg.maxFileSize || 5 * 1024 * 1024;
            if (file.size > maxSize) {
                var maxMb = Math.max(1, Math.round(maxSize / 1024 / 1024));
                showMessage('文件不能超过 ' + maxMb + 'MB', 'error');
                state.fileInput.value = '';
                return;
            }
            beginCrop(file);
        });
    }

    function openModal() {
        buildModal();
        state.modal.classList.add('ab-show');
        hideMessage();
    }

    function closeModal() {
        if (!state.modal) {
            return;
        }
        state.modal.classList.remove('ab-show');
        resetCropper();
        state.fileInput.value = '';
    }

    function showMessage(text, type) {
        if (!state.msgBox) {
            return;
        }
        state.msgBox.className = 'ab-avatar-msg ab-show ab-' + (type || 'info');
        state.msgBox.textContent = text;
    }

    function hideMessage() {
        if (!state.msgBox) {
            return;
        }
        state.msgBox.className = 'ab-avatar-msg';
        state.msgBox.textContent = '';
    }

    function beginCrop(file) {
        hideMessage();

        var cropWrap = qs('#ab-admin-avatar-crop', state.modal);
        cropWrap.classList.add('ab-show');

        var reader = new FileReader();
        reader.onload = function () {
            if (state.cropper) {
                state.cropper.destroy();
                state.cropper = null;
            }

            state.cropImage.setAttribute('src', reader.result);

            if (!window.Cropper) {
                showMessage('裁剪库未加载，无法继续', 'error');
                return;
            }

            state.cropper = new Cropper(state.cropImage, {
                aspectRatio: 1,
                viewMode: 1,
                autoCropArea: 1,
                background: false,
                movable: true,
                zoomable: true,
                scalable: false,
                rotatable: false,
                responsive: true
            });
        };
        reader.readAsDataURL(file);
    }

    function resetCropper() {
        var cropWrap = qs('#ab-admin-avatar-crop', state.modal);
        if (cropWrap) {
            cropWrap.classList.remove('ab-show');
        }

        if (state.cropper) {
            state.cropper.destroy();
            state.cropper = null;
        }

        if (state.cropImage) {
            state.cropImage.setAttribute('src', '');
        }
    }

    function submitCroppedAvatar() {
        if (!state.cropper) {
            showMessage('请先选择并裁剪图片', 'info');
            return;
        }

        state.submitBtn.setAttribute('disabled', 'disabled');

        var canvas = state.cropper.getCroppedCanvas({
            width: 320,
            height: 320,
            imageSmoothingEnabled: true,
            imageSmoothingQuality: 'high'
        });

        canvas.toBlob(function (blob) {
            if (!blob) {
                state.submitBtn.removeAttribute('disabled');
                showMessage('生成头像失败', 'error');
                return;
            }

            var fd = new FormData();
            fd.append('uid', String(cfg.uid));
            fd.append('action', 'upload');
            fd.append('avatar', blob, 'avatar.jpg');
            fd.append('_', cfg.token || '');

            fetch(cfg.manageUrl, {
                method: 'POST',
                credentials: 'same-origin',
                body: fd
            }).then(function (res) {
                return res.json();
            }).then(function (data) {
                if (!data || !data.success) {
                    showMessage((data && data.message) ? data.message : '上传失败', 'error');
                    return;
                }

                cfg.avatarUrl = data.avatarUrl || cfg.avatarUrl;
                refreshAvatarPreview();
                showMessage('头像已更新', 'success');

                setTimeout(function () {
                    closeModal();
                }, 600);
            }).catch(function () {
                showMessage('网络异常，请稍后重试', 'error');
            }).finally(function () {
                state.submitBtn.removeAttribute('disabled');
            });
        }, 'image/jpeg', 0.88);
    }

    function restoreAvatar() {
        hideMessage();

        var fd = new FormData();
        fd.append('uid', String(cfg.uid));
        fd.append('action', 'restore');
        fd.append('_', cfg.token || '');

        fetch(cfg.manageUrl, {
            method: 'POST',
            credentials: 'same-origin',
            body: fd
        }).then(function (res) {
            return res.json();
        }).then(function (data) {
            if (!data || !data.success) {
                showMessage((data && data.message) ? data.message : '恢复失败', 'error');
                return;
            }

            cfg.avatarUrl = data.avatarUrl || cfg.avatarUrl;
            refreshAvatarPreview();
            showMessage('已恢复 Gravatar 头像', 'success');

            setTimeout(function () {
                closeModal();
            }, 500);
        }).catch(function () {
            showMessage('网络异常，请稍后重试', 'error');
        });
    }

    function refreshAvatarPreview() {
        if (state.preview) {
            state.preview.setAttribute('src', withTs(cfg.avatarUrl || ''));
        }
    }

    function withTs(url) {
        if (!url) {
            return '';
        }
        return url + (url.indexOf('?') >= 0 ? '&' : '?') + '_abts=' + Date.now();
    }

    function escapeHtml(input) {
        return String(input || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function bootstrap() {
        if (state.retryTimer) {
            clearTimeout(state.retryTimer);
            state.retryTimer = null;
        }

        var tries = 0;
        (function tick() {
            if (ensureEntry()) {
                return;
            }
            tries += 1;
            if (tries < 15) {
                state.retryTimer = setTimeout(tick, 160);
            }
        })();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bootstrap);
    } else {
        bootstrap();
    }

    document.addEventListener('ab:pageload', function () {
        setTimeout(bootstrap, 0);
    });
})();
