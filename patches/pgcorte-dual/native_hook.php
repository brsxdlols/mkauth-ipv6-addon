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
if (!defined('MKAUTH_PGCORTE_DUAL_TEST') &&
    in_array(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH), ['/mkt/pgcorte', '/mkt/pgcorte.hhvm'], true)) {
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
        $worker = @file_get_contents(__DIR__.'/pgcorte_dual.rsc');
        $result = mkauthPgcorteDualTransform($body, http_response_code() ?: 200, $worker);
        if ($result !== $body && !headers_sent()) header_remove('Content-Length');
        return $result;
    });
}
