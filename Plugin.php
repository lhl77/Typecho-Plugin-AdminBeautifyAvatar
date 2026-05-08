<?php
if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * AdminBeautify 专用头像管理插件
 *
 * @package AB-Avatar
 * @author  LHL
 * @version 1.0.0
 * @link    https://github.com/lhl77/Typecho-Plugin-AdminBeautifyAvatar
 */
class AdminBeautifyAvatar_Plugin implements Typecho_Plugin_Interface
{
    const TABLE_NAME = 'ab_avatar_user';
    const LOCAL_UPLOAD_DIR = '/usr/plugins/AdminBeautifyAvatar/uploads';
    const ASSET_VERSION = '1.0.0';

    private static $hashCache = array();

    public static function activate()
    {
        Helper::addRoute('ab_avatar_proxy', '/ab-avatar/gravatar/[token:string]', 'AdminBeautifyAvatar_Widget', 'proxy');
        Helper::addRoute('ab_avatar_local', '/ab-avatar/local/[token:string]', 'AdminBeautifyAvatar_Widget', 'local');
        Helper::addRoute('ab_avatar_upload', '/ab-avatar/upload', 'AdminBeautifyAvatar_Widget', 'upload');
        Helper::addRoute('ab_avatar_restore', '/ab-avatar/restore', 'AdminBeautifyAvatar_Widget', 'restore');
        Helper::addRoute('ab_avatar_manage', '/ab-avatar/manage', 'AdminBeautifyAvatar_Widget', 'manage');

        Typecho_Plugin::factory('admin/footer.php')->end = array(__CLASS__, 'renderAdminFooter');

        // 前台评论头像直接走插件逻辑（镜像/代理/自定义头像）
        Typecho_Plugin::factory('Widget_Abstract_Comments')->gravatar = array(__CLASS__, 'renderCommentAvatar');
        // 前台其余头像通过 JS 统一替换 gravatar 地址
        Typecho_Plugin::factory('Widget_Archive')->footer = array(__CLASS__, 'renderArchiveFooter');

        self::installTable();
        self::ensureDefaultConfig();

        return _t('AdminBeautifyAvatar 已启用');
    }

    public static function deactivate()
    {
        Helper::removeRoute('ab_avatar_proxy');
        Helper::removeRoute('ab_avatar_local');
        Helper::removeRoute('ab_avatar_upload');
        Helper::removeRoute('ab_avatar_restore');
        Helper::removeRoute('ab_avatar_manage');

        return _t('AdminBeautifyAvatar 已禁用');
    }

    public static function config(Typecho_Widget_Helper_Form $form)
    {
        self::ensureDefaultConfig();

        $opts = self::options();
        $adminReady = self::isAdminBeautifyReady();

        if (!$adminReady) {
            echo '<div style="margin:8px 0 14px;padding:12px 14px;border-radius:12px;border:1px solid #f87171;background:#fff1f2;color:#b91c1c">'
                . '<strong>未检测到 AdminBeautify</strong>'
                . '<p style="margin:6px 0 0">本插件依赖 AdminBeautify。请先启用 AdminBeautify 后再使用。</p>'
                . '</div>';

            // 仍注册隐藏字段，避免核心配置回填时 getInput($key) 为空导致 fatal。
            $hiddenFields = $opts;
            try {
                $raw = Typecho_Widget::widget('Widget_Options')->plugin('AdminBeautifyAvatar');
                foreach ((array)$raw as $rawName => $rawValue) {
                    $rawName = trim((string)$rawName);
                    if ($rawName !== '') {
                        $hiddenFields[$rawName] = $rawValue;
                    }
                }
            } catch (Exception $e) {
            }

            foreach ($hiddenFields as $fieldName => $fieldValue) {
                $hidden = new Typecho_Widget_Helper_Form_Element_Hidden(
                    (string)$fieldName,
                    null,
                    is_scalar($fieldValue) ? (string)$fieldValue : ''
                );
                $form->addInput($hidden);
            }

            return;
        }

        $source = new Typecho_Widget_Helper_Form_Element_Select(
            'gravatar_source',
            array(
                'official' => _t('Gravatar 官方（www.gravatar.com）'),
                'loli'     => _t('loli 镜像（gravatar.loli.net）'),
                'cravatar' => _t('Cravatar（cravatar.cn）'),
                'custom'   => _t('自定义域名'),
                'proxy'    => _t('本地代理（推荐，支持缓存与自定义头像优先）'),
            ),
            $opts['gravatar_source'],
            _t('前台 Gravatar 源'),
            _t('用于前台头像展示。选择“本地代理”时将通过本地 PHP 代理抓取头像。')
        );
        $form->addInput($source);

        $customBase = new Typecho_Widget_Helper_Form_Element_Text(
            'custom_gravatar_base',
            null,
            $opts['custom_gravatar_base'],
            _t('自定义头像域名'),
            _t('仅在“自定义域名”时生效。示例：https://example.com/avatar（末尾不加斜线）')
        );
        $form->addInput($customBase);

        $proxyUpstream = new Typecho_Widget_Helper_Form_Element_Select(
            'proxy_upstream',
            array(
                'official' => _t('官方（www.gravatar.com）'),
                'loli'     => _t('loli 镜像（gravatar.loli.net）'),
                'cravatar' => _t('Cravatar（cravatar.cn）'),
                'custom'   => _t('自定义域名'),
            ),
            $opts['proxy_upstream'],
            _t('本地代理上游源'),
            _t('仅在“前台 Gravatar 源”选择“本地代理”时生效。')
        );
        $form->addInput($proxyUpstream);

        $cacheDays = new Typecho_Widget_Helper_Form_Element_Text(
            'proxy_cache_days',
            null,
            (string)$opts['proxy_cache_days'],
            _t('代理缓存天数'),
            _t('本地代理缓存有效期，默认 7 天。')
        );
        $cacheDays->addRule('isInteger', _t('请填写整数')); 
        $form->addInput($cacheDays);

        $proxyRate = new Typecho_Widget_Helper_Form_Element_Text(
            'proxy_rate_limit_per_min',
            null,
            (string)$opts['proxy_rate_limit_per_min'],
            _t('本地代理限流（每 IP / 分钟）'),
            _t('用于防滥用，默认 180。超过后将返回 429。')
        );
        $proxyRate->addRule('isInteger', _t('请填写整数'));
        $form->addInput($proxyRate);

        $proxyHotlink = new Typecho_Widget_Helper_Form_Element_Radio(
            'proxy_hotlink_protection',
            array(
                '1' => _t('开启'),
                '0' => _t('关闭'),
            ),
            (string)$opts['proxy_hotlink_protection'],
            _t('本地代理防盗链'),
            _t('开启后，Referer 明显来自外部站点时将拒绝请求。')
        );
        $form->addInput($proxyHotlink);

        $enableUpload = new Typecho_Widget_Helper_Form_Element_Radio(
            'enable_custom_upload',
            array(
                '0' => _t('关闭（默认）'),
                '1' => _t('开启'),
            ),
            (string)$opts['enable_custom_upload'],
            _t('允许用户上传自定义头像'),
            _t('开启后，个人设置页头像点击弹窗可上传自定义头像。')
        );
        $form->addInput($enableUpload);

        $avatarMaxMb = new Typecho_Widget_Helper_Form_Element_Text(
            'custom_avatar_max_mb',
            null,
            (string)$opts['custom_avatar_max_mb'],
            _t('自定义头像大小限制（MB）'),
            _t('上传原图大小限制，范围 1-20 MB，默认 5 MB。')
        );
        $avatarMaxMb->addRule('isInteger', _t('请填写整数'));
        $form->addInput($avatarMaxMb);

        $picupInfo = self::getPicUpProfiles();
        self::appendPicUpHintToForm($form, $picupInfo);

        $storageDriver = new Typecho_Widget_Helper_Form_Element_Select(
            'storage_driver',
            array(
                'local' => _t('本地存储（默认）'),
                'picup' => _t('PicUp（实验支持）'),
            ),
            $opts['storage_driver'],
            _t('头像存储后端'),
            _t('默认本地；PicUp 为实验支持，失败会自动回落到本地存储。')
        );
        $form->addInput($storageDriver);

        $picupOptions = array(
            '' => _t('自动（使用 PicUp 默认策略）'),
        );
        foreach ($picupInfo['profiles'] as $profileKey => $profileLabel) {
            $label = ($profileKey === $picupInfo['default'])
                ? _t('策略：%s（PicUp 默认）', $profileLabel)
                : _t('策略：%s', $profileLabel);
            $picupOptions[$profileKey] = $label;
        }

        $picupProfile = new Typecho_Widget_Helper_Form_Element_Select(
            'picup_profile',
            $picupOptions,
            (string)$opts['picup_profile'],
            _t('PicUp 上传策略'),
            _t('当“头像存储后端”为 PicUp 时生效。策略来自 PicUp 的配置方案（Profile）。')
        );
        $form->addInput($picupProfile);

        $replaceFront = new Typecho_Widget_Helper_Form_Element_Radio(
            'replace_front_avatar',
            array(
                '1' => _t('开启'),
                '0' => _t('关闭'),
            ),
            (string)$opts['replace_front_avatar'],
            _t('前台自动替换 Gravatar 地址'),
            _t('开启后，在前台页面自动把头像地址替换为当前插件配置的源。')
        );
        $form->addInput($replaceFront);

        self::renderConfigFieldToggles();

        self::renderManagePanel();
    }

