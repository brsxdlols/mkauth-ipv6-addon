<?php

function ipv6StartMkAuthSession(): void
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

function ipv6HasMkAuthSession(): bool
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

function ipv6RequireMkAuthLogin(): void
{
    ipv6StartMkAuthSession();
    if (!ipv6HasMkAuthSession()) {
        http_response_code(403);
        exit('Acesso negado... <a href="/admin/">Voltar ao MK-Auth</a>');
    }
}

function ipv6LoadAddonManifest(): object
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
