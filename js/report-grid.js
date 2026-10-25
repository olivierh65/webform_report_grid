/**
 * @file
 * Grid viewer: palette (groups/fields) + cartes libres GridStack + dialogs.
 *
 * Données : /webform-report/{id}/data (JSON). La config des cartes vient de
 * drupalSettings.webformReportGrid et est POSTée sur /save.
 *
 * Cartes :
 * - Simple    : { field, agg, x, y, w, h, label }
 * - Composite : { field, source_fields: [...], agg: "rep", mode: "union", ... }
 * - Dossier   : { type: "folder", label, collapsed, x, y, w, h, h_open, children: [cartes...] }
 *
 * Mise en page : GridStack possède x/y/w/h.
 * - Grille principale : `columns` colonnes (réglage), cellHeight = CELL_H px.
 * - Chaque dossier contient une SOUS-GRILLE de SUB_COLUMNS colonnes
 *   (un seul niveau d'imbrication).
 * - Les cartes sont identifiées par une clé interne stable (keyOf), pas par
 *   leur position dans le tableau : on peut donc les déplacer entre dossiers
 *   sans désynchroniser le DOM et la config.
 *
 * Clés PHP (sanitizeCard) à conserver pour les cartes ET les enfants :
 * x, y, w, h, auto_h, h_open (+ children récursif).
 */
