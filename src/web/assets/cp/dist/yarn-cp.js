/* Yarn — the map.
 *
 * A force-directed layout and an SVG renderer, with no dependencies. Everything here runs against
 * a node cap (the `maxNodes` setting) because the repulsion pass is O(n²): at 400 nodes that is
 * 80,000 pair comparisons a tick, which a browser does comfortably, and at 4,000 it is eight
 * million, which it does not.
 */
(function () {
    'use strict';

    var SVG_NS = 'http://www.w3.org/2000/svg';

    function el(name, attrs) {
        var node = document.createElementNS(SVG_NS, name);
        for (var key in attrs) {
            if (Object.prototype.hasOwnProperty.call(attrs, key)) {
                node.setAttribute(key, attrs[key]);
            }
        }
        return node;
    }

    function readConfig(root) {
        var script = root.querySelector('[data-yarn-config]');
        if (!script) {
            return null;
        }
        try {
            // A <script> element holds raw text: entities in it are never decoded, so the payload
            // is carried as JSON rather than as escaped HTML.
            return JSON.parse(script.textContent);
        } catch (e) {
            return null;
        }
    }

    function YarnMap(root, config) {
        this.root = root;
        this.config = config;
        this.svg = root.querySelector('svg');
        this.viewport = el('g', {});
        this.edgeLayer = el('g', {});
        this.nodeLayer = el('g', {});
        this.viewport.appendChild(this.edgeLayer);
        this.viewport.appendChild(this.nodeLayer);
        this.svg.appendChild(this.viewport);

        this.view = { x: 0, y: 0, k: 1 };
        this.nodes = [];
        this.links = [];
        this.byId = {};
        this.hiddenKinds = {};
        this.selected = null;
        // Set once the reader pans, zooms or drags. Until then the view keeps re-fitting itself
        // as the layout spreads; after it, the view is theirs and is never moved for them.
        this.userMoved = false;
        this.alpha = 0;
        this.ticks = 0;
        this.running = false;

        this.status = root.querySelector('[data-yarn-status]');
        this.detail = root.querySelector('[data-yarn-detail]');

        // The filters live above the canvas, not inside it, so they are looked up against the
        // whole screen rather than against the map element. Scoping them to the map is the bug
        // that silently ignores every filter the reader sets.
        this.scope = root.closest('.yarn') || document;

        this.colours = this.readColours();
        this.bindChrome();
        this.bindPointer();
        this.load();
    }

    /* Colours live in the stylesheet so the two never disagree; read them once. */
    YarnMap.prototype.readColours = function () {
        var styles = getComputedStyle(this.root);
        var kinds = ['entry', 'asset', 'category', 'tag', 'user', 'global', 'other'];
        var out = {};
        kinds.forEach(function (kind) {
            out[kind] = (styles.getPropertyValue('--yarn-' + kind) || '#6B7280').trim();
        });
        return out;
    };

    YarnMap.prototype.colourFor = function (node) {
        return this.colours[node.kind] || this.colours.other;
    };

    // ------------------------------------------------------------------ data

    YarnMap.prototype.params = function () {
        var params = {
            site: this.config.site,
            depth: this.config.depth || 2,
        };

        if (this.config.focus) {
            params.focus = this.config.focus;
        }

        var groups = this.scope.querySelector('[data-yarn-groups]');
        if (groups) {
            params.groups = Array.prototype.slice.call(groups.selectedOptions || []).map(function (option) {
                return option.value;
            }).filter(Boolean);
        }

        var hide = this.scope.querySelector('[data-yarn-hide-orphans]');
        if (hide && hide.checked) {
            params.hideOrphans = 1;
        }

        return params;
    };

    YarnMap.prototype.load = function () {
        var self = this;
        this.say('Loading…');

        Craft.sendActionRequest('GET', 'yarn/map/data', { params: this.params() })
            .then(function (response) {
                self.absorb(response.data);
            })
            .catch(function (error) {
                var message = (error && error.response && error.response.data && error.response.data.error) || 'could not load the graph';
                self.say('Failed: ' + message);
            });
    };

    YarnMap.prototype.absorb = function (data) {
        var self = this;
        var width = this.svg.clientWidth || 900;
        var height = this.svg.clientHeight || 620;

        this.byId = {};
        this.nodes = data.nodes.map(function (raw, index) {
            // Seeded from the index rather than from Math.random, so reloading the same graph
            // produces the same picture. A layout that reshuffles on every refresh is unreadable
            // as a thing to compare against last week's.
            var angle = index * 2.399963229728653;
            var radius = 12 * Math.sqrt(index + 1);
            var node = {
                id: raw.id,
                label: raw.label,
                kind: raw.kind,
                group: raw.group,
                health: raw.health,
                inCount: raw.inCount,
                outCount: raw.outCount,
                editUrl: raw.editUrl,
                uri: raw.uri,
                x: width / 2 + radius * Math.cos(angle),
                y: height / 2 + radius * Math.sin(angle),
                vx: 0,
                vy: 0,
                fixed: false,
            };
            node.r = 4 + Math.min(9, Math.sqrt(raw.inCount + raw.outCount) * 1.8);
            self.byId[node.id] = node;
            return node;
        });

        this.links = data.edges.map(function (edge) {
            return {
                source: self.byId[edge.from],
                target: self.byId[edge.to],
                kind: edge.kind,
                label: edge.label,
            };
        }).filter(function (link) {
            return link.source && link.target;
        });

        this.draw();
        this.centre();
        this.alpha = 1;
        this.ticks = 0;
        this.start();

        var message = data.nodes.length + ' of ' + data.total + ' elements, ' + data.edges.length + ' relations';
        if (data.truncated) {
            message += ' — trimmed to the busiest ' + data.nodes.length + '. Filter by source, or focus on one element, to see the rest.';
        }
        this.say(message);
    };

    YarnMap.prototype.say = function (message) {
        if (this.status) {
            this.status.textContent = message;
        }
    };

    // --------------------------------------------------------------- drawing

    YarnMap.prototype.draw = function () {
        var self = this;
        this.edgeLayer.textContent = '';
        this.nodeLayer.textContent = '';

        this.links.forEach(function (link) {
            link.el = el('line', { class: 'yarn-edge is-' + link.kind });
            self.edgeLayer.appendChild(link.el);
        });

        this.nodes.forEach(function (node) {
            var group = el('g', { class: 'yarn-vertex' });
            var circle = el('circle', { r: node.r, fill: self.colourFor(node) });

            if (node.health !== 'ok') {
                circle.setAttribute('fill-opacity', '0.35');
            }

            var title = el('title', {});
            title.textContent = node.label + (node.group ? ' — ' + node.group : '');

            group.appendChild(circle);
            group.appendChild(title);

            // Labels only where they will be read: a hub, or a graph small enough that every
            // label fits. Drawing 400 of them produces a grey smudge and halves the frame rate,
            // and even at seventy they overlap into illegibility at the default zoom.
            if (self.nodes.length <= 45 || node.inCount + node.outCount >= 3) {
                var text = el('text', { x: node.r + 4, y: 3 });
                text.textContent = node.label.length > 28 ? node.label.slice(0, 27) + '…' : node.label;
                group.appendChild(text);
                node.text = text;
            }

            group.addEventListener('pointerdown', function (event) {
                self.startDrag(event, node);
            });
            group.addEventListener('click', function (event) {
                event.stopPropagation();
                self.select(node);
            });
            group.addEventListener('dblclick', function (event) {
                event.preventDefault();
                event.stopPropagation();
                self.focusOn(node);
            });

            node.el = group;
            node.circle = circle;
            self.nodeLayer.appendChild(group);
        });

        // Labels hang off to the right of their dot, so fitting has to know how far. Measured
        // once, in graph units: the text is inside the scaled viewport, so zoom doesn't change it.
        this.nodes.forEach(function (node) {
            node.labelWidth = 0;
            if (node.text) {
                try {
                    node.labelWidth = node.r + 4 + node.text.getComputedTextLength();
                } catch (e) {
                    node.labelWidth = node.r + 4 + node.text.textContent.length * 5.5;
                }
            }
        });
    };

    YarnMap.prototype.render = function () {
        this.links.forEach(function (link) {
            link.el.setAttribute('x1', link.source.x);
            link.el.setAttribute('y1', link.source.y);
            link.el.setAttribute('x2', link.target.x);
            link.el.setAttribute('y2', link.target.y);
        });

        this.nodes.forEach(function (node) {
            node.el.setAttribute('transform', 'translate(' + node.x.toFixed(1) + ',' + node.y.toFixed(1) + ')');
        });

        this.viewport.setAttribute(
            'transform',
            'translate(' + this.view.x + ',' + this.view.y + ') scale(' + this.view.k + ')'
        );
    };

    // ------------------------------------------------------------ simulation

    YarnMap.MAX_TICKS = 700;

    YarnMap.prototype.start = function () {
        if (this.running) {
            return;
        }
        this.running = true;
        var self = this;

        function frame() {
            if (!self.running) {
                return;
            }
            for (var i = 0; i < 2; i++) {
                self.tick();
                self.ticks++;
            }
            self.render();

            // A hard tick budget as well as the alpha floor. requestAnimationFrame is throttled
            // to about a frame a second in a background tab, so a run that settles in four
            // seconds on screen can still be wandering minutes later on a tab nobody is looking
            // at — and then a click lands where a node used to be.
            if (self.alpha < 0.005 || self.ticks > YarnMap.MAX_TICKS) {
                self.running = false;
                // The layout keeps spreading for seconds after the first fit, and a picture
                // fitted to where things were mid-flight leaves the outer nodes off the edge.
                if (!self.userMoved) {
                    self.fit();
                }
                return;
            }
            requestAnimationFrame(frame);
        }

        requestAnimationFrame(frame);
    };

    YarnMap.prototype.tick = function () {
        var nodes = this.nodes;
        var count = nodes.length;
        var width = this.svg.clientWidth || 900;
        var height = this.svg.clientHeight || 620;
        var centreX = width / 2;
        var centreY = height / 2;
        var repulsion = 900 + count * 3;
        var alpha = this.alpha;
        var i;
        var j;

        for (i = 0; i < count; i++) {
            var a = nodes[i];
            for (j = i + 1; j < count; j++) {
                var b = nodes[j];
                var dx = b.x - a.x;
                var dy = b.y - a.y;
                var d2 = dx * dx + dy * dy;

                if (d2 > 90000) {
                    // Past ~300px apart two nodes have nothing to say to each other, and skipping
                    // the pair is most of what makes the O(n²) pass affordable.
                    continue;
                }
                if (d2 < 1) {
                    d2 = 1;
                    dx = (i % 2 ? 1 : -1) * 0.5;
                    dy = (j % 2 ? 1 : -1) * 0.5;
                }

                var force = repulsion / d2;
                var d = Math.sqrt(d2);
                var fx = (dx / d) * force;
                var fy = (dy / d) * force;

                a.vx -= fx;
                a.vy -= fy;
                b.vx += fx;
                b.vy += fy;
            }
        }

        for (i = 0; i < this.links.length; i++) {
            var link = this.links[i];
            var sx = link.target.x - link.source.x;
            var sy = link.target.y - link.source.y;
            var dist = Math.sqrt(sx * sx + sy * sy) || 1;
            var pull = (dist - 70) * 0.06;
            var px = (sx / dist) * pull;
            var py = (sy / dist) * pull;

            link.source.vx += px;
            link.source.vy += py;
            link.target.vx -= px;
            link.target.vy -= py;
        }

        for (i = 0; i < count; i++) {
            var node = nodes[i];

            node.vx += (centreX - node.x) * 0.006;
            node.vy += (centreY - node.y) * 0.006;

            if (node.fixed) {
                node.vx = 0;
                node.vy = 0;
                continue;
            }

            node.vx *= 0.82;
            node.vy *= 0.82;
            node.x += node.vx * alpha;
            node.y += node.vy * alpha;
        }

        this.alpha *= 0.988;
    };

    YarnMap.prototype.centre = function () {
        this.view = { x: 0, y: 0, k: 1 };
        this.userMoved = false;
        this.render();
        var self = this;
        // A first fit once the layout has had a moment to spread out — fitting on the seeded
        // positions would zoom hard into the spiral everything starts in — and a final one when
        // the simulation settles (see start()).
        clearTimeout(this.fitTimer);
        this.fitTimer = setTimeout(function () {
            if (!self.userMoved) {
                self.fit();
            }
        }, 1200);
    };

    YarnMap.prototype.fit = function () {
        if (!this.nodes.length) {
            return;
        }

        var minX = Infinity;
        var minY = Infinity;
        var maxX = -Infinity;
        var maxY = -Infinity;

        var hidden = this.hiddenKinds;
        this.nodes.forEach(function (node) {
            if (hidden[node.kind]) {
                return;
            }
            // A label is about 10px tall and sits on the dot's centre line.
            minX = Math.min(minX, node.x - node.r);
            minY = Math.min(minY, node.y - Math.max(node.r, 8));
            maxX = Math.max(maxX, node.x + Math.max(node.r, node.labelWidth || 0));
            maxY = Math.max(maxY, node.y + Math.max(node.r, 6));
        });

        if (minX === Infinity) {
            return;
        }

        var width = this.svg.clientWidth || 900;
        var height = this.svg.clientHeight || 620;

        // Keep clear of the Fit / Re-settle buttons along the bottom edge.
        var margin = 24;
        var bottom = 0;
        var controls = this.root.querySelector('.yarn-map-controls');
        if (controls) {
            bottom = controls.offsetHeight + 10;
        }

        var availableWidth = Math.max(1, width - margin * 2);
        var availableHeight = Math.max(1, height - margin * 2 - bottom);
        var scale = Math.min(availableWidth / Math.max(1, maxX - minX), availableHeight / Math.max(1, maxY - minY));

        // No lower clamp worth the name: a floor on the zoom is exactly what pushed a large graph
        // off the edges. The wheel's own limit is the only one that matters.
        // Capped at 1.5: a graph of two or three elements would otherwise be blown up until its
        // labels read as headlines. The wheel still goes further for anyone who wants it.
        this.view.k = Math.max(0.1, Math.min(1.5, scale));
        this.view.x = margin + (availableWidth - (maxX - minX) * this.view.k) / 2 - minX * this.view.k;
        this.view.y = margin + (availableHeight - (maxY - minY) * this.view.k) / 2 - minY * this.view.k;
        this.render();
    };

    // ------------------------------------------------------------ selection

    YarnMap.prototype.select = function (node) {
        this.selected = node;

        var lit = {};
        lit[node.id] = true;

        this.links.forEach(function (link) {
            if (link.source === node || link.target === node) {
                lit[link.source.id] = true;
                lit[link.target.id] = true;
                link.el.classList.add('is-lit');
                link.el.classList.remove('is-dim');
            } else {
                link.el.classList.remove('is-lit');
                link.el.classList.add('is-dim');
            }
        });

        this.nodes.forEach(function (other) {
            other.el.classList.toggle('is-dim', !lit[other.id]);
            other.el.classList.toggle('is-selected', other === node);
        });

        this.showDetail(node);
    };

    YarnMap.prototype.clearSelection = function () {
        this.selected = null;
        this.links.forEach(function (link) {
            link.el.classList.remove('is-lit', 'is-dim');
        });
        this.nodes.forEach(function (node) {
            node.el.classList.remove('is-dim', 'is-selected');
        });
        if (this.detail) {
            this.detail.hidden = true;
        }
    };

    YarnMap.prototype.showDetail = function (node) {
        if (!this.detail) {
            return;
        }

        this.detail.hidden = false;
        this.detail.innerHTML = '';

        var heading = document.createElement('h4');
        heading.textContent = node.label;
        this.detail.appendChild(heading);

        var group = document.createElement('div');
        group.className = 'light';
        group.textContent = (node.group || node.kind) + ' · #' + node.id;
        this.detail.appendChild(group);

        var list = document.createElement('ul');
        [
            ['Points at', node.outCount],
            ['Pointed at by', node.inCount],
            ['Status', node.health],
        ].forEach(function (row) {
            var item = document.createElement('li');
            var label = document.createElement('span');
            label.textContent = row[0];
            var value = document.createElement('b');
            value.textContent = row[1];
            item.appendChild(label);
            item.appendChild(value);
            list.appendChild(item);
        });
        this.detail.appendChild(list);

        var actions = document.createElement('div');
        actions.className = 'yarn-detail-actions';

        var focus = document.createElement('button');
        focus.type = 'button';
        focus.className = 'btn small';
        focus.textContent = 'Focus';
        var self = this;
        focus.addEventListener('click', function () {
            self.focusOn(node);
        });
        actions.appendChild(focus);

        var details = document.createElement('a');
        details.className = 'btn small';
        details.href = this.config.elementUrlTemplate.replace('__ID__', node.id);
        details.textContent = 'Relations';
        actions.appendChild(details);

        var edit = document.createElement('a');
        edit.className = 'btn small';
        edit.href = node.editUrl;
        edit.target = '_blank';
        edit.rel = 'noopener';
        edit.textContent = 'Edit';
        actions.appendChild(edit);

        this.detail.appendChild(actions);
    };

    YarnMap.prototype.focusOn = function (node) {
        this.config.focus = node.id;
        var field = this.scope.querySelector('[data-yarn-focus-label]');
        if (field) {
            field.textContent = node.label;
            field.parentNode.hidden = false;
        }
        this.clearSelection();
        this.load();
    };

    YarnMap.prototype.clearFocus = function () {
        this.config.focus = null;
        var holder = this.scope.querySelector('[data-yarn-focus]');
        if (holder) {
            holder.hidden = true;
        }
        this.load();
    };

    // -------------------------------------------------------------- controls

    YarnMap.prototype.bindChrome = function () {
        var self = this;

        // Through jQuery when the control panel has it: Craft's multi-select (selectize) tells
        // the hidden <select> it changed with jQuery's trigger(), which a native listener never
        // hears. jQuery's own handlers hear both that and real change events.
        var reload = this.scope.querySelectorAll('[data-yarn-reload]');
        Array.prototype.forEach.call(reload, function (control) {
            if (window.jQuery) {
                window.jQuery(control).on('change', function () {
                    self.load();
                });
            } else {
                control.addEventListener('change', function () {
                    self.load();
                });
            }
        });

        var refit = this.root.querySelector('[data-yarn-fit]');
        if (refit) {
            refit.addEventListener('click', function () {
                self.fit();
            });
        }

        var shake = this.root.querySelector('[data-yarn-shake]');
        if (shake) {
            shake.addEventListener('click', function () {
                self.alpha = 1;
                self.ticks = 0;
                self.userMoved = false;
                self.nodes.forEach(function (node) {
                    node.fixed = false;
                });
                self.start();
            });
        }

        var clear = this.scope.querySelector('[data-yarn-clear-focus]');
        if (clear) {
            clear.addEventListener('click', function () {
                self.clearFocus();
            });
        }

        var search = this.scope.querySelector('[data-yarn-search]');
        if (search) {
            search.addEventListener('input', function () {
                self.highlight(search.value.trim().toLowerCase());
            });
        }

        var legend = this.root.querySelector('[data-yarn-legend]');
        if (legend) {
            legend.addEventListener('click', function (event) {
                var button = event.target.closest('button[data-kind]');
                if (!button) {
                    return;
                }
                var kind = button.getAttribute('data-kind');
                self.hiddenKinds[kind] = !self.hiddenKinds[kind];
                button.setAttribute('aria-pressed', self.hiddenKinds[kind] ? 'false' : 'true');
                self.applyKindFilter();
            });
        }
    };

    YarnMap.prototype.applyKindFilter = function () {
        var hidden = this.hiddenKinds;

        this.nodes.forEach(function (node) {
            node.el.style.display = hidden[node.kind] ? 'none' : '';
        });

        this.links.forEach(function (link) {
            link.el.style.display = (hidden[link.source.kind] || hidden[link.target.kind]) ? 'none' : '';
        });
    };

    YarnMap.prototype.highlight = function (term) {
        if (!term) {
            this.nodes.forEach(function (node) {
                node.el.classList.remove('is-dim');
            });
            return;
        }

        this.nodes.forEach(function (node) {
            node.el.classList.toggle('is-dim', node.label.toLowerCase().indexOf(term) === -1);
        });
    };

    // --------------------------------------------------------- pan and zoom

    YarnMap.prototype.toGraph = function (event) {
        var rect = this.svg.getBoundingClientRect();
        return {
            x: (event.clientX - rect.left - this.view.x) / this.view.k,
            y: (event.clientY - rect.top - this.view.y) / this.view.k,
        };
    };

    YarnMap.prototype.bindPointer = function () {
        var self = this;
        var panning = null;

        this.svg.addEventListener('pointerdown', function (event) {
            if (event.target.closest('.yarn-vertex')) {
                return;
            }
            panning = { x: event.clientX, y: event.clientY, vx: self.view.x, vy: self.view.y };
            self.userMoved = true;
            self.svg.classList.add('is-panning');
            self.svg.setPointerCapture(event.pointerId);
        });

        this.svg.addEventListener('pointermove', function (event) {
            if (!panning) {
                return;
            }
            self.view.x = panning.vx + (event.clientX - panning.x);
            self.view.y = panning.vy + (event.clientY - panning.y);
            self.render();
        });

        ['pointerup', 'pointercancel'].forEach(function (name) {
            self.svg.addEventListener(name, function () {
                panning = null;
                self.svg.classList.remove('is-panning');
            });
        });

        this.svg.addEventListener('click', function (event) {
            if (!event.target.closest('.yarn-vertex')) {
                self.clearSelection();
            }
        });

        this.svg.addEventListener('wheel', function (event) {
            event.preventDefault();
            var rect = self.svg.getBoundingClientRect();
            var px = event.clientX - rect.left;
            var py = event.clientY - rect.top;
            var factor = event.deltaY < 0 ? 1.12 : 1 / 1.12;
            self.userMoved = true;
            var next = Math.max(0.1, Math.min(6, self.view.k * factor));

            // Zoom about the pointer, not about the origin: anything else sends whatever you were
            // looking at off the edge of the canvas.
            self.view.x = px - ((px - self.view.x) / self.view.k) * next;
            self.view.y = py - ((py - self.view.y) / self.view.k) * next;
            self.view.k = next;
            self.render();
        }, { passive: false });
    };

    YarnMap.prototype.startDrag = function (event, node) {
        event.preventDefault();
        event.stopPropagation();

        var self = this;
        var moved = false;
        node.fixed = true;

        function move(moveEvent) {
            moved = true;
            self.userMoved = true;
            var point = self.toGraph(moveEvent);
            node.x = point.x;
            node.y = point.y;
            self.render();
        }

        function up() {
            window.removeEventListener('pointermove', move);
            window.removeEventListener('pointerup', up);
            if (!moved) {
                node.fixed = false;
            }
            self.alpha = Math.max(self.alpha, 0.25);
            self.start();
        }

        window.addEventListener('pointermove', move);
        window.addEventListener('pointerup', up);
    };

    // Exposed for tests/js/map.test.mjs, which drives fitting and settling without a browser.
    window.YarnMap = YarnMap;

    document.addEventListener('DOMContentLoaded', function () {
        var roots = document.querySelectorAll('[data-yarn-map]');
        Array.prototype.forEach.call(roots, function (root) {
            var config = readConfig(root);
            if (config) {
                new YarnMap(root, config);
            }
        });
    });
}());
