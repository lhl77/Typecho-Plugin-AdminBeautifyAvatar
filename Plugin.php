<?php
if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * AdminBeautify 专用头像管理插件
 *
 * @package AB-Avatar
 * @author  LHL
 * @version 1.0.2
 * @link    https://github.com/lhl77/Typecho-Plugin-AdminBeautifyAvatar
 */
class AdminBeautifyAvatar_Plugin implements Typecho_Plugin_Interface
{
    const TABLE_NAME = 'ab_avatar_user';
    const LOCAL_UPLOAD_DIR = '/usr/plugins/AdminBeautifyAvatar/uploads';
    const ASSET_VERSION = '1.0.2';

    private static $hashCache = array();
    private static $routesEnsured = false;

    private static function requiredRoutes()
    {
        return array(
            'ab_avatar_proxy' => array(
                'url' => '/ab-avatar/gravatar/[token:string]',
                'widget' => 'AdminBeautifyAvatar_Widget',
                'action' => 'proxy',
            ),
            'ab_avatar_qq' => array(
                'url' => '/ab-avatar/qq/[token:string]',
                'widget' => 'AdminBeautifyAvatar_Widget',
                'action' => 'qq',
            ),
            'ab_avatar_comment' => array(
                'url' => '/ab-avatar/comment/[token:string]',
                'widget' => 'AdminBeautifyAvatar_Widget',
                'action' => 'comment',
            ),
            'ab_avatar_local' => array(
                'url' => '/ab-avatar/local/[token:string]',
                'widget' => 'AdminBeautifyAvatar_Widget',
                'action' => 'local',
            ),
            'ab_avatar_upload' => array(
                'url' => '/ab-avatar/upload',
                'widget' => 'AdminBeautifyAvatar_Widget',
                'action' => 'upload',
            ),
            'ab_avatar_restore' => array(
                'url' => '/ab-avatar/restore',
                'widget' => 'AdminBeautifyAvatar_Widget',
                'action' => 'restore',
            ),
            'ab_avatar_manage' => array(
                'url' => '/ab-avatar/manage',
                'widget' => 'AdminBeautifyAvatar_Widget',
                'action' => 'manage',
            ),
        );
    }

    private static function ensureRoutesRegistered()
    {
        if (self::$routesEnsured) {
            return;
        }

        self::$routesEnsured = true;

        try {
            $routingTable = (array)Typecho_Widget::widget('Widget_Options')->routingTable;
        } catch (Exception $e) {
            return;
        }

        if (isset($routingTable[0])) {
            unset($routingTable[0]);
        }

        $required = self::requiredRoutes();
        $normalized = $required;
        $changed = false;

        foreach ($required as $name => $route) {
            if (!isset($routingTable[$name]) || !is_array($routingTable[$name])) {
                $changed = true;
                continue;
            }

            $current = $routingTable[$name];
            if (($current['url'] ?? '') !== $route['url']
                || ($current['widget'] ?? '') !== $route['widget']
                || (($current['action'] ?? null) !== $route['action'])) {
                $changed = true;
            }
        }

        foreach ($routingTable as $name => $route) {
            if (!isset($required[$name])) {
                $normalized[$name] = $route;
            }
        }

        if ($changed) {
            Helper::setOption('routingTable', $normalized);
        }
    }

