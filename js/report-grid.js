/**
 * @file
 * Grid viewer: palette (groups/fields) + draggable cards + dialogs.
 *
 * Data comes from /webform-report/{id}/data (JSON). Cards config lives in
 * drupalSettings.webformReportGrid and is POSTed back on save (TBD).
 *
 * Cards can be:
 * - Simple: { field: "key", agg: "sum", w: 1, label: "..." }
 * - Composite (group): { field: "key", source_fields: ["key1", "key2"], agg: "rep", mode: "union", w: 1, label: "Group name" }
 * - Value filter: { value_filter: ["1", "0"] } to include only specific values.
 * - Folder (container): { type: "folder", label: "Jour 1", collapsed: false, w: 2, h: 2, children: [cards...] }
 *   Folders group data cards under a collapsible titled container. One level max.
 *   Children are addressed by path "i/j" (top index / child index).
 */
(function (Drupal, drupalSettings, once) {
  Drupal.behaviors.webformReportGrid = {
    attach(context) {
      once('wrg', '[data-report]', context).forEach((root) => init(root));
    },
  };

  function init(root) {
    const cfg = drupalSettings.webformReportGrid || {};
    const readOnly = !!cfg.readOnly;
    // Display settings from /admin/structure/webform-report/settings.
    const display = Object.assign(
      { fontSize: 0.95, cardGap: 16, columnWidth: 280, columns: 12, autoSizeCards: true },
      cfg.display || {}
    );
    // Grid column count (fixed): high = fine card widths. Clamped 4–24.
    const gridColumns = Math.min(24, Math.max(4, +display.columns || 12));
    const state = { groups: [], rows: [], aggs: {}, cards: cfg.cards || [], filter: '', meta: {} };
    const grid = root.querySelector('[data-wrg-grid]');
    const tree = root.querySelector('[data-wrg-tree]');
    const filters = root.querySelector('[data-wrg-filters]');
    const search = root.querySelector('[data-wrg-search]');
    const dialog = root.querySelector('[data-wrg-dialog]');
    const palette = root.querySelector('[data-wrg-palette]');
    const toolbar = root.querySelector('[data-wrg-toolbar]');
    const esc = (s) => String(s).replace(/[&<>"]/g, (c) => ({ '&': '&', '<': '<', '>': '>', '"': '"' }[c]));
    const saveStateEl = () => root.querySelector('[data-wrg-save-state]');
    function markDirty() {
      const saveState = saveStateEl();
      if (saveState) {
        saveState.textContent = '● Modifications non enregistrées';
        saveState.dataset.dirty = '1';
      }
    }

    // Hide palette and toolbar in read-only mode.
    if (readOnly) {
      if (palette) palette.style.display = 'none';
      if (toolbar) toolbar.style.display = 'none';
      // Also hide the palette/search/filters containers if they exist in the layout.
      const filterRow = root.querySelector('[data-wrg-filter-row]');
      if (filterRow) filterRow.style.display = 'none';
    }

    // Apply display settings: CSS variables + fixed/auto sizing class.
    (function applyDisplay() {
      const fs = Math.min(1.5, Math.max(0.7, +display.fontSize || 0.95));
      const gap = Math.min(40, Math.max(0, +display.cardGap || 0));
      root.style.setProperty('--wrg-fs', `${fs}rem`);
      root.style.setProperty('--wrg-gap', `${gap}px`);
      grid.classList.toggle('wrg-fixed-size', !display.autoSizeCards);
    })();

    fetch(cfg.dataUrl).then((r) => r.json()).then((payload) => {
      state.groups = payload.groups || [];
      state.rows = payload.rows || [];
      state.aggs = payload.aggregations || {};
      state.meta = payload;
      // In read-only mode, palette/search/tree/filters don't exist in DOM.
      if (!readOnly) {
        buildFilter();
        buildTree('');
      }
      render();
    });

    function filteredRows() {
      if (!state.filter || !cfg.filterField) return state.rows.map((r, i) => ({ ...r, _i: i }));
      return state.rows
        .map((r, i) => ({ ...r, _i: i }))
        .filter((r) => String(r.f[cfg.filterField] || '') === state.filter);
    }

    function buildFilter() {
      // In read-only mode (front view), don't render filter buttons.
      if (readOnly || !filters) return;
      const choice = state.groups.flatMap((g) => g.fields).find((f) => f.key === cfg.filterField);
      const shortLabel = (l) => String(l).split('--')[0].trim();
      let html = `<button aria-pressed="${!state.filter}" data-filter="" data-label="">Tous</button>`;
      if (choice && choice.options) {
        html += Object.entries(choice.options).map(([v, l]) => `<button aria-pressed="${state.filter === v}" data-filter="${esc(v)}" data-label="${esc(shortLabel(l))}">${esc(shortLabel(l))}</button>`).join('');
      }
      filters.innerHTML = html + '<button data-all style="margin-left:10px">Détail par personne</button>';
    }

    function buildTree(q) {
      q = (q || '').toLowerCase();
      tree.innerHTML = state.groups.map((g) => {
        const items = g.fields.filter((f) => !q || (f.label + g.title).toLowerCase().includes(q));
        if (!items.length) return '';
        return `<details ${q ? 'open' : ''}><summary>${esc(g.title)}</summary>` +
          items.map((f) => `<span class="wrg-chip" draggable="true" data-field="${esc(f.key)}">${esc(f.label)} <i>${esc(f.kind)}</i></span>`).join('') + '</details>';
      }).join('');
    }

    /**
     * Calculates aggregation for a card (simple or composite).
     * For composite cards, combines values from all source_fields.
     * Applies value_filter if configured.
     */
    function calc(card, rows) {
      const isComposite = card.source_fields && card.source_fields.length > 1;
      const primaryKey = card.field;
      const field = state.groups.flatMap((g) => g.fields).find((f) => f.key === primaryKey);
      if (!field) return { val: 0, who: [], field: null };

      // For composite cards in union mode, treat as choice field.
      const effectiveKind = isComposite && card.mode === 'union' ? 'r' : (field.kind || 'b');

      // Allow 'rep' aggregation for boolean fields (show Oui/Non breakdown).
      const wantsBreakdown = card.agg === 'rep';

      if (effectiveKind === 'r' || (wantsBreakdown && effectiveKind === 'b')) {
        const rep = {}, gp = {};
        rows.forEach((r) => {
          const v = getCombinedValue(card, r);
          if (v === FILTERED_OUT) return; // Skip filtered out values
          const val = v ?? '—';
          if (Array.isArray(val)) {
            val.forEach(vv => {
              rep[vv] = (rep[vv] || 0) + 1;
              (gp[vv] = gp[vv] || []).push(r._i);
            });
          }
          else {
            rep[val] = (rep[val] || 0) + 1;
            (gp[val] = gp[val] || []).push(r._i);
          }
        });
        // For boolean fields (original field kind), ensure 1/0 labels are meaningful.
        // Check field.kind instead of effectiveKind because composite union forces 'r'.
        if (field.kind === 'b') {
          const newRep = {}, newGp = {};
          Object.entries(rep).forEach(([k, cnt]) => {
            const label = k === '1' ? 'Oui' : (k === '0' ? 'Non' : (k === '—' ? 'Non répondu' : k));
            newRep[label] = cnt;
            newGp[label] = gp[k];
          });
          return { rep: newRep, gp: newGp, field: { ...field, kind: 'r', options: { 'Oui': 'Oui', 'Non': 'Non', 'Non répondu': 'Non répondu' } } };
        }
        return { rep, gp, field: { ...field, kind: 'r' } };
      }
      if (effectiveKind === 'n') {
        const vals = rows.map((r) => {
          const v = getCombinedValue(card, r);
          return v === FILTERED_OUT ? 0 : Number(v) || 0;
        });
        const sum = vals.reduce((a, b) => a + b, 0);
        const who = rows.filter((r) => {
          const v = getCombinedValue(card, r);
          return v !== FILTERED_OUT && Number(v) ? true : false;
        }).map((r) => r._i);
        const out = { sum: [sum, ''], avg: [vals.length ? (sum / vals.length).toFixed(1) : 0, '/ answer'], max: [Math.max(0, ...vals), ''], min: [vals.length ? Math.min(...vals) : 0, ''], count: [who.length, ''] }[card.agg] || [sum, ''];
        return { val: out, who, field: { ...field, kind: 'n' } };
      }
      if (effectiveKind === 't') {
        const who = rows.filter((r) => {
          const v = getCombinedValue(card, r);
          return v !== FILTERED_OUT && v !== null && v !== '';
        }).map((r) => r._i);
        return { val: [who.length, ''], who, field: { ...field, kind: 't' } };
      }
      // Boolean
      let sum = 0;
      const who = [];
      rows.forEach((r) => {
        const v = getCombinedValue(card, r);
        if (v === FILTERED_OUT) return;
        if (v) {
          sum += typeof v === 'number' ? v : 1;
          who.push(r._i);
        }
      });
      const out = card.agg === 'pct' ? [rows.length ? Math.round(100 * sum / rows.length) : 0, '%'] : [sum, ''];
      return { val: out, who, field: { ...field, kind: 'b' } };
    }

    /**
     * Gets combined value from all source fields of a card for a row.
     * Mirrors the PHP getCombinedValue logic including value_filter and value_filters.
     * Returns FILTERED_OUT symbol when value is filtered out by value_filter.
     */
    const FILTERED_OUT = Symbol('filtered_out');

    /**
     * Checks if a raw value matches a value_filter list.
     * Accepts direct matches plus boolean label equivalents
     * (composite union of booleans shows Oui/Non labels while
     * raw data holds 1/0).
     */
    function matchesValueFilter(v, filter) {
      if (!filter || !filter.length) return true;
      const strV = String(v);
      if (filter.includes(strV) || filter.includes(v)) return true;
      const isTrue = v === 1 || v === '1' || v === true;
      const isFalse = v === 0 || v === '0' || v === false;
      const isEmpty = v === null || v === undefined || v === '' || v === '—';
      if (isTrue && (filter.includes('Oui') || filter.includes('1'))) return true;
      if (isFalse && (filter.includes('Non') || filter.includes('0'))) return true;
      if (isEmpty && (filter.includes('Non répondu') || filter.includes('Non renseigné') || filter.includes('—') || filter.includes(''))) return true;
      return false;
    }

    function getCombinedValue(card, row) {
      const isComposite = card.source_fields && card.source_fields.length > 1;
      if (!isComposite) {
        const val = row.f[card.field];
        // Apply value_filter for simple cards too.
        if (card.value_filter && card.value_filter.length > 0) {
          if (!matchesValueFilter(val, card.value_filter)) {
            return FILTERED_OUT;
          }
        }
        return val;
      }
      const mode = card.mode || 'union';
      const value_filter = card.value_filter || null;
      const value_filters = card.value_filters || null;
      const values = [];

      // Collect values with per-source-field filters applied.
      card.source_fields.forEach((src) => {
        if (row.f[src] === undefined) return;
        let val = row.f[src];

        // Apply per-source-field value_filter (detail mode).
        if (value_filters && value_filters[src] && Array.isArray(value_filters[src])) {
          const srcFilter = value_filters[src];
          if (Array.isArray(val)) {
            val = val.filter(v => matchesValueFilter(v, srcFilter));
            if (!val.length) return;
          } else {
            if (!matchesValueFilter(val, srcFilter)) return;
          }
        }
        if (Array.isArray(val)) {
          values.push(...val);
        } else {
          values.push(val);
        }
      });

      if (!values.length) return FILTERED_OUT;

      // Apply card-level value_filter if configured (before combination mode).
      if (value_filter && value_filter.length > 0) {
        const filtered = values.filter(v => matchesValueFilter(v, value_filter));
        if (!filtered.length) return FILTERED_OUT;
        return applyMode(filtered, mode);
      }

      return applyMode(values, mode);
    }

    function applyMode(values, mode) {
      switch (mode) {
        case 'sum':
          return values.filter(v => typeof v === 'number').reduce((a, b) => a + b, 0);
        case 'first_non_empty':
          return values.find(v => v !== '' && v !== null && v !== undefined) ?? null;
        case 'union':
        default:
          const uniq = [...new Set(values.map(String))];
          return uniq.length === 1 ? uniq[0] : uniq;
      }
    }

    // Last opened list, for the "back" button in the person dialog.
    let lastList = null;
    const nameOf = (gi) => { const r = state.rows[gi]; return r ? r.n : '?'; };
    const personBtn = (gi, mult) => `<button class="wrg-nm" data-p="${gi}">${esc(nameOf(gi))}${mult > 1 ? ' ×' + mult : ''}</button>`;

    // ---- Folder helpers (paths "i" top-level, "i/j" child of folder i) ----
    const isFolder = (c) => !!c && c.type === 'folder';
    const getCardByPath = (path) => {
      const parts = String(path).split('/').map(Number);
      if (parts.length === 1) return state.cards[parts[0]] || null;
      if (parts.length === 2) {
        const f = state.cards[parts[0]];
        return (f && isFolder(f) && f.children) ? (f.children[parts[1]] || null) : null;
      }
      return null;
    };
    const getFolderOf = (path) => {
      const parts = String(path).split('/').map(Number);
      if (parts.length === 2) {
        const f = state.cards[parts[0]];
        return (f && isFolder(f)) ? f : null;
      }
      return null;
    };
    const multOf = (gi, card) => {
      const r = state.rows[gi];
      if (!r) return 0;
      const v = getCombinedValue(card, r);
      return typeof v === 'number' && v > 1 ? v : 0;
    };

    // GridStack v14 - using new API with columnOpts for responsive behavior.
    // columnWidth / margin / sizeToContent come from display settings.
    let gs = null;
    function gridstackSync() {
      if (typeof GridStack === 'undefined' || !grid) return false;
      if (!gs) {
        try {
          const autoSize = !!display.autoSizeCards;
          const colW = Math.min(600, Math.max(140, +display.columnWidth || 280));
          const gap = Math.min(40, Math.max(0, +display.cardGap || 0));
          gs = GridStack.init({
            // Fixed high column count (setting, default 12): fine-grained card
            // widths set in build are kept as-is in front (same column count).
            // Responsive: fixed breakpoints like the official "Responsive
            // breakpoint" demo (demo/responsive.html): narrow screens stack
            // cards (1 col) instead of squeezing them.
            column: gridColumns,
            columnOpts: {
              breakpointForWindow: true,
              layout: 'moveScale',
              breakpoints: [
                { w: 700, c: 1 },
                { w: 850, c: 3 },
                { w: 950, c: 6 },
                { w: 1100, c: 8 },
              ],
            },
            cellHeight: 120, // Fixed cell height in px (cards will be multiples of this)
            minRow: 0,
            // Auto-size: no row cap so tall cards can grow freely.
            // Fixed: cap at 20 rows.
            maxRow: autoSize ? 0 : 20,
            margin: gap,
            // float:false (= mode 'top'): cards pack upward when space frees
            // (e.g. folder collapse), while keeping widths + side-by-side.
            // float:true would leave a gap below a collapsed folder.
            float: false,
            animate: true,
            // Auto-size: cards grow to show all content; else fixed height set at creation.
            sizeToContent: autoSize,
            draggable: {
              handle: '.wrg-card',
              // Don't start a drag when interacting with controls inside cards
              // (folder toggle, buttons, selects, editable labels...).
              cancel: 'button, select, input, textarea, a, [contenteditable="true"], dialog, .wrg-names',
              scroll: true,
            },
            resizable: {
              handles: 'se',
              autoHide: true,
              minHeight: 120, // Minimum 1 row
              // Auto-size: allow growing tall; fixed: cap at 5 rows.
              maxHeight: autoSize ? 4000 : 600,
            },
          }, grid);
          gs.on('change', () => {
            // GridStack owns positions (x/y). We only persist DIMENSIONS (w/h)
            // and the visual order. Never write x/y ourselves.
            const order = [];
            grid.querySelectorAll('.grid-stack-item').forEach((el) => {
              // Top-level only: data-gs-index is the source of truth.
              const rawIdx = el.dataset.gsIndex ?? el.querySelector(':scope > .grid-stack-item-content > .wrg-card')?.dataset.i;
              const i = parseInt(String(rawIdx).split('/')[0], 10);
              if (Number.isInteger(i) && i >= 0 && state.cards[i]) {
                const card = state.cards[i];
                // Clamp to the current grid column count (responsive may shrink it).
                const w = Math.min(gridColumns, Math.max(1, +(el.dataset.gsW || card.w || 1)));
                const h = +(el.dataset.gsH || card.h || 2);
                if (w !== card.w || h !== card.h) {
                  card.w = w;
                  card.h = h;
                  markDirty();
                }
                order.push({ card, x: +(el.dataset.gsX || 0), y: +(el.dataset.gsY || 0) });
              }
            });
            order.sort((a, b) => a.y - b.y || a.x - b.x);
            const ids = order.map((o) => state.cards.indexOf(o.card));
            if (ids.length === state.cards.length) state.cards = ids.map((k) => state.cards[k]);
          });
        } catch (e) {
          console.error('GridStack init failed:', e);
          return false;
        }
      }
      return true;
    }

    /**
     * Renders a single card's inner HTML (without GridStack wrapper).
     * @param {Object} c - Card config.
     * @param {string|number} path - Card path ("i" or "i/j" for folder children).
     */
    function renderCard(c, i, rows, readOnly = false) {
      // Folder container: title + collapsible children grid.
      if (isFolder(c)) {
        return renderFolder(c, String(i), rows, readOnly);
      }
      const path = String(i);
      const isComposite = c.source_fields && c.source_fields.length > 1;
      const displayMode = c.display_mode || 'combined'; // 'combined' | 'detail'

      if (isComposite && displayMode === 'detail') {
        return renderCardDetail(c, i, rows, readOnly);
      }

      const x = calc(c, rows);
      const f = x.field || { label: c.field, kind: 'b', group: '' };
      const opts = Object.entries(state.aggs[f.kind] || {}).map(([v, l]) => `<option value="${v}" ${v === c.agg ? 'selected' : ''}>${esc(l)}</option>`).join('');

      let sourcesHtml = '';
      if (isComposite) {
        const sourceLabels = c.source_labels || {};
        const fieldLabels = c.source_fields.map(src => {
          if (sourceLabels[src]) return sourceLabels[src];
          const found = state.groups.flatMap(g => g.fields).find(fl => fl.key === src);
          return found ? found.label : src;
        });
        sourcesHtml = `<div class="wrg-sources">${c.source_fields.map((src, k) => readOnly
          ? `<span class="wrg-source-badge">${esc(fieldLabels[k])}</span>`
          : `<span class="wrg-source-badge" contenteditable="true" data-source-label="${path}:${esc(src)}">${esc(fieldLabels[k])}</span>`).join('')}</div>`;
      }

      let body;
      if (x.rep) {
        const mx = Math.max(1, ...Object.values(x.rep));
        const optLabel = (k) => (f.options && f.options[k] !== undefined ? String(f.options[k]).split('--')[0].trim() : (k === '—' ? 'Non renseigné' : k));
        body = Object.entries(x.rep).map(([k, n]) => `<div class="wrg-bar"><span>${esc(optLabel(k))}</span><div style="width:${100 * n / mx}%"></div><b>${n}</b></div>`).join('')
          + `<button class="wrg-more" data-l="${path}">Voir qui a répondu quoi</button>`;
      } else {
        const unit = x.val[1] || (c.agg === 'sum' && f.kind === 'b' ? 'personne' + (x.val[0] > 1 ? 's' : '') : '');
        const shown = x.who.slice(0, 5);
        const extra = x.who.length - shown.length;
        body = `<div class="wrg-big">${x.val[0]}<small>${esc(unit)}</small></div>`
          + `<div class="wrg-names">${shown.map((gi) => personBtn(gi, multOf(gi, c))).join('') || '<span class="wrg-none">Personne pour l\'instant</span>'}${extra > 0 ? `<span>+${extra}</span>` : ''}</div>`
          + (x.who.length ? `<button class="wrg-more" data-l="${path}">Voir la liste complète (${x.who.length})</button>` : '');
      }

      // In read-only mode, hide all configuration controls.
      const modeOpts = `<option value="union" ${c.mode === 'union' ? 'selected' : ''}>Union</option>
        <option value="sum" ${c.mode === 'sum' ? 'selected' : ''}>Somme</option
        <option value="first_non_empty" ${c.mode === 'first_non_empty' ? 'selected' : ''}>1er non vide</option>`;
      const modeSelect = (!readOnly && isComposite) ? `<select aria-label="Mode" data-mode="${path}" title="Mode de combinaison">${modeOpts}</select>` : '';

      // Display mode selector for composite cards.
      const displayModeOpts = `<option value="combined" ${c.display_mode !== 'detail' ? 'selected' : ''}>Combiné</option>
        <option value="detail" ${c.display_mode === 'detail' ? 'selected' : ''}>Détail par champ</option>`;
      const displayModeSelect = (!readOnly && isComposite) ? `<select aria-label="Affichage" data-display-mode="${path}" title="Mode d'affichage">${displayModeOpts}</select>` : '';

      const valueFilterHtml = readOnly ? '' : buildValueFilter(c, f);

      // Build tools HTML - hide in read-only mode.
      const toolsHtml = readOnly ? '' : `
        <div class="wrg-tools">
          <select aria-label="Synthèse" data-agg="${path}">${opts}</select>
          ${modeSelect}
          ${displayModeSelect}
          <button data-w="${path}" title="Élargir ou réduire" aria-label="Élargir ou réduire">↔</button>
          <button data-x="${path}" title="Retirer" aria-label="Retirer">✕</button>
        </div>`;

      // Label is not editable in read-only mode.
      const labelHtml = readOnly
        ? `<div class="wrg-t">${esc(c.label || f.label)}</div>`
        : `<div class="wrg-t" contenteditable="true" data-label="${path}">${esc(c.label || f.label)}</div>`;

      const groupLabel = c.group_label || f.group || '';
      const groupHtml = readOnly
        ? `<div class="wrg-g">${esc(groupLabel)}</div>`
        : `<div class="wrg-g" contenteditable="true" data-group-label="${path}">${esc(groupLabel)}</div>`;

      return `<article class="wrg-card${isComposite ? ' wrg-composite' : ''}" draggable="true" data-i="${path}" data-drop-target="true">
        <div class="wrg-top">
          <div>
            ${labelHtml}
            ${groupHtml}
            ${sourcesHtml}
          </div>
          ${toolsHtml}
        </div>
        ${valueFilterHtml}
        ${body}</article>`;
    }

    /**
     * Renders a folder (container) card: collapsible title + children grid.
     * Children are rendered via renderCard with path "i/j".
     */
    function renderFolder(c, path, rows, readOnly = false) {
      const children = Array.isArray(c.children) ? c.children : [];
      const collapsed = !!c.collapsed;
      const count = children.length;
      const kids = children.map((kid, j) => {
        const kidHtml = renderCard(kid, `${path}/${j}`, rows, readOnly);
        if (readOnly || isFolder(kid)) return kidHtml;
        // Wrap data-card children with up/down order controls.
        const isFirst = j === 0;
        const isLast = j === count - 1;
        return `<div class="wrg-folder-kid" data-kid="${path}/${j}">`
          + `<div class="wrg-kid-order" role="group" aria-label="Ordre">`
          + `<button data-move-up="${path}/${j}" title="Monter" aria-label="Monter" ${isFirst ? 'disabled' : ''}>▲</button>`
          + `<button data-move-down="${path}/${j}" title="Descendre" aria-label="Descendre" ${isLast ? 'disabled' : ''}>▼</button>`
          + `</div>${kidHtml}</div>`;
      }).join('');
      const emptyHtml = count === 0
        ? `<div class="wrg-folder-empty">${readOnly ? 'Dossier vide.' : 'Glissez des champs ici pour remplir ce dossier.'}</div>`
        : '';
      const labelHtml = readOnly
        ? `<div class="wrg-t">${esc(c.label || 'Dossier')}</div>`
        : `<div class="wrg-t" contenteditable="true" data-folder-label="${path}">${esc(c.label || 'Dossier')}</div>`;
      const toolsHtml = readOnly ? '' : `
        <div class="wrg-tools">
          <button data-w="${path}" title="Élargir ou réduire" aria-label="Élargir ou réduire">↔</button>
          <button data-x="${path}" title="Retirer le dossier" aria-label="Retirer le dossier">✕</button>
        </div>`;
      return `<article class="wrg-card wrg-folder${collapsed ? ' wrg-collapsed' : ''}" draggable="true" data-i="${path}" data-folder="${path}" data-drop-target="true">
        <div class="wrg-folder-head">
          <button class="wrg-folder-toggle" data-toggle="${path}" aria-expanded="${collapsed ? 'false' : 'true'}" title="${collapsed ? 'Déplier' : 'Replier'}">${collapsed ? '▶' : '▼'}</button>
          ${labelHtml}
          <span class="wrg-folder-count">${count} carte${count > 1 ? 's' : ''}</span>
          ${toolsHtml}
        </div>
        <div class="wrg-folder-children" data-folder-children="${path}">${kids}${emptyHtml}</div>
      </article>`;
    }

    /**
     * Renders a composite card in "detail" mode: one section per source field.
     * @param {string} path - Card path ("i" or "i/j").
     */
    function renderCardDetail(c, i, rows, readOnly = false) {
      const path = String(i);
      const f = state.groups.flatMap(g => g.fields).find(fl => fl.key === c.field) || { label: c.field, kind: 'b', group: '' };
      const opts = Object.entries(state.aggs[f.kind] || {}).map(([v, l]) => `<option value="${v}" ${v === c.agg ? 'selected' : ''}>${esc(l)}</option>`).join('');

      const sourceLabels = c.source_labels || {};
      const fieldLabels = c.source_fields.map(src => {
        if (sourceLabels[src]) return sourceLabels[src];
        const found = state.groups.flatMap(g => g.fields).find(fl => fl.key === src);
        return found ? found.label : src;
      });
      const sourcesHtml = `<div class="wrg-sources">${c.source_fields.map((src, k) => readOnly
        ? `<span class="wrg-source-badge">${esc(fieldLabels[k])}</span>`
        : `<span class="wrg-source-badge" contenteditable="true" data-source-label="${path}:${esc(src)}">${esc(fieldLabels[k])}</span>`).join('')}</div>`;

      // Value filters per source field: c.value_filters = { "field_key": ["1", "0"] }
      const valueFilters = c.value_filters || {};

      // Build detail sections for each source field.
      const sections = c.source_fields.map((src, srcIdx) => {
        const field = state.groups.flatMap(g => g.fields).find(fl => fl.key === src);
        if (!field) return `<div class="wrg-detail-section"><div class="wrg-detail-title">${esc(src)}</div><div class="wrg-none">Champ introuvable</div></div>`;

        // Get value_filter for this specific source field.
        const srcValueFilter = valueFilters[src] || [];

        // Create a temporary single-field card to calc aggregation (with value_filter).
        const tempCard = { ...c, field: src, source_fields: [src], value_filter: srcValueFilter };
        const x = calc(tempCard, rows);

        // Build value filter UI for this source field (only in edit mode).
        const vfHtml = readOnly ? '' : buildValueFilter({ ...tempCard, field: src, value_filter: srcValueFilter }, field, src);

        let body;
        if (x.rep) {
          const mx = Math.max(1, ...Object.values(x.rep));
          const optLabel = (k) => (field.options && field.options[k] !== undefined ? String(field.options[k]).split('--')[0].trim() : (k === '—' ? 'Non renseigné' : k));
          body = Object.entries(x.rep).map(([k, n]) => `<div class="wrg-bar"><span>${esc(optLabel(k))}</span><div style="width:${100 * n / mx}%"></div><b>${n}</b></div>`).join('')
            + `<button class="wrg-more" data-l="${path}" data-src="${srcIdx}">Voir qui</button>`;
        } else {
          const unit = x.val[1] || (c.agg === 'sum' && field.kind === 'b' ? 'personne' + (x.val[0] > 1 ? 's' : '') : '');
          const shown = x.who.slice(0, 5);
          const extra = x.who.length - shown.length;
          body = `<div class="wrg-big">${x.val[0]}<small>${esc(unit)}</small></div>`
            + `<div class="wrg-names">${shown.map((gi) => personBtn(gi, multOf(gi, tempCard))).join('') || '<span class="wrg-none">Personne pour l\'instant</span>'}${extra > 0 ? `<span>+${extra}</span>` : ''}</div>`
            + (x.who.length ? `<button class="wrg-more" data-l="${path}" data-src="${srcIdx}">Voir la liste (${x.who.length})</button>` : '');
        }

        const sectionTitle = (c.section_labels && c.section_labels[src]) || fieldLabels[srcIdx];
        const sectionTitleHtml = readOnly
          ? `<div class="wrg-detail-title">${esc(sectionTitle)}</div>`
          : `<div class="wrg-detail-title" contenteditable="true" data-section-label="${path}:${esc(src)}">${esc(sectionTitle)}</div>`;
        return `<div class="wrg-detail-section" data-src="${src}">
          ${sectionTitleHtml}
          ${vfHtml}
          ${body}
        </div>`;
      }).join('');

      // Summary row: total + union of names.
      // In combined mode, use card-level value_filter; in detail mode, use per-source-field value_filters.
      const summaryCard = { ...c };
      if (c.display_mode === 'detail') {
        summaryCard.value_filters = c.value_filters;
      }
      const xSummary = calc(summaryCard, rows);

      // Display mode selector for composite cards (same as renderCard).
      const displayModeOpts = `<option value="combined" ${c.display_mode !== 'detail' ? 'selected' : ''}>Combiné</option>
        <option value="detail" ${c.display_mode === 'detail' ? 'selected' : ''}>Détail par champ</option>`;

      let summaryHtml = '';
      const summaryTitle = c.summary_label || (xSummary.rep ? `Total (${c.mode === 'union' ? 'Union' : c.mode})` : 'Total');
      const summaryTitleHtml = readOnly
        ? `<div class="wrg-detail-title">${esc(summaryTitle)}</div>`
        : `<div class="wrg-detail-title" contenteditable="true" data-summary-label="${path}">${esc(summaryTitle)}</div>`;
      if (xSummary.rep) {
        const mx = Math.max(1, ...Object.values(xSummary.rep));
        const optLabel = (k) => (f.options && f.options[k] !== undefined ? String(f.options[k]).split('--')[0].trim() : (k === '—' ? 'Non renseigné' : k));
        summaryHtml = `<div class="wrg-detail-summary">
          ${summaryTitleHtml}
          ${Object.entries(xSummary.rep).map(([k, n]) => `<div class="wrg-bar"><span>${esc(optLabel(k))}</span><div style="width:${100 * n / mx}%"></div><b>${n}</b></div>`).join('')}
          ${xSummary.gp ? `<button class="wrg-more" data-l="${path}">Voir tous les noms (${Object.values(xSummary.gp).flat().length})</button>` : ''}
        </div>`;
      } else {
        const unit = xSummary.val[1] || (c.agg === 'sum' && f.kind === 'b' ? 'personne' + (xSummary.val[0] > 1 ? 's' : '') : '');
        const totalNames = xSummary.who.length;
        summaryHtml = `<div class="wrg-detail-summary">
          ${summaryTitleHtml}
          <div class="wrg-big">${xSummary.val[0]}<small>${esc(unit)}</small></div>
          <div class="wrg-names">${xSummary.who.slice(0, 5).map((gi) => personBtn(gi, multOf(gi, summaryCard))).join('') || '<span class="wrg-none">Personne</span>'}${totalNames > 5 ? `<span>+${totalNames - 5}</span>` : ''}</div>
          ${totalNames ? `<button class="wrg-more" data-l="${path}">Voir tous les noms (${totalNames})</button>` : ''}
        </div>`;
      }

      const displayModeSelect = readOnly ? '' : `<select aria-label="Affichage" data-display-mode="${path}" title="Mode d'affichage">${displayModeOpts}</select>`;

      // Label is not editable in read-only mode.
      const labelHtml = readOnly
        ? `<div class="wrg-t">${esc(c.label || f.label)}</div>`
        : `<div class="wrg-t" contenteditable="true" data-label="${path}">${esc(c.label || f.label)}</div>`;

      // Tools HTML - hide in read-only mode.
      const toolsHtml = readOnly ? '' : `
        <div class="wrg-tools">
          <select aria-label="Synthèse" data-agg="${path}">${opts}</select>
          ${displayModeSelect}
          <button data-w="${path}" title="Élargir ou réduire" aria-label="Élargir ou réduire">↔</button>
          <button data-x="${path}" title="Retirer" aria-label="Retirer">✕</button>
        </div>`;

      const groupLabel = c.group_label || f.group || '';
      const groupHtml = readOnly
        ? `<div class="wrg-g">${esc(groupLabel)}</div>`
        : `<div class="wrg-g" contenteditable="true" data-group-label="${path}">${esc(groupLabel)}</div>`;

      return `<article class="wrg-card wrg-composite wrg-detail-mode" draggable="true" data-i="${path}" data-drop-target="true">
        <div class="wrg-top">
          <div>
            ${labelHtml}
            ${groupHtml}
            ${sourcesHtml}
          </div>
          ${toolsHtml}
        </div>
        <div class="wrg-detail-sections">${sections}</div>
        ${summaryHtml}</article>`;
    }

    function render() {
      const rows = filteredRows();
      const useGs = gridstackSync();

      if (state.cards.length === 0) {
        grid.innerHTML = '<div class="wrg-empty">Glissez un champ ici pour créer votre première synthèse.</div>';
        return;
      }

      if (useGs && gs) {
        // GridStack mode: wrap each TOP-LEVEL card in grid-stack-item.
        // Folders render their children as inner CSS grid (not nested GridStack).
        // GridStack OWNS positions: we never inject x/y. Widths are plain
        // COLUMN counts on the shared N-column grid (setting, default 12):
        // a card set to 5 columns in build renders 5 columns in front.
        const autoSize = !!display.autoSizeCards;
        grid.innerHTML = state.cards.map((c, i) => {
          const inner = renderCard(c, i, rows, readOnly);
          const fallbackW = isFolder(c) ? Math.round(gridColumns / 2) : Math.round(gridColumns / 3);
          const w = Math.min(gridColumns, Math.max(1, c.w || fallbackW));
          // Auto-size: no fixed height (GridStack sizeToContent measures content
          // and grows the item to fit). Fixed: use saved height.
          const hAttr = autoSize ? '' : ` gs-h="${c.h || 2}"`;
          const minH = autoSize ? 1 : (c.min_h || 1);
          const maxHAttr = autoSize ? '' : ` gs-max-h="${c.max_h || 5}"`;
          const stc = autoSize ? ' gs-size-to-content="true"' : '';
          return `<div class="grid-stack-item" gs-w="${w}"${hAttr} gs-min-h="${minH}"${maxHAttr}${stc} data-gs-index="${i}"><div class="grid-stack-item-content">${inner}</div></div>`;
        }).join('');

        try {
          gs.removeAll(false);
          grid.querySelectorAll('.grid-stack-item').forEach((el) => gs.makeWidget(el));
          // Auto-size: measure content AFTER layout. GridStack only auto-measures
          // on add/resize events, not on innerHTML re-render, so we call
          // resizeToContent explicitly once the browser has laid out the DOM.
          if (autoSize) {
            requestAnimationFrame(() => {
              grid.querySelectorAll('.grid-stack-item').forEach((el) => {
                try { gs.resizeToContent(el); } catch (e) { /* ignore */ }
              });
              // Second pass after positions settle (items below shifted).
              requestAnimationFrame(() => {
                grid.querySelectorAll('.grid-stack-item').forEach((el) => {
                  try { gs.resizeToContent(el); } catch (e) { /* ignore */ }
                });
              });
            });
          }
        } catch (e) {
          console.warn('GridStack makeWidget failed:', e);
        }
      } else {
        // Native CSS Grid mode
        grid.innerHTML = state.cards.map((c, i) => {
          return renderCard(c, i, rows, readOnly);
        }).join('');
      }
    }

    /**
     * Updates a single card's inner content without touching GridStack layout.
     * Called when aggregation, mode, value_filter, label, or width changes.
     * @param {string|number} path - Card path ("i" or "i/j").
     */
    function updateCard(path) {
      path = String(path);
      const rows = filteredRows();
      const card = getCardByPath(path);
      // Folder children live inside the top-level folder item: re-render the folder.
      const topPath = path.split('/')[0];
      const topCard = state.cards[+topPath];
      const isChild = path.includes('/');
      const renderPath = isChild ? topPath : path;
      const toRender = isChild ? topCard : card;
      if (!toRender) return;
      const newHtml = renderCard(toRender, renderPath, rows, readOnly);
      const cardEl = grid.querySelector(`.grid-stack-item[data-gs-index="${renderPath}"] .grid-stack-item-content`);
      if (cardEl) {
        cardEl.innerHTML = newHtml;
        // Auto-size: re-measure after content change.
        if (gs && display.autoSizeCards) {
          const item = cardEl.closest('.grid-stack-item');
          if (item) {
            requestAnimationFrame(() => {
              try { gs.resizeToContent(item); } catch (e) { /* ignore */ }
            });
          }
        }
      }
      else {
        const folderEl = grid.querySelector(`.wrg-card[data-i="${CSS.escape(renderPath)}"]`);
        if (folderEl) folderEl.outerHTML = newHtml;
        else console.warn('[updateCard] Card element not found for path', path);
      }
    }

    /**
     * Builds the value filter UI for a card or a source field in detail mode.
     * Returns HTML string for the filter control.
     * @param {Object} card - Card config (may have value_filter)
     * @param {Object} field - Field definition
     * @param {string} [src] - Source field key (for detail mode)
     */
    function buildValueFilter(card, field, src) {
      if (!field || !field.options) return '';
      const kind = field.kind;
      const currentFilter = card.value_filter || [];
      const options = field.options;
      const filterKey = src || card.field; // Use source field key in detail mode
      const dataSrc = src ? ` data-src="${src}"` : ''; // Identify source field in detail mode

      if (kind === 'b') {
        // Boolean: checkboxes for 1 (true), 0 (false), empty (null).
        const opts = [
          { val: '1', label: 'Oui (1)' },
          { val: '0', label: 'Non (0)' },
          { val: '', label: 'Vide/Non répondu' },
        ];
        return `<div class="wrg-value-filter wrg-vf-boolean" data-filter="${filterKey}"${dataSrc}>
          <label class="wrg-vf-label">Filtrer valeurs :</label>
          <div class="wrg-vf-options">
            ${opts.map(o => `<label><input type="checkbox" value="${o.val}" ${currentFilter.includes(o.val) ? 'checked' : ''} data-vf-val="${o.val}"> ${esc(o.label)}</label>`).join('')}
          </div>
        </div>`;
      }
      if (kind === 'r') {
        // Choice: multiselect dropdown.
        const optsHtml = Object.entries(options).map(([v, l]) => `<option value="${esc(v)}" ${currentFilter.includes(v) ? 'selected' : ''}>${esc(l)}</option>`).join('');
        return `<div class="wrg-value-filter wrg-vf-choice" data-filter="${filterKey}"${dataSrc}>
          <label class="wrg-vf-label">Valeurs à inclure :</label>
          <select multiple data-vf-select="${filterKey}" title="Valeurs à inclure (Ctrl+clic pour plusieurs)">
            ${optsHtml}
          </select>
        </div>`;
      }
      return '';
    }

    function openDialog(title, bodyHtml) {
      dialog.innerHTML = `<div class="wrg-dh"><h2>${title}</h2><button data-close aria-label="Fermer">✕</button></div><div class="wrg-db">${bodyHtml}</div>`;
      if (!dialog.open) dialog.showModal();
    }

    function showCardList(path) {
      path = String(path);
      const c = getCardByPath(path);
      if (!c || isFolder(c)) return;
      const rows = filteredRows();
      const x = calc(c, rows);
      const f = x.field || { label: c.field, group: '' };
      lastList = { type: 'card', index: path };
      const sub = `${esc(f.group || '')}${state.filter && state.filterLabel ? ' · ' + esc(state.filterLabel) : ''} · cliquez un nom pour voir ses choix`;
      let body;
      if (x.rep) {
        const optLabel = (k) => (f.options && f.options[k] !== undefined ? String(f.options[k]).split('--')[0].trim() : (k === '—' ? 'Non renseigné' : k));
        body = Object.entries(x.gp).map(([k, gis]) => `<h3>${esc(optLabel(k))} (${gis.length})</h3><div class="wrg-names">${gis.map((gi) => personBtn(gi, 0)).join('')}</div>`).join('');
      } else {
        body = `<div class="wrg-names">${x.who.map((gi) => personBtn(gi, multOf(gi, c))).join('')}</div>`;
      }
      openDialog(esc(c.label || f.label), `<p class="wrg-g">${sub}</p>` + body);
    }

    async function showAllPersons() {
      const rows = filteredRows();
      lastList = { type: 'all' };
      openDialog(`Par personne (${rows.length})`, `<p class="wrg-g">Cliquez un nom pour voir tous ses choix</p><div class="wrg-names" style="margin-top:10px">${rows.map((r) => personBtn(r._i, 0)).join('')}</div>`);
    }

    async function showPerson(gi) {
      const base = cfg.dataUrl.replace(/\/data$/, '');
      try {
        const res = await fetch(`${base}/person/${gi}?filter=${encodeURIComponent(state.filter)}`);
        const p = await res.json();
        if (!res.ok) throw new Error(p.error || res.statusText);
        const groups = Object.entries(p.groups || {}).map(([g, items]) => `<h3>${esc(g)}</h3><ul>${items.map((t) => `<li>${esc(t)}</li>`).join('')}</ul>`).join('');
        const contacts = p.contacts && (p.contacts.phone || p.contacts.email)
          ? `<p class="wrg-g">${esc([p.contacts.phone, p.contacts.email].filter(Boolean).join(' · '))}</p>` : '';
        const back = lastList ? '<button class="wrg-more" data-back>← Retour à la liste</button>' : '';
        openDialog(esc(p.name), contacts + (groups || '<p class="wrg-g">Aucun choix coché.</p>') + back);
      } catch (err) {
        openDialog('Erreur', `<p class="wrg-g">Détail indisponible (${esc(err.message)}).</p>`);
      }
    }

    // Event listeners only for edit mode (when elements exist).
    if (search) {
      search.addEventListener('input', () => buildTree(search.value));
    }
    if (filters) {
      filters.addEventListener('click', (e) => {
        if (e.target.dataset.filter !== undefined) {
          state.filter = e.target.dataset.filter;
          state.filterLabel = e.target.dataset.label || '';
          buildFilter();
          render();
        } else if (e.target.dataset.all !== undefined) {
          showAllPersons();
        }
      });
    }
    if (grid) {
      grid.addEventListener('change', (e) => {
      if (e.target.dataset.agg !== undefined) { const p = String(e.target.dataset.agg); const card = getCardByPath(p); if (card) { card.agg = e.target.value; updateCard(p); markDirty(); } }
      else if (e.target.dataset.mode !== undefined) { const p = String(e.target.dataset.mode); const card = getCardByPath(p); if (card) { card.mode = e.target.value; updateCard(p); markDirty(); } }
      else if (e.target.dataset.displayMode !== undefined) { const p = String(e.target.dataset.displayMode); const card = getCardByPath(p); if (card) { card.display_mode = e.target.value; if (e.target.value !== 'detail') { delete card.value_filters; } updateCard(p); markDirty(); } }
      else if (e.target.dataset.vfVal !== undefined) {
        // Boolean checkbox filter (card-level or detail mode).
        const filterContainer = e.target.closest('.wrg-value-filter');
        if (filterContainer) {
          // Try to get src from filter container, or from parent detail section.
          let src = filterContainer.dataset.src;
          if (!src) {
            const detailSection = filterContainer.closest('.wrg-detail-section');
            src = detailSection ? detailSection.dataset.src : null;
          }
          // Find card path from the parent card element.
          const cardEl = filterContainer.closest('.wrg-card');
          const path = cardEl ? String(cardEl.dataset.i) : null;
          if (path) {
            const card = getCardByPath(path);
            const val = e.target.dataset.vfVal;
            if (src) {
              // Detail mode: update value_filters for specific source field.
              card.value_filters = card.value_filters || {};
              if (e.target.checked) {
                card.value_filters[src] = [...new Set([...(card.value_filters[src] || []), val])];
              }
              else {
                card.value_filters[src] = (card.value_filters[src] || []).filter(v => v !== val);
              }
            }
            else {
              // Card-level filter.
              if (e.target.checked) {
                card.value_filter = [...new Set([...(card.value_filter || []), val])];
              }
              else {
                card.value_filter = (card.value_filter || []).filter(v => v !== val);
              }
            }
            updateCard(path);
            markDirty();
          }
        }
      }
      else if (e.target.dataset.vfSelect !== undefined) {
        // Choice multiselect filter (card-level or detail mode).
        const filterContainer = e.target.closest('.wrg-value-filter');
        if (filterContainer) {
          // Try to get src from filter container, or from parent detail section.
          let src = filterContainer.dataset.src;
          if (!src) {
            const detailSection = filterContainer.closest('.wrg-detail-section');
            src = detailSection ? detailSection.dataset.src : null;
          }
          // Find card path from the parent card element.
          const cardEl = filterContainer.closest('.wrg-card');
          const path = cardEl ? String(cardEl.dataset.i) : null;
          if (path) {
            const selected = Array.from(e.target.selectedOptions).map(o => o.value);
            const card = getCardByPath(path);
            if (src) {
              // Detail mode: update value_filters for specific source field.
              card.value_filters = card.value_filters || {};
              card.value_filters[src] = selected;
            }
            else {
              // Card-level filter.
              card.value_filter = selected;
            }
            updateCard(path);
            markDirty();
          }
        }
      }
    }); // End grid.addEventListener('change')
    if (grid) {
      grid.addEventListener('click', (e) => {
        if (e.target.dataset.toggle !== undefined) {
          // Folder collapse/expand (works in both modes, saved on next save).
          const p = String(e.target.dataset.toggle);
          const folder = getCardByPath(p);
          if (folder && isFolder(folder)) {
            folder.collapsed = !folder.collapsed;
            const cardEl = e.target.closest('.wrg-card');
            if (cardEl) {
              cardEl.classList.toggle('wrg-collapsed', !!folder.collapsed);
              const btn = cardEl.querySelector('[data-toggle]');
              if (btn) {
                btn.textContent = folder.collapsed ? '▶' : '▼';
                btn.setAttribute('aria-expanded', folder.collapsed ? 'false' : 'true');
                btn.title = folder.collapsed ? 'Déplier' : 'Replier';
              }
              // Tell GridStack to re-measure this item (content height changed).
              if (gs && display.autoSizeCards) {
                const item = cardEl.closest('.grid-stack-item');
                if (item) {
                  try { gs.resizeToContent(item); } catch (err) { /* ignore */ }
                }
              }
            }
            markDirty();
          }
          return;
        }
        if (e.target.dataset.x !== undefined) {
          const p = String(e.target.dataset.x);
          if (p.includes('/')) {
            // Remove child from folder.
            const [fi, ci] = p.split('/').map(Number);
            const folder = state.cards[fi];
            if (folder && isFolder(folder) && folder.children) {
              folder.children.splice(ci, 1);
              render();
              markDirty();
            }
          } else {
            state.cards.splice(+p, 1);
            render();
            markDirty();
          }
        }
        else if (e.target.dataset.w !== undefined) {
          const p = String(e.target.dataset.w);
          const c = getCardByPath(p);
          if (c) {
            // Cycle widths across the grid: 1/3 → 1/2 → 2/3 → full → 1/3...
            const steps = [
              Math.max(1, Math.round(gridColumns / 3)),
              Math.max(1, Math.round(gridColumns / 2)),
              Math.max(1, Math.round(gridColumns * 2 / 3)),
              gridColumns,
            ];
            const cur = c.w || steps[0];
            const next = steps.find((s) => s > cur) ?? steps[0];
            c.w = next;
            updateCard(p);
            markDirty();
          }
        }
        else if (e.target.dataset.moveUp !== undefined || e.target.dataset.moveDown !== undefined) {
          // Reorder a card inside its folder: swap with previous/next sibling.
          const p = String(e.target.dataset.moveUp ?? e.target.dataset.moveDown);
          const dir = e.target.dataset.moveUp !== undefined ? -1 : 1;
          const parts = p.split('/').map(Number);
          if (parts.length === 2) {
            const [fi, ci] = parts;
            const folder = state.cards[fi];
            if (folder && isFolder(folder) && Array.isArray(folder.children)) {
              const ni = ci + dir;
              if (ni >= 0 && ni < folder.children.length) {
                [folder.children[ci], folder.children[ni]] = [folder.children[ni], folder.children[ci]];
                updateCard(String(fi));
                markDirty();
              }
            }
          }
        }
      });
    // Label edits on blur (title, group, badges, detail sections, summary, folders).
    grid.addEventListener('blur', (e) => {
      const el = e.target.closest('[data-label],[data-group-label],[data-source-label],[data-section-label],[data-summary-label],[data-folder-label]');
      if (!el) return;
      const text = el.textContent.trim();
      if (el.dataset.folderLabel !== undefined) {
        const p = String(el.dataset.folderLabel);
        const folder = getCardByPath(p);
        if (folder && isFolder(folder)) {
          folder.label = text || 'Dossier';
          updateCard(p);
          markDirty();
        }
        return;
      }
      if (el.dataset.label !== undefined) {
        const p = String(el.dataset.label);
        const card = getCardByPath(p);
        if (card) { card.label = text; updateCard(p); markDirty(); }
        return;
      }
      if (el.dataset.groupLabel !== undefined) {
        const p = String(el.dataset.groupLabel);
        const card = getCardByPath(p);
        if (card) { card.group_label = text; updateCard(p); markDirty(); }
        return;
      }
      if (el.dataset.sourceLabel !== undefined) {
        const [p, ...srcParts] = el.dataset.sourceLabel.split(':');
        const src = srcParts.join(':');
        const card = getCardByPath(p);
        if (card) {
          card.source_labels = card.source_labels || {};
          card.source_labels[src] = text;
          updateCard(p);
          markDirty();
        }
        return;
      }
      if (el.dataset.sectionLabel !== undefined) {
        const [p, ...srcParts] = el.dataset.sectionLabel.split(':');
        const src = srcParts.join(':');
        const card = getCardByPath(p);
        if (card) {
          card.section_labels = card.section_labels || {};
          card.section_labels[src] = text;
          updateCard(p);
          markDirty();
        }
        return;
      }
      if (el.dataset.summaryLabel !== undefined) {
        const p = String(el.dataset.summaryLabel);
        const card = getCardByPath(p);
        if (card) { card.summary_label = text; updateCard(p); markDirty(); }
        return;
      }
    }, true); // Use capture for blur.
    }

    // Delegated clicks: names, "see full list", back, close.
    document.addEventListener('click', (e) => {
      const t = e.target.closest('[data-p],[data-l],[data-close],[data-back]');
      if (!t || !root.contains(t)) return;
      if (t.dataset.p !== undefined) showPerson(+t.dataset.p);
      else if (t.dataset.l !== undefined) showCardList(String(t.dataset.l));
      else if (t.dataset.back !== undefined) {
        if (lastList && lastList.type === 'card') showCardList(lastList.index);
        else showAllPersons();
      }
      else dialog.close();
    });
    dialog.addEventListener('click', (e) => { if (e.target === dialog) dialog.close(); });

    // Drag & Drop logic - only in edit mode.
    // Paths: top-level "i", folder child "i/j". Folders accept drops into children.
    if (!readOnly) {
      let dragged = null; // { f: fieldKey } from palette, or { p: cardPath } from grid.
      let dragOverCard = null; // Path of card being hovered for drop target.

      document.addEventListener('dragstart', (e) => {
      const chip = e.target.closest('.wrg-chip');
      const card = e.target.closest('.wrg-card');
      dragged = chip ? { f: chip.dataset.field } : card ? { p: String(card.dataset.i) } : null;
      if (dragged) {
        e.dataTransfer.setData('text/plain', 'x');
        e.dataTransfer.effectAllowed = dragged.f ? 'copy' : 'move';
      }
    });

    document.addEventListener('dragend', () => {
      dragged = null;
      dragOverCard = null;
      document.querySelectorAll('.wrg-card').forEach(el => el.classList.remove('wrg-drop-hover', 'wrg-drop-hover-folder'));
    });

    grid.addEventListener('dragover', (e) => {
      e.preventDefault();
      e.dataTransfer.dropEffect = dragged?.f ? 'copy' : 'move';

      // Highlight card under cursor as drop target.
      const card = e.target.closest('.wrg-card');
      if (card && (dragged?.f || dragged?.p)) {
        const p = String(card.dataset.i);
        if (dragOverCard !== p) {
          document.querySelectorAll('.wrg-card').forEach(el => el.classList.remove('wrg-drop-hover', 'wrg-drop-hover-folder'));
          const target = getCardByPath(p);
          if (target && isFolder(target)) card.classList.add('wrg-drop-hover-folder');
          else card.classList.add('wrg-drop-hover');
          dragOverCard = p;
        }
      }
      else {
        document.querySelectorAll('.wrg-card').forEach(el => el.classList.remove('wrg-drop-hover', 'wrg-drop-hover-folder'));
        dragOverCard = null;
      }
    });

    grid.addEventListener('dragleave', (e) => {
      // Only clear if leaving the grid entirely.
      if (!e.currentTarget.contains(e.relatedTarget)) {
        document.querySelectorAll('.wrg-card').forEach(el => el.classList.remove('wrg-drop-hover', 'wrg-drop-hover-folder'));
        dragOverCard = null;
      }
    });

    grid.addEventListener('drop', (e) => {
      e.preventDefault();
      if (!dragged) return;

      // Drop onto a card (combine / into folder) vs drop onto empty space (new / reorder).
      const targetCard = e.target.closest('.wrg-card');
      const isOnCard = !!targetCard;
      const targetPath = isOnCard ? String(targetCard.dataset.i) : null;

      // Clear hover state.
      document.querySelectorAll('.wrg-card').forEach(el => el.classList.remove('wrg-drop-hover', 'wrg-drop-hover-folder'));
      dragOverCard = null;

      const newDataCard = (fieldKey) => {
        const field = state.groups.flatMap((g) => g.fields).find((f) => f.key === fieldKey);
        if (!field) return null;
        const agg = Object.keys(state.aggs[field.kind] || {})[0] || 'sum';
        return { field: field.key, agg, w: field.kind === 'r' ? 2 : 1 };
      };

      if (dragged.f) {
        // Dragging from palette (chip).
        const field = state.groups.flatMap((g) => g.fields).find((f) => f.key === dragged.f);
        if (!field) return;

        if (isOnCard) {
          const target = getCardByPath(targetPath);
          if (target && isFolder(target)) {
            // Drop ONTO folder → append as child (auto-expand).
            const nc = newDataCard(dragged.f);
            if (nc) {
              target.children = target.children || [];
              target.children.push(nc);
              target.collapsed = false;
            }
          }
          else if (target && !isFolder(target)) {
            // Drop ONTO data card → add to its source_fields (create composite).
            const sources = target.source_fields ? [...target.source_fields] : [target.field];
            if (!sources.includes(dragged.f)) {
              sources.push(dragged.f);
              target.source_fields = sources;
              target.mode = target.mode || 'union';
              if (!target.label || target.label === field.label) {
                target.label = sources.length === 2 ? `${field.label} + ...` : target.label;
              }
            }
          }
        }
        else {
          // Drop on empty space → create new simple card at top level.
          const nc = newDataCard(dragged.f);
          if (nc) state.cards.push(nc);
        }
      }
      else if (dragged.p) {
        // Dragging existing card → move (top-level reorder or into/out of folder).
        const srcPath = String(dragged.p);
        if (srcPath === targetPath) { dragged = null; return; }
        const srcParts = srcPath.split('/').map(Number);
        let moved = null;
        if (srcParts.length === 2) {
          const srcFolder = state.cards[srcParts[0]];
          if (srcFolder && isFolder(srcFolder) && srcFolder.children) {
            [moved] = srcFolder.children.splice(srcParts[1], 1);
          }
        } else {
          [moved] = state.cards.splice(srcParts[0], 1);
        }
        if (!moved) { dragged = null; return; }
        // Don't drop a folder into itself or into a folder child.
        if (isFolder(moved) && targetPath && (targetPath === srcPath || targetPath.startsWith(srcPath + '/'))) {
          // Restore.
          if (srcParts.length === 2) state.cards[srcParts[0]].children.splice(srcParts[1], 0, moved);
          else state.cards.splice(srcParts[0], 0, moved);
          dragged = null;
          return;
        }
        if (isOnCard) {
          const target = getCardByPath(targetPath);
          if (target && isFolder(target) && !isFolder(moved)) {
            // Move data card INTO folder.
            target.children = target.children || [];
            target.children.push(moved);
            target.collapsed = false;
          }
          else if (targetPath.includes('/')) {
            // Move next to a folder child (same folder).
            const [fi, ci] = targetPath.split('/').map(Number);
            let at = ci;
            if (srcParts.length === 2 && srcParts[0] === fi && srcParts[1] < ci) at = ci - 1;
            state.cards[fi].children.splice(at, 0, moved);
          }
          else {
            // Move to top level at target position.
            let at = +targetPath;
            if (srcParts.length === 1 && srcParts[0] < at) at = at - 1;
            state.cards.splice(at, 0, moved);
          }
        }
        else {
          // Drop on empty space: child → back to top level; top-level → end.
          state.cards.push(moved);
        }
      }
      dragged = null;
      render();
      markDirty();
    });
    } // End if (!readOnly) - Drag & Drop logic

    // Export buttons work in both modes.
    root.querySelectorAll('[data-wrg-export]').forEach((a) => {
      a.addEventListener('click', (e) => {
        e.preventDefault();
        const ext = a.dataset.wrgExport;
        window.location = `${cfg.dataUrl.replace(/\/data$/, '')}/export.${ext}?filter=${encodeURIComponent(state.filter)}`;
      });
    });

    // Save layout and markDirty only in edit mode.
    if (!readOnly) {
      // "New folder" button: append an empty folder container.
      const addFolderBtn = root.querySelector('[data-wrg-add-folder]');
      if (addFolderBtn) {
        addFolderBtn.addEventListener('click', () => {
          const n = state.cards.filter((c) => isFolder(c)).length + 1;
          state.cards.push({ type: 'folder', label: `Dossier ${n}`, collapsed: false, w: 2, h: 2, children: [] });
          render();
          markDirty();
        });
      }
      // Save layout back to the config entity.
      const saveBtn = root.querySelector('[data-wrg-save]');
      const saveState = root.querySelector('[data-wrg-save-state]');
      // Only mark dirty on actual changes (drop, aggregation change, width toggle, delete, label edit, mode change, value filter).
      grid.addEventListener('drop', markDirty);
      grid.addEventListener('change', (e) => { if (e.target.dataset.agg !== undefined || e.target.dataset.mode !== undefined || e.target.dataset.vfVal !== undefined || e.target.dataset.vfSelect !== undefined) markDirty(); });
      grid.addEventListener('click', (e) => { if (e.target.dataset.w !== undefined || e.target.dataset.x !== undefined) markDirty(); });
      if (saveBtn) {
      saveBtn.addEventListener('click', async () => {
        if (saveState) saveState.textContent = 'Enregistrement…';
        try {
          // Use Drupal's standard CSRF token from drupalSettings.
          const csrf = drupalSettings?.webformReportGrid?.csrfToken || '';
          const res = await fetch(`${cfg.dataUrl.replace(/\/data$/, '')}/save`, {
            method: 'POST',
            headers: {
              'Content-Type': 'application/json',
              'X-CSRF-Token': csrf,
            },
            body: JSON.stringify({ cards: state.cards }),
          });
          const text = await res.text();
          let payload;
          try {
            payload = JSON.parse(text);
          } catch {
            throw new Error(`HTTP ${res.status}: ${text.slice(0, 120)}`);
          }
          if (!res.ok) throw new Error(payload.error || res.statusText);
          if (saveState) {
            saveState.textContent = `✓ Enregistré (${payload.saved} cartes)`;
            delete saveState.dataset.dirty;
          }
        } catch (err) {
          if (saveState) saveState.textContent = `✕ Échec: ${err.message}`;
        }
      });
      window.addEventListener('beforeunload', (e) => {
        if (saveState && saveState.dataset.dirty) e.preventDefault();
      });
    } // End if (saveBtn)
    } // End if (!readOnly) - Save layout

    // Switch page: reload report list when the webform changes.
    const switchWebform = root.querySelector('[data-wrg-switch-webform]');
    if (switchWebform) {
      switchWebform.addEventListener('change', () => {
        const w = switchWebform.value;
        window.location = w ? `/webform-report/switch?webform=${encodeURIComponent(w)}` : '/webform-report/switch';
      });
    }
  }
} // Extra closing brace to balance
})(Drupal, drupalSettings, once);