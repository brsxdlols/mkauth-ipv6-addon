(function () {
    var panel = document.getElementById('mkauth-pgcorte-dual-installer');
    var heading = document.getElementById('mkauth-pgcorte-dual-heading');
    var body = document.getElementById('mkauth-pgcorte-dual-body');
    var button = document.getElementById('mkauth-pgcorte-dual-copy');
    var code = document.getElementById('mkauth-pgcorte-dual-code');
    if (!panel || !heading || !body || !button || !code) return;

    function fit() {
        if (!panel.classList.contains('is-active')) return;
        code.style.height = 'auto';
        code.style.height = (code.scrollHeight + 2) + 'px';
    }
    function sync() {
        var open = panel.classList.contains('is-active');
        heading.setAttribute('aria-expanded', String(open));
        body.setAttribute('aria-hidden', String(!open));
        heading.querySelector('button').setAttribute('aria-label',
            (open ? 'Fechar ' : 'Abrir ') + 'PGCORTE DUAL STACK');
        fit();
    }
    function toggle(event) {
        event.preventDefault();
        event.stopImmediatePropagation();
        panel.classList.toggle('is-active');
        sync();
    }
    // Capture prevents the native accordion handler from toggling this bar twice.
    heading.addEventListener('click', toggle, true);
    heading.addEventListener('keydown', function (event) {
        if (event.key === 'Enter' || event.key === ' ') toggle(event);
    }, true);
    if (typeof MutationObserver !== 'undefined') {
        new MutationObserver(sync).observe(panel, {attributes: true, attributeFilter: ['class']});
    }
    window.addEventListener('resize', fit);

    function mountFallback() {
        var fallback = document.getElementById('mkauth-pgcorte-dual-fallback');
        if (!fallback) return;
        var headers = document.querySelectorAll('.accordions .accordion-header');
        for (var i = 0; i < headers.length; i++) {
            if (headers[i] === heading) continue;
            var label = headers[i].querySelector('p') || headers[i];
            if (label.textContent.trim().toUpperCase() !== 'PGCORTE') continue;
            var nativePanel = headers[i].closest('.accordion');
            if (!nativePanel) continue;
            panel.className = nativePanel.className.replace(/\bis-active\b/g, '').trim();
            nativePanel.parentNode.insertBefore(panel, nativePanel.nextSibling);
            fallback.parentNode.removeChild(fallback);
            break;
        }
        sync();
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', mountFallback);
    else mountFallback();
    sync();

    button.onclick = async function () {
        var ok = false;
        if (window.isSecureContext && navigator.clipboard) {
            try { await navigator.clipboard.writeText(code.value); ok = true; } catch (error) {}
        }
        if (!ok) {
            code.focus();
            code.select();
            try { ok = document.execCommand('copy'); } catch (error) {}
        }
        button.textContent = ok ? 'Copiado!' : 'Selecione o codigo e pressione Ctrl+C';
    };
})();
