<?php

/* PHP 8.1 habilita MYSQLI_REPORT_STRICT por padrao. O addon trata erros
   explicitamente e precisa do mesmo comportamento no PHP 7 e PHP 8. */
if (function_exists('mysqli_report')) {
    mysqli_report(MYSQLI_REPORT_OFF);
}

/*
 * Nao carregue addons.class.php aqui. Em algumas versoes do MK-Auth esse
 * arquivo inicia uma sessao com outro nome e tambem imprime o diagnostico do
 * executor ("Exit code / Wall time / Output") na resposta HTTP. Alem de sujar
 * o topo, a sessao ja aberta impedia a leitura do cookie "mka" e causava
 * "Acesso negado" para um administrador realmente autenticado.
 *
 * O addon usa seu proprio manifest.json e as paginas carregam topo.php
 * diretamente; portanto esse runtime nao e necessario.
 */

function ipv6StartMkAuthSession()
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $candidates = array();
    if (isset($_COOKIE[session_name()])) {
        $candidates[] = session_name();
    }
    foreach (array('mka', 'PHPSESSID') as $name) {
        if (isset($_COOKIE[$name])) {
            $candidates[] = $name;
        }
    }
    $candidates[] = 'mka';

    foreach (array_unique($candidates) as $name) {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        session_name($name);
        @session_start();
        if (ipv6HasMkAuthSession()) {
            return;
        }
        @session_write_close();
        $_SESSION = array();
    }
    session_name('mka');
    @session_start();
}

function ipv6HasMkAuthSession()
{
    if (empty($_SESSION) || !is_array($_SESSION)) {
        return false;
    }
    foreach (array('mka_logado', 'MKA_Logado', 'logado', 'Logado', 'usuario', 'username', 'login', 'id_usuario') as $key) {
        if (isset($_SESSION[$key]) && $_SESSION[$key] !== '' && $_SESSION[$key] !== false) {
            return true;
        }
    }
    return false;
}

function ipv6RequireMkAuthLogin()
{
    ipv6StartMkAuthSession();
    if (!ipv6HasMkAuthSession()) {
        http_response_code(403);
        exit('Acesso negado... <a href="/admin/">Voltar ao MK-Auth</a>');
    }
    if (!headers_sent()) {
        header('Content-Type: text/html; charset=utf-8');
    }
}

function ipv6LoadAddonManifest()
{
    $manifest = __DIR__ . '/manifest.json';
    if (is_file($manifest)) {
        $decoded = json_decode((string) file_get_contents($manifest));
        if (is_object($decoded)) {
            return $decoded;
        }
    }
    return (object) array('name' => 'Painel IPv4 e IPv6', 'version' => '1.0');
}

