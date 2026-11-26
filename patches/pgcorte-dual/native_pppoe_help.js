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
        if (!validIp(ip)) throw new Error('Informe o IPv4 do ramal cadastrado no MK-Auth.');
        var matches = 0;
        var result = source.replace(/\/mkt\/pppoe(?:\/(?:\d{1,3}\.){3}\d{1,3})?(?=\\*["']|\s|[?&#]|$)/g, function () {
            matches++;
            return '/mkt/pppoe/' + ip;
        });
        if (matches !== 1) throw new Error('Nao foi possivel identificar uma unica URL /mkt/pppoe no codigo desta pagina.');
        return result;
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
            + '<header class="modal-card-head"><p class="modal-card-title" id="mkauth-pppoe-title" style="white-space:normal">PPPoE de um ramal espec&iacute;fico</p><button type="button" class="delete" data-pppoe-close aria-label="Fechar ajuda"></button></header>'
            + '<section class="modal-card-body"><p>Informe o <strong>IP do ramal cadastrado no MK-Auth</strong>. O bot&atilde;o abaixo copia o c&oacute;digo completo desta se&ccedil;&atilde;o, alterando somente a URL de exporta&ccedil;&atilde;o.</p>'
            + '<p style="margin-top:10px">Sem filtro: <code>/mkt/pppoe</code><br>Somente um ramal: <code>/mkt/pppoe/172.16.12.125</code></p>'
            + '<div class="field" style="margin-top:16px"><label class="label" for="mkauth-pppoe-ip">IP do ramal</label><div class="control"><input class="input" id="mkauth-pppoe-ip" placeholder="Ex.: 172.16.12.125" autocomplete="off" inputmode="decimal"></div></div>'
            + '<p class="help is-danger" id="mkauth-pppoe-error" role="alert" hidden></p>'
            + '<p style="margin-top:12px">IPMKAUTH, USER e PASS permanecem como foram gerados na p&aacute;gina. O IP informado aqui &eacute; o do ramal, n&atilde;o o IP do MK-Auth nem o IP de um cliente PPPoE.</p>'
            + '<p style="margin-top:10px">Se ler_pppoe j&aacute; existe no MikroTik, atualize o Source do script existente; n&atilde;o crie outro agendamento.</p>'
            + '<label class="label" for="mkauth-pppoe-code" style="margin-top:16px">C&oacute;digo com filtro</label><textarea readonly spellcheck="false" id="mkauth-pppoe-code" style="display:block;width:100%;box-sizing:border-box;min-height:150px;overflow:hidden;font:12px monospace"></textarea></section>'
            + '<footer class="modal-card-foot"><button type="button" class="button is-primary" id="mkauth-pppoe-copy">Copiar c&oacute;digo deste ramal</button><button type="button" class="button" data-pppoe-close>Fechar</button></footer></div>';
        var style = document.createElement('style');
        style.textContent = '#mkauth-pppoe-dialog::backdrop{background:rgba(10,10,10,.65)}';
        dialog.appendChild(style); document.body.appendChild(dialog);
        var ip = dialog.querySelector('#mkauth-pppoe-ip');
        var code = dialog.querySelector('#mkauth-pppoe-code');
        var error = dialog.querySelector('#mkauth-pppoe-error');
        var copy = dialog.querySelector('#mkauth-pppoe-copy');
        function fit() { code.style.height = 'auto'; code.style.height = (code.scrollHeight + 2) + 'px'; }
        function update(showError) {
            code.value = ''; error.hidden = true;
            copy.textContent = 'Copiar codigo deste ramal';
            try {
                var nativeCode = article.querySelector('pre') || article.querySelector('textarea') || article.querySelector('code');
                if (!nativeCode) throw new Error('Codigo PPPoE nao localizado nesta pagina.');
                var source = typeof nativeCode.value === 'string' ? nativeCode.value : nativeCode.textContent;
                code.value = filterCode(source, ip.value.trim());
                fit(); return true;
            } catch (failure) {
                if (showError) { error.textContent = failure.message; error.hidden = false; }
                fit(); return false;
            }
        }
        help.addEventListener('click', function (event) {
            event.preventDefault(); event.stopImmediatePropagation();
            dialog.showModal(); update(false); ip.focus();
        }, true);
        ip.addEventListener('input', function () { update(ip.value.trim() !== ''); });
        copy.addEventListener('click', async function () {
            if (!update(true)) { ip.focus(); return; }
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