    public static function personalConfig(Typecho_Widget_Helper_Form $form)
    {
    }

    public static function ensureDefaultConfig()
    {
        try {
            Typecho_Widget::widget('Widget_Options')->plugin('AdminBeautifyAvatar');
            return;
        } catch (Exception $e) {
        }

        Helper::configPlugin('AdminBeautifyAvatar', array(
            'gravatar_source'      => 'proxy',
            'custom_gravatar_base' => '',
            'proxy_upstream'       => 'official',
            'proxy_cache_days'     => '7',
            'proxy_rate_limit_per_min' => '180',
            'proxy_hotlink_protection' => '1',
            'enable_custom_upload' => '0',
            'custom_avatar_max_mb' => '5',
            'storage_driver'       => 'local',
            'picup_profile'        => '',
            'replace_front_avatar' => '1',
        ));
    }

    public static function options()
    {
        $defaults = array(
            'gravatar_source'      => 'proxy',
            'custom_gravatar_base' => '',
            'proxy_upstream'       => 'official',
            'proxy_cache_days'     => 7,
            'proxy_rate_limit_per_min' => 180,
            'proxy_hotlink_protection' => 1,
            'enable_custom_upload' => 0,
            'custom_avatar_max_mb' => 5,
            'storage_driver'       => 'local',
            'picup_profile'        => '',
            'replace_front_avatar' => 1,
        );

        try {
            $opt = Typecho_Widget::widget('Widget_Options')->plugin('AdminBeautifyAvatar');

            $source = isset($opt->gravatar_source) ? trim((string)$opt->gravatar_source) : $defaults['gravatar_source'];
            if (!in_array($source, array('official', 'loli', 'cravatar', 'custom', 'proxy'), true)) {
                $source = $defaults['gravatar_source'];
            }

            $upstream = isset($opt->proxy_upstream) ? trim((string)$opt->proxy_upstream) : $defaults['proxy_upstream'];
            if (!in_array($upstream, array('official', 'loli', 'cravatar', 'custom'), true)) {
                $upstream = $defaults['proxy_upstream'];
            }

            $cacheDays = isset($opt->proxy_cache_days) ? (int)$opt->proxy_cache_days : (int)$defaults['proxy_cache_days'];
            if ($cacheDays < 1) {
                $cacheDays = 1;
            }
            if ($cacheDays > 60) {
                $cacheDays = 60;
            }

            $proxyRateLimit = isset($opt->proxy_rate_limit_per_min)
                ? (int)$opt->proxy_rate_limit_per_min
                : (int)$defaults['proxy_rate_limit_per_min'];
            if ($proxyRateLimit < 30) {
                $proxyRateLimit = 30;
            }
            if ($proxyRateLimit > 2000) {
                $proxyRateLimit = 2000;
            }

            $proxyHotlink = isset($opt->proxy_hotlink_protection)
                ? (int)$opt->proxy_hotlink_protection
                : (int)$defaults['proxy_hotlink_protection'];

            $avatarMaxMb = isset($opt->custom_avatar_max_mb)
                ? (int)$opt->custom_avatar_max_mb
                : (int)$defaults['custom_avatar_max_mb'];
            if ($avatarMaxMb < 1) {
                $avatarMaxMb = 1;
            }
            if ($avatarMaxMb > 20) {
                $avatarMaxMb = 20;
            }

            $enableUpload = isset($opt->enable_custom_upload) ? (int)$opt->enable_custom_upload : (int)$defaults['enable_custom_upload'];
            $replaceFront = isset($opt->replace_front_avatar) ? (int)$opt->replace_front_avatar : (int)$defaults['replace_front_avatar'];

            $storageDriver = isset($opt->storage_driver) ? trim((string)$opt->storage_driver) : $defaults['storage_driver'];
            if (!in_array($storageDriver, array('local', 'picup'), true)) {
                $storageDriver = 'local';
            }

            $picupProfile = isset($opt->picup_profile) ? trim((string)$opt->picup_profile) : '';
            $picupInfo = self::getPicUpProfiles();
            if ($picupProfile !== '' && !isset($picupInfo['profiles'][$picupProfile])) {
                $picupProfile = '';
            }

            $custom = isset($opt->custom_gravatar_base) ? trim((string)$opt->custom_gravatar_base) : '';

            return array(
                'gravatar_source'      => $source,
                'custom_gravatar_base' => $custom,
                'proxy_upstream'       => $upstream,
                'proxy_cache_days'     => $cacheDays,
                'proxy_rate_limit_per_min' => $proxyRateLimit,
                'proxy_hotlink_protection' => $proxyHotlink ? 1 : 0,
                'enable_custom_upload' => $enableUpload ? 1 : 0,
                'custom_avatar_max_mb' => $avatarMaxMb,
                'storage_driver'       => $storageDriver,
                'picup_profile'        => $picupProfile,
                'replace_front_avatar' => $replaceFront ? 1 : 0,
            );
        } catch (Exception $e) {
            return $defaults;
        }
    }

