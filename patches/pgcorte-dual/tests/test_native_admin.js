const assert = require('assert');
const fs = require('fs');
const path = require('path');
const vm = require('vm');

(async function () {
    const script = fs.readFileSync(path.join(__dirname, '../native_admin.js'), 'utf8');
    for (const fallbackMode of [false, true]) {
        const classes = new Set(['accordion', 'is-small']);
        const handlers = {}, headingAttrs = {}, bodyAttrs = {}, toggleAttrs = {};
        let copied = 0, written = '', mounted = false, removed = false;
        const panel = {classList: {
            contains: name => classes.has(name),
            toggle: name => classes.has(name) ? classes.delete(name) : classes.add(name)
        }};
        Object.defineProperty(panel, 'className', {
            get: () => [...classes].join(' '),
            set: value => { classes.clear(); value.split(/\s+/).forEach(c => classes.add(c)); }
        });
        const heading = {
            addEventListener: (type, handler, capture) => { assert.strictEqual(capture, true); handlers[type] = handler; },
            setAttribute: (name, value) => headingAttrs[name] = value,
            querySelector: () => ({setAttribute: (name, value) => toggleAttrs[name] = value})
        };
        const body = {setAttribute: (name, value) => bodyAttrs[name] = value};
        const button = {};
        const code = {value: '{\n :put "quoted \\ value"; :local x "$fixture"\n}', style: {}, scrollHeight: 600, focus() {}, select() {}};
        const nativePanel = {className: 'accordion is-small is-active', nextSibling: {}, parentNode: {
            insertBefore(node, next) { assert.strictEqual(node, panel); assert.strictEqual(next, nativePanel.nextSibling); mounted = true; }
        }};
        const fallback = fallbackMode ? {parentNode: {removeChild() { removed = true; }}} : null;
        const elements = {
            'mkauth-pgcorte-dual-installer': panel, 'mkauth-pgcorte-dual-heading': heading,
            'mkauth-pgcorte-dual-body': body, 'mkauth-pgcorte-dual-copy': button,
            'mkauth-pgcorte-dual-code': code, 'mkauth-pgcorte-dual-fallback': fallback
        };
        const window = {isSecureContext: false, addEventListener() {}};
        const document = {
            readyState: 'complete', getElementById: id => elements[id],
            querySelectorAll: () => [{querySelector: () => ({textContent: 'PGCORTE'}), closest: () => nativePanel}],
            execCommand(command) { assert.strictEqual(command, 'copy'); copied++; return true; }
        };
        vm.runInNewContext(script, {window, document, navigator: {clipboard: {async writeText(value) { written = value; }}}});
        assert.strictEqual(headingAttrs['aria-expanded'], 'false');
        assert.strictEqual(bodyAttrs['aria-hidden'], 'true');
        if (fallbackMode) { assert(mounted); assert(removed); assert(!classes.has('is-active')); }
        const event = () => ({prevented: false, stopped: false, preventDefault() { this.prevented = true; }, stopImmediatePropagation() { this.stopped = true; }});
        let click = event(); handlers.click(click);
        assert(click.prevented && click.stopped);
        assert.strictEqual(headingAttrs['aria-expanded'], 'true');
        assert.strictEqual(toggleAttrs['aria-label'], 'Fechar PGCORTE DUAL STACK');
        assert.strictEqual(code.style.height, '602px');
        await button.onclick(); assert.strictEqual(copied, 1); assert.strictEqual(button.textContent, 'Copiado!');
        window.isSecureContext = true;
        await button.onclick(); assert.strictEqual(written, code.value); assert.strictEqual(copied, 1);
        handlers.keydown(Object.assign(event(), {key: 'Enter'}));
        assert.strictEqual(headingAttrs['aria-expanded'], 'false');
        handlers.keydown(Object.assign(event(), {key: ' '}));
        assert.strictEqual(headingAttrs['aria-expanded'], 'true');
    }
    console.log('NATIVE_ACCORDION_TOGGLE_COPY_POSITION_OK');
})().catch(error => { console.error(error); process.exit(1); });
