// The map's framing and wiring, without a browser.
//
//     node --test tests/js/map.test.mjs
//
// Loads src/web/assets/cp/dist/yarn-cp.js with just enough of `window` and `document` to define
// YarnMap, then calls its prototype methods on plain objects. Until 5.0.1 the view was fitted once,
// 1.2s in, while the layout was still spreading, and fitting measured dots but not their labels —
// so outer nodes and their names ended up off the canvas (GitHub #1).

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import vm from 'node:vm';

const source = readFileSync(fileURLToPath(new URL('../../src/web/assets/cp/dist/yarn-cp.js', import.meta.url)), 'utf8');

function load(extra = {}) {
    const frames = [];
    const window = { ...extra };
    const context = {
        window,
        document: { addEventListener() {}, createElementNS: () => ({ setAttribute() {}, appendChild() {} }) },
        requestAnimationFrame: (fn) => frames.push(fn),
        setTimeout: () => 0,
        clearTimeout: () => {},
        Math,
        Infinity,
    };
    vm.createContext(context);
    vm.runInContext(source, context);

    return { YarnMap: window.YarnMap, frames, window };
}

/** A map stub: a 1000×620 canvas, a 30px-tall control strip, and whatever nodes the test gives it. */
function stubMap(YarnMap, nodes) {
    const map = Object.create(YarnMap.prototype);
    map.svg = { clientWidth: 1000, clientHeight: 620 };
    map.root = { querySelector: (selector) => (selector === '.yarn-map-controls' ? { offsetHeight: 30 } : null) };
    map.nodes = nodes;
    map.links = [];
    map.hiddenKinds = {};
    map.view = { x: 0, y: 0, k: 1 };
    map.viewport = { setAttribute() {} };
    map.render = () => {};
    return map;
}

function node(x, y, labelWidth = 0, r = 6) {
    return { x, y, r, labelWidth, kind: 'entry' };
}

/** Where a graph point lands on screen under the map's current view. */
function screen(map, x, y) {
    return { x: map.view.x + x * map.view.k, y: map.view.y + y * map.view.k };
}

test('fitting keeps every dot and the whole of every label on the canvas, clear of the controls', () => {
    const { YarnMap } = load();
    // A wide spread, a long label on the right-most node — the label is what used to hang off.
    const nodes = [node(-900, -500, 0), node(0, 0, 40), node(1400, 300, 180), node(200, 700, 60)];
    const map = stubMap(YarnMap, nodes);

    map.fit();

    for (const n of nodes) {
        const left = screen(map, n.x - n.r, n.y - n.r);
        const right = screen(map, n.x + Math.max(n.r, n.labelWidth), n.y + n.r);
        assert.ok(left.x >= 0 && left.y >= 0, `node at ${n.x},${n.y} starts off the top or left edge`);
        assert.ok(right.x <= 1000, `label of node at ${n.x},${n.y} runs off the right edge (${right.x.toFixed(1)})`);
        assert.ok(right.y <= 620 - 30, `node at ${n.x},${n.y} sits under the Fit / Re-settle buttons (${right.y.toFixed(1)})`);
    }
});

test('a tall graph stops short of the Fit / Re-settle buttons along the bottom', () => {
    const { YarnMap } = load();
    // Height is the binding constraint here, so the bottom node is pushed as low as fitting allows.
    const nodes = [node(0, -2000), node(40, 2000)];
    const map = stubMap(YarnMap, nodes);

    map.fit();

    const bottom = screen(map, 40, 2000 + 6);
    assert.ok(bottom.y <= 620 - 30, `bottom node at ${bottom.y.toFixed(1)}, under the controls that start at ${620 - 30}`);
});

test('fitting a big graph zooms out as far as it has to, not to a fixed floor', () => {
    const { YarnMap } = load();
    // 8,000 units across needs a zoom of about 0.12 — under the old 0.15 floor, which left the
    // edges of a large graph off the canvas.
    const nodes = [node(-4000, 0), node(4000, 300)];
    const map = stubMap(YarnMap, nodes);

    map.fit();

    const left = screen(map, -4000 - 6, 0);
    const right = screen(map, 4000 + 6, 300);
    assert.ok(left.x >= 0 && right.x <= 1000, `edges at ${left.x.toFixed(1)} and ${right.x.toFixed(1)} with zoom ${map.view.k}`);
});

test('fitting a tiny graph does not blow it up to headline size', () => {
    const { YarnMap } = load();
    const map = stubMap(YarnMap, [node(0, 0, 120), node(60, 40, 140)]);

    map.fit();

    assert.ok(map.view.k <= 1.5, `zoomed to ${map.view.k}`);
});

test('fitting ignores kinds switched off in the legend', () => {
    const { YarnMap } = load();
    const hidden = { ...node(50000, 50000), kind: 'asset' };
    const map = stubMap(YarnMap, [node(0, 0), node(300, 200), hidden]);
    map.hiddenKinds = { asset: true };

    map.fit();

    assert.ok(map.view.k > 0.5, `a hidden outlier shrank the view to ${map.view.k}`);
});

test('when the layout settles the view is fitted again — unless the reader has moved it', () => {
    for (const userMoved of [false, true]) {
        const { YarnMap, frames } = load();
        const map = stubMap(YarnMap, [node(0, 0)]);
        let fitted = 0;
        map.fit = () => { fitted++; };
        map.tick = () => {};
        map.alpha = 0.001; // already below the floor: the first frame is the last
        map.ticks = 0;
        map.running = false;
        map.userMoved = userMoved;

        map.start();
        frames.shift()();

        assert.equal(map.running, false);
        assert.equal(fitted, userMoved ? 0 : 1, userMoved ? 'refitted over the reader' : 'not refitted on settle');
    }
});

test('the reload controls listen through jQuery, which is how Craft’s multi-select reports a change', () => {
    const bound = [];
    const jQuery = (element) => ({ on: (event, handler) => bound.push({ element, event, handler }) });
    const { YarnMap } = load({ jQuery });

    const groups = { id: 'yarn-groups', addEventListener() { throw new Error('bound natively — selectize changes would be missed'); } };
    const map = Object.create(YarnMap.prototype);
    let loads = 0;
    map.load = () => { loads++; };
    map.root = { querySelector: () => null };
    map.scope = { querySelector: () => null, querySelectorAll: (selector) => (selector === '[data-yarn-reload]' ? [groups] : []) };

    map.bindChrome();

    assert.equal(bound.length, 1);
    assert.equal(bound[0].element, groups);
    assert.equal(bound[0].event, 'change');
    bound[0].handler();
    assert.equal(loads, 1);
});

test('legend buttons report whether their kind is shown', () => {
    const { YarnMap } = load();
    const attributes = { 'data-kind': 'asset', 'aria-pressed': 'true' };
    const button = {
        getAttribute: (name) => attributes[name],
        setAttribute: (name, value) => { attributes[name] = value; },
        classList: { toggle() {} },
    };
    let clicked;
    const legend = { addEventListener: (event, handler) => { clicked = handler; } };

    const map = Object.create(YarnMap.prototype);
    map.hiddenKinds = {};
    map.applyKindFilter = () => {};
    map.root = { querySelector: (selector) => (selector === '[data-yarn-legend]' ? legend : null) };
    map.scope = { querySelector: () => null, querySelectorAll: () => [] };
    map.bindChrome();

    clicked({ target: { closest: () => button } });
    assert.equal(attributes['aria-pressed'], 'false');
    clicked({ target: { closest: () => button } });
    assert.equal(attributes['aria-pressed'], 'true');
});