    private static function getPicUpProfiles()
    {
        $result = array(
            'enabled' => false,
            'default' => '',
            'profiles' => array(),
        );

        try {
            $result['enabled'] = Typecho_Plugin::exists('PicUp');
        } catch (Exception $e) {
            $result['enabled'] = false;
        }

        try {
            $picup = Typecho_Widget::widget('Widget_Options')->plugin('PicUp');
            $default = isset($picup->defaultProfile) ? trim((string)$picup->defaultProfile) : '';
            if ($default === '') {
                $default = 'default';
            }
            $result['default'] = $default;

            $configJson = isset($picup->configJson) ? (string)$picup->configJson : '';
            $config = json_decode($configJson, true);
            if (is_array($config)) {
                foreach ($config as $profileKey => $profileCfg) {
                    $key = trim((string)$profileKey);
                    if ($key === '') {
                        continue;
                    }
                    $result['profiles'][$key] = $key;
                }
            }

            if ($result['default'] !== '' && !isset($result['profiles'][$result['default']])) {
                $result['profiles'][$result['default']] = $result['default'];
            }
            ksort($result['profiles']);
        } catch (Exception $e) {
        }

        return $result;
    }

    private static function appendPicUpHintToForm(Typecho_Widget_Helper_Form $form, array $picupInfo)
    {
        $status = !empty($picupInfo['enabled'])
            ? '（已检测到 PicUp）'
            : '（未检测到 PicUp，选择 PicUp 存储将自动回退本地）';

        $html = '<div class="ab-avatar-picup-hint">'
            . '<strong>PicUp 插件地址：</strong> <a href="https://github.com/lhl77/Typecho-Plugin-PicUp" target="_blank" rel="noopener">https://github.com/lhl77/Typecho-Plugin-PicUp</a>'
            . '<span style="margin-left:8px;opacity:.8">' . $status . '</span>'
            . '</div>';

        $row = new Typecho_Widget_Helper_Layout('ul', array('class' => 'typecho-option'));
        $li = new Typecho_Widget_Helper_Layout('li');
        $li->html($html);
        $row->addItem($li);
        $form->addItem($row);
    }

    private static function renderConfigFieldToggles()
    {
        echo '<style>'
            . '.ab-avatar-picup-hint{margin:10px 0 14px;padding:10px 12px;border:1px solid #d1d5db;border-radius:10px;background:#f8fafc;color:#334155;font-size:12px}'
            . '.ab-avatar-picup-hint a{color:#2563eb;text-decoration:none;font-weight:600}'
            . '[data-theme="dark"] .ab-avatar-picup-hint{background:var(--md-dark-surface-container,#211f26);border-color:var(--md-dark-outline-variant,#49454f);color:var(--md-dark-on-surface,#e6e1e5)}'
            . '[data-theme="dark"] .ab-avatar-picup-hint a{color:var(--md-dark-primary,#d0bcff)}'
            . '</style>';

        echo '<script>(function(){'
            . 'function itemByName(name){'
            . 'var el=document.querySelector("[name=\""+name+"\"]")||document.querySelector("[name=\""+name+"[]\"]");'
            . 'if(!el)return null;'
            . 'return el.closest("li")||el.closest(".typecho-option")||el.parentNode;'
            . '}'
            . 'function setVisible(name,show){'
            . 'var item=itemByName(name);if(!item)return;item.style.display=show?"":"none";'
            . '}'
            . 'function refresh(){'
            . 'var sourceEl=document.querySelector("[name=\"gravatar_source\"]");'
            . 'var source=sourceEl?sourceEl.value:"";'
            . 'setVisible("custom_gravatar_base",source==="custom");'
            . 'var proxyOn=(source==="proxy");'
            . 'setVisible("proxy_upstream",proxyOn);'
            . 'setVisible("proxy_cache_days",proxyOn);'
            . 'setVisible("proxy_rate_limit_per_min",proxyOn);'
            . 'setVisible("proxy_hotlink_protection",proxyOn);'
                . 'var uploadEls=document.querySelectorAll("[name=\"enable_custom_upload\"]");'
                . 'var uploadOn=false;'
                . 'for(var i=0;i<uploadEls.length;i++){if(uploadEls[i].checked){uploadOn=(uploadEls[i].value==="1");break;}}'
                . 'setVisible("custom_avatar_max_mb",uploadOn);'
            . 'var storageEl=document.querySelector("[name=\"storage_driver\"]");'
            . 'var storage=storageEl?storageEl.value:"local";'
            . 'setVisible("picup_profile",storage==="picup");'
            . '}'
            . 'document.addEventListener("change",function(e){'
            . 'if(!e||!e.target)return;'
                . 'if(e.target.name==="gravatar_source"||e.target.name==="storage_driver"||e.target.name==="enable_custom_upload"){refresh();}'
            . '});'
            . 'if(document.readyState==="loading"){document.addEventListener("DOMContentLoaded",refresh);}else{refresh();}'
            . 'document.addEventListener("ab:pageload",refresh);'
            . '})();</script>';
    }

