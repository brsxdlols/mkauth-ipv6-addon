<?php
/* Preserve native authorization and IPv4 export; append only to a valid export. */
function mkauthPgcorteDualTransform($body, $status, $worker)
{
    if ($status !== 200 || !is_string($worker) || $worker === '' ||
        strpos($body, '/ip firewall address-list') === false ||
        strpos($body, 'api_corte_') === false ||
        strpos($body, '# BEGIN MKAUTH_PGCORTE_DUAL') !== false ||
        preg_match('/<(?:!doctype|html|body|br|b)\b/i', $body)) {
        return $body;
    }
    return $body."\n".$worker;
}
function mkauthPppoeRamalContext($id, array $globals)
{
    if (!is_string($id) || strlen($id) > 64) return [];
    $numeric = preg_match('/^[1-9][0-9]{0,9}$/D', $id);
    if (!$numeric && !preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/Di', $id)) return [];
    $fromRow = function ($row) use ($id, $numeric) {
        if (!is_array($row) || !isset($row['nasname'])) return [];
        $matches = $numeric ? isset($row['id']) && (string)$row['id'] === $id
            : isset($row['uuid_nas']) && strcasecmp((string)$row['uuid_nas'], $id) === 0;
        $ip = trim((string)$row['nasname']);
        if (!$matches || !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) || $ip === '0.0.0.0' || $ip === '255.255.255.255') return [];
        return ['id'=>$id, 'ip'=>$ip, 'name'=>(string)($row['shortname'] ?? '')];
    };
    foreach ($globals as $value) {
        $context = $fromRow($value);
        if ($context) return $context;
    }
    // Reuse the native page's existing connection; never store database credentials.
    foreach ($globals as $value) {
        if (!($value instanceof mysqli)) continue;
        try {
            $query = $numeric ? 'SELECT id,nasname,shortname FROM nas WHERE id=? LIMIT 2'
                : 'SELECT id,uuid_nas,nasname,shortname FROM nas WHERE uuid_nas=? LIMIT 2';
            $statement = @$value->prepare($query);
            if (!$statement) continue;
            $statement->bind_param('s', $id);
            $statement->execute();
            $rows = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
            $statement->close();
            if (count($rows) === 1 && ($context = $fromRow($rows[0]))) return $context;
        } catch (Throwable $failure) { /* The browser can read the native edit form. */ }
    }
    return [];
}

