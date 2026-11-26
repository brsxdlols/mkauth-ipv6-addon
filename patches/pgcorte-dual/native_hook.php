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
function mkauthPgcorteDualAdmin($body, $status, $installer)
{
    if ($status !== 200 || !is_string($installer) || $installer === '' ||
        strpos($body, 'mkauth-pgcorte-dual-installer') !== false ||
        stripos($body, '</body>') === false ||
        (strpos($body, 'PGCORTE') === false && strpos($body, 'ler_pgcorte') === false)) return $body;
    $code = htmlspecialchars($installer, ENT_QUOTES, 'UTF-8');
    $panel = '<section id="mkauth-pgcorte-dual-installer" style="margin:24px;padding:18px;border:1px solid #bbb;border-radius:8px;background:#fff;color:#222">'
        .'<h3>PGCORTE din&acirc;mico — IPv4 e IPv6 (RouterOS 6/7)</h3>'
        .'<p>Copie e cole no terminal do MikroTik. Cria ou atualiza o script e o scheduler de 1 minuto, sem duplicar.</p>'
        .'<p>O RADIUS controla o bloqueio; este leitor acrescenta os nomes dos clientes. O agendador antigo ler_pgcorte ser&aacute; desativado para evitar entradas est&aacute;ticas.</p>'
        .'<textarea id="mkauth-pgcorte-dual-code" readonly style="display:block;box-sizing:border-box;width:100%;height:auto;min-height:180px;overflow:hidden;font-family:monospace">'.$code.'</textarea>'
        .'<button type="button" id="mkauth-pgcorte-dual-copy" style="margin-top:10px;padding:10px">Copiar instalador — script + scheduler</button>'
        .'<script>(function(){var b=document.getElementById("mkauth-pgcorte-dual-copy"),t=document.getElementById("mkauth-pgcorte-dual-code");function fit(){t.style.height="auto";t.style.height=(t.scrollHeight+2)+"px";}fit();window.addEventListener("resize",fit);b.onclick=async function(){var ok=false;if(window.isSecureContext&&navigator.clipboard){try{await navigator.clipboard.writeText(t.value);ok=true}catch(e){}}if(!ok){t.focus();t.select();try{ok=document.execCommand("copy")}catch(e){}}b.textContent=ok?"Copiado!":"Selecione o codigo e pressione Ctrl+C";};})();</script></section>';
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
