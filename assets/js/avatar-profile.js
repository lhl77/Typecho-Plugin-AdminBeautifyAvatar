(function () {
    if (!window.ABAvatarConfig || !window.ABAvatarConfig.enabled) {
        return;
    }

    var cfg = window.ABAvatarConfig;
    var state = {
        cropper: null,
        modal: null,
        cropImage: null,
        fileInput: null,
        msgBox: null,
        submitBtn: null,
        retryTimer: null
    };

    function qs(selector, root) {
        return (root || document).querySelector(selector);
    }

    function qsa(selector, root) {
        return Array.prototype.slice.call((root || document).querySelectorAll(selector));
    }

    function findAvatarNode() {
        var img = qs('.ab-profile-avatar-wrap img.profile-avatar') || qs('img.profile-avatar');
        if (!img) {
            return null;
        }
        var anchor = img.closest('a');
        if (!anchor) {
            return null;
        }
        return {
            img: img,
            anchor: anchor
        };
    }

    function ensureAvatarMask() {
        var node = findAvatarNode();
        if (!node) {
            return false;
        }

        if (cfg.avatarUrl) {
            node.img.setAttribute('src', cfg.avatarUrl);
        }

        if (!node.anchor.classList.contains('ab-avatar-edit-target')) {
            node.anchor.classList.add('ab-avatar-edit-target');
            var mask = document.createElement('span');
            mask.className = 'ab-avatar-edit-mask';
            mask.innerHTML = '<span class="material-icons-round">edit</span>';
            node.anchor.appendChild(mask);
        }

        node.anchor.setAttribute('href', '#');
        node.anchor.setAttribute('title', '编辑头像');

        if (!node.anchor.dataset.abAvatarBind) {
            node.anchor.dataset.abAvatarBind = '1';
            node.anchor.addEventListener('click', function (ev) {
                ev.preventDefault();
                openModal();
            });
        }

        return true;
    }

    function buildModal() {
        if (state.modal) {
            return;
        }

        var html = ''
            + '<div class="ab-avatar-modal" id="ab-avatar-modal">'
            + '  <div class="ab-avatar-dialog">'
            + '    <div class="ab-avatar-dialog-head">'
            + '      <h3>编辑头像</h3>'
            + '      <button type="button" class="ab-avatar-close" aria-label="关闭"><span class="material-icons-round">close</span></button>'
            + '    </div>'
            + '    <div class="ab-avatar-dialog-body">'
            + '      <div class="ab-avatar-actions">'
            + '        <button type="button" class="ab-avatar-btn" data-role="restore">恢复邮箱对应 Gravatar</button>'
            + '        <button type="button" class="ab-avatar-btn ab-primary" data-role="upload"' + (cfg.enableUpload ? '' : ' disabled') + '>上传自定义头像</button>'
            + '      </div>'
            + '      <div class="ab-avatar-note">'
            + (cfg.enableUpload
                ? '支持 JPG/PNG/GIF/WEBP，上传后裁剪为 1:1 并自动压缩。'
                : '管理员尚未开启自定义头像上传。你仍可恢复为 Gravatar。')
            + '      </div>'
            + '      <div class="ab-avatar-msg" id="ab-avatar-msg"></div>'
            + '      <div class="ab-avatar-crop" id="ab-avatar-crop">'
            + '        <div class="ab-avatar-crop-wrap"><img id="ab-avatar-crop-image" alt="crop"></div>'
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

        state.msgBox = qs('#ab-avatar-msg', state.modal);
        state.cropImage = qs('#ab-avatar-crop-image', state.modal);
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
            if (!cfg.enableUpload) {
                showMessage('管理员尚未开启上传功能', 'info');
                return;
            }
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

        var cropWrap = qs('#ab-avatar-crop', state.modal);
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
        var cropWrap = qs('#ab-avatar-crop', state.modal);
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
            fd.append('avatar', blob, 'avatar.jpg');
            fd.append('_token', cfg.token || '');

            fetch(cfg.uploadUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'X-ABAVATAR-TOKEN': cfg.token || ''
                },
                body: fd
            }).then(function (res) {
                return res.json();
            }).then(function (data) {
                if (!data || !data.success) {
                    showMessage((data && data.message) ? data.message : '上传失败', 'error');
                    return;
                }

                showMessage('头像已更新', 'success');
                updateAvatarImage(data.avatarUrl || '');
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
        fd.append('_token', cfg.token || '');

        fetch(cfg.restoreUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'X-ABAVATAR-TOKEN': cfg.token || ''
            },
            body: fd
        }).then(function (res) {
            return res.json();
        }).then(function (data) {
            if (!data || !data.success) {
                showMessage((data && data.message) ? data.message : '恢复失败', 'error');
                return;
            }

            showMessage('已恢复 Gravatar 头像', 'success');
            updateAvatarImage(data.avatarUrl || cfg.avatarUrl || '');
            setTimeout(function () {
                closeModal();
            }, 600);
        }).catch(function () {
            showMessage('网络异常，请稍后重试', 'error');
        });
    }

    function updateAvatarImage(url) {
        if (!url) {
            return;
        }

        qsa('img.profile-avatar, .ab-profile-avatar-wrap img').forEach(function (img) {
            img.setAttribute('src', url + (url.indexOf('?') >= 0 ? '&' : '?') + '_abts=' + Date.now());
        });
    }

    function bootstrap() {
        if (state.retryTimer) {
            clearTimeout(state.retryTimer);
            state.retryTimer = null;
        }

        var tries = 0;
        (function tick() {
            if (ensureAvatarMask()) {
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