function mkauthPgcorteDualAdmin($body, $status, $installer)
{
    if ($status !== 200 || !is_string($installer) || $installer === '' ||
        strpos($body, 'mkauth-pgcorte-dual-installer') !== false ||
        stripos($body, '</body>') === false ||
        (strpos($body, 'PGCORTE') === false && strpos($body, 'ler_pgcorte') === false)) return $body;
    $script = @file_get_contents(__DIR__.'/native_admin.js');
    if (!is_string($script) || $script === '') return $body;
    $pppoeHelp = @file_get_contents(__DIR__.'/native_pppoe_help.js');
    if (is_string($pppoeHelp)) $script .= "\n".$pppoeHelp;
    $ramal = mkauthPppoeRamalContext($_GET['id'] ?? '', $GLOBALS);
    if (!$ramal && is_string($_GET['id'] ?? null)) $ramal = ['id'=>$_GET['id']];
    $ramalJson = htmlspecialchars(json_encode($ramal, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8');
    $code = htmlspecialchars($installer, ENT_QUOTES, 'UTF-8');
    $render = function ($classes) use ($code, $script, $ramalJson) {
        $classes = preg_replace('/\bis-active\b/', '', $classes);
        return '<article id="mkauth-pgcorte-dual-installer" data-ramal-context="'.$ramalJson.'" class="'.htmlspecialchars(trim($classes), ENT_QUOTES, 'UTF-8').'">'
            .'<div class="accordion-header" id="mkauth-pgcorte-dual-heading" role="button" tabindex="0" aria-expanded="false" aria-controls="mkauth-pgcorte-dual-body">'
            .'<p>PGCORTE DUAL STACK</p><button type="button" class="toggle" aria-label="Abrir PGCORTE DUAL STACK" tabindex="-1"></button></div>'
            .'<div class="accordion-body" id="mkauth-pgcorte-dual-body" role="region" aria-labelledby="mkauth-pgcorte-dual-heading" aria-hidden="true">'
            .'<div class="accordion-content"><p>RouterOS 6 e 7. Copie e cole no terminal para criar ou atualizar o script e o scheduler de 1 minuto, sem duplicar.</p>'
            .'<p>O RADIUS controla o bloqueio. O leitor identifica os clientes nas entradas existentes. O agendador legado ler_pgcorte ser&aacute; desativado para evitar importacao estatica concorrente.</p>'
            .'<button type="button" class="button is-primary is-small" id="mkauth-pgcorte-dual-copy">Copiar instalador — script + scheduler</button>'
            .'<textarea id="mkauth-pgcorte-dual-code" readonly spellcheck="false" style="display:block;box-sizing:border-box;width:100%;height:auto;min-height:180px;overflow:hidden;margin-top:12px;font-family:monospace">'.$code.'</textarea>'
            .'</div></div><style>#mkauth-pgcorte-dual-installer.is-active>.accordion-body{max-height:none}#mkauth-pgcorte-dual-heading{cursor:pointer}</style>'
            .'<script>'.$script.'</script></article>';
    };
    $inserted = false;
    $headerPattern = <<<'MK_HEADER'
~<[^>]+class\s*=\s*(["'])[^"']*\baccordion-header\b[^"']*\1[^>]*>(.*?)</(?:div|header)>~is
MK_HEADER;
    $classPattern = <<<'MK_CLASS'
~^<article\b[^>]*\sclass\s*=\s*(["'])(.*?)\1~is
MK_CLASS;
    $result = preg_replace_callback('~<article\b[^>]*>.*?</article>~is', function ($match) use (&$inserted, $render, $headerPattern, $classPattern) {
        $article = $match[0];
        if ($inserted || !preg_match($headerPattern, $article, $header)) return $article;
        $title = html_entity_decode(strip_tags($header[2]), ENT_QUOTES, 'UTF-8');
        if (preg_replace('/[\s+\-]+/', '', strtoupper($title)) !== 'PGCORTE') return $article;
        $classes = 'accordion';
        if (preg_match($classPattern, $article, $classMatch)) $classes = $classMatch[2];
        $inserted = true;
        return $article.$render($classes);
    }, $body);
    if ($inserted) return $result;
    $panel = '<section class="accordions" id="mkauth-pgcorte-dual-fallback" style="margin:24px">'.$render('accordion').'</section>';
    return preg_replace_callback('/<\/body>/i', function () use ($panel) { return $panel.'</body>'; }, $body, 1);
}

if (!defined('MKAUTH_PGCORTE_DUAL_TEST') &&
    in_array(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH), ['/mkt/pgcorte', '/mkt/pgcorte.hhvm', '/admin/ramal_det.hhvm'], true)) {
    $pending = '';
    ob_start(function ($chunk, $phase) use (&$pending) {
        if ($phase & PHP_OUTPUT_HANDLER_CLEAN) {
            $pending = '';
            return '';
        }
        $pending .= $chunk;
        if (!($phase & PHP_OUTPUT_HANDLER_FINAL)) return '';
        $body = $pending;
        $pending = '';
        if (parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) === '/admin/ramal_det.hhvm') {
            $installer = @file_get_contents(__DIR__.'/instalar_pgcorte_dual.rsc');
            $result = mkauthPgcorteDualAdmin($body, http_response_code() ?: 200, $installer);
        } else {
            $worker = @file_get_contents(__DIR__.'/pgcorte_dual.rsc');
            $result = mkauthPgcorteDualTransform($body, http_response_code() ?: 200, $worker);
        }
        if ($result !== $body && !headers_sent()) header_remove('Content-Length');
        return $result;
    });
}