    public static function activate()
    {
        Helper::addRoute('ab_avatar_proxy', '/ab-avatar/gravatar/[token:string]', 'AdminBeautifyAvatar_Widget', 'proxy');
        Helper::addRoute('ab_avatar_qq', '/ab-avatar/qq/[token:string]', 'AdminBeautifyAvatar_Widget', 'qq');
        Helper::addRoute('ab_avatar_comment', '/ab-avatar/comment/[token:string]', 'AdminBeautifyAvatar_Widget', 'comment');
        Helper::addRoute('ab_avatar_local', '/ab-avatar/local/[token:string]', 'AdminBeautifyAvatar_Widget', 'local');
        Helper::addRoute('ab_avatar_upload', '/ab-avatar/upload', 'AdminBeautifyAvatar_Widget', 'upload');
        Helper::addRoute('ab_avatar_restore', '/ab-avatar/restore', 'AdminBeautifyAvatar_Widget', 'restore');
        Helper::addRoute('ab_avatar_manage', '/ab-avatar/manage', 'AdminBeautifyAvatar_Widget', 'manage');

        Typecho_Plugin::factory('admin/footer.php')->end = array(__CLASS__, 'renderAdminFooter');

        // 前台评论头像直接走插件逻辑（镜像/代理/自定义头像）
        Typecho_Plugin::factory('Widget_Abstract_Comments')->gravatar = array(__CLASS__, 'renderCommentAvatar');
        Typecho_Plugin::factory('Mirages_Widget_Comments_Archive')->gravatar = array(__CLASS__, 'renderCommentAvatar');
        Typecho_Plugin::factory('Widget_Archive')->header = array(__CLASS__, 'renderArchiveHeader');
        // 前台其余头像通过 JS 统一替换 gravatar 地址
        Typecho_Plugin::factory('Widget_Archive')->footer = array(__CLASS__, 'renderArchiveFooter');

        if (class_exists('Typecho\\Plugin')) {
            \Typecho\Plugin::factory('Widget\\Base\\Comments')->gravatar = array(__CLASS__, 'renderCommentAvatar');
            \Typecho\Plugin::factory('Widget\\Archive')->header = array(__CLASS__, 'renderArchiveHeader');
            \Typecho\Plugin::factory('Widget\\Archive')->footer = array(__CLASS__, 'renderArchiveFooter');
        }

        self::installTable();
        self::ensureDefaultConfig();

        return _t('AdminBeautifyAvatar 已启用');
    }

