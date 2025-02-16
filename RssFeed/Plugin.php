<?php
if (!defined('__TYPECHO_ROOT_DIR__')) exit;

/**
 * RSS/Atom 订阅插件
 * 
 * @package RssFeed
 * @author 子夜松声
 * @version 1.7
 * @link https://xyzbz.cn/
 */
class RssFeed_Plugin implements Typecho_Plugin_Interface
{
    // 缓存键名前缀
    const CACHE_KEY = 'rssfeed_latest_items';

    /**
     * 激活插件
     */
    public static function activate()
    {
        // 挂载到内容解析钩子
        Typecho_Plugin::factory('Widget_Abstract_Contents')->contentEx = array('RssFeed_Plugin', 'parseShortcode');

        // 创建缓存表
        $db = Typecho_Db::get();
        $prefix = $db->getPrefix();
        $sql = "CREATE TABLE IF NOT EXISTS `{$prefix}rssfeed_cache` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `cache_key` VARCHAR(255) NOT NULL,
            `cache_value` TEXT NOT NULL,
            `expire_time` INT UNSIGNED NOT NULL,
            UNIQUE KEY (`cache_key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8;";
        $db->query($sql);
    }

    /**
     * 禁用插件
     */
    public static function deactivate()
    {
        // 删除缓存表
        $db = Typecho_Db::get();
        $prefix = $db->getPrefix();
        $sql = "DROP TABLE IF EXISTS `{$prefix}rssfeed_cache`;";
        $db->query($sql);
    }

    /**
     * 插件配置面板
     */
    public static function config(Typecho_Widget_Helper_Form $form)
    {
        // 显示文章数量
        $itemCount = new Typecho_Widget_Helper_Form_Element_Text('itemCount', NULL, '10', _t('显示文章数量'), _t('请输入要显示的RSS/Atom文章数量（默认10条）'));
        $form->addInput($itemCount->addRule('isInteger', _t('请输入整数')));

        // 订阅刷新时间（单位：分钟）
        $refreshInterval = new Typecho_Widget_Helper_Form_Element_Text('refreshInterval', NULL, '60', _t('订阅刷新时间（分钟）'), _t('请输入订阅刷新时间（单位：分钟，默认60分钟，最小值为1）'));
        $refreshInterval->addRule('isInteger', _t('请输入整数'))
            ->addRule('min', _t('刷新时间不能小于1分钟'), 1);
        $form->addInput($refreshInterval);

        // 多个 RSS/Atom 源
        $rssUrls = new Typecho_Widget_Helper_Form_Element_Textarea('rssUrls', NULL, '', _t('RSS/Atom源地址列表'), _t('每行输入一个RSS/Atom源地址'));
        $form->addInput($rssUrls);
    }

    /**
     * 个人用户的配置面板
     */
    public static function personalConfig(Typecho_Widget_Helper_Form $form)
    {
    }

    /**
     * 获取缓存键
     */
    private static function getCacheKey()
    {
        $options = Typecho_Widget::widget('Widget_Options');
        return self::CACHE_KEY . '_' . md5($options->plugin('RssFeed')->rssUrls);
    }

    /**
     * 获取缓存数据
     */
    private static function getCache($key)
    {
        $db = Typecho_Db::get();
        $prefix = $db->getPrefix();
        $row = $db->fetchRow($db->select()->from("{$prefix}rssfeed_cache")
            ->where('cache_key = ?', $key)
            ->where('expire_time > ?', time()));

        if ($row) {
            self::logError("缓存已读取，键：{$key}，过期时间：" . date('Y-m-d H:i:s', $row['expire_time']));
            return unserialize($row['cache_value']);
        }

        self::logError("缓存未命中或已过期，键：{$key}");
        return null;
    }

    /**
     * 设置缓存数据
     */
    private static function setCache($key, $value, $expire)
    {
        $db = Typecho_Db::get();
        $prefix = $db->getPrefix();
        $data = [
            'cache_key' => $key,
            'cache_value' => serialize($value),
            'expire_time' => time() + $expire
        ];

        // 删除旧的缓存（如果存在）
        $db->query($db->delete("{$prefix}rssfeed_cache")->where('cache_key = ?', $key));

        // 插入新的缓存
        $db->query($db->insert("{$prefix}rssfeed_cache")->rows($data));

        // 记录日志
        self::logError("缓存已写入，键：{$key}，过期时间：" . date('Y-m-d H:i:s', time() + $expire));
    }

    /**
     * 获取最新的 RSS/Atom 文章
     */
    public static function getLatestRssItems()
    {
        $options = Typecho_Widget::widget('Widget_Options');
        $cacheKey = self::getCacheKey(); // 使用新的缓存键
        $cacheExpire = $options->plugin('RssFeed')->refreshInterval * 60;

        // 从缓存中获取数据
        $cachedItems = self::getCache($cacheKey);
        if ($cachedItems !== null) {
            return $cachedItems;
        }

        $rssUrls = explode("\n", $options->plugin('RssFeed')->rssUrls);
        $itemCount = intval($options->plugin('RssFeed')->itemCount);

        if (empty($rssUrls)) {
            return null;
        }

        $allItems = [];
        foreach ($rssUrls as $rssUrl) {
            $rssUrl = trim($rssUrl);
            if (empty($rssUrl)) {
                continue;
            }

            // 获取 RSS/Atom 内容
            $rssContent = @file_get_contents($rssUrl);
            if ($rssContent === false) {
                self::logError("无法获取 RSS/Atom 内容：{$rssUrl}");
                continue;
            }

            $xml = @simplexml_load_string($rssContent);
            if ($xml === false) {
                self::logError("无法解析 RSS/Atom 内容：{$rssUrl}");
                continue;
            }

            // 提取域名
            $domain = parse_url($rssUrl, PHP_URL_HOST);

            // 判断是 RSS 还是 Atom
            if (isset($xml->channel)) {
                // RSS 格式
                foreach ($xml->channel->item as $item) {
                    $pubDate = isset($item->pubDate) ? strtotime((string)$item->pubDate) : time();
                    $pubDateFormatted = date('Y-m-d H:i:s', $pubDate); // 格式化时间戳
                    $allItems[] = [
                        'title' => htmlspecialchars((string)$item->title, ENT_QUOTES, 'UTF-8'),
                        'link' => htmlspecialchars((string)$item->link, ENT_QUOTES, 'UTF-8'),
                        'description' => self::truncateDescription((string)$item->description, 200),
                        'source' => $domain, // 只显示域名
                        'pubDate' => $pubDateFormatted // 使用格式化后的时间
                    ];
                }
            } elseif (isset($xml->entry)) {
                // Atom 格式
                foreach ($xml->entry as $entry) {
                    $pubDate = isset($entry->updated) ? strtotime((string)$entry->updated) : time();
                    $pubDateFormatted = date('Y-m-d H:i:s', $pubDate); // 格式化时间戳
                    $link = '';
                    if (isset($entry->link['href'])) {
                        $link = (string)$entry->link['href'];
                    }
                    $description = isset($entry->summary) ? (string)$entry->summary : (isset($entry->content) ? (string)$entry->content : '');
                    $allItems[] = [
                        'title' => htmlspecialchars((string)$entry->title, ENT_QUOTES, 'UTF-8'),
                        'link' => htmlspecialchars($link, ENT_QUOTES, 'UTF-8'),
                        'description' => self::truncateDescription($description, 200),
                        'source' => $domain, // 只显示域名
                        'pubDate' => $pubDateFormatted // 使用格式化后的时间
                    ];
                }
            }
        }

        // 按发布时间排序
        usort($allItems, function($a, $b) {
            return strtotime($b['pubDate']) - strtotime($a['pubDate']);
        });

        // 仅返回最新的文章
        $latestItems = array_slice($allItems, 0, $itemCount);

        // 缓存结果
        self::setCache($cacheKey, $latestItems, $cacheExpire);

        return $latestItems;
    }

    /**
     * 截断文章内容
     */
    private static function truncateDescription($description, $length)
    {
        if (mb_strlen($description, 'UTF-8') > $length) {
            $description = mb_substr($description, 0, $length, 'UTF-8') . '...';
        }
        return $description;
    }

    /**
     * 记录错误日志
     */
    private static function logError($message)
    {
        $logFile = __TYPECHO_ROOT_DIR__ . '/usr/plugins/RssFeed/logs/error.log';
        if (!file_exists(dirname($logFile))) {
            mkdir(dirname($logFile), 0755, true);
        }
        file_put_contents($logFile, date('Y-m-d H:i:s') . " - " . $message . PHP_EOL, FILE_APPEND);
    }

    /**
     * 渲染 RSS/Atom 内容
     */
public static function render()
{
    $items = self::getLatestRssItems();
    if ($items) {
        echo '<div class="rss-feed">';
        echo '<h3>' . _t('最新订阅文章') . '</h3>';
        foreach ($items as $item) {
            echo <<<HTML
<div class="rss-item">
    <h4><a href="{$item['link']}" target="_blank">{$item['title']}</a></h4>
    <p>{$item['description']}</p>
    <p><small>(来源): {$item['source']} | (发布时间): {$item['pubDate']}</small></p>
</div>
HTML;
        }
        echo '</div>';
    } else {
        echo '<div class="rss-feed"><p>' . _t('无法获取 RSS/Atom 内容，请检查配置。') . '</p></div>';
    }
}

    /**
     * 解析短代码
     */
public static function parseShortcode($content, $widget)
{
    // 匹配短代码 [rssfeed]
    if (preg_match('/\[rssfeed\]/', $content)) {
        ob_start();
        self::render();
        $rssContent = ob_get_clean();

        // 将 RSS 内容包裹在一个 div 中，避免破坏原有的 HTML 结构
        $rssContent = '<div class="rss-feed-container">' . $rssContent . '</div>';

        // 替换短代码
        $content = str_replace('[rssfeed]', $rssContent, $content);
    }
    return $content;
}
}