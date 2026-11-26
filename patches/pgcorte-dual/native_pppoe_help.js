(function () {
    'use strict';
    function validIp(value) {
        if (!/^(?:\d{1,3}\.){3}\d{1,3}$/.test(value)) return false;
        var parts = value.split('.');
        for (var i = 0; i < parts.length; i++) {
            if (Number(parts[i]) > 255 || String(Number(parts[i])) !== parts[i]) return false;
        }
        return value !== '0.0.0.0' && value !== '255.255.255.255';
    }
    function filterCode(source, ip) {
        if (!validIp(ip)) throw new Error('IPv4 do ramal nao identificado no cadastro.');
        var matches = 0;
        var result = source.replace(/\/mkt\/pppoe(?:\/(?:\d{1,3}\.){3}\d{1,3})?(?=\\*["']|\s|[?&#]|$)/g, function () {
            matches++;
            return '/mkt/pppoe/' + ip;
        });
        if (matches !== 1) throw new Error('Nao foi possivel identificar uma unica URL /mkt/pppoe no codigo desta pagina.');
        return result;
    }
    async function currentRamal() {
        var id = new URLSearchParams(window.location.search).get('id');
        if (!id) throw new Error('Ramal nao identificado nesta pagina.');
        var panel = document.getElementById('mkauth-pgcorte-dual-installer');
        var context = {};
        try { context = JSON.parse(panel ? panel.getAttribute('data-ramal-context') || '{}' : '{}'); } catch (failure) {}
        if (context.id && context.id !== id) throw new Error('O ramal da pagina mudou. Atualize antes de copiar o codigo.');
        if (context.id === id && validIp(context.ip)) return context;
        // Read the current ramal's native form with the existing authenticated session.
        var url = new URL('ramal_alt.hhvm', window.location.href);
        url.searchParams.set('id', id);
        var links = document.querySelectorAll('a[href*="ramal_alt.hhvm"]');
        for (var link = 0; link < links.length; link++) {
            if (typeof links[link].href !== 'string') continue;
            var nativeUrl = new URL(links[link].href, window.location.href);
            if (nativeUrl.origin === url.origin && nativeUrl.pathname === url.pathname && nativeUrl.searchParams.get('id') === id) { url = nativeUrl; break; }
        }
        var response = await fetch(url.href, {credentials: 'same-origin', cache: 'no-store'});
        if (!response.ok || (response.url && new URL(response.url).pathname !== url.pathname)) {
            throw new Error('Nao foi possivel identificar o ramal. Atualize a pagina ou confira seu acesso ao cadastro.');
        }
        var form = new DOMParser().parseFromString(await response.text(), 'text/html');
        var nodes = form.querySelectorAll('input[name="nasname"],input#nasname,input[name="ip"],input#ip,input[name="ipramal"],input[name="ip_ramal"]');
        var ips = [];
        for (var i = 0; i < nodes.length; i++) {
            var value = nodes[i].value.trim();
            if (validIp(value) && ips.indexOf(value) === -1) ips.push(value);
        }
        if (ips.length !== 1) throw new Error('Nao foi possivel identificar um unico IPv4 deste ramal. Confira seu cadastro no MK-Auth.');
        return {id: id, ip: ips[0], name: ''};
    }
    function mount() {
        if (document.getElementById('mkauth-pppoe-help')) return;
        var headers = document.querySelectorAll('.accordions .accordion-header');
        var header = null, article = null;
        for (var i = 0; i < headers.length; i++) {
            var label = headers[i].querySelector('p') || headers[i];
            if (label.textContent.trim().toUpperCase() === 'PPPOE') {
                header = headers[i]; article = header.closest('.accordion'); break;
            }
        }
        if (!header || !article) return;
        var help = document.createElement('button');
        help.id = 'mkauth-pppoe-help'; help.type = 'button'; help.textContent = '?';
        help.setAttribute('aria-label', 'Ajuda: copiar PPPoE de um ramal especifico');
        help.setAttribute('aria-haspopup', 'dialog');
        help.style.cssText = 'position:absolute;right:42px;top:50%;transform:translateY(-50%);width:20px;height:20px;border:0;border-radius:50%;background:rgba(10,10,10,.2);color:#fff;font-weight:bold;cursor:pointer;line-height:20px;padding:0';
        header.appendChild(help);
        var dialog = document.createElement('dialog');
        dialog.id = 'mkauth-pppoe-dialog';
        dialog.setAttribute('aria-labelledby', 'mkauth-pppoe-title');
        dialog.style.cssText = 'padding:0;border:0;background:transparent;max-width:calc(100vw - 24px);max-height:90vh';
        dialog.innerHTML = '<div class="modal-card" style="margin:0;max-width:calc(100vw - 24px);max-height:88vh">'
            + '<header class="modal-card-head"><p class="modal-card-title" id="mkauth-pppoe-title" style="white-space:normal">PPPoE deste ramal</p><button type="button" class="delete" data-pppoe-close aria-label="Fechar ajuda"></button></header>'
            + '<section class="modal-card-body"><p>O c&oacute;digo abaixo j&aacute; vem completo e filtrado para o <strong>ramal aberto nesta p&aacute;gina</strong>. Basta copiar e colar no terminal.</p>'
            + '<p id="mkauth-pppoe-current" style="margin-top:12px">Identificando o ramal...</p>'
            + '<p class="help is-danger" id="mkauth-pppoe-error" role="alert" hidden></p>'
            + '<p style="margin-top:12px">IPMKAUTH, USER e PASS permanecem como foram gerados na p&aacute;gina. O IP do ramal &eacute; identificado automaticamente; n&atilde;o &eacute; preciso preencher nada.</p>'
            + '<p style="margin-top:10px">Se ler_pppoe j&aacute; existe no MikroTik, atualize o Source do script existente; n&atilde;o crie outro agendamento.</p>'
            + '<label class="label" for="mkauth-pppoe-code" style="margin-top:16px">C&oacute;digo com filtro</label><textarea readonly spellcheck="false" id="mkauth-pppoe-code" style="display:block;width:100%;box-sizing:border-box;min-height:150px;overflow:hidden;font:12px monospace"></textarea></section>'
            + '<footer class="modal-card-foot"><button type="button" class="button is-primary" id="mkauth-pppoe-copy">Copiar c&oacute;digo deste ramal</button><button type="button" class="button" data-pppoe-close>Fechar</button></footer></div>';
        var style = document.createElement('style');
        style.textContent = '#mkauth-pppoe-dialog::backdrop{background:rgba(10,10,10,.65)}';
        dialog.appendChild(style); document.body.appendChild(dialog);
        var current = dialog.querySelector('#mkauth-pppoe-current');
        var contextPromise = null;
        var contextId = null;
        var code = dialog.querySelector('#mkauth-pppoe-code');
        var error = dialog.querySelector('#mkauth-pppoe-error');
        var copy = dialog.querySelector('#mkauth-pppoe-copy');
        function fit() { code.style.height = 'auto'; code.style.height = (code.scrollHeight + 2) + 'px'; }
        async function prepare() {
            code.value = ''; error.hidden = true; copy.disabled = true;
            copy.textContent = 'Preparando codigo...';
            try {
                var pageId = new URLSearchParams(window.location.search).get('id');
                if (!contextPromise || contextId !== pageId) { contextId = pageId; contextPromise = currentRamal(); }
                var ramal = await contextPromise;
                current.textContent = 'Ramal atual: ' + (ramal.name ? ramal.name + ' — ' : '') + ramal.ip;
                var nativeCode = article.querySelector('pre') || article.querySelector('textarea') || article.querySelector('code');
                if (!nativeCode) throw new Error('Codigo PPPoE nao localizado nesta pagina.');
                var source = typeof nativeCode.value === 'string' ? nativeCode.value : nativeCode.textContent;
                code.value = filterCode(source, ramal.ip);
                copy.textContent = 'Copiar codigo deste ramal'; copy.disabled = false;
                fit(); return true;
            } catch (failure) {
                contextPromise = null;
                error.textContent = failure.message; error.hidden = false;
                current.textContent = 'Ramal nao identificado.';
                copy.textContent = 'Codigo indisponivel'; fit(); return false;
            }
        }
        help.addEventListener('click', async function (event) {
            event.preventDefault(); event.stopImmediatePropagation();
            dialog.showModal();
            if (await prepare()) copy.focus();
        }, true);
        copy.addEventListener('click', async function () {
            if (!await prepare()) return;
            var ok = false;
            if (window.isSecureContext && navigator.clipboard) {
                try { await navigator.clipboard.writeText(code.value); ok = true; } catch (failure) {}
            }
            if (!ok) {
                code.focus(); code.select();
                try { ok = document.execCommand('copy'); } catch (failure) {}
            }
            copy.textContent = ok ? 'Copiado!' : 'Selecione o codigo e pressione Ctrl+C';
        });
        var closes = dialog.querySelectorAll('[data-pppoe-close]');
        for (var j = 0; j < closes.length; j++) closes[j].addEventListener('click', function () { dialog.close(); });
        dialog.addEventListener('close', function () { help.focus(); });
        dialog.addEventListener('click', function (event) {
            if (event.target !== dialog) return;
            var bounds = dialog.getBoundingClientRect();
            if (event.clientX < bounds.left || event.clientX > bounds.right || event.clientY < bounds.top || event.clientY > bounds.bottom) dialog.close();
        });
        window.addEventListener('resize', function () { if (dialog.open) fit(); });
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', mount);
    else mount();
})();