    public static function deactivate()
    {
        Helper::removeRoute('ab_avatar_proxy');
        Helper::removeRoute('ab_avatar_qq');
        Helper::removeRoute('ab_avatar_comment');
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

        $qqMailMode = new Typecho_Widget_Helper_Form_Element_Select(
            'qq_mail_mode',
            array(
                'qq'       => _t('QQ 邮箱优先使用 QQ 头像'),
                'gravatar' => _t('QQ 邮箱继续走 Gravatar'),
            ),
            (string)$opts['qq_mail_mode'],
            _t('QQ 邮箱头像来源'),
            _t('仅对 qq.com / vip.qq.com / foxmail.com 生效；当邮箱前缀是纯数字 QQ 号时可直接拉取 QQ 头像，否则自动回退到 Gravatar。')
        );
        $form->addInput($qqMailMode);
        self::appendQqMailHintToForm($form);

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

        $resolvedDefaultPreviewRaw = ((string)$opts['default_avatar_type'] === 'auto')
            ? '自动'
            : self::defaultAvatarDisplayLabel((string)self::getResolvedDefaultAvatar());
        $resolvedDefaultPreview = htmlspecialchars($resolvedDefaultPreviewRaw, ENT_QUOTES, 'UTF-8');

        $defaultAvatarType = new Typecho_Widget_Helper_Form_Element_Select(
            'default_avatar_type',
            array(
                'auto'      => _t('自动检测（其他插件设置）'),
                'mm'        => _t('神秘人（默认）'),
                'identicon' => _t('几何图案'),
                'monsterid' => _t('可爱怪物'),
                'wavatar'   => _t('头像风格'),
                'retro'     => _t('像素艺术'),
                'robohash'  => _t('机器人头像'),
                'custom'    => _t('自定义 URL'),
            ),
            (string)$opts['default_avatar_type'],
            _t('默认头像方案'),
            _t('用户未设置 Gravatar 头像时的默认显示方案。选择"自动检测"将优先使用其他插件设置的默认头像。当前解析值：%s', '<code>' . $resolvedDefaultPreview . '</code>')
        );
        $form->addInput($defaultAvatarType);

        $defaultAvatarUrl = new Typecho_Widget_Helper_Form_Element_Text(
            'default_avatar_url',
            null,
            (string)$opts['default_avatar_url'],
            _t('自定义头像 URL'),
            _t('当选择"自定义 URL"时，输入完整的头像图片链接（如：https://example.com/avatar.jpg）')
        );
        $form->addInput($defaultAvatarUrl);

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
            'qq_mail_mode'         => 'qq',
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
            'default_avatar_type'  => 'auto',
            'default_avatar_url'   => '',
        ));
    }

    public static function options()
    {
        self::ensureRoutesRegistered();

        $defaults = array(
            'gravatar_source'      => 'proxy',
            'qq_mail_mode'         => 'qq',
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
            'default_avatar_type'  => 'auto',
            'default_avatar_url'   => '',
        );

        try {
            $opt = Typecho_Widget::widget('Widget_Options')->plugin('AdminBeautifyAvatar');

            $source = isset($opt->gravatar_source) ? trim((string)$opt->gravatar_source) : $defaults['gravatar_source'];
            if (!in_array($source, array('official', 'loli', 'cravatar', 'custom', 'proxy'), true)) {
                $source = $defaults['gravatar_source'];
            }

            $qqMailMode = isset($opt->qq_mail_mode) ? trim((string)$opt->qq_mail_mode) : $defaults['qq_mail_mode'];
            if (!in_array($qqMailMode, array('qq', 'gravatar'), true)) {
                $qqMailMode = $defaults['qq_mail_mode'];
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

            $defaultType = isset($opt->default_avatar_type) ? trim((string)$opt->default_avatar_type) : $defaults['default_avatar_type'];
            if (!in_array($defaultType, array('auto', 'mm', 'identicon', 'monsterid', 'wavatar', 'retro', 'robohash', 'custom'), true)) {
                $defaultType = 'auto';
            }

            $defaultUrl = isset($opt->default_avatar_url) ? trim((string)$opt->default_avatar_url) : '';

            return array(
                'gravatar_source'      => $source,
                'qq_mail_mode'         => $qqMailMode,
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
                'default_avatar_type'  => $defaultType,
                'default_avatar_url'   => $defaultUrl,
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

    private static function appendQqMailHintToForm(Typecho_Widget_Helper_Form $form)
    {
        $html = '<div class="ab-avatar-qq-hint">'
            . '<strong>QQ 邮箱说明：</strong>'
            . ' 仅当邮箱属于 qq.com / vip.qq.com / foxmail.com 且前缀是纯数字 QQ 号时，才能直接请求 QQ 头像；'
            . '其余情况会自动回退到 Gravatar。'
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
            . '.ab-avatar-qq-hint{margin:10px 0 14px;padding:10px 12px;border:1px solid #dbeafe;border-radius:10px;background:#eff6ff;color:#1e3a8a;font-size:12px}'
            . '[data-theme="dark"] .ab-avatar-picup-hint{background:var(--md-dark-surface-container,#211f26);border-color:var(--md-dark-outline-variant,#49454f);color:var(--md-dark-on-surface,#e6e1e5)}'
            . '[data-theme="dark"] .ab-avatar-picup-hint a{color:var(--md-dark-primary,#d0bcff)}'
            . '[data-theme="dark"] .ab-avatar-qq-hint{background:rgba(59,130,246,.12);border-color:rgba(96,165,250,.28);color:#bfdbfe}'
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
            . 'var defaultTypeEl=document.querySelector("[name=\"default_avatar_type\"]");'
            . 'var defaultType=defaultTypeEl?defaultTypeEl.value:"auto";'
            . 'setVisible("default_avatar_url",defaultType==="custom");'
            . '}'
            . 'document.addEventListener("change",function(e){'
            . 'if(!e||!e.target)return;'
                . 'if(e.target.name==="gravatar_source"||e.target.name==="storage_driver"||e.target.name==="enable_custom_upload"||e.target.name==="default_avatar_type"){refresh();}'
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
        $hash = self::emailHash($comments->mail);
        $record = self::getAvatarByHash($hash);

        if (!empty($record) && !empty($record['avatar_path'])) {
            $url = self::avatarPathToUrl($record['avatar_path']);
        } else {
            $resolvedDefault = self::getResolvedDefaultAvatar((string)$default);
            $url = self::buildAvatarUrlByMail((string)$comments->mail, (int)$size, (string)$rating, $resolvedDefault, $comments->request->isSecure());
        }

        echo '<img class="avatar" loading="lazy" src="' . htmlspecialchars($url) . '" alt="'
            . htmlspecialchars($comments->author) . '" width="' . (int)$size . '" height="' . (int)$size . '" />';
    }

    public static function renderArchiveHeader($header, $archive)
    {
        self::overrideMiragesHeadFaceUrl($archive);
    }

    private static function overrideMiragesHeadFaceUrl($archive)
    {
        if (!class_exists('Mirages', false) || !isset(Mirages::$options) || !is_object(Mirages::$options)) {
            return;
        }

        $themeOptions = Mirages::$options;
        $sideMenuAvatar = isset($themeOptions->sideMenuAvatar) ? trim((string)$themeOptions->sideMenuAvatar) : '';
        if ($sideMenuAvatar !== '') {
            return;
        }

        $uid = 0;
        $mail = '';

        $currentUser = isset($themeOptions->currentUser) ? $themeOptions->currentUser : null;
        if (is_object($currentUser) && method_exists($currentUser, 'hasLogin') && $currentUser->hasLogin()) {
            $uid = isset($currentUser->uid) ? (int)$currentUser->uid : 0;
            $mail = isset($currentUser->mail) ? trim((string)$currentUser->mail) : '';
        }

        if ($mail === '') {
            $db = Typecho_Db::get();
            $users = $db->fetchAll($db->select()->from('table.users')->order('UID')->limit(1));
            if (!empty($users[0]) && is_array($users[0])) {
                $uid = !empty($users[0]['uid']) ? (int)$users[0]['uid'] : (!empty($users[0]['UID']) ? (int)$users[0]['UID'] : 0);
                $mail = !empty($users[0]['mail']) ? trim((string)$users[0]['mail']) : '';
            }
        }

        if ($mail === '') {
            return;
        }

        $rating = isset($themeOptions->commentsAvatarRating) ? (string)$themeOptions->commentsAvatarRating : 'X';
        $resolvedDefault = self::getResolvedDefaultAvatar();
        $isSecure = isset($archive->request) ? $archive->request->isSecure() : true;

        $themeOptions->headFaceUrl = self::getUserAvatarUrl($uid, $mail, 200, $rating, $resolvedDefault, $isSecure);
    }

    /**
     * 获取最终的默认头像值
     * 根据配置自动检测或返回自定义 URL
     */
    public static function getResolvedDefaultAvatar($fallback = '')
    {
        $opts = self::options();
        $type = (string)$opts['default_avatar_type'];

        $fallback = trim((string)$fallback);
        if (strtolower($fallback) === 'auto') {
            $fallback = '';
        }

        if ($type === 'auto') {
            // 优先沿用调用方传入的默认值（通常是 Typecho/主题/其他插件已决定好的 d 参数）
            if ($fallback !== '' && self::isValidDefaultAvatarValue($fallback)) {
                return $fallback;
            }

            // 其次尝试读取全局可见的常见配置字段
            try {
                $globalOpts = Typecho_Widget::widget('Widget_Options');

                $candidates = array(
                    'defaultAvatar',
                    'gravatarDefault',
                    'commentsDefaultAvatar',
                    'avatarDefault',
                );

                foreach ($candidates as $field) {
                    if (!empty($globalOpts->{$field})) {
                        $value = trim((string)$globalOpts->{$field});
                        if (self::isValidDefaultAvatarValue($value)) {
                            return $value;
                        }
                    }
                }
            } catch (Exception $e) {
            }

            return 'mm';
        }

        if ($type === 'custom') {
            $url = (string)$opts['default_avatar_url'];
            $url = trim($url);
            if (self::isValidDefaultAvatarValue($url)) {
                return $url;
            }
            return 'mm';
        }

        return $type !== '' ? $type : 'mm';
    }

    private static function defaultAvatarDisplayLabel($value)
    {
        $value = trim((string)$value);
        if ($value === '') {
            return '神秘人（mm）';
        }

        $map = array(
            '404' => '空头像（404）',
            'mp' => '神秘人（mp）',
            'mm' => '神秘人（mm）',
            'identicon' => '几何图案（identicon）',
            'monsterid' => '可爱怪物（monsterid）',
            'wavatar' => '头像风格（wavatar）',
            'retro' => '像素艺术（retro）',
            'robohash' => '机器人头像（robohash）',
            'blank' => '透明空白（blank）',
        );

        $key = strtolower($value);
        if (isset($map[$key])) {
            return $map[$key];
        }

        if (preg_match('#^https?://#i', $value)) {
            return '自定义头像 URL';
        }

        return $value;
    }

    private static function isValidDefaultAvatarValue($value)
    {
        $value = trim((string)$value);
        if ($value === '') {
            return false;
        }

        $presets = array('404', 'mp', 'mm', 'identicon', 'monsterid', 'wavatar', 'retro', 'robohash', 'blank');
        if (in_array(strtolower($value), $presets, true)) {
            return true;
        }

        return (bool)preg_match('#^https?://#i', $value);
    }

    public static function buildAvatarUrlByHash($hash, $size = 80, $rating = 'X', $default = 'mm', $isSecure = true)
    {
        $opts = self::options();
        $size = max(1, (int)$size);
        $rating = (string)$rating;
        $default = self::getResolvedDefaultAvatar((string)$default);

        if ($opts['gravatar_source'] === 'proxy') {
            $base = rtrim(Typecho_Common::url('/ab-avatar/gravatar', Typecho_Widget::widget('Widget_Options')->index), '/');
            $token = self::encodeProxyToken((string)$hash, $size, $rating, $default);
            return $base . '/' . $token;
        }

        $host = self::resolveMirrorBase($opts['gravatar_source'], $opts['custom_gravatar_base'], $isSecure);
        $query = '?s=' . $size . '&r=' . rawurlencode($rating) . '&d=' . rawurlencode($default);
        return rtrim($host, '/') . '/' . strtolower((string)$hash) . $query;
    }

    public static function buildAvatarUrlByMail($mail, $size = 80, $rating = 'X', $default = 'mm', $isSecure = true)
    {
        $qqNumber = self::resolveQqMailNumber($mail);
        if ($qqNumber !== '' && self::shouldUseQqAvatar($mail)) {
            return self::buildQqAvatarUrl($qqNumber, $size, $isSecure);
        }

        return self::buildAvatarUrlByHash(self::emailHash($mail), $size, $rating, $default, $isSecure);
    }

    private static function shouldUseQqAvatar($mail)
    {
        $opts = self::options();
        if ((string)$opts['qq_mail_mode'] !== 'qq') {
            return false;
        }

        return self::resolveQqMailNumber($mail) !== '';
    }

    private static function resolveQqMailNumber($mail)
    {
        $mail = strtolower(trim((string)$mail));
        if ($mail === '' || strpos($mail, '@') === false) {
            return '';
        }

        $parts = explode('@', $mail, 2);
        $local = isset($parts[0]) ? trim((string)$parts[0]) : '';
        $domain = isset($parts[1]) ? trim((string)$parts[1]) : '';

        if ($local === '' || $domain === '') {
            return '';
        }

        if (!in_array($domain, array('qq.com', 'vip.qq.com', 'foxmail.com'), true)) {
            return '';
        }

        if (!preg_match('/^\d{4,12}$/', $local)) {
            return '';
        }

        return $local;
    }

    private static function buildQqAvatarUrl($qqNumber, $size = 80, $isSecure = true)
    {
        $base = ($isSecure ? 'https' : 'http') . '://q1.qlogo.cn/g';
        $qqSize = self::normalizeQqAvatarSize($size);
        return $base . '?b=qq&nk=' . rawurlencode((string)$qqNumber) . '&s=' . $qqSize;
    }

    private static function normalizeQqAvatarSize($size)
    {
        $size = max(1, (int)$size);
        if ($size <= 40) {
            return 40;
        }
        if ($size <= 100) {
            return 100;
        }
        if ($size <= 140) {
            return 140;
        }
        return 640;
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

    public static function encodeQqAvatarToken($qqNumber, $size = 80)
    {
        $qqNumber = trim((string)$qqNumber);
        if (!preg_match('/^[1-9][0-9]{4,11}$/', $qqNumber)) {
            return '';
        }

        $payload = json_encode(array(
            'q' => $qqNumber,
            's' => max(1, min(1024, (int)$size)),
        ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return self::base64UrlEncode((string)$payload);
    }

    public static function decodeQqAvatarToken($token)
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
        if (!is_array($data) || empty($data['q'])) {
            return null;
        }

        $qqNumber = trim((string)$data['q']);
        if (!preg_match('/^[1-9][0-9]{4,11}$/', $qqNumber)) {
            return null;
        }

        $size = isset($data['s']) ? (int)$data['s'] : 80;
        if ($size < 1) {
            $size = 1;
        }
        if ($size > 1024) {
            $size = 1024;
        }

        return array(
            'qq' => $qqNumber,
            'size' => $size,
        );
    }

    public static function encodeCommentAvatarToken($commentId, $size = 100)
    {
        $commentId = (int)$commentId;
        $size = (int)$size;
        if ($commentId <= 0) {
            return '';
        }

        if ($size < 1) {
            $size = 100;
        }
        if ($size > 1024) {
            $size = 1024;
        }

        $payload = json_encode(array(
            'c' => $commentId,
            's' => $size,
        ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return self::base64UrlEncode((string)$payload);
    }

    public static function decodeCommentAvatarToken($token)
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
        if (!is_array($data) || empty($data['c'])) {
            return null;
        }

        $commentId = isset($data['c']) ? (int)$data['c'] : 0;
        if ($commentId <= 0) {
            return null;
        }

        $size = isset($data['s']) ? (int)$data['s'] : 100;
        if ($size < 1) {
            $size = 100;
        }
        if ($size > 1024) {
            $size = 1024;
        }

        return array(
            'commentId' => $commentId,
            'size' => $size,
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

        return self::buildAvatarUrlByMail($mail, $size, $rating, $default, $isSecure);
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
        $resolvedDefault = self::getResolvedDefaultAvatar();
        $avatarUrl = self::getUserAvatarUrl((int)$user->uid, (string)$user->mail, 220, 'X', $resolvedDefault, $isSecure);

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
        $resolvedDefault = self::getResolvedDefaultAvatar();
        $avatarUrl = self::getUserAvatarUrl($uid, $mail, 220, 'X', $resolvedDefault, (bool)$isSecure);

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
        $opts = self::options();
        if (!$opts['replace_front_avatar']) {
            return;
        }

        $baseUrl = Typecho_Widget::widget('Widget_Options')->index;
        $proxyBase = rtrim(Typecho_Common::url('/ab-avatar/gravatar', $baseUrl), '/');
        $qqProxyBase = rtrim(Typecho_Common::url('/ab-avatar/qq', $baseUrl), '/');
        $commentProxyBase = rtrim(Typecho_Common::url('/ab-avatar/comment', $baseUrl), '/');
        $mirrorBase = rtrim(self::resolveMirrorBase($opts['gravatar_source'], $opts['custom_gravatar_base'], true), '/');
        $useProxy = $opts['gravatar_source'] === 'proxy';
        
        // 获取最终的默认头像值
        $defaultType = (string)$opts['default_avatar_type'];
        $defaultUrl = (string)$opts['default_avatar_url'];
        $resolvedDefault = self::getResolvedDefaultAvatar();

        $script = <<<'ABAVATAR_ARCHIVE_JS'
<script>(function(){
    var useProxy=__USE_PROXY__;
    var proxyBase=__PROXY_BASE__;
    var qqProxyBase=__QQ_PROXY_BASE__;
    var commentProxyBase=__COMMENT_PROXY_BASE__;
    var mirrorBase=__MIRROR_BASE__;
    var defaultType=__DEFAULT_TYPE__;
    var defaultUrl=__DEFAULT_URL__;
    var resolvedDefault=__RESOLVED_DEFAULT__;
    var attrs=['src','data-src','data-original','data-lazy-src','srcset','data-srcset'];

    function parseQ(q){
        var out={};
        if(!q) return out;
        var seg=(q.charAt(0)==='?'?q.slice(1):q).split('&');
        for(var i=0;i<seg.length;i++){
            if(!seg[i]) continue;
            var kv=seg[i].split('=');
            var k=decodeURIComponent(kv[0]||'');
            if(!k) continue;
            var v=decodeURIComponent(kv.slice(1).join('=')||'');
            out[k]=v;
        }
        return out;
    }

    function b64u(str){
        try{return btoa(unescape(encodeURIComponent(str))).replace(/\+/g,'-').replace(/\//g,'_').replace(/=+$/g,'');}
        catch(e){return '';}
    }

    function normalizeSize(size){
        size=parseInt(size||'80',10);
        if(!isFinite(size)||size<1) size=80;
        if(size>1024) size=1024;
        return size;
    }

    function qqToken(qq, size){
        if(!/^[1-9][0-9]{4,11}$/.test((qq||''))) return '';
        return b64u(JSON.stringify({q:String(qq),s:normalizeSize(size)}));
    }

    function commentToken(commentId, size){
        commentId=parseInt(commentId||'0',10);
        if(!isFinite(commentId)||commentId<1) return '';
        return b64u(JSON.stringify({c:commentId,s:normalizeSize(size)}));
    }

    function getDefaultAvatar(p){
        if(defaultType==='custom'&&defaultUrl){return defaultUrl;}
        if(defaultType==='auto'&&p&&p.d){return p.d.toString();}
        return resolvedDefault||'mm';
    }

    function proxyToken(hash, search){
        var p=parseQ(search);
        var s=normalizeSize(p.s||'80');
        var r=(p.r||'X').toString();
        var d=getDefaultAvatar(p);
        return b64u(JSON.stringify({h:(hash||'').toLowerCase(),s:s,r:r,d:d}));
    }

    function parseCandidate(raw){
        if(!raw || typeof raw !== 'string') return null;
        var value=raw.trim();
        if(!value || /^(data|blob):/i.test(value)) return null;

        try {
            var url=new URL(value, window.location.href);
            var path=(url.pathname||'').replace(/\/+$/,'');
            var segs=path ? path.split('/').filter(Boolean) : [];
            var host=(url.hostname||'').toLowerCase();
            var joined=segs.join('/').toLowerCase();
            var query=parseQ(url.search||'');

            var commentMatch=path.match(/\/mirages-api\/comment-avatar\/(\d+)(?:_(\d+))?\.json$/i);
            if(commentMatch){
                return {
                    type: 'comment',
                    commentId: commentMatch[1],
                    size: normalizeSize(commentMatch[2] || '100')
                };
            }

            var qq=(query.nk||query.dst_uin||'').toString().trim();
            var looksLikeQqHost=/(^|\.)(thirdqq|qlogo)\.cn$/.test(host) || host === 'q.qlogo.cn' || host === 'q1.qlogo.cn';
            var looksLikeQqPath=joined.indexOf('headimg_dl') !== -1 || (query.b||'').toString().toLowerCase() === 'qq';
            if(/^[1-9][0-9]{4,11}$/.test(qq) && (looksLikeQqHost || looksLikeQqPath)) {
                return {
                    type: 'qq',
                    qq: qq,
                    size: normalizeSize(query.s || query.spec || '80')
                };
            }

            var hash=segs.length ? segs[segs.length - 1] : '';
            if(!/^[a-f0-9]{32}$/i.test(hash)) return null;

            var looksLikeAvatarHost=/(^|\.)(gravatar|cravatar|weavatar)/.test(host) || host.indexOf('avatar') !== -1;
            var looksLikeAvatarPath=joined.indexOf('avatar') !== -1 || joined.indexOf('wavatar') !== -1 || joined.indexOf('gravatar') !== -1;
            var looksLikeAvatarQuery=Object.prototype.hasOwnProperty.call(query, 'd') || Object.prototype.hasOwnProperty.call(query, 's') || Object.prototype.hasOwnProperty.call(query, 'r');

            if(!looksLikeAvatarHost && !looksLikeAvatarPath && !looksLikeAvatarQuery) {
                return null;
            }

            return {
                type: 'hash',
                hash: hash.toLowerCase(),
                search: url.search||''
            };
        } catch (e) {
            return null;
        }
    }

    function buildAvatarUrl(candidate){
        if(!candidate) return '';

        if(candidate.type === 'comment'){
            var commentRouteToken=commentToken(candidate.commentId, candidate.size);
            return commentRouteToken ? commentProxyBase + '/' + commentRouteToken : '';
        }

        if(candidate.type === 'qq'){
            var qqRouteToken=qqToken(candidate.qq, candidate.size);
            return qqRouteToken ? qqProxyBase + '/' + qqRouteToken : '';
        }

        var hash=candidate.hash;
        var search=candidate.search || '';
        if(useProxy){
            var token=proxyToken(hash, search);
            return token ? proxyBase + '/' + token : '';
        }

        var p=parseQ(search);
        return mirrorBase + '/' + hash.toLowerCase()
            + '?s=' + encodeURIComponent(p.s || '80')
            + '&r=' + encodeURIComponent(p.r || 'X')
            + '&d=' + encodeURIComponent(getDefaultAvatar(p));
    }

    function rewriteSrcset(value){
        if(!value) return value;
        var parts=value.split(',');
        var changed=false;

        for(var i=0;i<parts.length;i++){
            var item=parts[i].trim();
            if(!item) continue;

            var tokens=item.split(/\s+/);
            var candidate=parseCandidate(tokens[0]);
            if(!candidate) continue;

            var next=buildAvatarUrl(candidate);
            if(!next) continue;

            tokens[0]=next;
            parts[i]=tokens.join(' ');
            changed=true;
        }

        return changed ? parts.join(', ') : value;
    }

    function rewriteAttr(node, attr){
        var value=node.getAttribute(attr);
        if(!value) return;

        var next=value;
        if(attr === 'srcset' || attr === 'data-srcset'){
            next=rewriteSrcset(value);
        } else {
            var candidate=parseCandidate(value);
            if(!candidate) return;
            next=buildAvatarUrl(candidate);
            if(!next) return;
        }

        if(next !== value){
            node.setAttribute(attr, next);
        }
    }

    function replaceOne(img){
        if(!img || !img.getAttribute) return;
        for(var i=0;i<attrs.length;i++){
            rewriteAttr(img, attrs[i]);
        }
    }

    function run(){
        var imgs=document.querySelectorAll('img[src],img[data-src],img[data-original],img[data-lazy-src],img[srcset],img[data-srcset]');
        for(var i=0;i<imgs.length;i++){
            replaceOne(imgs[i]);
        }
    }

    run();

    if(document.readyState==='loading'){
        document.addEventListener('DOMContentLoaded', run);
    }

    document.addEventListener('ab:pageload', run);
})();</script>
ABAVATAR_ARCHIVE_JS;

        echo str_replace(
            array(
                '__USE_PROXY__',
                '__PROXY_BASE__',
                '__QQ_PROXY_BASE__',
                '__COMMENT_PROXY_BASE__',
                '__MIRROR_BASE__',
                '__DEFAULT_TYPE__',
                '__DEFAULT_URL__',
                '__RESOLVED_DEFAULT__',
            ),
            array(
                $useProxy ? 'true' : 'false',
                json_encode($proxyBase, JSON_UNESCAPED_SLASHES),
                json_encode($qqProxyBase, JSON_UNESCAPED_SLASHES),
                json_encode($commentProxyBase, JSON_UNESCAPED_SLASHES),
                json_encode($mirrorBase, JSON_UNESCAPED_SLASHES),
                json_encode($defaultType, JSON_UNESCAPED_SLASHES),
                json_encode($defaultUrl, JSON_UNESCAPED_SLASHES),
                json_encode($resolvedDefault, JSON_UNESCAPED_SLASHES),
            ),
            $script
        );
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
