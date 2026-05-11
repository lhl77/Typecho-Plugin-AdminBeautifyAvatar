<?php
if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * AdminBeautifyAvatar 路由处理
 */
class AdminBeautifyAvatar_Widget extends Widget_Abstract_Users
{
    public function proxy()
    {
        $token = trim((string)$this->request->get('token'));
        if ($token === '' && !empty($this->request->token)) {
            $token = trim((string)$this->request->token);
        }
        if ($token === '' && !empty($this->request->hash)) {
            // 兼容旧路由参数名。
            $token = trim((string)$this->request->hash);
        }

        $decoded = AdminBeautifyAvatar_Plugin::decodeProxyToken($token);
        if (empty($decoded)) {
            $this->response->setStatus(400);
            echo 'invalid token';
            return;
        }

        $hash = (string)$decoded['hash'];
        $size = (int)$decoded['size'];
        $rating = (string)$decoded['rating'];
        $default = (string)$decoded['default'];

        $opts = AdminBeautifyAvatar_Plugin::options();

        // 防盗链：仅当 Referer 明显来自外部站点时拒绝，空 Referer 允许通过。
        if ((int)$opts['proxy_hotlink_protection'] === 1 && !$this->isAllowedProxyReferer()) {
            $this->response->setStatus(403);
            echo 'forbidden';
            return;
        }

        // 限流：防止第三方批量滥刷代理。
        $limitPerMin = isset($opts['proxy_rate_limit_per_min']) ? (int)$opts['proxy_rate_limit_per_min'] : 180;
        if (!$this->consumeProxyQuota($limitPerMin)) {
            $this->response->setStatus(429);
            $this->response->setHeader('Retry-After', '60');
            echo 'too many requests';
            return;
        }

        if ($size > 1024) {
            $size = 1024;
        }

        // 自定义头像优先返回
        $record = AdminBeautifyAvatar_Plugin::getAvatarByHash($hash);
        if (!empty($record) && !empty($record['avatar_path'])) {
            $url = AdminBeautifyAvatar_Plugin::avatarPathToUrl($record['avatar_path']);

            if (preg_match('#^https?://#i', $url)) {
                $this->response->redirect($url);
                return;
            }

            $local = __TYPECHO_ROOT_DIR__ . '/' . ltrim($record['avatar_path'], '/');
            if (is_file($local)) {
                $mime = !empty($record['mime']) ? (string)$record['mime'] : self::detectMime($local, 'image/jpeg');
                self::outputImage(file_get_contents($local), $mime, 3600);
                return;
            }
        }

        $cacheDays = (int)$opts['proxy_cache_days'];
        if ($cacheDays < 1) {
            $cacheDays = 1;
        }

        $upstream = rtrim(AdminBeautifyAvatar_Plugin::resolveProxyUpstreamBase($this->request->isSecure()), '/');
        $upstreamUrl = $upstream . '/' . $hash
            . '?s=' . $size
            . '&r=' . rawurlencode($rating)
            . '&d=' . rawurlencode($default);

        $cacheDir = __TYPECHO_ROOT_DIR__ . '/var/AvatarProxyCache';
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0755, true);
        }

        $cacheKey = md5($upstreamUrl);
        $cacheFile = $cacheDir . '/' . $cacheKey . '.bin';
        $metaFile = $cacheDir . '/' . $cacheKey . '.meta';

        if (is_file($cacheFile) && (time() - (int)@filemtime($cacheFile) <= $cacheDays * 86400)) {
            $mime = 'image/jpeg';
            if (is_file($metaFile)) {
                $meta = @file_get_contents($metaFile);
                if ($meta) {
                    $mime = trim((string)$meta);
                }
            }

            $content = @file_get_contents($cacheFile);
            if ($content !== false) {
                self::outputImage($content, $mime, $cacheDays * 86400);
                return;
            }
        }

        $fetched = self::fetchRemoteImage($upstreamUrl);
        if ($fetched === false) {
            error_log('AdminBeautifyAvatar proxy fetch failed | URL: ' . $upstreamUrl . ' | hash: ' . $hash . ' | default: ' . $default);
            $this->response->setStatus(502);
            echo 'fetch failed';
            return;
        }

        $blob = $fetched['content'];
        $mime = $fetched['mime'];

        @file_put_contents($cacheFile, $blob);
        @file_put_contents($metaFile, $mime);

        self::outputImage($blob, $mime, $cacheDays * 86400);
    }

    public function local()
    {
        $token = trim((string)$this->request->get('token'));
        if ($token === '' && !empty($this->request->token)) {
            $token = trim((string)$this->request->token);
        }

        $path = AdminBeautifyAvatar_Plugin::decodeLocalAvatarToken($token);
        if ($path === '') {
            $this->response->setStatus(400);
            echo 'invalid token';
            return;
        }

        $opts = AdminBeautifyAvatar_Plugin::options();
        if ((int)$opts['proxy_hotlink_protection'] === 1 && !$this->isAllowedProxyReferer()) {
            $this->response->setStatus(403);
            echo 'forbidden';
            return;
        }

        $abs = __TYPECHO_ROOT_DIR__ . '/' . ltrim($path, '/');
        if (!is_file($abs)) {
            $this->response->setStatus(404);
            echo 'not found';
            return;
        }

        $mime = self::detectMime($abs, 'image/jpeg');
        $blob = @file_get_contents($abs);
        if ($blob === false || $blob === '') {
            $this->response->setStatus(404);
            echo 'not found';
            return;
        }

        self::outputImage($blob, $mime, 86400);
    }

    public function upload()
    {
        if (!AdminBeautifyAvatar_Plugin::isAdminBeautifyReady()) {
            $this->json(array('success' => false, 'message' => '未启用 AdminBeautify，当前插件不可用'), 403);
        }

        if (!$this->user->hasLogin()) {
            $this->json(array('success' => false, 'message' => '请先登录'), 401);
        }

        $opts = AdminBeautifyAvatar_Plugin::options();
        if ((int)$opts['enable_custom_upload'] !== 1) {
            $this->json(array('success' => false, 'message' => '管理员未开启自定义头像上传'), 403);
        }

        $uid = (int)$this->user->uid;
        if (!$this->verifyUserToken($uid)) {
            $this->json(array('success' => false, 'message' => '安全校验失败'), 403);
        }

        if (empty($_FILES['avatar'])) {
            $this->json(array('success' => false, 'message' => '未接收到上传文件'), 400);
        }

        $file = $_FILES['avatar'];
        if (!isset($file['error']) || (int)$file['error'] !== UPLOAD_ERR_OK) {
            $this->json(array('success' => false, 'message' => '文件上传失败'), 400);
        }

        $tmpName = isset($file['tmp_name']) ? (string)$file['tmp_name'] : '';
        if ($tmpName === '' || !is_uploaded_file($tmpName)) {
            $this->json(array('success' => false, 'message' => '非法上传请求'), 400);
        }

        $maxMb = isset($opts['custom_avatar_max_mb']) ? (int)$opts['custom_avatar_max_mb'] : 5;
        if ($maxMb < 1) {
            $maxMb = 1;
        }
        if ($maxMb > 20) {
            $maxMb = 20;
        }
        $maxSize = $maxMb * 1024 * 1024;
        $fileSize = isset($file['size']) ? (int)$file['size'] : 0;
        if ($fileSize <= 0 || $fileSize > $maxSize) {
            $this->json(array('success' => false, 'message' => '文件大小不能超过 ' . $maxMb . 'MB'), 400);
        }

        $mime = self::detectMime($tmpName, '');
        if (!in_array($mime, array('image/jpeg', 'image/png', 'image/gif', 'image/webp'), true)) {
            $this->json(array('success' => false, 'message' => '仅支持 JPG / PNG / GIF / WEBP'), 400);
        }

        $processed = self::processAvatarImage($tmpName, $mime, 320, 84);
        if ($processed === false) {
            $this->json(array('success' => false, 'message' => '头像处理失败，请检查 GD 扩展'), 500);
        }

        $save = $this->storeAvatar($uid, $processed, $opts['storage_driver'], isset($opts['picup_profile']) ? (string)$opts['picup_profile'] : '');
        if ($save['success'] !== true) {
            $this->json(array('success' => false, 'message' => $save['message']), 500);
        }

        $old = AdminBeautifyAvatar_Plugin::getAvatarByUid($uid);
        if (!empty($old) && !empty($old['avatar_path']) && $old['avatar_path'] !== $save['path']) {
            $this->deleteLocalAvatarIfNeeded($old['avatar_path']);
        }

        $this->saveAvatarRecord($uid, array(
            'email_hash' => AdminBeautifyAvatar_Plugin::emailHash($this->user->mail),
            'avatar_source' => 'custom',
            'storage' => $save['storage'],
            'avatar_path' => $save['path'],
            'mime' => $save['mime'],
            'size_bytes' => $save['size_bytes'],
            'width' => 320,
            'height' => 320,
            'meta' => !empty($save['meta']) ? json_encode($save['meta'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '',
        ));

        $this->json(array(
            'success' => true,
            'message' => '头像上传成功',
            'avatarUrl' => AdminBeautifyAvatar_Plugin::avatarPathToUrl($save['path']),
            'storage' => $save['storage'],
        ));
    }

    public function restore()
    {
        if (!AdminBeautifyAvatar_Plugin::isAdminBeautifyReady()) {
            $this->json(array('success' => false, 'message' => '未启用 AdminBeautify，当前插件不可用'), 403);
        }

        if (!$this->user->hasLogin()) {
            $this->json(array('success' => false, 'message' => '请先登录'), 401);
        }

        $uid = (int)$this->user->uid;
        if (!$this->verifyUserToken($uid)) {
            $this->json(array('success' => false, 'message' => '安全校验失败'), 403);
        }

        $old = AdminBeautifyAvatar_Plugin::getAvatarByUid($uid);
        if (!empty($old) && !empty($old['avatar_path'])) {
            $this->deleteLocalAvatarIfNeeded($old['avatar_path']);
        }

        $this->saveAvatarRecord($uid, array(
            'email_hash' => AdminBeautifyAvatar_Plugin::emailHash($this->user->mail),
            'avatar_source' => 'gravatar',
            'storage' => 'local',
            'avatar_path' => '',
            'mime' => '',
            'size_bytes' => 0,
            'width' => 0,
            'height' => 0,
            'meta' => '',
        ));

        $opts = AdminBeautifyAvatar_Plugin::options();
        $resolvedDefault = AdminBeautifyAvatar_Plugin::getResolvedDefaultAvatar();
        $avatarUrl = AdminBeautifyAvatar_Plugin::buildAvatarUrlByHash(
            AdminBeautifyAvatar_Plugin::emailHash($this->user->mail),
            220,
            'X',
            $resolvedDefault,
            $this->request->isSecure()
        );

        $this->json(array(
            'success' => true,
            'message' => '已恢复为 Gravatar 头像',
            'avatarUrl' => $avatarUrl,
        ));
    }

    public function manage()
    {
        if (!AdminBeautifyAvatar_Plugin::isAdminBeautifyReady()) {
            $this->json(array('success' => false, 'message' => '未启用 AdminBeautify，当前插件不可用'), 403);
        }

        if (!$this->user->hasLogin() || !$this->user->pass('administrator', true)) {
            $this->json(array('success' => false, 'message' => '仅管理员可操作'), 403);
        }

        $token = trim((string)$this->request->get('_'));
        $expected = Typecho_Widget::widget('Widget_Security')->getToken('abavatar-manage');
        if ($token === '' || !hash_equals($expected, $token)) {
            $this->json(array('success' => false, 'message' => '安全校验失败'), 403);
        }

        $uid = (int)$this->request->get('uid');
        if ($uid <= 0) {
            $this->json(array('success' => false, 'message' => '无效的用户ID'), 400);
        }

        $action = strtolower(trim((string)$this->request->get('action')));
        if (!in_array($action, array('restore', 'upload'), true)) {
            $this->json(array('success' => false, 'message' => '不支持的操作'), 400);
        }

        if ($action === 'upload') {
            $this->adminUploadForUser($uid);
            return;
        }

        $old = AdminBeautifyAvatar_Plugin::getAvatarByUid($uid);
        if (!empty($old) && !empty($old['avatar_path'])) {
            $this->deleteLocalAvatarIfNeeded($old['avatar_path']);
        }

        $db = Typecho_Db::get();
        $user = $db->fetchRow(
            $db->select('uid', 'mail')
                ->from('table.users')
                ->where('uid = ?', $uid)
                ->limit(1)
        );
        $emailHash = !empty($user['mail']) ? AdminBeautifyAvatar_Plugin::emailHash($user['mail']) : '';
        $mail = !empty($user['mail']) ? (string)$user['mail'] : '';

        $this->saveAvatarRecord($uid, array(
            'email_hash' => $emailHash,
            'avatar_source' => 'gravatar',
            'storage' => 'local',
            'avatar_path' => '',
            'mime' => '',
            'size_bytes' => 0,
            'width' => 0,
            'height' => 0,
            'meta' => '',
        ));

        $opts = AdminBeautifyAvatar_Plugin::options();
        $resolvedDefault = AdminBeautifyAvatar_Plugin::getResolvedDefaultAvatar();
        $avatarUrl = AdminBeautifyAvatar_Plugin::buildAvatarUrlByHash(
            AdminBeautifyAvatar_Plugin::emailHash($mail),
            220,
            'X',
            $resolvedDefault,
            $this->request->isSecure()
        );

        $this->json(array(
            'success' => true,
            'message' => '用户头像已恢复为 Gravatar',
            'avatarUrl' => $avatarUrl,
        ));
    }

    private function adminUploadForUser($uid)
    {
        $uid = (int)$uid;

        $db = Typecho_Db::get();
        $target = $db->fetchRow(
            $db->select('uid', 'mail')
                ->from('table.users')
                ->where('uid = ?', $uid)
                ->limit(1)
        );

        if (empty($target) || empty($target['uid'])) {
            $this->json(array('success' => false, 'message' => '目标用户不存在'), 404);
        }

        if (empty($_FILES['avatar'])) {
            $this->json(array('success' => false, 'message' => '未接收到上传文件'), 400);
        }

        $file = $_FILES['avatar'];
        if (!isset($file['error']) || (int)$file['error'] !== UPLOAD_ERR_OK) {
            $this->json(array('success' => false, 'message' => '文件上传失败'), 400);
        }

        $tmpName = isset($file['tmp_name']) ? (string)$file['tmp_name'] : '';
        if ($tmpName === '' || !is_uploaded_file($tmpName)) {
            $this->json(array('success' => false, 'message' => '非法上传请求'), 400);
        }

        $opts = AdminBeautifyAvatar_Plugin::options();
        $maxMb = isset($opts['custom_avatar_max_mb']) ? (int)$opts['custom_avatar_max_mb'] : 5;
        if ($maxMb < 1) {
            $maxMb = 1;
        }
        if ($maxMb > 20) {
            $maxMb = 20;
        }

        $maxSize = $maxMb * 1024 * 1024;
        $fileSize = isset($file['size']) ? (int)$file['size'] : 0;
        if ($fileSize <= 0 || $fileSize > $maxSize) {
            $this->json(array('success' => false, 'message' => '文件大小不能超过 ' . $maxMb . 'MB'), 400);
        }

        $mime = self::detectMime($tmpName, '');
        if (!in_array($mime, array('image/jpeg', 'image/png', 'image/gif', 'image/webp'), true)) {
            $this->json(array('success' => false, 'message' => '仅支持 JPG / PNG / GIF / WEBP'), 400);
        }

        $processed = self::processAvatarImage($tmpName, $mime, 320, 84);
        if ($processed === false) {
            $this->json(array('success' => false, 'message' => '头像处理失败，请检查 GD 扩展'), 500);
        }

        $old = AdminBeautifyAvatar_Plugin::getAvatarByUid($uid);

        $save = $this->storeAvatar(
            $uid,
            $processed,
            $opts['storage_driver'],
            isset($opts['picup_profile']) ? (string)$opts['picup_profile'] : ''
        );
        if ($save['success'] !== true) {
            $this->json(array('success' => false, 'message' => $save['message']), 500);
        }

        if (!empty($old) && !empty($old['avatar_path']) && $old['avatar_path'] !== $save['path']) {
            $this->deleteLocalAvatarIfNeeded($old['avatar_path']);
        }

        $mail = !empty($target['mail']) ? (string)$target['mail'] : '';
        $this->saveAvatarRecord($uid, array(
            'email_hash' => AdminBeautifyAvatar_Plugin::emailHash($mail),
            'avatar_source' => 'custom',
            'storage' => $save['storage'],
            'avatar_path' => $save['path'],
            'mime' => $save['mime'],
            'size_bytes' => $save['size_bytes'],
            'width' => 320,
            'height' => 320,
            'meta' => !empty($save['meta']) ? json_encode($save['meta'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '',
        ));

        $this->json(array(
            'success' => true,
            'message' => '用户头像上传成功',
            'avatarUrl' => AdminBeautifyAvatar_Plugin::avatarPathToUrl($save['path']),
            'storage' => $save['storage'],
        ));
    }

    private function verifyUserToken($uid)
    {
        $uid = (int)$uid;
        $token = '';

        if (!empty($_SERVER['HTTP_X_ABAVATAR_TOKEN'])) {
            $token = trim((string)$_SERVER['HTTP_X_ABAVATAR_TOKEN']);
        }
        if ($token === '') {
            $token = trim((string)$this->request->get('_token'));
        }

        if ($token === '') {
            return false;
        }

        $expected = Typecho_Widget::widget('Widget_Security')->getToken('abavatar-user-' . $uid);
        return hash_equals($expected, $token);
    }

    private function saveAvatarRecord($uid, array $data)
    {
        $uid = (int)$uid;
        $db = Typecho_Db::get();

        $exists = $db->fetchRow(
            $db->select('id', 'avatar_path')
                ->from('table.' . AdminBeautifyAvatar_Plugin::TABLE_NAME)
                ->where('uid = ?', $uid)
                ->limit(1)
        );

        $now = (int)Typecho_Widget::widget('Widget_Options')->gmtTime;

        $rows = array(
            'uid' => $uid,
            'email_hash' => isset($data['email_hash']) ? (string)$data['email_hash'] : '',
            'avatar_source' => isset($data['avatar_source']) ? (string)$data['avatar_source'] : 'gravatar',
            'storage' => isset($data['storage']) ? (string)$data['storage'] : 'local',
            'avatar_path' => isset($data['avatar_path']) ? (string)$data['avatar_path'] : '',
            'mime' => isset($data['mime']) ? (string)$data['mime'] : '',
            'size_bytes' => isset($data['size_bytes']) ? (int)$data['size_bytes'] : 0,
            'width' => isset($data['width']) ? (int)$data['width'] : 0,
            'height' => isset($data['height']) ? (int)$data['height'] : 0,
            'meta' => isset($data['meta']) ? (string)$data['meta'] : '',
            'updated' => $now,
        );

        if (!empty($exists)) {
            $db->query(
                $db->update('table.' . AdminBeautifyAvatar_Plugin::TABLE_NAME)
                    ->rows($rows)
                    ->where('id = ?', (int)$exists['id'])
            );
        } else {
            $rows['created'] = $now;
            $db->query(
                $db->insert('table.' . AdminBeautifyAvatar_Plugin::TABLE_NAME)
                    ->rows($rows)
            );
        }
    }

    private function deleteLocalAvatarIfNeeded($path)
    {
        $path = trim((string)$path);
        if ($path === '') {
            return;
        }

        if (preg_match('#^https?://#i', $path)) {
            return;
        }

        $prefix = rtrim(AdminBeautifyAvatar_Plugin::LOCAL_UPLOAD_DIR, '/');
        if (strpos($path, $prefix) !== 0) {
            return;
        }

        $abs = __TYPECHO_ROOT_DIR__ . '/' . ltrim($path, '/');
        if (is_file($abs)) {
            @unlink($abs);
        }
    }

    private function storeAvatar($uid, array $processed, $storageDriver, $picupProfile = '')
    {
        $uid = (int)$uid;
        $storageDriver = strtolower(trim((string)$storageDriver));
        $picupProfile = trim((string)$picupProfile);
        if ($storageDriver === '') {
            $storageDriver = 'local';
        }

        $local = $this->storeLocalAvatar($uid, $processed);
        if ($local['success'] !== true) {
            return $local;
        }

        if ($storageDriver === 'picup') {
            $picup = $this->tryStoreByPicUp($local, $picupProfile);
            if ($picup['success'] === true) {
                // 上传成功后可清理本地临时头像文件，保留一份以便回退
                $picup['meta'] = array(
                    'fallback_local' => $local['path'],
                );
                return $picup;
            }
        }

        return $local;
    }

    private function storeLocalAvatar($uid, array $processed)
    {
        $uid = (int)$uid;

        $uploadDir = __TYPECHO_ROOT_DIR__ . AdminBeautifyAvatar_Plugin::LOCAL_UPLOAD_DIR;
        if (!is_dir($uploadDir) && !@mkdir($uploadDir, 0755, true)) {
            return array('success' => false, 'message' => '创建本地头像目录失败');
        }

        $ext = $processed['ext'];
        $fileName = 'u' . $uid . '_' . date('YmdHis') . '_' . substr(md5(Typecho_Common::randString(16)), 0, 10) . '.' . $ext;
        $abs = rtrim($uploadDir, '/') . '/' . $fileName;

        if (@file_put_contents($abs, $processed['binary']) === false) {
            return array('success' => false, 'message' => '写入头像文件失败');
        }

        $rel = rtrim(AdminBeautifyAvatar_Plugin::LOCAL_UPLOAD_DIR, '/') . '/' . $fileName;
        return array(
            'success' => true,
            'storage' => 'local',
            'path' => $rel,
            'mime' => $processed['mime'],
            'size_bytes' => (int)@filesize($abs),
            'meta' => array(),
        );
    }

    private function tryStoreByPicUp(array $local, $profile = '')
    {
        $profile = trim((string)$profile);

        if (!class_exists('TypechoPlugin\\PicUp\\Plugin')) {
            return array('success' => false, 'message' => 'PicUp 插件未启用');
        }

        if (!method_exists('TypechoPlugin\\PicUp\\Plugin', 'uploadHandle')) {
            return array('success' => false, 'message' => 'PicUp 接口不可用');
        }

        $abs = __TYPECHO_ROOT_DIR__ . '/' . ltrim($local['path'], '/');
        if (!is_file($abs)) {
            return array('success' => false, 'message' => '本地临时头像不存在');
        }

        $fake = array(
            'name' => basename($abs),
            'tmp_name' => $abs,
            'size' => (int)@filesize($abs),
            'type' => $local['mime'],
        );

        try {
            if (!isset($_POST) || !is_array($_POST)) {
                $_POST = array();
            }

            $hasOldProfile = array_key_exists('_picup_profile', $_POST);
            $oldProfile = $hasOldProfile ? (string)$_POST['_picup_profile'] : '';
            $hasOldForce = array_key_exists('_picup_force', $_POST);
            $oldForce = $hasOldForce ? (string)$_POST['_picup_force'] : '';

            if ($profile !== '') {
                $_POST['_picup_profile'] = $profile;
            }
            $_POST['_picup_force'] = '1';

            try {
                $result = call_user_func(array('TypechoPlugin\\PicUp\\Plugin', 'uploadHandle'), $fake);
            } finally {
                if ($hasOldProfile) {
                    $_POST['_picup_profile'] = $oldProfile;
                } else {
                    unset($_POST['_picup_profile']);
                }
                if ($hasOldForce) {
                    $_POST['_picup_force'] = $oldForce;
                } else {
                    unset($_POST['_picup_force']);
                }
            }

            if (!is_array($result) || empty($result['path'])) {
                return array('success' => false, 'message' => 'PicUp 上传失败');
            }

            return array(
                'success' => true,
                'storage' => 'picup',
                'path' => (string)$result['path'],
                'mime' => !empty($result['mime']) ? (string)$result['mime'] : $local['mime'],
                'size_bytes' => isset($result['size']) ? (int)$result['size'] : (int)$fake['size'],
                'meta' => array(
                    'picup' => array(
                        'type' => isset($result['type']) ? (string)$result['type'] : '',
                        'profile' => $profile,
                    ),
                ),
            );
        } catch (Exception $e) {
            return array('success' => false, 'message' => 'PicUp 上传异常：' . $e->getMessage());
        } catch (Throwable $e) {
            return array('success' => false, 'message' => 'PicUp 上传异常：' . $e->getMessage());
        }
    }

    private static function processAvatarImage($file, $mime, $target = 320, $quality = 84)
    {
        if (!extension_loaded('gd')) {
            return false;
        }

        $src = null;
        switch ($mime) {
            case 'image/jpeg':
                $src = @imagecreatefromjpeg($file);
                break;
            case 'image/png':
                $src = @imagecreatefrompng($file);
                break;
            case 'image/gif':
                $src = @imagecreatefromgif($file);
                break;
            case 'image/webp':
                if (function_exists('imagecreatefromwebp')) {
                    $src = @imagecreatefromwebp($file);
                }
                break;
        }

        if (!$src) {
            return false;
        }

        $w = imagesx($src);
        $h = imagesy($src);
        if ($w <= 0 || $h <= 0) {
            imagedestroy($src);
            return false;
        }

        $side = min($w, $h);
        $srcX = (int)floor(($w - $side) / 2);
        $srcY = (int)floor(($h - $side) / 2);

        $dst = imagecreatetruecolor($target, $target);
        imagealphablending($dst, true);
        imagesavealpha($dst, true);

        imagecopyresampled($dst, $src, 0, 0, $srcX, $srcY, $target, $target, $side, $side);
        imagedestroy($src);

        ob_start();
        $saved = false;
        $ext = 'jpg';
        $outMime = 'image/jpeg';

        if (function_exists('imagewebp')) {
            $saved = imagewebp($dst, null, $quality);
            if ($saved) {
                $ext = 'webp';
                $outMime = 'image/webp';
            }
        }

        if (!$saved) {
            $saved = imagejpeg($dst, null, min(95, max(60, $quality)));
            $ext = 'jpg';
            $outMime = 'image/jpeg';
        }

        $binary = ob_get_clean();
        imagedestroy($dst);

        if (!$saved || $binary === false || $binary === '') {
            return false;
        }

        return array(
            'binary' => $binary,
            'ext' => $ext,
            'mime' => $outMime,
        );
    }

    private static function detectMime($file, $default = 'application/octet-stream')
    {
        $mime = '';
        if (function_exists('mime_content_type')) {
            $mime = @mime_content_type($file);
        }

        if (!$mime && function_exists('finfo_open')) {
            $fi = @finfo_open(FILEINFO_MIME_TYPE);
            if ($fi) {
                $mime = @finfo_file($fi, $file);
                @finfo_close($fi);
            }
        }

        $mime = trim((string)$mime);
        return $mime !== '' ? $mime : (string)$default;
    }

    private static function fetchRemoteImage($url)
    {
        $content = false;
        $mime = '';

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 8);
            curl_setopt($ch, CURLOPT_TIMEOUT, 12);
            curl_setopt($ch, CURLOPT_USERAGENT, 'AdminBeautifyAvatar/1.0');
            curl_setopt($ch, CURLOPT_HEADER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

            $resp = curl_exec($ch);
            if ($resp !== false) {
                $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
                $statusCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $body = substr($resp, $headerSize);

                if ($statusCode >= 200 && $statusCode < 400 && $body !== false && $body !== '') {
                    $content = $body;
                    $mime = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
                }
            }
            curl_close($ch);
        }

        if ($content === false) {
            $ctx = stream_context_create(array(
                'http' => array(
                    'method' => 'GET',
                    'timeout' => 12,
                    'header' => "User-Agent: AdminBeautifyAvatar/1.0\r\n",
                ),
            ));
            $content = @file_get_contents($url, false, $ctx);
        }

        if ($content === false || $content === '') {
            return false;
        }

        if ($mime === '' || stripos($mime, 'image/') !== 0) {
            $tmp = tempnam(sys_get_temp_dir(), 'abav_');
            @file_put_contents($tmp, $content);
            $mime = self::detectMime($tmp, 'image/jpeg');
            @unlink($tmp);
        }

        if (stripos($mime, 'image/') !== 0) {
            $mime = 'image/jpeg';
        }

        return array(
            'content' => $content,
            'mime' => $mime,
        );
    }

    private static function outputImage($content, $mime = 'image/jpeg', $cacheSeconds = 3600)
    {
        $cacheSeconds = max(60, (int)$cacheSeconds);
        header('Content-Type: ' . $mime);
        header('Cache-Control: public, max-age=' . $cacheSeconds);
        header('Pragma: cache');
        echo $content;
        exit;
    }

    private function getClientIp()
    {
        if (!empty($_SERVER['REMOTE_ADDR'])) {
            $remote = trim((string)$_SERVER['REMOTE_ADDR']);
            if (filter_var($remote, FILTER_VALIDATE_IP)) {
                return $remote;
            }
        }

        if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
            $cfIp = trim((string)$_SERVER['HTTP_CF_CONNECTING_IP']);
            if (filter_var($cfIp, FILTER_VALIDATE_IP)) {
                return $cfIp;
            }
        }

        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $parts = explode(',', (string)$_SERVER['HTTP_X_FORWARDED_FOR']);
            foreach ($parts as $part) {
                $ip = trim((string)$part);
                if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }

        return '0.0.0.0';
    }

    private function isAllowedProxyReferer()
    {
        $referer = isset($_SERVER['HTTP_REFERER']) ? trim((string)$_SERVER['HTTP_REFERER']) : '';
        if ($referer === '') {
            return true;
        }

        $refererHost = strtolower((string)parse_url($referer, PHP_URL_HOST));
        $siteHost = strtolower((string)parse_url((string)$this->options->siteUrl, PHP_URL_HOST));

        if ($refererHost === '' || $siteHost === '') {
            return true;
        }

        if ($refererHost === $siteHost) {
            return true;
        }

        if (substr($refererHost, -strlen('.' . $siteHost)) === '.' . $siteHost) {
            return true;
        }

        if (substr($siteHost, -strlen('.' . $refererHost)) === '.' . $refererHost) {
            return true;
        }

        return false;
    }

    private function consumeProxyQuota($limitPerMinute)
    {
        $limitPerMinute = (int)$limitPerMinute;
        if ($limitPerMinute <= 0) {
            return true;
        }
        if ($limitPerMinute < 30) {
            $limitPerMinute = 30;
        }

        $ip = $this->getClientIp();
        $bucket = date('YmdHi');
        $key = md5($ip . '|' . $bucket);

        $dir = __TYPECHO_ROOT_DIR__ . '/var/AvatarProxyRate';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $file = rtrim($dir, '/') . '/' . $key . '.cnt';
        $fp = @fopen($file, 'c+');
        if (!$fp) {
            return true;
        }

        $allowed = true;
        if (@flock($fp, LOCK_EX)) {
            $raw = stream_get_contents($fp);
            $count = (int)trim((string)$raw);

            if ($count >= $limitPerMinute) {
                $allowed = false;
            } else {
                $count += 1;
                @ftruncate($fp, 0);
                @rewind($fp);
                @fwrite($fp, (string)$count);
                @fflush($fp);
            }

            @flock($fp, LOCK_UN);
        }

        @fclose($fp);

        if (!$allowed) {
            return false;
        }

        // 低频清理过期计数文件，避免目录持续膨胀。
        if (mt_rand(1, 200) === 1) {
            foreach ((array)glob(rtrim($dir, '/') . '/*.cnt') as $old) {
                if (is_file($old) && (time() - (int)@filemtime($old) > 180)) {
                    @unlink($old);
                }
            }
        }

        return true;
    }

    private function json(array $payload, $status = 200)
    {
        $this->response->setStatus((int)$status);
        $this->response->setHeader('Content-Type', 'application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