(function (Drupal, drupalSettings, once) {
  "use strict";

  Drupal.behaviors.webformReportGrid = {
    attach(context) {
      once("wrg", "[data-report]", context).forEach((root) => init(root));
    },
  };

  // Hauteur d'une ligne de grille en px (toutes les hauteurs h sont des multiples).
  const CELL_H = 60;
  // Nombre de colonnes des sous-grilles (dossiers).
  const SUB_COLUMNS = 12;
  // Hauteurs par défaut (en lignes).
  const DEFAULT_H = 3;
  const FOLDER_DEFAULT_H = 6;
  // Breakpoints utilisés uniquement en lecture seule (front).
  const BREAKPOINTS = [
    { w: 700, c: 1 },
    { w: 850, c: 3 },
    { w: 950, c: 6 },
    { w: 1100, c: 8 },
  ];

  const clamp = (v, min, max) => Math.min(max, Math.max(min, v));

  function init(root) {
    const cfg = drupalSettings.webformReportGrid || {};
    const readOnly = !!cfg.readOnly;
    const display = Object.assign(
      {
        fontSize: 0.95,
        cardGap: 16,
        columnWidth: 280,
        columns: 12,
        float: false,
      },
      cfg.display || {},
    );
    const gridColumns = clamp(+display.columns || 12, 4, 24);
    const state = {
      groups: [],
      rows: [],
      aggs: {},
      cards: cfg.cards || [],
      filter: "",
      filterLabel: "",
      meta: {},
    };

    const grid = root.querySelector("[data-wrg-grid]");
    const tree = root.querySelector("[data-wrg-tree]");
    const filters = root.querySelector("[data-wrg-filters]");
    const search = root.querySelector("[data-wrg-search]");
    const dialog = root.querySelector("[data-wrg-dialog]");
    const palette = root.querySelector("[data-wrg-palette]");
    const toolbar = root.querySelector("[data-wrg-toolbar]");

    const ESC_MAP = {
      "&": "&amp;",
      "<": "&lt;",
      ">": "&gt;",
      '"': "&quot;",
      "'": "&#39;",
    };
    const esc = (s) => String(s).replace(/[&<>"']/g, (c) => ESC_MAP[c]);

    const saveStateEl = () => root.querySelector("[data-wrg-save-state]");
    function markDirty() {
      const saveState = saveStateEl();
      if (saveState) {
        saveState.textContent = "● Modifications non enregistrées";
        saveState.dataset.dirty = "1";
      }
    }

    if (readOnly) {
      if (palette) palette.style.display = "none";
      if (toolbar) toolbar.style.display = "none";
      const filterRow = root.querySelector("[data-wrg-filter-row]");
      if (filterRow) filterRow.style.display = "none";
    }

    // Réglages d'affichage -> variables CSS.
    (function applyDisplay() {
      const fs = clamp(+display.fontSize || 0.95, 0.7, 1.5);
      const gap = clamp(+display.cardGap || 0, 0, 40);
      root.style.setProperty("--wrg-fs", `${fs}rem`);
      root.style.setProperty("--wrg-gap", `${gap}px`);
    })();
    const gap = clamp(+display.cardGap || 0, 0, 40);

    // Message "grille vide" hors de la grille (GridStack possède le contenu de #grid).
    const emptyEl = document.createElement("div");
    emptyEl.className = "wrg-empty";
    emptyEl.textContent = readOnly
      ? "Aucune synthèse."
      : "Glissez un champ ici pour créer votre première synthèse.";
    emptyEl.hidden = true;
    grid.parentNode.insertBefore(emptyEl, grid);

    fetch(cfg.dataUrl)
      .then((r) => r.json())
      .then((payload) => {
        state.groups = payload.groups || [];
        state.rows = payload.rows || [];
        state.aggs = payload.aggregations || {};
        state.meta = payload;
        if (!readOnly) {
          buildFilter();
          buildTree("");
        }
        render();
      })
      .catch((err) => {
        grid.innerHTML = `<div class="wrg-empty">Données indisponibles (${esc(err.message)}).</div>`;
      });

    function filteredRows() {
      if (!state.filter || !cfg.filterField)
        return state.rows.map((r, i) => ({ ...r, _i: i }));
      return state.rows
        .map((r, i) => ({ ...r, _i: i }))
        .filter((r) => String(r.f[cfg.filterField] || "") === state.filter);
    }

    function buildFilter() {
      if (readOnly || !filters) return;
      const choice = state.groups
        .flatMap((g) => g.fields)
        .find((f) => f.key === cfg.filterField);
      const shortLabel = (l) => String(l).split("--")[0].trim();
      let html = `<button aria-pressed="${!state.filter}" data-filter="" data-label="">Tous</button>`;
      if (choice && choice.options) {
        html += Object.entries(choice.options)
          .map(
            ([v, l]) =>
              `<button aria-pressed="${state.filter === v}" data-filter="${esc(v)}" data-label="${esc(shortLabel(l))}">${esc(shortLabel(l))}</button>`,
          )
          .join("");
      }
      filters.innerHTML =
        html +
        '<button data-all style="margin-left:10px">Détail par personne</button>';
    }

    function buildTree(q) {
      q = (q || "").toLowerCase();
      tree.innerHTML = state.groups
        .map((g) => {
          const items = g.fields.filter(
            (f) => !q || (f.label + g.title).toLowerCase().includes(q),
          );
          if (!items.length) return "";
          return (
            `<details ${q ? "open" : ""}><summary>${esc(g.title)}</summary>` +
            items
              .map(
                (f) =>
                  `<span class="wrg-chip" draggable="true" data-field="${esc(f.key)}">${esc(f.label)} <i>${esc(f.kind)}</i></span>`,
              )
              .join("") +
            "</details>"
          );
        })
        .join("");
    }

    // ------------------------------------------------------------------
    // Calculs (inchangés)
    // ------------------------------------------------------------------

    const FILTERED_OUT = Symbol("filtered_out");

    function matchesValueFilter(v, filter) {
      if (!filter || !filter.length) return true;
      const strV = String(v);
      if (filter.includes(strV) || filter.includes(v)) return true;
      const isTrue = v === 1 || v === "1" || v === true;
      const isFalse = v === 0 || v === "0" || v === false;
      const isEmpty = v === null || v === undefined || v === "" || v === "—";
      if (isTrue && (filter.includes("Oui") || filter.includes("1")))
        return true;
      if (isFalse && (filter.includes("Non") || filter.includes("0")))
        return true;
      if (
        isEmpty &&
        (filter.includes("Non répondu") ||
          filter.includes("Non renseigné") ||
          filter.includes("—") ||
          filter.includes(""))
      )
        return true;
      return false;
    }

    function applyMode(values, mode) {
      switch (mode) {
        case "sum":
          return values
            .filter((v) => typeof v === "number")
            .reduce((a, b) => a + b, 0);
        case "first_non_empty":
          return (
            values.find((v) => v !== "" && v !== null && v !== undefined) ??
            null
          );
        case "union":
        default: {
          const uniq = [...new Set(values.map(String))];
          return uniq.length === 1 ? uniq[0] : uniq;
        }
      }
    }

    function getCombinedValue(card, row) {
      const isComposite = card.source_fields && card.source_fields.length > 1;
      if (!isComposite) {
        const val = row.f[card.field];
        if (card.value_filter && card.value_filter.length > 0) {
          if (!matchesValueFilter(val, card.value_filter)) return FILTERED_OUT;
        }
        return val;
      }
      const mode = card.mode || "union";
      const valueFilter = card.value_filter || null;
      const valueFilters = card.value_filters || null;
      const values = [];

      card.source_fields.forEach((src) => {
        if (row.f[src] === undefined) return;
        let val = row.f[src];
        if (
          valueFilters &&
          valueFilters[src] &&
          Array.isArray(valueFilters[src])
        ) {
          const srcFilter = valueFilters[src];
          if (Array.isArray(val)) {
            val = val.filter((v) => matchesValueFilter(v, srcFilter));
            if (!val.length) return;
          } else if (!matchesValueFilter(val, srcFilter)) {
            return;
          }
        }
        if (Array.isArray(val)) values.push(...val);
        else values.push(val);
      });

      if (!values.length) return FILTERED_OUT;

      if (valueFilter && valueFilter.length > 0) {
        const filtered = values.filter((v) =>
          matchesValueFilter(v, valueFilter),
        );
        if (!filtered.length) return FILTERED_OUT;
        return applyMode(filtered, mode);
      }
      return applyMode(values, mode);
    }

    function calc(card, rows) {
      const isComposite = card.source_fields && card.source_fields.length > 1;
      const field = state.groups
        .flatMap((g) => g.fields)
        .find((f) => f.key === card.field);
      if (!field) return { val: [0, ""], who: [], field: null };

      const effectiveKind =
        isComposite && card.mode === "union" ? "r" : field.kind || "b";
      const wantsBreakdown = card.agg === "rep";

      if (effectiveKind === "r" || (wantsBreakdown && effectiveKind === "b")) {
        const rep = {},
          gp = {};
        rows.forEach((r) => {
          const v = getCombinedValue(card, r);
          if (v === FILTERED_OUT) return;
          const val = v ?? "—";
          if (Array.isArray(val)) {
            val.forEach((vv) => {
              rep[vv] = (rep[vv] || 0) + 1;
              (gp[vv] = gp[vv] || []).push(r._i);
            });
          } else {
            rep[val] = (rep[val] || 0) + 1;
            (gp[val] = gp[val] || []).push(r._i);
          }
        });
        if (field.kind === "b") {
          const newRep = {},
            newGp = {};
          Object.entries(rep).forEach(([k, cnt]) => {
            const label =
              k === "1"
                ? "Oui"
                : k === "0"
                  ? "Non"
                  : k === "—"
                    ? "Non répondu"
                    : k;
            newRep[label] = cnt;
            newGp[label] = gp[k];
          });
          return {
            rep: newRep,
            gp: newGp,
            field: {
              ...field,
              kind: "r",
              options: { Oui: "Oui", Non: "Non", "Non répondu": "Non répondu" },
            },
          };
        }
        return { rep, gp, field: { ...field, kind: "r" } };
      }
      if (effectiveKind === "n") {
        const vals = rows.map((r) => {
          const v = getCombinedValue(card, r);
          return v === FILTERED_OUT ? 0 : Number(v) || 0;
        });
        const sum = vals.reduce((a, b) => a + b, 0);
        const who = rows
          .filter((r) => {
            const v = getCombinedValue(card, r);
            return v !== FILTERED_OUT && Number(v) ? true : false;
          })
          .map((r) => r._i);
        const out = {
          sum: [sum, ""],
          avg: [vals.length ? (sum / vals.length).toFixed(1) : 0, "/ answer"],
          max: [Math.max(0, ...vals), ""],
          min: [vals.length ? Math.min(...vals) : 0, ""],
          count: [who.length, ""],
        }[card.agg] || [sum, ""];
        return { val: out, who, field: { ...field, kind: "n" } };
      }
      if (effectiveKind === "t") {
        const who = rows
          .filter((r) => {
            const v = getCombinedValue(card, r);
            return v !== FILTERED_OUT && v !== null && v !== "";
          })
          .map((r) => r._i);
        return { val: [who.length, ""], who, field: { ...field, kind: "t" } };
      }
      // Boolean.
      let sum = 0;
      const who = [];
      rows.forEach((r) => {
        const v = getCombinedValue(card, r);
        if (v === FILTERED_OUT) return;
        if (v) {
          sum += typeof v === "number" ? v : 1;
          who.push(r._i);
        }
      });
      const out =
        card.agg === "pct"
          ? [rows.length ? Math.round((100 * sum) / rows.length) : 0, "%"]
          : [sum, ""];
      return { val: out, who, field: { ...field, kind: "b" } };
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    let lastList = null;
    const nameOf = (gi) => {
      const r = state.rows[gi];
      return r ? r.n : "?";
    };
    const personBtn = (gi, mult) =>
      `<button class="wrg-nm" data-p="${gi}">${esc(nameOf(gi))}${mult > 1 ? " ×" + mult : ""}</button>`;
    const repTotals = (x) => {
      const allGis = [...new Set(Object.values((x && x.gp) || {}).flat())];
      const responses = Object.values((x && x.rep) || {}).reduce(
        (a, n) => a + (+n || 0),
        0,
      );
      return { allGis, distinct: allGis.length, responses };
    };
    const pluralPersonnes = (n) => "personne" + (n > 1 ? "s" : "");
    const allFields = () => state.groups.flatMap((g) => g.fields);

    const isFolder = (c) => !!c && c.type === "folder";

    // Clés internes stables (indépendantes de la position dans l'arbre).
    const keyMap = new WeakMap();
    const byKey = new Map();
    let keySeq = 0;
    const keyOf = (c) => {
      let k = keyMap.get(c);
      if (!k) {
        k = "k" + ++keySeq;
        keyMap.set(c, k);
        byKey.set(k, c);
      }
      return k;
    };
    const getCard = (k) => byKey.get(String(k)) || null;

    const flatten = (list) =>
      list.flatMap((c) =>
        isFolder(c) ? [c, ...flatten(c.children || [])] : [c],
      );
    // Emplacement d'une carte dans l'arbre : { list, index, parent }.
    function findLoc(card) {
      let i = state.cards.indexOf(card);
      if (i >= 0) return { list: state.cards, index: i, parent: null };
      for (const f of state.cards) {
        if (isFolder(f) && Array.isArray(f.children)) {
          i = f.children.indexOf(card);
          if (i >= 0) return { list: f.children, index: i, parent: f };
        }
      }
      return null;
    }

    const fieldKindOf = (key) => {
      const f = allFields().find((fl) => fl.key === key);
      return f ? f.kind || "b" : null;
    };
    // Miroir de PHP sanitizeCard() : union => 'r', sum => 'n', sinon type du 1er champ.
    const effectiveKindOf = (card) => {
      const sources =
        card.source_fields && card.source_fields.length
          ? card.source_fields
          : card.field
            ? [card.field]
            : [];
      const kind = fieldKindOf(sources[0]) || "b";
      if (sources.length > 1) {
        if ((card.mode || "union") === "union") return "r";
        if (card.mode === "sum") return "n";
      }
      return kind;
    };
    const ensureValidAgg = (card) => {
      if (!card || isFolder(card)) return false;
      const valid = Object.keys(state.aggs[effectiveKindOf(card)] || {});
      if (!valid.length) return false;
      if (!valid.includes(card.agg)) {
        card.agg = valid[0];
        return true;
      }
      return false;
    };
    const multOf = (gi, card) => {
      const r = state.rows[gi];
      if (!r) return 0;
      const v = getCombinedValue(card, r);
      return typeof v === "number" && v > 1 ? v : 0;
    };
    const defaultW = (kind, cols) =>
      Math.max(1, Math.round(cols / (kind === "r" ? 3 : 4)));

    // ------------------------------------------------------------------
    // GridStack
    // ------------------------------------------------------------------

    let gs = null;
    let syncing = false; // true pendant render() : ignore les événements GridStack.
    let syncTimer = null;
    const boundGrids = new WeakSet();

    // Un dossier ne peut pas être déposé dans un dossier (1 niveau max).
    const acceptFn = (el) => {
      const n = el && el.gridstackNode;
      if (n && (n.subGrid || n.subGridOpts)) return false;
      return !(el && el.classList && el.classList.contains("wrg-gs-folder"));
    };

    const responsiveOpts = () =>
      readOnly
        ? {
            columnOpts: {
              breakpointForWindow: true,
              layout: "moveScale",
              breakpoints: BREAKPOINTS,
            },
          }
        : {};

    // Gravité (float: false, défaut GridStack) : les cartes remontent et
    // s'insèrent naturellement entre les autres pendant un déplacement, sans
    // trous. Même comportement en build et en front => même ordre.
    // Mettre display.float = true (réglage) pour un placement libre avec trous.
    const floatOpt = !!display.float;

    function baseOpts(columns, nested = false) {
      return {
        column: columns,
        cellHeight: CELL_H,
        margin: gap,
        float: floatOpt,
        animate: true,
        staticGrid: readOnly,
        acceptWidgets: readOnly ? false : acceptFn,
        draggable: {
          // .wrg-drag = poignée ⠿ des cartes + toute la barre d'en-tête des dossiers.
          handle: ".wrg-drag",
          // Ne pas démarrer un déplacement depuis un contrôle ou un libellé éditable.
          cancel:
            'input,textarea,button,select,option,[contenteditable="true"]',
          // Pas de défilement automatique : un dossier ouvert plus haut que la
          // fenêtre faisait défiler la page en boucle pendant le glissement.
          // La molette fonctionne toujours pendant un glissement.
          scroll: false,
        },
        // Sous-grilles : poignées toujours visibles (l'autoHide au survol est
        // peu fiable avec des grilles imbriquées : le survol de l'enfant est
        // "volé" par le dossier parent).
        resizable: { handles: "e, se, s", autoHide: !nested },
        ...responsiveOpts(),
      };
    }

    function ensureGrid() {
      if (gs) return true;
      if (typeof GridStack === "undefined" || !grid) return false;
      try {
        GridStack.renderCB = (el, w) => {
          el.innerHTML = w.content || "";
        };
        gs = GridStack.init(baseOpts(gridColumns), grid);
        bindGrid(gs);
        return true;
      } catch (e) {
        console.error("GridStack init failed:", e);
        gs = null;
        return false;
      }
    }

    function bindGrid(g) {
      if (readOnly || !g || boundGrids.has(g)) return;
      boundGrids.add(g);
      g.on("change dragstop resizestop dropped added removed", onLayoutEvent);
    }

    function onLayoutEvent() {
      if (syncing || readOnly) return;
      clearTimeout(syncTimer);
      // Léger délai : on laisse GridStack finir un transfert entre grilles.
      syncTimer = setTimeout(() => {
        if (syncTreeFromGrid()) {
          markDirty();
          refreshFolderHeads();
        }
      }, 40);
    }

    const walk = (g, fn) =>
      g.getGridItems().forEach((el) => {
        const n = el.gridstackNode;
        if (!n) return;
        fn(el, n);
        if (n.subGrid) walk(n.subGrid, fn);
      });

    const itemEl = (key) => {
      let found = null;
      grid.querySelectorAll(".grid-stack-item").forEach((el) => {
        if (el.gridstackNode && String(el.gridstackNode.id) === String(key))
          found = el;
      });
      return found;
    };

    /**
     * Lit la grille (positions + structure dossiers) et met à jour state.cards.
     * Retourne true si quelque chose a changé. N'applique rien si le nombre de
     * cartes lues diffère de la config (transfert en cours, etc.).
     */
    function syncTreeFromGrid() {
      if (!gs || readOnly) return false;

      const read = (g, cols) => {
        const items = [];
        g.getGridItems().forEach((el) => {
          const n = el.gridstackNode;
          const card = n && getCard(n.id);
          if (card) items.push({ card, n });
        });
        items.sort(
          (a, b) => (a.n.y || 0) - (b.n.y || 0) || (a.n.x || 0) - (b.n.x || 0),
        );
        return items.map(({ card, n }) => ({
          card,
          geo: {
            x: Math.max(0, n.x || 0),
            y: Math.max(0, n.y || 0),
            w: clamp(n.w || 1, 1, cols),
            h: Math.max(1, n.h || 1),
          },
          kids:
            isFolder(card) && n.subGrid ? read(n.subGrid, SUB_COLUMNS) : null,
        }));
      };

      const pending = read(gs, gridColumns);

      // Validation : même ensemble de cartes avant/après.
      const seen = new Set();
      const collect = (items) =>
        items.forEach((it) => {
          seen.add(it.card);
          if (it.kids) collect(it.kids);
          else if (isFolder(it.card))
            (it.card.children || []).forEach((k) => seen.add(k));
        });
      collect(pending);
      const before = new Set(flatten(state.cards));
      if (seen.size !== before.size || [...before].some((c) => !seen.has(c)))
        return false;

      let changed = false;
      const setGeo = (card, geo) => {
        ["x", "y", "w", "h"].forEach((k) => {
          // Un dossier replié a h=1 : on garde sa hauteur dépliée.
          if (k === "h" && isFolder(card) && card.collapsed) return;
          if (card[k] !== geo[k]) {
            card[k] = geo[k];
            changed = true;
          }
        });
      };
      const sameList = (a, b) =>
        a.length === b.length && a.every((c, i) => c === b[i]);
      const apply = (items) =>
        items.map((it) => {
          const c = it.card;
          setGeo(c, it.geo);
          if (isFolder(c) && it.kids) {
            const kids = apply(it.kids);
            if (!sameList(kids, c.children || [])) changed = true;
            c.children = kids;
          }
          return c;
        });
      const top = apply(pending);
      if (!sameList(top, state.cards)) changed = true;
      state.cards = top;
      return changed;
    }

    /**
     * Applique une modification structurelle : synchronise d'abord les
     * positions courantes (sinon elles sont perdues par le re-render).
     */
    function commit(fn) {
      clearTimeout(syncTimer);
      syncTreeFromGrid();
      fn();
      render();
      markDirty();
    }

    function refreshFolderHeads() {
      state.cards.filter(isFolder).forEach((f) => updateCard(keyOf(f)));
    }

    // Construit la définition d'un widget GridStack (récursif pour les dossiers).
    function widgetOf(c, rows, cols) {
      const key = keyOf(c);
      const folder = isFolder(c);
      const hasPos =
        Number.isInteger(c.x) && c.x >= 0 && Number.isInteger(c.y) && c.y >= 0;
      let w, h;

      if (folder) {
        if (c.collapsed && !(c.h_open > 1))
          c.h_open = c.h > 1 ? c.h : FOLDER_DEFAULT_H;
        w = clamp(Math.round(c.w) || Math.round(cols / 2), 1, cols);
        h = c.collapsed
          ? 1
          : c.h > 1
            ? c.h
            : c.h_open > 1
              ? c.h_open
              : FOLDER_DEFAULT_H;
      } else {
        const dw = defaultW(effectiveKindOf(c), cols);
        // Ancienne config (pas de x/y) : tailles de l'ancienne grille CSS (1, 2...) -> défauts.
        w = hasPos ? clamp(Math.round(c.w) || dw, 1, cols) : dw;
        h = hasPos
          ? Math.max(1, Math.round(c.h) || DEFAULT_H)
          : Math.max(Math.round(c.h) || 0, DEFAULT_H);
      }

      const widget = {
        id: key,
        w,
        h,
        minW: 1,
        minH: 1,
        content: renderCard(c, rows),
        ...(hasPos ? { x: c.x, y: c.y } : {}),
        ...(!folder && c.auto_h ? { sizeToContent: true } : {}),
      };

      if (folder) {
        widget.subGridOpts = {
          ...baseOpts(SUB_COLUMNS, true),
          // Pas de breakpoints par colonne dans les sous-grilles en édition.
          children: (c.children || [])
            .filter((k) => !isFolder(k))
            .map((k) => widgetOf(k, rows, SUB_COLUMNS)),
        };
      }
      return widget;
    }

    // Classes CSS sur les éléments (dossiers) + binding des sous-grilles.
    function decorate(g) {
      walk(g, (el, n) => {
        const card = getCard(n.id);
        if (!card || !isFolder(card)) return;
        el.classList.add("wrg-gs-folder");
        el.classList.toggle("wrg-folder-collapsed", !!card.collapsed);
        if (n.subGrid) {
          bindGrid(n.subGrid);
          // Filet de sécurité : selon la version de GridStack, les options
          // de la sous-grille ne réactivent pas toujours le redimensionnement
          // (et les poignées) des enfants créés via load().
          if (!readOnly) {
            try {
              n.subGrid.setStatic(false);
              n.subGrid.enableMove(true);
              n.subGrid.enableResize(true);
            } catch (e) {
              /* ignore */
            }
          }
        }
      });
    }

    // Recalcule la hauteur des cartes en mode auto_h.
    function fitAll() {
      const run = () =>
        walk(gs, (el, n) => {
          const card = getCard(n.id);
          if (card && !isFolder(card) && card.auto_h && n.grid) {
            try {
              n.grid.resizeToContent(el);
            } catch (e) {
              /* ignore */
            }
          }
        });
      requestAnimationFrame(() => {
        run();
        requestAnimationFrame(run);
      });
    }

    function refit(el, card) {
      const n = el && el.gridstackNode;
      if (!n || !n.grid || !card || isFolder(card) || !card.auto_h) return;
      requestAnimationFrame(() => {
        try {
          n.grid.resizeToContent(el);
        } catch (e) {
          /* ignore */
        }
      });
    }

    // ------------------------------------------------------------------
    // Rendu des cartes (HTML interne uniquement : GridStack gère les wrappers)
    // ------------------------------------------------------------------

    function renderCard(c, rows) {
      if (isFolder(c)) return renderFolder(c);
      const key = keyOf(c);
      const isComposite = c.source_fields && c.source_fields.length > 1;
      const displayMode = c.display_mode || "combined";

      if (isComposite && displayMode === "detail")
        return renderCardDetail(c, rows);

      const x = calc(c, rows);
      const f = x.field || { label: c.field, kind: "b", group: "" };
      const opts = Object.entries(state.aggs[f.kind] || {})
        .map(
          ([v, l]) =>
            `<option value="${v}" ${v === c.agg ? "selected" : ""}>${esc(l)}</option>`,
        )
        .join("");

      let sourcesHtml = "";
      if (isComposite) {
        const sourceLabels = c.source_labels || {};
        const fieldLabels = c.source_fields.map((src) => {
          if (sourceLabels[src]) return sourceLabels[src];
          const found = allFields().find((fl) => fl.key === src);
          return found ? found.label : src;
        });
        sourcesHtml = `<div class="wrg-sources">${c.source_fields
          .map((src, k) =>
            readOnly
              ? `<span class="wrg-source-badge">${esc(fieldLabels[k])}</span>`
              : `<span class="wrg-source-badge" contenteditable="true" data-source-label="${key}:${esc(src)}">${esc(fieldLabels[k])}</span>`,
          )
          .join("")}</div>`;
      }

      let body;
      if (x.rep) {
        const mx = Math.max(1, ...Object.values(x.rep));
        const optLabel = (k) =>
          f.options && f.options[k] !== undefined
            ? String(f.options[k]).split("--")[0].trim()
            : k === "—"
              ? "Non renseigné"
              : k;
        const { allGis, distinct } = repTotals(x);
        const shownGis = allGis.slice(0, 5);
        const extraGis = distinct - shownGis.length;
        body =
          `<div class="wrg-big">${distinct}<small>${esc(pluralPersonnes(distinct))}</small></div>` +
          Object.entries(x.rep)
            .map(
              ([k, n]) =>
                `<div class="wrg-bar"><span>${esc(optLabel(k))}</span><div style="width:${(100 * n) / mx}%"></div><b>${n}</b></div>`,
            )
            .join("") +
          `<div class="wrg-names">${shownGis.map((gi) => personBtn(gi, multOf(gi, c))).join("") || '<span class="wrg-none">Personne pour l\'instant</span>'}${extraGis > 0 ? `<span>+${extraGis}</span>` : ""}</div>` +
          `<button class="wrg-more" data-l="${key}">Voir qui a répondu quoi</button>`;
      } else {
        const unit =
          x.val[1] ||
          (c.agg === "sum" && f.kind === "b"
            ? "personne" + (x.val[0] > 1 ? "s" : "")
            : "");
        const shown = x.who.slice(0, 5);
        const extra = x.who.length - shown.length;
        body =
          `<div class="wrg-big">${x.val[0]}<small>${esc(unit)}</small></div>` +
          `<div class="wrg-names">${shown.map((gi) => personBtn(gi, multOf(gi, c))).join("") || '<span class="wrg-none">Personne pour l\'instant</span>'}${extra > 0 ? `<span>+${extra}</span>` : ""}</div>` +
          (x.who.length
            ? `<button class="wrg-more" data-l="${key}">Voir la liste complète (${x.who.length})</button>`
            : "");
      }

      const modeOpts = `<option value="union" ${c.mode === "union" ? "selected" : ""}>Union</option>
        <option value="sum" ${c.mode === "sum" ? "selected" : ""}>Somme</option>
        <option value="first_non_empty" ${c.mode === "first_non_empty" ? "selected" : ""}>1er non vide</option>`;
      const modeSelect =
        !readOnly && isComposite
          ? `<select aria-label="Mode" data-mode="${key}" title="Mode de combinaison">${modeOpts}</select>`
          : "";

      const displayModeOpts = `<option value="combined" ${c.display_mode !== "detail" ? "selected" : ""}>Combiné</option>
        <option value="detail" ${c.display_mode === "detail" ? "selected" : ""}>Détail par champ</option>`;
      const displayModeSelect =
        !readOnly && isComposite
          ? `<select aria-label="Affichage" data-display-mode="${key}" title="Mode d'affichage">${displayModeOpts}</select>`
          : "";

      const valueFilterHtml = readOnly ? "" : buildValueFilter(c, f);

      const toolsHtml = readOnly
        ? ""
        : `
        <div class="wrg-tools">
          <select aria-label="Synthèse" data-agg="${key}">${opts}</select>
          ${modeSelect}
          ${displayModeSelect}
          <button data-fit-h="${key}" title="Ajuster la hauteur au contenu (tel qu'affiché en front)" aria-label="Ajuster la hauteur">⇕</button>
          <button data-fit-w="${key}" title="Élargir jusqu'à tout voir sans défiler (hauteur conservée)" aria-label="Ajuster la largeur">⇔</button>
          <button data-auto="${key}" title="Hauteur automatique permanente (suit le contenu quand les données changent)" aria-label="Hauteur automatique" aria-pressed="${!!c.auto_h}">auto</button>
          <button data-w="${key}" title="Élargir ou réduire" aria-label="Élargir ou réduire">↔</button>
          <button data-x="${key}" title="Retirer" aria-label="Retirer">✕</button>
        </div>`;

      const labelHtml = readOnly
        ? `<div class="wrg-t">${esc(c.label || f.label)}</div>`
        : `<div class="wrg-t" contenteditable="true" data-label="${key}">${esc(c.label || f.label)}</div>`;

      const groupLabel = c.group_label || f.group || "";
      const groupHtml = readOnly
        ? `<div class="wrg-g">${esc(groupLabel)}</div>`
        : `<div class="wrg-g" contenteditable="true" data-group-label="${key}">${esc(groupLabel)}</div>`;

      const grip = readOnly
        ? ""
        : '<span class="wrg-grip wrg-drag" title="Déplacer" aria-label="Déplacer">⠿</span>';

      return `<article class="wrg-card${isComposite ? " wrg-composite" : ""}${c.auto_h ? " wrg-autoh" : ""}" data-k="${key}">
        <div class="wrg-top">
          ${grip}
          <div class="wrg-top-main">
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
     * Dossier : seulement l'en-tête. La sous-grille (enfants) est ajoutée
     * par GridStack après ce contenu (subGridOpts).
     */
    function renderFolder(c) {
      const key = keyOf(c);
      const count = (c.children || []).filter((k) => !isFolder(k)).length;
      const collapsed = !!c.collapsed;
      const labelHtml = readOnly
        ? `<div class="wrg-t">${esc(c.label || "Dossier")}</div>`
        : `<div class="wrg-t" contenteditable="true" data-folder-label="${key}">${esc(c.label || "Dossier")}</div>`;
      const grip = readOnly
        ? ""
        : '<span class="wrg-grip wrg-drag" title="Déplacer" aria-label="Déplacer">⠿</span>';
      const toolsHtml = readOnly
        ? ""
        : `
        <div class="wrg-tools">
          <button data-fit-h="${key}" title="Ajuster la hauteur pour voir toutes les cartes du dossier" aria-label="Ajuster la hauteur">⇕</button>
          <button data-w="${key}" title="Élargir ou réduire" aria-label="Élargir ou réduire">↔</button>
          <button data-x="${key}" title="Retirer le dossier" aria-label="Retirer le dossier">✕</button>
        </div>`;
      return `<article class="wrg-card wrg-folder${collapsed ? " wrg-collapsed" : ""}" data-k="${key}" data-folder="${key}">
        <div class="wrg-folder-head">
          ${grip}
          <button class="wrg-folder-toggle" data-toggle="${key}" aria-expanded="${collapsed ? "false" : "true"}" title="${collapsed ? "Déplier" : "Replier"}">${collapsed ? "▶" : "▼"}</button>
          ${labelHtml}
          <span class="wrg-folder-count">${count} carte${count > 1 ? "s" : ""}</span>
          ${toolsHtml}
        </div>
      </article>`;
    }

    function renderCardDetail(c, rows) {
      const key = keyOf(c);
      const f = allFields().find((fl) => fl.key === c.field) || {
        label: c.field,
        kind: "b",
        group: "",
      };
      const opts = Object.entries(state.aggs[f.kind] || {})
        .map(
          ([v, l]) =>
            `<option value="${v}" ${v === c.agg ? "selected" : ""}>${esc(l)}</option>`,
        )
        .join("");

      const sourceLabels = c.source_labels || {};
      const fieldLabels = c.source_fields.map((src) => {
        if (sourceLabels[src]) return sourceLabels[src];
        const found = allFields().find((fl) => fl.key === src);
        return found ? found.label : src;
      });
      const sourcesHtml = `<div class="wrg-sources">${c.source_fields
        .map((src, k) =>
          readOnly
            ? `<span class="wrg-source-badge">${esc(fieldLabels[k])}</span>`
            : `<span class="wrg-source-badge" contenteditable="true" data-source-label="${key}:${esc(src)}">${esc(fieldLabels[k])}</span>`,
        )
        .join("")}</div>`;

      const valueFilters = c.value_filters || {};

      const sections = c.source_fields
        .map((src, srcIdx) => {
          const field = allFields().find((fl) => fl.key === src);
          if (!field)
            return `<div class="wrg-detail-section"><div class="wrg-detail-title">${esc(src)}</div><div class="wrg-none">Champ introuvable</div></div>`;

          const srcValueFilter = valueFilters[src] || [];
          const tempCard = {
            ...c,
            field: src,
            source_fields: [src],
            value_filter: srcValueFilter,
          };
          const x = calc(tempCard, rows);
          const vfHtml = readOnly ? "" : buildValueFilter(tempCard, field, src);

          let body;
          if (x.rep) {
            const mx = Math.max(1, ...Object.values(x.rep));
            const optLabel = (k) =>
              field.options && field.options[k] !== undefined
                ? String(field.options[k]).split("--")[0].trim()
                : k === "—"
                  ? "Non renseigné"
                  : k;
            const { allGis, distinct } = repTotals(x);
            const shownGis = allGis.slice(0, 5);
            const extraGis = distinct - shownGis.length;
            body =
              `<div class="wrg-big">${distinct}<small>${esc(pluralPersonnes(distinct))}</small></div>` +
              Object.entries(x.rep)
                .map(
                  ([k, n]) =>
                    `<div class="wrg-bar"><span>${esc(optLabel(k))}</span><div style="width:${(100 * n) / mx}%"></div><b>${n}</b></div>`,
                )
                .join("") +
              `<div class="wrg-names">${shownGis.map((gi) => personBtn(gi, multOf(gi, tempCard))).join("") || '<span class="wrg-none">Personne pour l\'instant</span>'}${extraGis > 0 ? `<span>+${extraGis}</span>` : ""}</div>` +
              `<button class="wrg-more" data-l="${key}" data-src="${srcIdx}">Voir qui</button>`;
          } else {
            const unit =
              x.val[1] ||
              (c.agg === "sum" && field.kind === "b"
                ? "personne" + (x.val[0] > 1 ? "s" : "")
                : "");
            const shown = x.who.slice(0, 5);
            const extra = x.who.length - shown.length;
            body =
              `<div class="wrg-big">${x.val[0]}<small>${esc(unit)}</small></div>` +
              `<div class="wrg-names">${shown.map((gi) => personBtn(gi, multOf(gi, tempCard))).join("") || '<span class="wrg-none">Personne pour l\'instant</span>'}${extra > 0 ? `<span>+${extra}</span>` : ""}</div>` +
              (x.who.length
                ? `<button class="wrg-more" data-l="${key}" data-src="${srcIdx}">Voir la liste (${x.who.length})</button>`
                : "");
          }

          const sectionTitle =
            (c.section_labels && c.section_labels[src]) || fieldLabels[srcIdx];
          const sectionTitleHtml = readOnly
            ? `<div class="wrg-detail-title">${esc(sectionTitle)}</div>`
            : `<div class="wrg-detail-title" contenteditable="true" data-section-label="${key}:${esc(src)}">${esc(sectionTitle)}</div>`;
          return `<div class="wrg-detail-section" data-src="${esc(src)}">
          ${sectionTitleHtml}
          ${vfHtml}
          ${body}
        </div>`;
        })
        .join("");

      const summaryCard = { ...c, value_filters: c.value_filters };
      const xSummary = calc(summaryCard, rows);

      const displayModeOpts = `<option value="combined" ${c.display_mode !== "detail" ? "selected" : ""}>Combiné</option>
        <option value="detail" ${c.display_mode === "detail" ? "selected" : ""}>Détail par champ</option>`;

      let summaryHtml = "";
      const summaryTitle =
        c.summary_label ||
        (xSummary.rep
          ? `Total (${c.mode === "union" ? "Union" : c.mode})`
          : "Total");
      const summaryTitleHtml = readOnly
        ? `<div class="wrg-detail-title">${esc(summaryTitle)}</div>`
        : `<div class="wrg-detail-title" contenteditable="true" data-summary-label="${key}">${esc(summaryTitle)}</div>`;
      if (xSummary.rep) {
        const mx = Math.max(1, ...Object.values(xSummary.rep));
        const optLabel = (k) =>
          f.options && f.options[k] !== undefined
            ? String(f.options[k]).split("--")[0].trim()
            : k === "—"
              ? "Non renseigné"
              : k;
        const { allGis, distinct } = repTotals(xSummary);
        const shownGis = allGis.slice(0, 5);
        const extraGis = distinct - shownGis.length;
        summaryHtml = `<div class="wrg-detail-summary">
          ${summaryTitleHtml}
          <div class="wrg-big">${distinct}<small>${esc(pluralPersonnes(distinct))}</small></div>
          ${Object.entries(xSummary.rep)
            .map(
              ([k, n]) =>
                `<div class="wrg-bar"><span>${esc(optLabel(k))}</span><div style="width:${(100 * n) / mx}%"></div><b>${n}</b></div>`,
            )
            .join("")}
          <div class="wrg-names">${shownGis.map((gi) => personBtn(gi, multOf(gi, summaryCard))).join("") || '<span class="wrg-none">Personne</span>'}${extraGis > 0 ? `<span>+${extraGis}</span>` : ""}</div>
          ${xSummary.gp ? `<button class="wrg-more" data-l="${key}">Voir tous les noms (${distinct})</button>` : ""}
        </div>`;
      } else {
        const unit =
          xSummary.val[1] ||
          (c.agg === "sum" && f.kind === "b"
            ? "personne" + (xSummary.val[0] > 1 ? "s" : "")
            : "");
        const totalNames = xSummary.who.length;
        summaryHtml = `<div class="wrg-detail-summary">
          ${summaryTitleHtml}
          <div class="wrg-big">${xSummary.val[0]}<small>${esc(unit)}</small></div>
          <div class="wrg-names">${
            xSummary.who
              .slice(0, 5)
              .map((gi) => personBtn(gi, multOf(gi, summaryCard)))
              .join("") || '<span class="wrg-none">Personne</span>'
          }${totalNames > 5 ? `<span>+${totalNames - 5}</span>` : ""}</div>
          ${totalNames ? `<button class="wrg-more" data-l="${key}">Voir tous les noms (${totalNames})</button>` : ""}
        </div>`;
      }

      const displayModeSelect = readOnly
        ? ""
        : `<select aria-label="Affichage" data-display-mode="${key}" title="Mode d'affichage">${displayModeOpts}</select>`;

      const labelHtml = readOnly
        ? `<div class="wrg-t">${esc(c.label || f.label)}</div>`
        : `<div class="wrg-t" contenteditable="true" data-label="${key}">${esc(c.label || f.label)}</div>`;

      const toolsHtml = readOnly
        ? ""
        : `
        <div class="wrg-tools">
          <select aria-label="Synthèse" data-agg="${key}">${opts}</select>
          ${displayModeSelect}
          <button data-fit-h="${key}" title="Ajuster la hauteur au contenu (tel qu'affiché en front)" aria-label="Ajuster la hauteur">⇕</button>
          <button data-fit-w="${key}" title="Élargir jusqu'à tout voir sans défiler (hauteur conservée)" aria-label="Ajuster la largeur">⇔</button>
          <button data-auto="${key}" title="Hauteur automatique permanente (suit le contenu quand les données changent)" aria-label="Hauteur automatique" aria-pressed="${!!c.auto_h}">auto</button>
          <button data-w="${key}" title="Élargir ou réduire" aria-label="Élargir ou réduire">↔</button>
          <button data-x="${key}" title="Retirer" aria-label="Retirer">✕</button>
        </div>`;

      const groupLabel = c.group_label || f.group || "";
      const groupHtml = readOnly
        ? `<div class="wrg-g">${esc(groupLabel)}</div>`
        : `<div class="wrg-g" contenteditable="true" data-group-label="${key}">${esc(groupLabel)}</div>`;

      const grip = readOnly
        ? ""
        : '<span class="wrg-grip wrg-drag" title="Déplacer" aria-label="Déplacer">⠿</span>';

      return `<article class="wrg-card wrg-composite wrg-detail-mode${c.auto_h ? " wrg-autoh" : ""}" data-k="${key}">
        <div class="wrg-top">
          ${grip}
          <div class="wrg-top-main">
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
      if (!ensureGrid()) {
        grid.innerHTML =
          '<div class="wrg-empty">GridStack est introuvable (bibliothèque non chargée).</div>';
        return;
      }
      const rows = filteredRows();
      emptyEl.hidden = state.cards.length > 0;

      syncing = true;
      clearTimeout(syncTimer);
      try {
        // IMPORTANT : on vide avant de recharger, sinon load() ne fait que
        // "mettre à jour" les widgets existants sans régénérer leur contenu.
        gs.removeAll(true, false);
        if (state.cards.length) {
          gs.load(state.cards.map((c) => widgetOf(c, rows, gridColumns)));
          decorate(gs);
          // Les dossiers (parents de sous-grilles) perdent parfois leur
          // glissement lors de la création de la sous-grille : on ré-initialise
          // le glisser/redimensionner de TOUS les items, récursivement.
          if (!readOnly) {
            try {
              gs.enableMove(true);
              gs.enableResize(true);
            } catch (e) {
              /* ignore */
            }
          }
          fitAll();
        }
      } catch (e) {
        console.error("GridStack render failed:", e);
      }
      setTimeout(() => {
        syncing = false;
      }, 80);
    }

    /**
     * Met à jour uniquement le HTML interne d'une carte (sans toucher à la
     * mise en page GridStack).
     */
    function updateCard(key) {
      const card = getCard(key);
      if (!card) return;
      const el = itemEl(key);
      if (!el) {
        render();
        return;
      }
      const content = el.querySelector(":scope > .grid-stack-item-content");
      const old = content && content.querySelector(":scope > .wrg-card");
      if (!old) return;
      const tpl = document.createElement("template");
      tpl.innerHTML = renderCard(card, filteredRows()).trim();
      const fresh = tpl.content.firstElementChild;
      if (!fresh) return;
      // IMPORTANT : on garde l'élément poignée (⠿) d'origine. GridStack lie le
      // glissement aux éléments poignée existants : si on en crée un nouveau,
      // la carte (ou le dossier) ne peut plus être déplacée.
      const oldGrip = old.querySelector(".wrg-grip");
      const newGrip = fresh.querySelector(".wrg-grip");
      if (oldGrip && newGrip) newGrip.replaceWith(oldGrip);
      old.replaceWith(fresh);
      refit(el, card);
    }

    function buildValueFilter(card, field, src) {
      if (!field || !field.options) return "";
      const kind = field.kind;
      const currentFilter = card.value_filter || [];
      const options = field.options;
      const filterKey = src || card.field;
      const dataSrc = src ? ` data-src="${esc(src)}"` : "";

      if (kind === "b") {
        const opts = [
          { val: "1", label: "Oui (1)" },
          { val: "0", label: "Non (0)" },
          { val: "", label: "Vide/Non répondu" },
        ];
        return `<div class="wrg-value-filter wrg-vf-boolean" data-filter="${esc(filterKey)}"${dataSrc}>
          <label class="wrg-vf-label">Filtrer valeurs :</label>
          <div class="wrg-vf-options">
            ${opts.map((o) => `<label><input type="checkbox" value="${o.val}" ${currentFilter.includes(o.val) ? "checked" : ""} data-vf-val="${o.val}"> ${esc(o.label)}</label>`).join("")}
          </div>
        </div>`;
      }
      if (kind === "r") {
        const optsHtml = Object.entries(options)
          .map(
            ([v, l]) =>
              `<option value="${esc(v)}" ${currentFilter.includes(v) ? "selected" : ""}>${esc(l)}</option>`,
          )
          .join("");
        return `<div class="wrg-value-filter wrg-vf-choice" data-filter="${esc(filterKey)}"${dataSrc}>
          <label class="wrg-vf-label">Valeurs à inclure :</label>
          <select multiple data-vf-select="${esc(filterKey)}" title="Valeurs à inclure (Ctrl+clic pour plusieurs)">
            ${optsHtml}
          </select>
        </div>`;
      }
      return "";
    }

    // ------------------------------------------------------------------
    // Dialogs
    // ------------------------------------------------------------------

    function openDialog(title, bodyHtml) {
      dialog.innerHTML = `<div class="wrg-dh"><h2>${title}</h2><button data-close aria-label="Fermer">✕</button></div><div class="wrg-db">${bodyHtml}</div>`;
      if (!dialog.open) dialog.showModal();
    }

    function showCardList(key, srcIdx) {
      const c = getCard(key);
      if (!c || isFolder(c)) return;
      const rows = filteredRows();
      let card = c;
      if (srcIdx !== undefined && srcIdx !== null && srcIdx !== "") {
        const src = c.source_fields ? c.source_fields[+srcIdx] : null;
        if (src) {
          const valueFilters = c.value_filters || {};
          card = {
            ...c,
            field: src,
            source_fields: [src],
            value_filter: valueFilters[src] || [],
          };
        }
      }
      const x = calc(card, rows);
      const f = x.field || { label: card.field, group: "" };
      lastList = { type: "card", index: String(key), srcIdx };
      const sub = `${esc(f.group || "")}${state.filter && state.filterLabel ? " · " + esc(state.filterLabel) : ""} · cliquez un nom pour voir ses choix`;
      let body;
      if (x.rep) {
        const optLabel = (k) =>
          f.options && f.options[k] !== undefined
            ? String(f.options[k]).split("--")[0].trim()
            : k === "—"
              ? "Non renseigné"
              : k;
        body = Object.entries(x.gp)
          .map(
            ([k, gis]) =>
              `<h3>${esc(optLabel(k))} (${gis.length})</h3><div class="wrg-names">${gis.map((gi) => personBtn(gi, 0)).join("")}</div>`,
          )
          .join("");
      } else {
        body = `<div class="wrg-names">${x.who.map((gi) => personBtn(gi, multOf(gi, card))).join("")}</div>`;
      }
      openDialog(
        esc(card.label || c.label || f.label),
        `<p class="wrg-g">${sub}</p>` + body,
      );
    }

    function showAllPersons() {
      const rows = filteredRows();
      lastList = { type: "all" };
      openDialog(
        `Par personne (${rows.length})`,
        `<p class="wrg-g">Cliquez un nom pour voir tous ses choix</p><div class="wrg-names" style="margin-top:10px">${rows.map((r) => personBtn(r._i, 0)).join("")}</div>`,
      );
    }

    async function showPerson(gi) {
      const base = cfg.dataUrl.replace(/\/data$/, "");
      try {
        const res = await fetch(
          `${base}/person/${gi}?filter=${encodeURIComponent(state.filter)}`,
        );
        const p = await res.json();
        if (!res.ok) throw new Error(p.error || res.statusText);
        const groups = Object.entries(p.groups || {})
          .map(
            ([g, items]) =>
              `<h3>${esc(g)}</h3><ul>${items.map((t) => `<li>${esc(t)}</li>`).join("")}</ul>`,
          )
          .join("");
        const contacts =
          p.contacts && (p.contacts.phone || p.contacts.email)
            ? `<p class="wrg-g">${esc([p.contacts.phone, p.contacts.email].filter(Boolean).join(" · "))}</p>`
            : "";
        const back = lastList
          ? '<button class="wrg-more" data-back>← Retour à la liste</button>'
          : "";
        openDialog(
          esc(p.name),
          contacts +
            (groups || '<p class="wrg-g">Aucun choix coché.</p>') +
            back,
        );
      } catch (err) {
        openDialog(
          "Erreur",
          `<p class="wrg-g">Détail indisponible (${esc(err.message)}).</p>`,
        );
      }
    }

    // ------------------------------------------------------------------
    // Événements
    // ------------------------------------------------------------------

    if (search) search.addEventListener("input", () => buildTree(search.value));

    if (filters) {
      filters.addEventListener("click", (e) => {
        if (e.target.dataset.filter !== undefined) {
          state.filter = e.target.dataset.filter;
          state.filterLabel = e.target.dataset.label || "";
          buildFilter();
          clearTimeout(syncTimer);
          syncTreeFromGrid();
          render();
        } else if (e.target.dataset.all !== undefined) {
          showAllPersons();
        }
      });
    }

    // Retrouve la carte propriétaire d'un contrôle (via data-k de l'article).
    const cardOfControl = (el) => {
      const art = el.closest(".wrg-card");
      return art ? getCard(art.dataset.k) : null;
    };

    function cycleWidth(key) {
      const el = itemEl(key);
      const n = el && el.gridstackNode;
      if (!n || !n.grid) return;
      const cols = n.grid.getColumn();
      const steps = [1 / 3, 1 / 2, 2 / 3, 1].map((fr) =>
        Math.max(1, Math.round(cols * fr)),
      );
      const next = steps.find((s) => s > n.w) ?? steps[0];
      n.grid.update(el, { w: next });
      refit(el, getCard(key));
    }

    function toggleAutoHeight(key) {
      const card = getCard(key);
      const el = itemEl(key);
      const n = el && el.gridstackNode;
      if (!card || isFolder(card) || !n) return;
      card.auto_h = !card.auto_h;
      n.sizeToContent = !!card.auto_h;
      updateCard(key);
      if (card.auto_h) refit(el, card);
      markDirty();
    }

    function toggleFolder(key) {
      const folder = getCard(key);
      const el = itemEl(key);
      const n = el && el.gridstackNode;
      if (!folder || !isFolder(folder) || !n || !n.grid) return;
      if (!folder.collapsed) {
        // On mémorise la hauteur dépliée avant de replier.
        folder.h_open = n.h > 1 ? n.h : folder.h_open || FOLDER_DEFAULT_H;
        folder.collapsed = true;
        el.classList.add("wrg-folder-collapsed");
        updateCard(key);
        n.grid.update(el, { h: 1 });
      } else {
        folder.collapsed = false;
        folder.h = folder.h_open > 1 ? folder.h_open : FOLDER_DEFAULT_H;
        el.classList.remove("wrg-folder-collapsed");
        updateCard(key);
        n.grid.update(el, { h: folder.h });
      }
      markDirty();
    }

    // ---- Ajustement de taille (mesuré tel qu'en FRONT) ----------------
    // Le build contient des sélecteurs, filtres et boutons absents du front :
    // on clone la carte, on retire ces éléments et on la mesure hors écran.
    function measureFront(art, widthPx) {
      const host = document.createElement("div");
      host.className = "wrg-measure";
      host.style.width = `${widthPx}px`;
      const clone = art.cloneNode(true);
      clone
        .querySelectorAll(".wrg-tools,.wrg-value-filter,.wrg-grip")
        .forEach((e) => e.remove());
      clone
        .querySelectorAll("[contenteditable]")
        .forEach((e) => e.removeAttribute("contenteditable"));
      clone.classList.add("wrg-autoh");
      clone.style.cssText = `height:auto;overflow:visible;width:${widthPx}px`;
      host.appendChild(clone);
      root.appendChild(host);
      const h = clone.offsetHeight;
      host.remove();
      return h;
    }
    const marginOf = (g) => parseInt(g && g.opts && g.opts.margin, 10) || gap;
    const cardParts = (key) => {
      const card = getCard(key);
      const el = itemEl(key);
      const n = el && el.gridstackNode;
      if (!card || !n || !n.grid) return null;
      const art = el.querySelector(
        ":scope > .grid-stack-item-content > .wrg-card",
      );
      return art ? { card, el, n, art } : null;
    };

    // Hauteur : juste ce qu'il faut pour afficher tout le contenu.
    function fitHeight(key) {
      const p = cardParts(key);
      if (!p) return;
      const m = marginOf(p.n.grid);
      let needPx;
      if (isFolder(p.card)) {
        if (p.card.collapsed) return;
        // En-tête + sous-grille (toutes les cartes enfants).
        needPx = p.el.querySelector(
          ":scope > .grid-stack-item-content",
        ).scrollHeight;
      } else {
        needPx = measureFront(p.art, p.art.offsetWidth);
      }
      const rows = Math.max(1, Math.ceil((needPx + 2 * m + 2) / CELL_H));
      p.n.grid.update(p.el, { h: rows });
    }

    // Largeur : élargit (jamais ne réduit) jusqu'à ce que tout tienne dans la
    // hauteur actuelle de la carte.
    function fitWidth(key) {
      const p = cardParts(key);
      if (!p || isFolder(p.card)) return;
      const g = p.n.grid;
      const cols = g.getColumn();
      const m = marginOf(g);
      const colPx = g.el.clientWidth / cols;
      const availPx = p.n.h * CELL_H - 2 * m;
      let best = cols;
      for (let w = Math.max(1, p.n.w); w <= cols; w++) {
        if (measureFront(p.art, Math.floor(colPx * w - 2 * m)) <= availPx) {
          best = w;
          break;
        }
      }
      g.update(p.el, { w: best });
    }

    function removeCard(key) {
      const card = getCard(key);
      if (!card) return;
      if (
        isFolder(card) &&
        (card.children || []).length &&
        !window.confirm(
          `Supprimer ce dossier et ses ${card.children.length} carte(s) ?`,
        )
      ) {
        return;
      }
      commit(() => {
        const loc = findLoc(card);
        if (loc) loc.list.splice(loc.index, 1);
      });
    }

    if (grid) {
      grid.addEventListener("change", (e) => {
        const t = e.target;
        if (!t || !t.dataset) return; // CustomEvent GridStack remonté du DOM.
        if (t.dataset.agg !== undefined) {
          const card = getCard(t.dataset.agg);
          if (card) {
            card.agg = t.value;
            updateCard(t.dataset.agg);
            markDirty();
          }
        } else if (t.dataset.mode !== undefined) {
          const card = getCard(t.dataset.mode);
          if (card) {
            card.mode = t.value;
            ensureValidAgg(card);
            updateCard(t.dataset.mode);
            markDirty();
          }
        } else if (t.dataset.displayMode !== undefined) {
          const card = getCard(t.dataset.displayMode);
          if (card) {
            card.display_mode = t.value;
            if (t.value !== "detail") delete card.value_filters;
            updateCard(t.dataset.displayMode);
            markDirty();
          }
        } else if (
          t.dataset.vfVal !== undefined ||
          t.dataset.vfSelect !== undefined
        ) {
          const filterContainer = t.closest(".wrg-value-filter");
          if (!filterContainer) return;
          let src = filterContainer.dataset.src;
          if (!src) {
            const detailSection = filterContainer.closest(
              ".wrg-detail-section",
            );
            src = detailSection ? detailSection.dataset.src : null;
          }
          const card = cardOfControl(filterContainer);
          if (!card) return;
          if (t.dataset.vfVal !== undefined) {
            const val = t.dataset.vfVal;
            if (src) {
              card.value_filters = card.value_filters || {};
              card.value_filters[src] = t.checked
                ? [...new Set([...(card.value_filters[src] || []), val])]
                : (card.value_filters[src] || []).filter((v) => v !== val);
            } else {
              card.value_filter = t.checked
                ? [...new Set([...(card.value_filter || []), val])]
                : (card.value_filter || []).filter((v) => v !== val);
            }
          } else {
            const selected = Array.from(t.selectedOptions).map((o) => o.value);
            if (src) {
              card.value_filters = card.value_filters || {};
              card.value_filters[src] = selected;
            } else {
              card.value_filter = selected;
            }
          }
          updateCard(keyOf(card));
          markDirty();
        }
      });

      grid.addEventListener("click", (e) => {
        const d = e.target.dataset;
        if (!d) return;
        if (d.toggle !== undefined) {
          toggleFolder(d.toggle);
          return;
        }
        if (d.x !== undefined) {
          removeCard(d.x);
          return;
        }
        if (d.w !== undefined) {
          cycleWidth(d.w);
          return;
        }
        if (d.fitH !== undefined) {
          fitHeight(d.fitH);
          return;
        }
        if (d.fitW !== undefined) {
          fitWidth(d.fitW);
          return;
        }
        if (d.auto !== undefined) {
          toggleAutoHeight(d.auto);
        }
      });

      // Édition des libellés au blur (capture).
      grid.addEventListener(
        "blur",
        (e) => {
          const el =
            e.target.closest &&
            e.target.closest(
              "[data-label],[data-group-label],[data-source-label],[data-section-label],[data-summary-label],[data-folder-label]",
            );
          if (!el) return;
          const text = el.textContent.trim();
          const d = el.dataset;
          let key = null;
          if (d.folderLabel !== undefined) {
            key = d.folderLabel;
            const c = getCard(key);
            if (c && isFolder(c)) c.label = text || "Dossier";
            else return;
          } else if (d.label !== undefined) {
            key = d.label;
            const c = getCard(key);
            if (c) c.label = text;
            else return;
          } else if (d.groupLabel !== undefined) {
            key = d.groupLabel;
            const c = getCard(key);
            if (c) c.group_label = text;
            else return;
          } else if (d.sourceLabel !== undefined) {
            const [k, ...rest] = d.sourceLabel.split(":");
            key = k;
            const c = getCard(key);
            if (!c) return;
            c.source_labels = c.source_labels || {};
            c.source_labels[rest.join(":")] = text;
          } else if (d.sectionLabel !== undefined) {
            const [k, ...rest] = d.sectionLabel.split(":");
            key = k;
            const c = getCard(key);
            if (!c) return;
            c.section_labels = c.section_labels || {};
            c.section_labels[rest.join(":")] = text;
          } else if (d.summaryLabel !== undefined) {
            key = d.summaryLabel;
            const c = getCard(key);
            if (c) c.summary_label = text;
            else return;
          }
          if (key !== null) {
            updateCard(key);
            markDirty();
          }
        },
        true,
      );
    }

    // Clics délégués : noms, listes complètes, retour, fermeture.
    document.addEventListener("click", (e) => {
      const t = e.target.closest("[data-p],[data-l],[data-close],[data-back]");
      if (!t || !root.contains(t)) return;
      if (t.dataset.p !== undefined) showPerson(+t.dataset.p);
      else if (t.dataset.l !== undefined)
        showCardList(t.dataset.l, t.dataset.src);
      else if (t.dataset.back !== undefined) {
        if (lastList && lastList.type === "card")
          showCardList(lastList.index, lastList.srcIdx);
        else showAllPersons();
      } else dialog.close();
    });
    dialog.addEventListener("click", (e) => {
      if (e.target === dialog) dialog.close();
    });

    // ------------------------------------------------------------------
    // Palette -> grille (drag & drop natif HTML5, uniquement depuis les chips).
    // Le déplacement des cartes existantes est entièrement géré par GridStack.
    // ------------------------------------------------------------------
    if (!readOnly) {
      let dragged = null; // { f: fieldKey }
      let hoverKey = null;

      const clearHover = () =>
        grid
          .querySelectorAll(".wrg-drop-hover,.wrg-drop-hover-folder")
          .forEach((el) =>
            el.classList.remove("wrg-drop-hover", "wrg-drop-hover-folder"),
          );

      document.addEventListener("dragstart", (e) => {
        const chip = e.target.closest && e.target.closest(".wrg-chip");
        dragged = chip ? { f: chip.dataset.field } : null;
        if (dragged) {
          e.dataTransfer.setData("text/plain", "x");
          e.dataTransfer.effectAllowed = "copy";
        }
      });
      document.addEventListener("dragend", () => {
        dragged = null;
        hoverKey = null;
        clearHover();
      });

      // Hit-test géométrique : la plus petite carte contenant le pointeur
      // (une carte enfant l'emporte sur son dossier).
      function hitTestDrop(x, y) {
        if (!Number.isFinite(x) || !Number.isFinite(y)) return null;
        let best = null;
        grid.querySelectorAll(".grid-stack-item").forEach((el) => {
          const n = el.gridstackNode;
          const card = n && getCard(n.id);
          if (!card) return;
          const r = el.getBoundingClientRect();
          if (x >= r.left && x <= r.right && y >= r.top && y <= r.bottom) {
            const area = Math.max(1, r.width) * Math.max(1, r.height);
            if (!best || area < best.area) best = { card, el, area };
          }
        });
        if (!best) return null;
        let kind = "card";
        if (isFolder(best.card)) kind = "folder";
        else if (findLoc(best.card) && findLoc(best.card).parent)
          kind = "child";
        return { card: best.card, el: best.el, kind };
      }

      grid.addEventListener("dragover", (e) => {
        if (!dragged) return;
        e.preventDefault();
        e.dataTransfer.dropEffect = "copy";
        const hit = hitTestDrop(e.clientX, e.clientY);
        const k = hit ? `${hit.kind}:${keyOf(hit.card)}` : "none";
        if (k !== hoverKey) {
          clearHover();
          if (hit)
            hit.el.classList.add(
              hit.kind === "folder"
                ? "wrg-drop-hover-folder"
                : "wrg-drop-hover",
            );
          hoverKey = k;
        }
      });

      grid.addEventListener("dragleave", (e) => {
        if (!e.currentTarget.contains(e.relatedTarget)) {
          clearHover();
          hoverKey = null;
        }
      });

      grid.addEventListener("drop", (e) => {
        if (!dragged) return;
        e.preventDefault();
        const hit = hitTestDrop(e.clientX, e.clientY);
        clearHover();
        hoverKey = null;

        const field = allFields().find((f) => f.key === dragged.f);
        dragged = null;
        if (!field) return;

        const newDataCard = (cols) => {
          const agg = Object.keys(state.aggs[field.kind] || {})[0] || "sum";
          return {
            field: field.key,
            agg,
            w: defaultW(field.kind, cols),
            h: DEFAULT_H,
          };
        };

        if (hit && (hit.kind === "card" || hit.kind === "child")) {
          // Dépôt SUR une carte -> regroupement (fusion dans source_fields).
          const card = hit.card;
          const sources = card.source_fields
            ? [...card.source_fields]
            : [card.field];
          if (!sources.includes(field.key)) {
            sources.push(field.key);
            card.source_fields = sources;
            card.mode = card.mode || "union";
            ensureValidAgg(card);
            if (!card.label || card.label === field.label) {
              card.label =
                sources.length === 2 ? `${field.label} + ...` : card.label;
            }
            updateCard(keyOf(card));
            markDirty();
          }
        } else if (hit && hit.kind === "folder") {
          // Dépôt sur un dossier -> nouvelle carte enfant (placée par GridStack).
          const folder = hit.card;
          commit(() => {
            folder.children = folder.children || [];
            folder.children.push(newDataCard(SUB_COLUMNS));
            folder.collapsed = false;
          });
        } else {
          // Espace vide -> nouvelle carte en haut niveau.
          commit(() => {
            state.cards.push(newDataCard(gridColumns));
          });
        }
      });
    }

    // Export (les deux modes).
    root.querySelectorAll("[data-wrg-export]").forEach((a) => {
      a.addEventListener("click", (e) => {
        e.preventDefault();
        const ext = a.dataset.wrgExport;
        window.location = `${cfg.dataUrl.replace(/\/data$/, "")}/export.${ext}?filter=${encodeURIComponent(state.filter)}`;
      });
    });

    // Boutons "nouveau dossier" et "enregistrer" (édition uniquement).
    if (!readOnly) {
      const addFolderBtn = root.querySelector("[data-wrg-add-folder]");
      if (addFolderBtn) {
        addFolderBtn.addEventListener("click", () => {
          const n = state.cards.filter(isFolder).length + 1;
          commit(() => {
            state.cards.push({
              type: "folder",
              label: `Dossier ${n}`,
              collapsed: false,
              w: Math.round(gridColumns / 2),
              h: FOLDER_DEFAULT_H,
              children: [],
            });
          });
        });
      }

      const saveBtn = root.querySelector("[data-wrg-save]");
      const saveState = root.querySelector("[data-wrg-save-state]");
      if (saveBtn) {
        saveBtn.addEventListener("click", async () => {
          if (saveState) saveState.textContent = "Enregistrement…";
          try {
            // GridStack = source de vérité pour x/y/w/h et la structure.
            clearTimeout(syncTimer);
            syncTreeFromGrid();
            const csrf =
              (drupalSettings.webformReportGrid &&
                drupalSettings.webformReportGrid.csrfToken) ||
              "";
            const res = await fetch(
              `${cfg.dataUrl.replace(/\/data$/, "")}/save`,
              {
                method: "POST",
                headers: {
                  "Content-Type": "application/json",
                  "X-CSRF-Token": csrf,
                },
                body: JSON.stringify({ cards: state.cards }),
              },
            );
            const text = await res.text();
            let payload;
            try {
              payload = JSON.parse(text);
            } catch (err) {
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
      }
      window.addEventListener("beforeunload", (e) => {
        const s = saveStateEl();
        if (s && s.dataset.dirty) e.preventDefault();
      });
    }

    // Changement de webform.
    const switchWebform = root.querySelector("[data-wrg-switch-webform]");
    if (switchWebform) {
      switchWebform.addEventListener("change", () => {
        const w = switchWebform.value;
        window.location = w
          ? `/webform-report/switch?webform=${encodeURIComponent(w)}`
          : "/webform-report/switch";
      });
    }
  }
})(Drupal, drupalSettings, once);