    public static function installTable()
    {
        $db = Typecho_Db::get();
        $table = $db->getPrefix() . self::TABLE_NAME;

        $sql = "CREATE TABLE IF NOT EXISTS `{$table}` (
          `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
          `uid` int(10) unsigned NOT NULL DEFAULT 0,
          `email_hash` char(32) NOT NULL DEFAULT '',
          `avatar_source` varchar(16) NOT NULL DEFAULT 'gravatar',
          `storage` varchar(32) NOT NULL DEFAULT 'local',
          `avatar_path` varchar(255) NOT NULL DEFAULT '',
          `mime` varchar(64) NOT NULL DEFAULT '',
          `size_bytes` int(10) unsigned NOT NULL DEFAULT 0,
          `width` smallint(5) unsigned NOT NULL DEFAULT 0,
          `height` smallint(5) unsigned NOT NULL DEFAULT 0,
          `meta` text NULL,
          `created` int(10) unsigned NOT NULL DEFAULT 0,
          `updated` int(10) unsigned NOT NULL DEFAULT 0,
          PRIMARY KEY (`id`),
          UNIQUE KEY `uniq_uid` (`uid`),
          KEY `idx_email_hash` (`email_hash`),
          KEY `idx_source` (`avatar_source`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

        $db->query($sql);
    }

    public static function isAdminBeautifyReady()
    {
        try {
            return Typecho_Plugin::exists('AdminBeautify');
        } catch (Exception $e) {
            return false;
        }
    }

    public static function renderCommentAvatar($size, $rating, $default, $comments)
    {
        if (!self::isAdminBeautifyReady()) {
            $raw = Typecho_Common::gravatarUrl($comments->mail, (int)$size, (string)$rating, (string)$default, $comments->request->isSecure());
            echo '<img class="avatar" loading="lazy" src="' . htmlspecialchars($raw) . '" alt="'
                . htmlspecialchars($comments->author) . '" width="' . (int)$size . '" height="' . (int)$size . '" />';
            return;
        }

        $hash = self::emailHash($comments->mail);
        $record = self::getAvatarByHash($hash);

        if (!empty($record) && !empty($record['avatar_path'])) {
            $url = self::avatarPathToUrl($record['avatar_path']);
        } else {
            $url = self::buildAvatarUrlByHash($hash, (int)$size, (string)$rating, (string)$default, $comments->request->isSecure());
        }

        echo '<img class="avatar" loading="lazy" src="' . htmlspecialchars($url) . '" alt="'
            . htmlspecialchars($comments->author) . '" width="' . (int)$size . '" height="' . (int)$size . '" />';
    }

    public static function buildAvatarUrlByHash($hash, $size = 80, $rating = 'X', $default = 'mm', $isSecure = true)
    {
        $opts = self::options();
        $size = max(1, (int)$size);
        $rating = (string)$rating;
        $default = (string)$default;

        if ($opts['gravatar_source'] === 'proxy') {
            $base = rtrim(Typecho_Common::url('/ab-avatar/gravatar', Typecho_Widget::widget('Widget_Options')->index), '/');
            $token = self::encodeProxyToken((string)$hash, $size, $rating, $default);
            return $base . '/' . $token;
        }

        $host = self::resolveMirrorBase($opts['gravatar_source'], $opts['custom_gravatar_base'], $isSecure);
        $query = '?s=' . $size . '&r=' . rawurlencode($rating) . '&d=' . rawurlencode($default);
        return rtrim($host, '/') . '/' . strtolower((string)$hash) . $query;
    }

    public static function resolveMirrorBase($source, $customBase = '', $isSecure = true)
    {
        $source = strtolower(trim((string)$source));

        if ($source === 'custom') {
            $base = trim((string)$customBase);
            if ($base !== '') {
                return rtrim($base, '/');
            }
            $source = 'official';
        }

        $map = array(
            'official' => $isSecure ? 'https://www.gravatar.com/avatar' : 'http://www.gravatar.com/avatar',
            'loli'     => 'https://gravatar.loli.net/avatar',
            'cravatar' => 'https://cravatar.cn/avatar',
        );

        return isset($map[$source]) ? $map[$source] : $map['official'];
    }

    public static function resolveProxyUpstreamBase($isSecure = true)
    {
        $opts = self::options();
        return self::resolveMirrorBase($opts['proxy_upstream'], $opts['custom_gravatar_base'], $isSecure);
    }

    public static function encodeProxyToken($hash, $size = 80, $rating = 'X', $default = 'mm')
    {
        $payload = json_encode(array(
            'h' => strtolower(trim((string)$hash)),
            's' => max(1, (int)$size),
            'r' => (string)$rating,
            'd' => (string)$default,
        ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return self::base64UrlEncode((string)$payload);
    }

    public static function decodeProxyToken($token)
    {
        $token = trim((string)$token);
        if ($token === '') {
            return null;
        }

        $json = self::base64UrlDecode($token);
        if ($json === '') {
            return null;
        }

        $data = json_decode($json, true);
        if (!is_array($data) || empty($data['h'])) {
            return null;
        }

        $hash = strtolower(trim((string)$data['h']));
        if (!preg_match('/^[a-f0-9]{32}$/', $hash)) {
            return null;
        }

        $size = isset($data['s']) ? (int)$data['s'] : 80;
        if ($size < 1) {
            $size = 1;
        }
        if ($size > 1024) {
            $size = 1024;
        }

        $rating = isset($data['r']) ? trim((string)$data['r']) : 'X';
        if ($rating === '') {
            $rating = 'X';
        }

        $default = isset($data['d']) ? trim((string)$data['d']) : 'mm';
        if ($default === '') {
            $default = 'mm';
        }

        return array(
            'hash' => $hash,
            'size' => $size,
            'rating' => $rating,
            'default' => $default,
        );
    }

    public static function encodeLocalAvatarToken($path)
    {
        $path = trim((string)$path);
        if ($path === '') {
            return '';
        }

        $prefix = rtrim(self::LOCAL_UPLOAD_DIR, '/');
        if (strpos($path, $prefix . '/') !== 0) {
            return '';
        }

        $payload = self::base64UrlEncode($path);
        $sign = substr(hash_hmac('sha256', $payload, self::routeSignKey()), 0, 16);
        return $sign . $payload;
    }

    public static function decodeLocalAvatarToken($token)
    {
        $token = trim((string)$token);
        if ($token === '' || strlen($token) <= 16) {
            return '';
        }

        $sign = substr($token, 0, 16);
        $payload = substr($token, 16);
        $expected = substr(hash_hmac('sha256', $payload, self::routeSignKey()), 0, 16);
        if (!hash_equals($expected, $sign)) {
            return '';
        }

        $path = self::base64UrlDecode($payload);
        if ($path === '') {
            return '';
        }

        $path = trim((string)$path);
        $prefix = rtrim(self::LOCAL_UPLOAD_DIR, '/');
        if (strpos($path, $prefix . '/') !== 0 || strpos($path, '..') !== false) {
            return '';
        }

        return $path;
    }

    private static function routeSignKey()
    {
        $site = '';
        try {
            $site = (string)Typecho_Widget::widget('Widget_Options')->siteUrl;
        } catch (Exception $e) {
        }

        return hash('sha256', __TYPECHO_ROOT_DIR__ . '|' . $site . '|AdminBeautifyAvatarRoute');
    }

    private static function base64UrlEncode($raw)
    {
        return rtrim(strtr(base64_encode((string)$raw), '+/', '-_'), '=');
    }

    private static function base64UrlDecode($encoded)
    {
        $encoded = trim((string)$encoded);
        if ($encoded === '') {
            return '';
        }

        $raw = strtr($encoded, '-_', '+/');
        $padLen = strlen($raw) % 4;
        if ($padLen > 0) {
            $raw .= str_repeat('=', 4 - $padLen);
        }

        $decoded = base64_decode($raw, true);
        return $decoded === false ? '' : $decoded;
    }

    public static function emailHash($mail)
    {
        return md5(strtolower(trim((string)$mail)));
    }

    public static function getAvatarByHash($hash)
    {
        $hash = strtolower(trim((string)$hash));
        if ($hash === '' || !preg_match('/^[a-f0-9]{32}$/', $hash)) {
            return null;
        }

        if (array_key_exists($hash, self::$hashCache)) {
            return self::$hashCache[$hash];
        }

        $db = Typecho_Db::get();
        $row = $db->fetchRow(
            $db->select()
                ->from('table.' . self::TABLE_NAME)
                ->where('email_hash = ?', $hash)
                ->where('avatar_source = ?', 'custom')
                ->where('avatar_path <> ?', '')
                ->limit(1)
        );

        self::$hashCache[$hash] = !empty($row) ? $row : null;
        return self::$hashCache[$hash];
    }

    public static function getAvatarByUid($uid)
    {
        $uid = (int)$uid;
        if ($uid <= 0) {
            return null;
        }

        $db = Typecho_Db::get();
        return $db->fetchRow(
            $db->select()
                ->from('table.' . self::TABLE_NAME)
                ->where('uid = ?', $uid)
                ->limit(1)
        );
    }

    public static function avatarPathToUrl($path)
    {
        $path = trim((string)$path);
        if ($path === '') {
            return '';
        }

        if (preg_match('#^https?://#i', $path)) {
            return $path;
        }

        $token = self::encodeLocalAvatarToken($path);
        if ($token !== '') {
            return Typecho_Common::url('/ab-avatar/local/' . $token, Typecho_Widget::widget('Widget_Options')->index);
        }

        return Typecho_Common::url($path, Typecho_Widget::widget('Widget_Options')->index);
    }

    public static function getUserAvatarUrl($uid, $mail, $size = 220, $rating = 'X', $default = 'mm', $isSecure = true)
    {
        $record = self::getAvatarByUid($uid);
        if (!empty($record) && !empty($record['avatar_path']) && $record['avatar_source'] === 'custom') {
            return self::avatarPathToUrl($record['avatar_path']);
        }

        return self::buildAvatarUrlByHash(self::emailHash($mail), $size, $rating, $default, $isSecure);
    }

    public static function renderAdminFooter()
    {
        if (!self::isAdminBeautifyReady()) {
            return;
        }

        // 统一在后台全局注入，确保 AdminBeautify 的 AJAX 导航进入 profile 页面时无需手动刷新。
        self::renderProfileInjector();
    }

    private static function renderProfileInjector()
    {
        $user = Typecho_Widget::widget('Widget_User');
        if (!$user->hasLogin()) {
            return;
        }

        $opts = self::options();
        $isSecure = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off');
        $avatarUrl = self::getUserAvatarUrl((int)$user->uid, (string)$user->mail, 220, 'X', 'mm', $isSecure);

        $base = Typecho_Widget::widget('Widget_Options')->index;
        $css = self::assetUrl('/usr/plugins/AdminBeautifyAvatar/assets/css/avatar.css', $base);
        $js = self::assetUrl('/usr/plugins/AdminBeautifyAvatar/assets/js/avatar-profile.js', $base);
        $uploadUrl = Typecho_Common::url('/ab-avatar/upload', $base);
        $restoreUrl = Typecho_Common::url('/ab-avatar/restore', $base);

        $security = Typecho_Widget::widget('Widget_Security');
        $token = $security->getToken('abavatar-user-' . (int)$user->uid);

        echo '<link rel="stylesheet" href="' . htmlspecialchars($css) . '">';
        echo '<script src="https://cdn.jsdelivr.net/npm/cropperjs@1.6.2/dist/cropper.min.js"></script>';
        echo '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/cropperjs@1.6.2/dist/cropper.min.css">';

        echo '<script>window.ABAvatarConfig=' . json_encode(array(
            'enabled' => true,
            'enableUpload' => $opts['enable_custom_upload'] ? true : false,
            'uploadUrl' => $uploadUrl,
            'restoreUrl' => $restoreUrl,
            'token' => $token,
            'avatarUrl' => $avatarUrl,
            'maxFileSize' => (int)$opts['custom_avatar_max_mb'] * 1024 * 1024,
        ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ';</script>';

        echo '<script src="' . htmlspecialchars($js) . '"></script>';

        self::renderAdminUserEditorInjector($opts, $isSecure);
    }

    private static function renderAdminUserEditorInjector(array $opts, $isSecure)
    {
        $currentUser = Typecho_Widget::widget('Widget_User');
        if (!$currentUser->hasLogin() || !$currentUser->pass('administrator', true)) {
            return;
        }

        $context = self::resolveAdminUserEditContext();
        if (empty($context) || empty($context['uid'])) {
            return;
        }

        $uid = (int)$context['uid'];
        $mail = (string)$context['mail'];
        $screenName = !empty($context['screenName']) ? (string)$context['screenName'] : (string)$context['name'];
        $avatarUrl = self::getUserAvatarUrl($uid, $mail, 220, 'X', 'mm', (bool)$isSecure);

        $base = Typecho_Widget::widget('Widget_Options')->index;
        $js = self::assetUrl('/usr/plugins/AdminBeautifyAvatar/assets/js/avatar-user-edit.js', $base);
        $manageUrl = Typecho_Common::url('/ab-avatar/manage', $base);
        $token = Typecho_Widget::widget('Widget_Security')->getToken('abavatar-manage');

        echo '<script>window.ABAvatarAdminConfig=' . json_encode(array(
            'enabled' => true,
            'uid' => $uid,
            'screenName' => $screenName,
            'avatarUrl' => $avatarUrl,
            'manageUrl' => $manageUrl,
            'token' => $token,
            'maxFileSize' => (int)$opts['custom_avatar_max_mb'] * 1024 * 1024,
        ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ';</script>';

        echo '<script src="' . htmlspecialchars($js) . '"></script>';
    }

    private static function resolveAdminUserEditContext()
    {
        $scriptName = isset($_SERVER['SCRIPT_NAME']) ? strtolower(basename((string)$_SERVER['SCRIPT_NAME'])) : '';
        $uriPath = isset($_SERVER['REQUEST_URI']) ? (string)parse_url((string)$_SERVER['REQUEST_URI'], PHP_URL_PATH) : '';
        $uriName = strtolower(basename($uriPath));

        if ($scriptName !== 'user.php' && $uriName !== 'user.php') {
            return null;
        }

        $uid = isset($_GET['uid']) ? (int)$_GET['uid'] : 0;
        if ($uid <= 0) {
            return null;
        }

        $db = Typecho_Db::get();
        $row = $db->fetchRow(
            $db->select('uid', 'name', 'screenName', 'mail')
                ->from('table.users')
                ->where('uid = ?', $uid)
                ->limit(1)
        );

        return !empty($row) ? $row : null;
    }

    private static function assetUrl($path, $base)
    {
        $path = '/' . ltrim((string)$path, '/');
        $url = Typecho_Common::url($path, $base);

        $version = self::ASSET_VERSION;
        $abs = __TYPECHO_ROOT_DIR__ . $path;
        if (is_file($abs)) {
            $mtime = @filemtime($abs);
            if ($mtime !== false && $mtime > 0) {
                $version = (string)$mtime;
            }
        }

        return $url . (strpos($url, '?') === false ? '?' : '&') . 'v=' . rawurlencode($version);
    }

    public static function renderArchiveFooter($archive)
    {
        if (!self::isAdminBeautifyReady()) {
            return;
        }

        $opts = self::options();
        if (!$opts['replace_front_avatar']) {
            return;
        }

        $baseUrl = Typecho_Widget::widget('Widget_Options')->index;
        $proxyBase = rtrim(Typecho_Common::url('/ab-avatar/gravatar', $baseUrl), '/');
        $mirrorBase = rtrim(self::resolveMirrorBase($opts['gravatar_source'], $opts['custom_gravatar_base'], true), '/');
        $useProxy = $opts['gravatar_source'] === 'proxy';

        echo '<script>(function(){'
            . 'var useProxy=' . ($useProxy ? 'true' : 'false') . ';'
            . 'var proxyBase=' . json_encode($proxyBase, JSON_UNESCAPED_SLASHES) . ';'
            . 'var mirrorBase=' . json_encode($mirrorBase, JSON_UNESCAPED_SLASHES) . ';'
            . 'function pickBase(){return useProxy?proxyBase:mirrorBase;}'
            . 'function parseQ(q){'
            . 'var out={};if(!q)return out;'
            . 'var seg=(q.charAt(0)==="?"?q.slice(1):q).split("&");'
            . 'for(var i=0;i<seg.length;i++){if(!seg[i])continue;var kv=seg[i].split("=");'
            . 'var k=decodeURIComponent(kv[0]||"");if(!k)continue;'
            . 'var v=decodeURIComponent(kv.slice(1).join("=")||"");out[k]=v;}'
            . 'return out;'
            . '}'
            . 'function b64u(str){'
            . 'try{return btoa(unescape(encodeURIComponent(str))).replace(/\+/g,"-").replace(/\//g,"_").replace(/=+$/g,"");}'
            . 'catch(e){return "";}'
            . '}'
            . 'function proxyToken(hash,q){'
            . 'var p=parseQ(q);'
            . 'var s=parseInt(p.s||"80",10);if(!isFinite(s)||s<1)s=80;if(s>1024)s=1024;'
            . 'var r=(p.r||"X").toString();var d=(p.d||"mm").toString();'
            . 'return b64u(JSON.stringify({h:(hash||"").toLowerCase(),s:s,r:r,d:d}));'
            . '}'
            . 'function replaceOne(img){'
            . 'if(!img||!img.getAttribute)return;'
            . 'var src=img.getAttribute("src")||"";'
            . 'var m=src.match(/https?:\\/\\/[^\\/]+\\/avatar\\/([a-f0-9]{32})(\\?[^"\']*)?/i);'
            . 'if(!m)return;'
            . 'var q=m[2]||"";'
            . 'if(useProxy){'
            . 'var t=proxyToken(m[1],q);if(!t)return;img.setAttribute("src",proxyBase+"/"+t);'
            . '}else{'
            . 'img.setAttribute("src", mirrorBase+"/"+m[1].toLowerCase()+q);'
            . '}'
            . '}'
            . 'function run(){'
            . 'var imgs=document.querySelectorAll("img[src*=\\"/avatar/\\"]");'
            . 'for(var i=0;i<imgs.length;i++){replaceOne(imgs[i]);}'
            . '}'
            . 'if(document.readyState==="loading"){document.addEventListener("DOMContentLoaded",run);}else{run();}'
            . 'document.addEventListener("ab:pageload",run);'
            . '})();</script>';
    }

    private static function renderManagePanel()
    {
        $db = Typecho_Db::get();
        $pageSize = 10;
        $page = isset($_GET['ab_avatar_page']) ? (int)$_GET['ab_avatar_page'] : 1;
        if ($page < 1) {
            $page = 1;
        }

        $totalObj = $db->fetchObject(
            $db->select(array('COUNT(*)' => 'num'))
                ->from('table.' . self::TABLE_NAME)
                ->where('avatar_source = ?', 'custom')
        );
        $total = !empty($totalObj->num) ? (int)$totalObj->num : 0;
        $totalPages = max(1, (int)ceil($total / $pageSize));
        if ($page > $totalPages) {
            $page = $totalPages;
        }

        $rows = $db->fetchAll(
            $db->select()
                ->from('table.' . self::TABLE_NAME)
                ->where('avatar_source = ?', 'custom')
                ->order('updated', Typecho_Db::SORT_DESC)
                ->page($page, $pageSize)
        );

        $tr = '';
        foreach ((array)$rows as $row) {
            $uid = (int)$row['uid'];
            $user = $db->fetchRow(
                $db->select('uid', 'name', 'screenName', 'mail')
                    ->from('table.users')
                    ->where('uid = ?', $uid)
                    ->limit(1)
            );

            $name = !empty($user['screenName']) ? $user['screenName'] : (!empty($user['name']) ? $user['name'] : ('UID ' . $uid));
            $mail = !empty($user['mail']) ? $user['mail'] : '-';
            $avatarUrl = self::avatarPathToUrl($row['avatar_path']);
            $updatedText = $row['updated'] > 0 ? date('Y-m-d H:i:s', (int)$row['updated']) : '-';

            $tr .= '<tr class="ab-avatar-manage-row">'
                . '<td class="ab-avatar-manage-cell ab-avatar-manage-cell-avatar"><img src="' . htmlspecialchars($avatarUrl) . '" alt="avatar" class="ab-avatar-manage-thumb"></td>'
                . '<td class="ab-avatar-manage-cell">' . htmlspecialchars($name) . '<br><small class="ab-avatar-manage-mail">' . htmlspecialchars($mail) . '</small></td>'
                . '<td class="ab-avatar-manage-cell">' . htmlspecialchars((string)$row['storage']) . '</td>'
                . '<td class="ab-avatar-manage-cell">' . htmlspecialchars($updatedText) . '</td>'
                . '<td class="ab-avatar-manage-cell"><button type="button" class="ab-avatar-manage-btn" data-uid="' . $uid . '">恢复 Gravatar</button></td>'
                . '</tr>';
        }

        if ($tr === '') {
            $tr = '<tr><td colspan="5" class="ab-avatar-manage-empty">暂无自定义头像记录</td></tr>';
        }

        $baseAdmin = Typecho_Widget::widget('Widget_Options')->adminUrl;
        $query = $_GET;
        unset($query['ab_avatar_page']);
        $baseUrl = Typecho_Common::url('options-plugin.php', $baseAdmin);
        $queryString = http_build_query($query);
        $pageBase = $baseUrl . ($queryString !== '' ? ('?' . $queryString . '&') : '?') . 'ab_avatar_page=';
        $currentListUrl = $pageBase . $page;

        $pager = '';
        if ($total > 0 && $totalPages > 1) {
            $prev = $page > 1 ? $page - 1 : 1;
            $next = $page < $totalPages ? $page + 1 : $totalPages;

            $pager .= '<div class="ab-avatar-manage-pagination">';
            $pager .= '<a class="ab-avatar-page-link' . ($page <= 1 ? ' is-disabled' : '') . '" href="' . htmlspecialchars($pageBase . $prev) . '">上一页</a>';

            $start = max(1, $page - 2);
            $end = min($totalPages, $page + 2);
            for ($i = $start; $i <= $end; $i++) {
                $pager .= '<a class="ab-avatar-page-link' . ($i === $page ? ' is-active' : '') . '" href="' . htmlspecialchars($pageBase . $i) . '">' . $i . '</a>';
            }

            $pager .= '<a class="ab-avatar-page-link' . ($page >= $totalPages ? ' is-disabled' : '') . '" href="' . htmlspecialchars($pageBase . $next) . '">下一页</a>';
            $pager .= '<span class="ab-avatar-page-stat">共 ' . $total . ' 条，第 ' . $page . '/' . $totalPages . ' 页</span>';
            $pager .= '</div>';
        }

        $manageUrl = Typecho_Common::url('/ab-avatar/manage', Typecho_Widget::widget('Widget_Options')->index);
        $token = Typecho_Widget::widget('Widget_Security')->getToken('abavatar-manage');

        echo '<div class="ab-avatar-manage-card" data-list-url="' . htmlspecialchars($currentListUrl) . '">'
            . '<h3 class="ab-avatar-manage-title">自定义头像管理</h3>'
            . '<p class="ab-avatar-manage-desc">集中管理用户上传头像，可一键恢复为邮箱对应 Gravatar。</p>'
            . '<div class="ab-avatar-manage-scroll">'
            . '<table class="ab-avatar-manage-table">'
            . '<thead><tr class="ab-avatar-manage-head-row">'
            . '<th class="ab-avatar-manage-head">头像</th>'
            . '<th class="ab-avatar-manage-head">用户</th>'
            . '<th class="ab-avatar-manage-head">存储</th>'
            . '<th class="ab-avatar-manage-head">更新时间</th>'
            . '<th class="ab-avatar-manage-head">操作</th>'
            . '</tr></thead><tbody id="ab-avatar-manage-tbody">' . $tr . '</tbody>'
            . '</table></div>' . $pager . '</div>';

        echo '<style>'
            . '.ab-avatar-manage-card{margin-top:16px;padding:14px;border:1px solid var(--md-outline-variant,#d1d5db);border-radius:12px;background:var(--md-surface-container,#f8fafc);color:var(--md-on-surface,#1f2937)}'
            . '.ab-avatar-manage-card.is-loading{opacity:.68;pointer-events:none}'
            . '.ab-avatar-manage-title{margin:0 0 10px}'
            . '.ab-avatar-manage-desc{margin:0 0 10px;color:var(--md-on-surface-variant,#6b7280)}'
            . '.ab-avatar-manage-scroll{overflow:auto}'
            . '.ab-avatar-manage-table{width:100%;border-collapse:collapse;min-width:680px}'
            . '.ab-avatar-manage-head-row{border-bottom:1px solid var(--md-outline-variant,#d1d5db)}'
            . '.ab-avatar-manage-head{text-align:left;padding:8px 10px}'
            . '.ab-avatar-manage-cell{padding:8px 10px}'
            . '.ab-avatar-manage-thumb{width:40px;height:40px;border-radius:50%;object-fit:cover;border:1px solid var(--md-outline-variant,#d1d5db)}'
            . '.ab-avatar-manage-mail{opacity:.75}'
            . '.ab-avatar-manage-empty{padding:10px;color:var(--md-on-surface-variant,#6b7280)}'
            . '.ab-avatar-manage-btn{border:1px solid var(--md-primary,#6750a4);background:transparent;color:var(--md-primary,#6750a4);border-radius:999px;padding:6px 12px;cursor:pointer;font-size:12px;font-weight:600}'
            . '.ab-avatar-manage-btn:hover{background:color-mix(in srgb,var(--md-primary,#6750a4) 10%,transparent)}'
            . '.ab-avatar-manage-btn[disabled]{opacity:.5;cursor:not-allowed}'
            . '.ab-avatar-manage-pagination{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-top:12px}'
            . '.ab-avatar-page-link{display:inline-flex;align-items:center;justify-content:center;min-width:34px;height:32px;padding:0 10px;border:1px solid var(--md-outline-variant,#d1d5db);border-radius:8px;color:var(--md-on-surface,#1f2937);text-decoration:none;font-size:12px}'
            . '.ab-avatar-page-link:hover{background:color-mix(in srgb,var(--md-primary,#6750a4) 10%,transparent)}'
            . '.ab-avatar-page-link.is-active{border-color:var(--md-primary,#6750a4);color:var(--md-primary,#6750a4);font-weight:700}'
            . '.ab-avatar-page-link.is-disabled{pointer-events:none;opacity:.45}'
            . '.ab-avatar-page-stat{margin-left:auto;font-size:12px;color:var(--md-on-surface-variant,#6b7280)}'
            . '[data-theme="dark"] .ab-avatar-manage-card{background:var(--md-dark-surface-container,#211f26);border-color:var(--md-dark-outline-variant,#49454f);color:var(--md-dark-on-surface,#e6e1e5)}'
            . '[data-theme="dark"] .ab-avatar-manage-desc,[data-theme="dark"] .ab-avatar-manage-empty{color:var(--md-dark-on-surface-variant,#cac4d0)}'
            . '[data-theme="dark"] .ab-avatar-manage-head-row{border-bottom-color:var(--md-dark-outline-variant,#49454f)}'
            . '[data-theme="dark"] .ab-avatar-manage-thumb{border-color:var(--md-dark-outline-variant,#49454f)}'
            . '[data-theme="dark"] .ab-avatar-manage-btn{border-color:var(--md-dark-primary,#d0bcff);color:var(--md-dark-primary,#d0bcff)}'
            . '[data-theme="dark"] .ab-avatar-manage-btn:hover{background:color-mix(in srgb,var(--md-dark-primary,#d0bcff) 14%,transparent)}'
            . '[data-theme="dark"] .ab-avatar-page-link{border-color:var(--md-dark-outline-variant,#49454f);color:var(--md-dark-on-surface,#e6e1e5)}'
            . '[data-theme="dark"] .ab-avatar-page-link.is-active{border-color:var(--md-dark-primary,#d0bcff);color:var(--md-dark-primary,#d0bcff)}'
            . '[data-theme="dark"] .ab-avatar-page-link:hover{background:color-mix(in srgb,var(--md-dark-primary,#d0bcff) 14%,transparent)}'
            . '[data-theme="dark"] .ab-avatar-page-stat{color:var(--md-dark-on-surface-variant,#cac4d0)}'
            . '</style>';

        echo '<script>(function(){'
            . 'var url=' . json_encode($manageUrl, JSON_UNESCAPED_SLASHES) . ';'
            . 'var token=' . json_encode($token, JSON_UNESCAPED_SLASHES) . ';'
            . 'function qs(s,r){return (r||document).querySelector(s);}'
            . 'function qsa(s,r){return Array.prototype.slice.call((r||document).querySelectorAll(s));}'
            . 'function card(){return qs(".ab-avatar-manage-card");}'
            . 'function setBusy(on){var c=card();if(!c)return;c.classList[on?"add":"remove"]("is-loading");}'
            . 'function parseCard(html){try{var d=new DOMParser().parseFromString(html,"text/html");return d.querySelector(".ab-avatar-manage-card");}catch(e){return null;}}'
            . 'function loadCard(href){'
            . 'if(!href){return Promise.resolve();}'
            . 'setBusy(true);'
            . 'return fetch(href,{credentials:"same-origin"})'
            . '.then(function(r){return r.text();})'
            . '.then(function(t){'
            . 'var cur=card();if(!cur)return;var next=parseCard(t);if(!next){location.href=href;return;}'
            . 'cur.replaceWith(next);bind(next);'
            . '})'
            . '.catch(function(){location.href=href;})'
            . '.finally(function(){setBusy(false);});'
            . '}'
            . 'function bind(root){'
            . 'if(!root)return;'
            . 'var btns=qsa(".ab-avatar-manage-btn",root);'
            . 'for(var i=0;i<btns.length;i++){'
            . 'btns[i].onclick=function(){'
            . 'var uid=this.getAttribute("data-uid");if(!uid)return;'
            . 'if(!confirm("确认将该用户头像恢复为 Gravatar 吗？"))return;'
            . 'this.disabled=true;'
            . 'var fd=new FormData();fd.append("uid",uid);fd.append("action","restore");fd.append("_",token);'
            . 'fetch(url,{method:"POST",credentials:"same-origin",body:fd})'
            . '.then(function(r){return r.json();})'
            . '.then(function(d){'
            . 'if(!(d&&d.success)){alert((d&&d.message)?d.message:"操作失败");return;}'
            . 'var c=card();var listUrl=c?c.getAttribute("data-list-url"):"";'
            . 'loadCard(listUrl||window.location.href);'
            . '})'
            . '.catch(function(){alert("网络错误");})'
            . '.finally((function(btn){return function(){btn.disabled=false;};})(this));'
            . '};'
            . '}'
            . 'var links=qsa(".ab-avatar-page-link",root);'
            . 'for(var j=0;j<links.length;j++){'
            . 'links[j].onclick=function(ev){'
            . 'if(this.classList.contains("is-disabled"))return;'
            . 'if(ev)ev.preventDefault();'
            . 'var href=this.getAttribute("href")||"";if(!href)return;'
            . 'loadCard(href);'
            . '};'
            . '}'
            . '}'
            . 'function bootstrap(){var c=card();if(c){bind(c);}}'
            . 'if(document.readyState==="loading"){document.addEventListener("DOMContentLoaded",bootstrap);}else{bootstrap();}'
            . 'document.addEventListener("ab:pageload",function(){setTimeout(bootstrap,0);});'
            . '})();</script>';
    }
}
