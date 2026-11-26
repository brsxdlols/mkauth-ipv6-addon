const assert = require('assert');
const fs = require('fs');
const path = require('path');
const vm = require('vm');

(async function () {
    const script = fs.readFileSync(path.join(__dirname, '../native_pppoe_help.js'), 'utf8');
    const api = {document: {readyState: 'loading', addEventListener() {}}};
    assert(script.includes('    function mount() {'));
    vm.runInNewContext(script.replace('    function mount() {',
        '    globalThis.fixtureFilter=filterCode;\n    function mount() {'), api);
    const samples = [
        '/tool fetch user=$USER password=$PASS url="https://$IPMKAUTH/mkt/pppoe" dst-path=mkt_pppoe.rsc;',
        String.raw`/system script add name=ler_pppoe source=":global USER \"fixture-user\";\r\n:global PASS \"fixture-password\";\r\n/tool fetch user=\$USER password=\$PASS url=\"https\://\$IPMKAUTH/mkt/pppoe\" dst-path=mkt_pppoe.rsc;"`,
        '/tool fetch url="https://$IPMKAUTH/mkt/pppoe?existing=value";'
    ];
    for (const source of samples) {
        const filtered = api.fixtureFilter(source, '172.16.12.125');
        assert(filtered.includes('/mkt/pppoe/172.16.12.125'));
        assert.strictEqual(filtered.replace('/mkt/pppoe/172.16.12.125', '/mkt/pppoe'), source);
    }
    assert.strictEqual(api.fixtureFilter('/tool fetch url="https://host/mkt/pppoe/192.0.2.1";', '172.16.12.125'),
        '/tool fetch url="https://host/mkt/pppoe/172.16.12.125";');
    for (const ip of ['', '0.0.0.0', '255.255.255.255', '256.1.1.1', '172.016.12.125', '::1', 'host.example', '1.2.3.4"; bad']) {
        assert.throws(() => api.fixtureFilter(samples[0], ip));
    }
    assert.throws(() => api.fixtureFilter(samples[0] + '\n' + samples[0], '172.16.12.125'));
    assert.throws(() => api.fixtureFilter('/tool fetch url="https://host/mkt/pppoe_other";', '172.16.12.125'));

    const currentId = '11111111-1111-1111-1111-111111111111';
    for (const mode of ['server', 'form', 'ambiguous', 'mismatch']) {
        const elements = {};
        function element() {
            return {style: {}, value: '', hidden: false, scrollHeight: 200,
                setAttribute() {}, appendChild() {}, focus() { this.focused = true; }, select() {},
                addEventListener(type, handler) { this.events = this.events || {}; this.events[type] = handler; }};
        }
        const current = element(), preview = element(), error = element(), copy = element(), close = element();
        const fields = {'#mkauth-pppoe-current': current, '#mkauth-pppoe-code': preview,
            '#mkauth-pppoe-error': error, '#mkauth-pppoe-copy': copy};
        let dialog, help, clipboard = '', copied = 0, fetched = 0;
        const sourceNode = {textContent: samples[1]};
        const article = {querySelector: selector => selector === 'pre' ? sourceNode : null};
        const header = {querySelector: () => ({textContent: 'PPPOE'}), closest: () => article,
            appendChild(node) { help = node; elements[node.id] = node; }};
        elements['mkauth-pgcorte-dual-installer'] = {getAttribute() {
            return JSON.stringify({id: mode === 'mismatch' ? 'another-id' : currentId,
                ip: mode === 'server' ? '172.31.255.1' : '', name: 'current fixture'});
        }};
        const window = {isSecureContext: true, addEventListener() {}, location: {
            href: 'https://mkauth.test/admin/ramal_det.hhvm?id=' + currentId, search: '?id=' + currentId
        }};
        const context = {window, URL, URLSearchParams,
            navigator: {clipboard: {async writeText(value) { clipboard = value; }}},
            async fetch(url, options) {
                fetched++; assert.strictEqual(new URL(url).searchParams.get('id'), currentId);
                assert.strictEqual(options.credentials, 'same-origin');
                return {ok: true, url, async text() { return 'native form fixture'; }};
            },
            DOMParser: class { parseFromString(text) {
                assert.strictEqual(text, 'native form fixture');
                return {querySelectorAll: () => mode === 'ambiguous'
                    ? [{value: '192.0.2.1'}, {value: '192.0.2.2'}] : [{value: '172.31.255.1'}]};
            }},
            document: {
                readyState: 'complete', getElementById: id => elements[id],
                querySelectorAll: selector => selector.includes('accordion') ? [header] : [],
                body: {appendChild(node) { elements[node.id] = node; }},
                createElement(tag) {
                    const node = element();
                    if (tag === 'dialog') {
                        dialog = node; node.querySelector = selector => fields[selector];
                        node.querySelectorAll = () => [close];
                        node.showModal = () => { node.open = true; };
                        node.close = () => { node.open = false; if (node.events.close) node.events.close(); };
                    }
                    return node;
                },
                execCommand() { copied++; return true; }
            }};
        vm.runInNewContext(script, context);
        let prevented = false, stopped = false;
        await help.events.click({preventDefault() { prevented = true; }, stopImmediatePropagation() { stopped = true; }});
        assert(prevented && stopped && dialog.open);
        assert(!dialog.innerHTML.includes('id="mkauth-pppoe-ip"'));
        if (mode === 'ambiguous' || mode === 'mismatch') {
            assert.strictEqual(error.hidden, false); assert.strictEqual(copy.disabled, true);
            assert.strictEqual(preview.value, ''); assert.strictEqual(clipboard, '');
            if (mode === 'mismatch') assert.strictEqual(fetched, 0);
        } else {
            assert.strictEqual(preview.value, api.fixtureFilter(samples[1], '172.31.255.1'));
            assert.strictEqual(error.hidden, true); assert.strictEqual(copy.disabled, false);
            assert(current.textContent.includes('172.31.255.1'));
            await copy.events.click(); assert.strictEqual(clipboard, preview.value);
            window.isSecureContext = false;
            await copy.events.click(); assert.strictEqual(copied, 1);
            assert.strictEqual(fetched, mode === 'form' ? 1 : 0);
        }
        close.events.click(); assert.strictEqual(dialog.open, false); assert(help.focused);
    }
    console.log('PPPOE_RAMAL_FILTER_POPUP_COPY_OK');
})().catch(error => { console.error(error); process.exit(1); });
