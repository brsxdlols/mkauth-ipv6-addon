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

    const events = {}, elements = {};
    function element() {
        return {style: {}, value: '', hidden: false, scrollHeight: 200,
            setAttribute() {}, appendChild() {}, focus() { this.focused = true; }, select() {},
            addEventListener(type, handler) { this.events = this.events || {}; this.events[type] = handler; }};
    }
    const field = element(), preview = element(), error = element(), copy = element(), close = element();
    const fields = {'#mkauth-pppoe-ip': field, '#mkauth-pppoe-code': preview,
        '#mkauth-pppoe-error': error, '#mkauth-pppoe-copy': copy};
    let dialog, help, clipboard = '', copied = 0;
    const sourceNode = {textContent: samples[1]};
    const article = {querySelector: selector => selector === 'pre' ? sourceNode : null};
    const header = {querySelector: () => ({textContent: 'PPPOE'}), closest: () => article,
        appendChild(node) { help = node; elements[node.id] = node; }};
    const window = {isSecureContext: true, addEventListener() {}};
    const context = {window, navigator: {clipboard: {async writeText(value) { clipboard = value; }}}, document: {
        readyState: 'complete', getElementById: id => elements[id],
        querySelectorAll: () => [header],
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
    help.events.click({preventDefault() { prevented = true; }, stopImmediatePropagation() { stopped = true; }});
    assert(prevented && stopped && dialog.open && field.focused);
    await copy.events.click(); assert(error.hidden === false); assert.strictEqual(clipboard, '');
    field.value = '172.16.12.125'; field.events.input();
    assert.strictEqual(preview.value, api.fixtureFilter(samples[1], field.value));
    assert.strictEqual(error.hidden, true);
    await copy.events.click(); assert.strictEqual(clipboard, preview.value);
    window.isSecureContext = false;
    await copy.events.click(); assert.strictEqual(copied, 1);
    close.events.click(); assert.strictEqual(dialog.open, false); assert(help.focused);
    console.log('PPPOE_RAMAL_FILTER_POPUP_COPY_OK');
})().catch(error => { console.error(error); process.exit(1); });
