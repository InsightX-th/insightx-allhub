/* InsightX Offload — File Manager page. Every change goes through the
   isxs_fm AJAX endpoint; the server decides what is allowed. */
(function ($) {
  "use strict";

  const cfg = window.isxsFm;
  if (!cfg) return;
  const t = cfg.i18n;
  const can = (a) => cfg.can.includes(a);

  const state = {
    path: cfg.start,
    listing: null, // last folder listing
    rows: [], // what the table shows (listing or search results)
    search: null, // {query, path, truncated} while showing results
    selected: new Set(),
    anchor: null, // last clicked row, for Shift+click
    sort: { key: "name", dir: 1 },
  };

  /* ---------- helpers ---------- */

  const esc = (s) =>
    String(s).replace(/[&<>"']/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" })[c]);
  const sel = (p) => '[data-path="' + CSS.escape(p) + '"]';

  const sizeText = (b) => {
    if (b < 1024) return b + " B";
    const u = ["KB", "MB", "GB", "TB"];
    let i = -1;
    do { b /= 1024; i++; } while (b >= 1024 && i < u.length - 1);
    return b.toFixed(b < 10 ? 1 : 0) + " " + u[i];
  };
  const dateText = (ts) =>
    new Date(ts * 1000).toLocaleString(undefined, { year: "numeric", month: "short", day: "2-digit", hour: "2-digit", minute: "2-digit" });

  const KINDS = {
    pdf: "PDF", doc: "Word", docx: "Word", xls: "Excel", xlsx: "Excel", csv: "CSV", ppt: "PowerPoint", pptx: "PowerPoint",
    txt: "Text", md: "Markdown", json: "JSON", xml: "XML", html: "HTML", htm: "HTML", css: "CSS", js: "JavaScript", php: "PHP source",
    jpg: "JPEG image", jpeg: "JPEG image", png: "PNG image", gif: "GIF image", webp: "WebP image", svg: "SVG image", avif: "AVIF image", ico: "Icon",
    mp4: "MP4 video", mov: "QuickTime video", webm: "WebM video", mp3: "MP3 audio", wav: "WAV audio", m4a: "M4A audio",
    zip: "ZIP archive", rar: "RAR archive", "7z": "7-Zip archive", gz: "GZip archive", tar: "TAR archive",
    woff: "Font", woff2: "Font", ttf: "Font", otf: "Font", log: "Log", sql: "SQL",
  };
  const extOf = (name) => (name.lastIndexOf(".") > 0 ? name.slice(name.lastIndexOf(".") + 1).toLowerCase() : "");
  const kindOf = (row) => {
    if (row.size === undefined) return t.kindFolder;
    const ext = extOf(row.name);
    return KINDS[ext] || (ext ? t.kindFile.replace("%s", ext.toUpperCase()) : t.kindNone);
  };

  const api = (op, data) =>
    new Promise((resolve, reject) => {
      $.post(cfg.ajaxUrl, Object.assign({ action: "isxs_fm", nonce: cfg.nonce, op }, data))
        .done((res) => (res && res.success ? resolve(res.data) : reject((res && res.data) || { message: t.failed })))
        .fail((xhr) => reject((xhr.responseJSON && xhr.responseJSON.data) || { message: t.failed }));
    });

  let toastTimer = null;
  const toast = (text, isError) => {
    const $t = $("#isxs-toast");
    clearTimeout(toastTimer);
    $t.text(text).toggleClass("is-error", !!isError).toggleClass("is-ok", !isError).addClass("is-visible");
    toastTimer = setTimeout(() => $t.removeClass("is-visible"), isError ? 5000 : 3000);
  };
  const withNotes = (text, notes) => (notes && notes.length ? text + " · " + notes.join(" · ") : text);

  /* ---------- modal ---------- */

  const $modal = $("#isxs-fm-modal");
  let modalDone = null;

  // Resolves with the input's value / picked folder / true, or null when cancelled.
  // cancel: null shows only the OK button; wide: a larger dialog (preview).
  const modal = ({ title, html = "", input = null, ok = t.save, cancel = t.cancel, danger = false, wide = false, onOpen }) =>
    new Promise((resolve) => {
      modalDone = resolve;
      $modal.removeData("pick");
      $("#isxs-fm-modal-title").text(title);
      const $body = $modal.find(".isxs-fm-modal-body").html(html);
      if (input !== null) $body.append($('<input type="text" class="isxs-fm-input">').val(input));
      $modal.find('[data-modal="ok"]').text(ok).toggleClass("isxs-btn-primary", !danger).toggleClass("isxs-btn-danger", danger);
      $modal.find('[data-modal="cancel"]').text(cancel || "").prop("hidden", cancel === null);
      $modal.find(".isxs-fm-dialog").toggleClass("is-wide", wide);
      $modal.prop("hidden", false);
      const $in = $body.find(".isxs-fm-input");
      if ($in.length) {
        const v = $in.val();
        const dot = v.lastIndexOf(".");
        $in.trigger("focus")[0].setSelectionRange(0, dot > 0 ? dot : v.length);
      } else {
        $modal.find('[data-modal="ok"]').trigger("focus");
      }
      if (onOpen) onOpen($body);
    });

  const closeModal = (value) => {
    $modal.prop("hidden", true);
    const done = modalDone;
    modalDone = null;
    if (done) done(value);
  };

  $modal.on("click", '[data-modal="cancel"]', () => closeModal(null));
  $modal.on("click", '[data-modal="ok"]', () => {
    const $in = $modal.find(".isxs-fm-input");
    const pick = $modal.data("pick");
    closeModal($in.length ? $in.val().trim() || null : pick !== undefined ? pick : true);
  });
  $modal.on("click", (e) => { if (e.target === $modal[0]) closeModal(null); });
  $modal.on("keydown", (e) => {
    if (e.key === "Escape") closeModal(null);
    if (e.key === "Enter" && $(e.target).is(".isxs-fm-input")) $modal.find('[data-modal="ok"]').trigger("click");
  });

  /* ---------- tree (lazy, one level per click) ---------- */

  const folderSvg = '<svg viewBox="0 0 24 24" class="isxs-fm-ico is-dir" aria-hidden="true"><path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/></svg>';
  const fileSvg = '<svg viewBox="0 0 24 24" class="isxs-fm-ico" aria-hidden="true"><path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5"/></svg>';
  const cloudSvg = '<span class="isxs-fm-cloud" title="' + esc(t.onBucket) + '"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 18H7a5 5 0 1 1 1-9.9A6 6 0 0 1 19.5 10 4 4 0 0 1 18 18z"/></svg></span>';

  // The tree starts at this user's folder ("" = the whole site).
  const rootName = (base) => (base ? base.split("/").pop() : t.root);

  const treeNode = (name, path) =>
    $('<li class="isxs-fm-node">').attr("data-path", path).append(
      $('<button type="button" class="isxs-fm-twist" aria-expanded="false">').text("▸"),
      $('<button type="button" class="isxs-fm-node-name">').html(folderSvg).append($("<span>").text(name))
    );

  /* Listings are cached per folder: going back to a folder, folding and
     unfolding the tree, or revealing a path is instant; the server is
     asked again in the background and only changes are re-drawn. */
  const cache = new Map(); // path => listing
  const inflight = new Map(); // path => pending request
  const fetchList = (path) => {
    if (inflight.has(path)) return inflight.get(path);
    const req = api("list", { path })
      .then((d) => {
        cache.set(d.path, d);
        $(".isxs-fm-tree li" + sel(d.path)).each(function () { fillNode($(this), d.dirs); });
        return d;
      })
      .finally(() => inflight.delete(path));
    inflight.set(path, req);
    return req;
  };
  // Forget folders after a change (they are fetched again when shown).
  const invalidate = (paths) => paths.forEach((p) => cache.delete(p));
  const parentPath = (p) => (p.includes("/") ? p.slice(0, p.lastIndexOf("/")) : "");

  // (Re)draws a node's children, keeping sub-trees already unfolded.
  const fillNode = ($li, dirs) => {
    const $old = $li.children("ul");
    const $ul = $("<ul>").prop("hidden", $old.length ? $old.prop("hidden") : $li.children(".isxs-fm-twist").attr("aria-expanded") !== "true");
    dirs.forEach((dir) => {
      const $keep = $old.children("li" + sel(dir.path));
      $ul.append($keep.length ? $keep : treeNode(dir.name, dir.path));
    });
    $old.remove();
    $li.children(".isxs-fm-twist").toggleClass("is-leaf", !dirs.length);
    $li.append($ul);
  };

  const expand = ($li, open) => {
    const $tw = $li.children(".isxs-fm-twist");
    $tw.attr("aria-expanded", open ? "true" : "false").text(open ? "▾" : "▸");
    const $ul = $li.children("ul");
    $ul.prop("hidden", !open);
    if (!open || $ul.length) return Promise.resolve();
    const path = $li.attr("data-path");
    if (cache.has(path)) { fillNode($li, cache.get(path).dirs); return Promise.resolve(); }
    return fetchList(path).catch(() => {});
  };

  // Opens the tree down to `path`: missing levels are fetched all at once,
  // not one after another.
  const reveal = ($tree, path) => {
    const base = $tree.data("base");
    const rest = path.slice(base.length).replace(/^\//, "");
    const parts = rest ? rest.split("/") : [];
    const levels = [base].concat(parts.map((_, i) => (base ? base + "/" : "") + parts.slice(0, i + 1).join("/")));
    const unfold = levels;
    return Promise.all(unfold.filter((p) => !cache.has(p)).map((p) => fetchList(p).catch(() => null))).then(() => {
      unfold.forEach((p) => {
        const $li = $tree.find("li" + sel(p)).first();
        if ($li.length) expand($li, true);
      });
      markCurrent($tree, path);
    });
  };
  const markCurrent = ($tree, path) => {
    $tree.find(".is-current").removeClass("is-current");
    $tree.find("li" + sel(path)).first().children(".isxs-fm-node-name").addClass("is-current");
  };

  const buildTree = ($tree, base) => {
    $tree.data("base", base).empty().append($("<ul>").append(treeNode(rootName(base), base)));
    $tree.on("click", ".isxs-fm-twist", function () {
      const $li = $(this).closest("li");
      expand($li, $(this).attr("aria-expanded") !== "true");
    });
  };

  /* ---------- table ---------- */

  const $table = $("#isxs-fm-table");
  const COLS = [
    { key: "name", label: t.name },
    { key: "perm", label: t.perm },
    { key: "mtime", label: t.modified },
    { key: "size", label: t.size, num: true },
    { key: "kind", label: t.kind },
  ];

  const isDir = (r) => r.size === undefined;
  const permText = (r) => (r.writable ? t.readWrite : t.readOnlyShort);
  const parentOf = (r) => r.path.slice(0, r.path.length - r.name.length).replace(/\/$/, "");

  const sortRows = (rows) => {
    const { key, dir } = state.sort;
    const val = (r) => (key === "kind" ? kindOf(r) : key === "perm" ? (r.writable ? 1 : 0) : r[key]);
    return rows.slice().sort((a, b) => {
      if (isDir(a) !== isDir(b)) return isDir(a) ? -1 : 1; // folders first, always
      const va = val(a), vb = val(b);
      const c = typeof va === "string" ? va.localeCompare(vb, undefined, { numeric: true, sensitivity: "base" }) : (va || 0) - (vb || 0);
      return c * dir || a.name.localeCompare(b.name, undefined, { numeric: true });
    });
  };

  const renderCrumbs = (path) => {
    const $c = $("#isxs-fm-crumbs").empty();
    const base = cfg.jail;
    const parts = path.slice(base.length).replace(/^\//, "").split("/").filter(Boolean);
    $c.append($('<button type="button">').attr("data-go", base).text(rootName(base)));
    parts.forEach((p, i) => {
      $c.append('<span aria-hidden="true">/</span>');
      $c.append($('<button type="button">').attr("data-go", (base ? base + "/" : "") + parts.slice(0, i + 1).join("/")).text(p));
    });
  };

  const renderTable = () => {
    const rows = sortRows(state.rows);
    const head = COLS.map((c) => {
      const on = state.sort.key === c.key;
      return '<th class="' + (c.num ? "is-num " : "") + (on ? "is-sorted" : "") + '" data-sort="' + c.key + '" aria-sort="' +
        (on ? (state.sort.dir > 0 ? "ascending" : "descending") : "none") + '"><button type="button">' + esc(c.label) +
        '<span class="isxs-fm-arrow">' + (on ? (state.sort.dir > 0 ? "▲" : "▼") : "") + "</span></button></th>";
    }).join("");
    const body = rows.map((r) => {
      const where = state.search ? '<small class="isxs-fm-where">/' + esc(parentOf(r)) + "</small>" : "";
      const on = state.selected.has(r.path);
      return '<tr data-path="' + esc(r.path) + '" class="' + (on ? "is-selected" : "") + '">' +
        '<td class="isxs-fm-check"><input type="checkbox"' + (on ? " checked" : "") + ' aria-label="' + esc(r.name) + '"></td>' +
        '<td class="isxs-fm-name"><div>' + (isDir(r) ? folderSvg : fileSvg) + '<span class="isxs-fm-label"><span>' + esc(r.name) + "</span>" + where + "</span>" + (r.bucket ? cloudSvg : "") + "</div></td>" +
        "<td>" + esc(permText(r)) + "</td>" +
        "<td>" + esc(r.mtime ? dateText(r.mtime) : "—") + "</td>" +
        '<td class="is-num">' + (isDir(r) ? "—" : esc(sizeText(r.size))) + "</td>" +
        "<td>" + esc(kindOf(r)) + "</td></tr>";
    });
    $table.html(
      '<thead><tr><th class="isxs-fm-check"><input type="checkbox" id="isxs-fm-all" aria-label="' + esc(t.menu.all) + '"></th>' + head + "</tr></thead><tbody>" +
      (body.length ? body.join("") : '<tr class="isxs-fm-empty"><td colspan="6">' + esc(state.search ? t.noResults : t.empty) + "</td></tr>") + "</tbody>"
    );
    renderStatus();
  };

  const renderStatus = () => {
    const total = state.rows.reduce((n, r) => n + (r.size || 0), 0);
    const picked = state.rows.filter((r) => state.selected.has(r.path));
    const pickedSize = picked.reduce((n, r) => n + (r.size || 0), 0);
    $("#isxs-fm-status-path").text("/" + state.path);
    $("#isxs-fm-status-count").text(
      picked.length
        ? t.selectedItems.replace("%1$d", picked.length).replace("%2$s", sizeText(pickedSize))
        : t.items.replace("%1$d", state.rows.length).replace("%2$s", sizeText(total))
    );
  };

  let byPath = new Map();
  const setRows = (rows) => { state.rows = rows; byPath = new Map(rows.map((r) => [r.path, r])); };
  const rowOf = (path) => byPath.get(path);

  const syncButtons = () => {
    const picked = [...state.selected].map(rowOf).filter(Boolean);
    const writable = !!(state.listing && state.listing.writable) && !state.search;
    const changeable = picked.length > 0 && picked.every((r) => r.writable);
    $(".isxs-fm-actions [data-fm]").each(function () {
      const op = $(this).attr("data-fm");
      const ok = {
        upload: writable,
        mkfile: writable,
        mkdir: writable,
        rename: picked.length === 1 && changeable,
        move: changeable,
        delete: changeable,
        download: picked.length === 1 && !isDir(picked[0]),
      }[op];
      $(this).prop("disabled", !ok);
    });
    renderStatus();
  };

  const showSelection = () => {
    $table.find("tbody tr[data-path]").each(function () {
      const on = state.selected.has($(this).attr("data-path"));
      $(this).toggleClass("is-selected", on).find(".isxs-fm-check input").prop("checked", on);
    });
    const n = state.selected.size;
    $("#isxs-fm-all").prop("checked", n > 0 && n === state.rows.length).prop("indeterminate", n > 0 && n < state.rows.length);
    syncButtons();
  };

  // Draws a listing. keep: stay on the current selection (a background refresh).
  const render = (d, select, keep) => {
    state.path = d.path;
    state.listing = d;
    setRows(d.dirs.concat(d.files));
    state.search = null;
    if (keep) {
      state.selected = new Set([...state.selected].filter((p) => byPath.has(p)));
    } else {
      state.selected = new Set(select ? [select] : []);
      state.anchor = select || null;
    }
    $("#isxs-fm-results").prop("hidden", true);
    if (!keep) $("#isxs-fm-q").val("");
    renderCrumbs(d.path);
    renderTable();
    $("#isxs-fm-note").text(t.readOnly).prop("hidden", d.writable);
    syncButtons();
    if (select && !keep) {
      const $row = $table.find("tr" + sel(select));
      if ($row.length) $row[0].scrollIntoView({ block: "center" });
    }
  };

  let loadSeq = 0;
  // Shows a folder: from the cache at once when it has been opened before
  // (then refreshed quietly), otherwise after the request, with the current
  // table left in place and a thin loading bar meanwhile.
  const load = (path, select) => {
    const seq = ++loadSeq;
    const $main = $("#isxs-fm-main");
    renderCrumbs(path);
    markCurrent($("#isxs-fm-tree"), path);
    const cached = cache.get(path);
    if (cached) render(cached, select, false);
    const slow = cached ? null : setTimeout(() => $main.addClass("is-loading"), 150);
    reveal($("#isxs-fm-tree"), path);
    return fetchList(path)
      .then((d) => {
        if (seq !== loadSeq) return; // another folder was opened meanwhile
        if (!cached) render(d, select, false);
        else if (JSON.stringify(cached) !== JSON.stringify(d)) render(d, select, true);
      })
      .catch((e) => {
        if (seq !== loadSeq) return;
        toast(e.message, true);
        if (!cached && path !== cfg.jail) load(cfg.jail);
      })
      .finally(() => {
        clearTimeout(slow);
        if (seq === loadSeq) $main.removeClass("is-loading");
      });
  };
  // After a change: forget these folders (and the open one), then show it fresh.
  const reloadAfter = (paths, select) => {
    invalidate([state.path].concat(paths || []));
    return load(state.path, select);
  };

  /* ---------- selection & navigation ---------- */

  $table.on("click", "thead [data-sort]", function () {
    const key = $(this).attr("data-sort");
    state.sort = { key, dir: state.sort.key === key ? -state.sort.dir : 1 };
    renderTable();
    syncButtons();
  });

  // Tick boxes: add or remove one row, like ⌘/Ctrl+click; the header box picks all.
  $table.on("click", "tbody .isxs-fm-check", function (e) {
    e.stopPropagation();
    const path = $(this).closest("tr").attr("data-path");
    state.selected.has(path) ? state.selected.delete(path) : state.selected.add(path);
    state.anchor = path;
    showSelection();
  });
  $table.on("change", "#isxs-fm-all", function () {
    state.selected = this.checked ? new Set(state.rows.map((r) => r.path)) : new Set();
    showSelection();
  });
  $table.on("dblclick", ".isxs-fm-check", (e) => e.stopPropagation());

  $table.on("click", "tbody tr[data-path]", function (e) {
    const path = $(this).attr("data-path");
    if (e.shiftKey && state.anchor) {
      const order = $table.find("tbody tr[data-path]").map(function () { return $(this).attr("data-path"); }).get();
      const [a, b] = [order.indexOf(state.anchor), order.indexOf(path)].sort((x, y) => x - y);
      state.selected = new Set(order.slice(Math.max(a, 0), b + 1));
    } else if (e.metaKey || e.ctrlKey) {
      state.selected.has(path) ? state.selected.delete(path) : state.selected.add(path);
      state.anchor = path;
    } else {
      state.selected = new Set([path]);
      state.anchor = path;
    }
    showSelection();
  });

  // Double-click: open a folder, show a search hit in its folder, preview or download a file.
  $table.on("dblclick", "tbody tr[data-path]", function () { act("open", [$(this).attr("data-path")]); });

  $(document).on("keydown", (e) => {
    if (!$modal.prop("hidden") || $(e.target).is("input, textarea, select")) return;
    if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === "a" && $('[data-panel="files"]').is(":visible")) {
      e.preventDefault();
      state.selected = new Set(state.rows.map((r) => r.path));
      showSelection();
    }
    if (e.key === "Escape" && state.search) load(state.search.path);
  });

  $("#isxs-fm-crumbs").on("click", "[data-go]", function () { load($(this).attr("data-go")); });
  $("#isxs-fm-tree").on("click", ".isxs-fm-node-name", function () { load($(this).closest("li").attr("data-path")); });

  /* ---------- search ---------- */

  $("#isxs-fm-search").on("submit", (e) => {
    e.preventDefault();
    const q = $("#isxs-fm-q").val().trim();
    const base = state.search ? state.search.path : state.path;
    if (!q) return load(base);
    $table.html('<tbody><tr class="isxs-fm-empty"><td>' + esc(t.searching) + "</td></tr></tbody>");
    api("search", { path: base, q })
      .then((d) => {
        state.search = { query: d.query, path: d.path, truncated: d.truncated };
        setRows(d.items);
        state.selected.clear();
        state.anchor = null;
        renderTable();
        syncButtons();
        $("#isxs-fm-results").prop("hidden", false).children("span").text(
          t.results.replace("%1$s", d.query).replace("%2$s", d.path).replace("%3$d", d.items.length) + (d.truncated ? " " + t.resultsCut : "")
        );
      })
      .catch((err) => { toast(err.message, true); load(base); });
  });
  // The clear (×) button of a search field.
  $("#isxs-fm-q").on("search", function () { if (!this.value && state.search) load(state.search.path); });
  $("#isxs-fm-results").on("click", ".isxs-fm-results-clear", () => load(state.search ? state.search.path : state.path));

  /* ---------- upload ---------- */

  const $queue = $("#isxs-fm-queue");

  const sendFile = (file, dir, replace, $row) =>
    new Promise((resolve) => {
      const fd = new FormData();
      fd.append("action", "isxs_fm");
      fd.append("nonce", cfg.nonce);
      fd.append("op", "upload");
      fd.append("path", dir);
      fd.append("replace", replace ? "1" : "");
      fd.append("file", file, file.name);
      const xhr = new XMLHttpRequest();
      xhr.open("POST", cfg.ajaxUrl);
      xhr.upload.onprogress = (e) => {
        if (e.lengthComputable) $row.find(".isxs-fm-bar span").css("width", Math.round((e.loaded / e.total) * 100) + "%");
      };
      xhr.onload = () => {
        let res = null;
        try { res = JSON.parse(xhr.responseText); } catch (err) { /* not JSON */ }
        resolve(res && res.success ? { ok: true, data: res.data } : { ok: false, err: (res && res.data) || { message: t.failed } });
      };
      xhr.onerror = () => resolve({ ok: false, err: { message: t.failed } });
      xhr.send(fd);
    });

  const queueRow = (file) => {
    const $row = $('<li><span class="isxs-fm-qname"></span><span class="isxs-fm-bar"><span></span></span><span class="isxs-fm-qstate"></span></li>');
    $row.find(".isxs-fm-qname").text(file.name);
    $queue.prop("hidden", false).append($row);
    return $row;
  };
  const finishRow = ($row, ok, text) => {
    $row.addClass(ok ? "is-ok" : "is-error").find(".isxs-fm-qstate").text(text);
    $row.find(".isxs-fm-bar span").css("width", "100%");
  };

  const uploadable = () => can("upload") && state.listing && state.listing.writable && !state.search;

  const upload = async (files) => {
    if (!files.length || !uploadable()) return;
    const dir = state.path;
    const exists = [];
    let notes = [];
    let done = 0;
    const ok = ($row, r) => { done++; notes = notes.concat(r.data.notes || []); finishRow($row, true, (r.data.notes || []).join(" · ") || t.uploaded); };
    for (const file of files) {
      const $row = queueRow(file);
      if (cfg.maxUpload && file.size > cfg.maxUpload) { finishRow($row, false, t.tooBig.replace("%s", sizeText(cfg.maxUpload))); continue; }
      const r = await sendFile(file, dir, false, $row);
      if (r.ok) ok($row, r);
      else if (r.err.code === "isxfm_exists") exists.push({ file, $row });
      else finishRow($row, false, r.err.message);
    }
    if (exists.length) {
      const list = '<ul class="isxs-fm-list">' + exists.map((x) => "<li>" + esc(x.file.name) + "</li>").join("") + "</ul>";
      const yes = await modal({ title: t.replaceAsk, html: list, ok: t.replace, cancel: t.skip });
      for (const x of exists) {
        if (!yes) { finishRow(x.$row, false, t.skip); continue; }
        const r = await sendFile(x.file, dir, true, x.$row);
        if (r.ok) ok(x.$row, r);
        else finishRow(x.$row, false, r.err.message);
      }
    }
    if (done) toast(withNotes(t.uploaded + " " + done, [...new Set(notes)]), false);
    setTimeout(() => $queue.find("li.is-ok").fadeOut(300, function () { $(this).remove(); if (!$queue.children().length) $queue.prop("hidden", true); }), 4000);
    invalidate([dir]);
    if (state.path === dir && !state.search) load(dir);
  };

  $("#isxs-fm-input").on("change", function () { upload([...this.files]); this.value = ""; });

  const $main = $("#isxs-fm-main");
  let dragDepth = 0;
  $main.on("dragenter", (e) => {
    if (!uploadable() || !e.originalEvent.dataTransfer.types.includes("Files")) return;
    e.preventDefault();
    dragDepth++;
    $("#isxs-fm-drop").prop("hidden", false);
  });
  $main.on("dragover", (e) => e.preventDefault());
  $main.on("dragleave", () => { if (--dragDepth <= 0) { dragDepth = 0; $("#isxs-fm-drop").prop("hidden", true); } });
  $main.on("drop", (e) => {
    e.preventDefault();
    dragDepth = 0;
    $("#isxs-fm-drop").prop("hidden", true);
    upload([...e.originalEvent.dataTransfer.files]);
  });

  /* ---------- toolbar ---------- */

  const download = (path) => {
    window.location.href = cfg.downloadUrl + "&_wpnonce=" + encodeURIComponent(cfg.nonce) + "&path=" + encodeURIComponent(path);
  };

  const runEach = async (paths, op, extra, okText) => {
    let notes = [];
    let done = 0;
    for (const path of paths) {
      try {
        const d = await api(op, Object.assign({ path }, extra));
        done++;
        notes = notes.concat((d && d.notes) || []);
      } catch (e) {
        toast(e.message, true);
        break;
      }
    }
    if (done === paths.length) toast(withNotes(okText, [...new Set(notes)]), false);
    // The open folder, where things went (to), and where they came from.
    reloadAfter([extra.to].concat(paths.map(parentPath)).filter((p) => typeof p === "string"));
  };

  // A folder picked from a tree in the modal; resolves "" for the root.
  const pickFolder = (title, okText, base, start) =>
    modal({
      title,
      html: '<div class="isxs-fm-tree isxs-fm-pick"></div><p class="isxs-fm-picked"></p>',
      ok: okText,
      onOpen: ($body) => {
        const $tree = $body.find(".isxs-fm-pick");
        buildTree($tree, base);
        const pick = (p) => {
          $modal.data("pick", p);
          $body.find(".isxs-fm-picked").text("/" + p);
          $tree.find(".is-current").removeClass("is-current");
          $tree.find("li" + sel(p)).first().children(".isxs-fm-node-name").addClass("is-current");
        };
        $tree.on("click", ".isxs-fm-node-name", function () { pick($(this).closest("li").attr("data-path")); });
        reveal($tree, start).then(() => pick(start));
      },
    });

  // Buttons for actions this user may not take are not shown at all.
  $(".isxs-fm-actions [data-fm]").each(function () {
    if (!can($(this).attr("data-can"))) $(this).remove();
  });

  const nameOf = (p) => p.split("/").pop();
  const listHtml = (paths) => '<ul class="isxs-fm-list">' + paths.map((p) => "<li>" + esc(nameOf(p)) + "</li>").join("") + "</ul>";
  const PREVIEW = { image: ["jpg", "jpeg", "png", "gif", "webp", "avif"], frame: ["pdf", "txt", "csv", "log"], video: ["mp4", "webm"], audio: ["mp3", "wav"] };
  const previewKind = (name) => Object.keys(PREVIEW).find((k) => PREVIEW[k].includes(extOf(name)));
  const urlOf = (base, path) => base + path.split("/").map(encodeURIComponent).join("/");
  // A file on the bucket is linked there (the CDN domain); anything else on this server.
  const publicUrl = (row) => urlOf(row.bucket && cfg.bucketUrl ? cfg.bucketUrl : cfg.siteUrl, row.path);
  const fileUrl = (path, inline) =>
    cfg.downloadUrl + "&_wpnonce=" + encodeURIComponent(cfg.nonce) + "&path=" + encodeURIComponent(path) + (inline ? "&inline=1" : "");

  // Copy / Cut keep paths here until Paste.
  let clip = null; // {mode: "copy"|"cut", paths: []}

  const preview = (row) => {
    const kind = previewKind(row.name);
    if (!kind) return toast(t.noPreview, true);
    const url = esc(fileUrl(row.path, true));
    const html = {
      image: '<img class="isxs-fm-preview" src="' + url + '" alt="">',
      frame: '<iframe class="isxs-fm-preview is-frame" src="' + url + '" title="' + esc(row.name) + '"></iframe>',
      video: '<video class="isxs-fm-preview" src="' + url + '" controls></video>',
      audio: '<audio src="' + url + '" controls></audio>',
    }[kind];
    modal({ title: row.name, html, ok: t.close, cancel: null, wide: true });
  };

  const info = (row) => {
    const line = (k, v) => "<tr><th>" + esc(k) + "</th><td>" + v + "</td></tr>";
    const link = (u) => '<a href="' + esc(u) + '" target="_blank" rel="noopener">' + esc(u) + "</a>";
    modal({
      title: row.name,
      html: '<table class="isxs-fm-info">' +
        line(t.kind, esc(kindOf(row))) +
        line(t.infoPath, "<code>/" + esc(row.path) + "</code>") +
        (isDir(row) ? "" : line(t.size, esc(sizeText(row.size)) + " (" + row.size.toLocaleString() + " B)")) +
        (row.mtime ? line(t.modified, esc(dateText(row.mtime))) : "") +
        line(t.perm, esc(permText(row))) +
        (isDir(row) ? "" : line(t.infoBucket, esc(row.bucket ? t.yes : t.no))) +
        (row.bucket && cfg.bucketUrl ? line(t.infoUrl, link(urlOf(cfg.bucketUrl, row.path))) : "") +
        (row.path ? line(t.infoSiteUrl, link(urlOf(cfg.siteUrl, row.path))) : "") +
        "</table>" +
        (isDir(row) || !row.path ? "" : '<div class="isxs-fm-check"><button type="button" class="isxs-btn isxs-btn-ghost isxs-fm-check-btn">' + esc(t.checkBucket) + '</button><p class="isxs-fm-check-out"></p></div>'),
      ok: t.close,
      cancel: null,
      onOpen: ($body) => {
        $body.on("click", ".isxs-fm-check-btn", function () {
          const $out = $body.find(".isxs-fm-check-out").removeClass("is-ok is-error").text(t.checking);
          $(this).prop("disabled", true);
          api("check", { path: row.path })
            .then((d) => {
              if (d.exists) {
                $out.addClass("is-ok").text(t.foundOn.replace("%1$s", d.destination).replace("%2$s", sizeText(d.size)) + (d.healed ? " · " + t.healed : "")).append(
                  " · ", $('<a target="_blank" rel="noopener">').attr("href", d.url).text(t.openOnBucket)
                );
                // Fixed a missing record: refresh so the cloud icon shows.
                if (d.healed) load(state.search ? state.search.path : state.path, row.path);
              } else {
                $out.addClass("is-error").text(t.notFoundOn.replace("%s", d.destination));
              }
              $out.append($("<code>").text(d.key));
            })
            .catch((e) => $out.addClass("is-error").text(e.message))
            .finally(() => $(this).prop("disabled", false));
        });
      },
    });
  };

  // The folder a row stands for when it is the target (a folder row), else the open folder.
  const currentFolderRow = () => ({ name: rootName(state.path) || t.root, path: state.path, mtime: 0, writable: !!(state.listing && state.listing.writable) });

  const paste = async (target) => {
    if (!clip) return;
    const op = clip.mode === "cut" ? "move" : "copy";
    const paths = clip.paths;
    if (clip.mode === "cut") clip = null;
    await runEach(paths, op, { to: target }, op === "move" ? t.moved : t.copied);
  };

  // Every action, from the toolbar, the right-click menu or the keyboard.
  const act = async (op, picked, target) => {
    const rows = picked.map(rowOf).filter(Boolean);
    const one = rows[0];
    switch (op) {
      case "open":
        if (!one) return;
        if (isDir(one)) return load(one.path);
        if (state.search) return load(parentOf(one), one.path);
        return previewKind(one.name) ? preview(one) : can("download") && download(one.path);
      case "openTab":
        return window.open(isDir(one) ? cfg.pageUrl + "&path=" + encodeURIComponent(one.path) : publicUrl(one), "_blank", "noopener");
      case "preview": return preview(one);
      case "compress":
        toast(t.compressing, false);
        return api("compress", { path: one.path })
          .then((d) => {
            const text = d.replaced ? t.shrunk.replace("%1$s", sizeText(d.before)).replace("%2$s", sizeText(d.after)) : t.notShrunk;
            toast(withNotes(text, d.uploaded ? [t.bucketUpdated] : []), false);
            reloadAfter([], one.path);
          })
          .catch((e) => toast(e.message, true));
      case "info": return info(one || currentFolderRow());
      case "download": return download(picked[0]);
      case "upload": return $("#isxs-fm-input").trigger("click");
      case "all":
        state.selected = new Set(state.rows.map((r) => r.path));
        return showSelection();
      case "invert":
        state.selected = new Set(state.rows.map((r) => r.path).filter((p) => !state.selected.has(p)));
        return showSelection();
      case "copy":
      case "cut":
        clip = { mode: op, paths: picked };
        return toast((op === "copy" ? t.clipCopy : t.clipCut).replace("%d", picked.length), false);
      case "paste": return paste(target);
      case "duplicate": return runEach(picked, "duplicate", {}, t.duplicated);
      case "mkfile": {
        const name = await modal({ title: t.newFile, html: "<label>" + esc(t.fileName) + "</label>", input: "" });
        if (!name) return;
        return api("mkfile", { path: state.path, name })
          .then((d) => { toast(withNotes(t.fileCreated, d.notes), false); reloadAfter([], d.path); })
          .catch((e) => toast(e.message, true));
      }
      case "mkdir": {
        const name = await modal({ title: t.newFolder, html: "<label>" + esc(t.folderName) + "</label>", input: "" });
        if (!name) return;
        return api("mkdir", { path: state.path, name })
          .then(() => {
            toast(t.created, false);
            reloadAfter([]);
          })
          .catch((e) => toast(e.message, true));
      }
      case "rename": {
        const current = nameOf(picked[0]);
        const name = await modal({ title: t.rename, html: "<label>" + esc(t.newName) + "</label>", input: current });
        if (!name || name === current) return;
        return runEach(picked, "rename", { name }, t.renamed);
      }
      case "move": {
        const to = await pickFolder(t.moveTo, t.moveHere, cfg.jail, state.path);
        if (to === null || to === true) return;
        return runEach(picked, "move", { to }, t.moved);
      }
      case "delete": {
        const onBucket = rows.some((r) => r.bucket) || rows.some((r) => isDir(r));
        const warn = onBucket ? '<p class="isxs-fm-warn">' + esc(t.deleteBucket.replace("%s", t.destination)) + "</p>" : "";
        const yes = await modal({ title: t.delete, html: "<p>" + esc(t.deleteAsk.replace("%d", picked.length)) + "</p>" + warn + listHtml(picked), ok: t.delete, danger: true });
        if (yes) runEach(picked, "delete", {}, t.deleted);
        return;
      }
      case "empty": {
        const yes = await modal({ title: t.menu.empty, html: "<p>" + esc(t.emptyAsk.replace("%s", one.name)) + '</p><p class="isxs-fm-warn">' + esc(t.deleteBucket.replace("%s", t.destination)) + "</p>", ok: t.menu.empty, danger: true });
        if (yes) runEach([one.path], "empty", {}, t.emptied);
        return;
      }
    }
  };

  $(".isxs-fm-actions").on("click", "[data-fm]", function () {
    act($(this).attr("data-fm"), [...state.selected]);
  });

  /* ---------- right-click menu ---------- */

  const $ctx = $('<ul class="isxs-fm-ctx" role="menu" hidden></ul>').appendTo("#isxs-fm");
  const CTX_ICONS = {
    open: '<path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>',
    openTab: '<path d="M14 4h6v6"/><path d="M20 4 11 13"/><path d="M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5"/>',
    download: '<path d="M12 4v12"/><path d="m7 11 5 5 5-5"/><path d="M4 20h16"/>',
    preview: '<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>',
    upload: '<path d="M12 16V4"/><path d="m7 9 5-5 5 5"/><path d="M4 16v3a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-3"/>',
    mkfile: '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5"/><path d="M12 12v6M9 15h6"/>',
    mkdir: '<path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><path d="M12 10v6M9 13h6"/>',
    copy: '<rect x="9" y="9" width="11" height="11" rx="2"/><path d="M5 15V5a1 1 0 0 1 1-1h9"/>',
    cut: '<circle cx="6" cy="18" r="3"/><circle cx="18" cy="18" r="3"/><path d="M8.1 16 19 4M15.9 16 5 4"/>',
    paste: '<rect x="6" y="4" width="12" height="17" rx="2"/><path d="M9 4V3h6v1"/><path d="M9 11h6M9 15h4"/>',
    duplicate: '<rect x="8" y="8" width="12" height="12" rx="2"/><path d="M4 16V5a1 1 0 0 1 1-1h11"/><path d="M14 11v6M11 14h6"/>',
    rename: '<path d="M4 20h4L19 9l-4-4L4 16z"/><path d="m13.5 6.5 4 4"/>',
    delete: '<path d="M4 7h16"/><path d="M10 11v6M14 11v6"/><path d="M6 7l1 12a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2l1-12"/><path d="M9 7V4h6v3"/>',
    empty: '<path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><path d="m10 11 4 4M14 11l-4 4"/>',
    all: '<rect x="4" y="4" width="16" height="16" rx="2"/><path d="m8 12 3 3 5-6"/>',
    invert: '<path d="M7 4 3 8l4 4"/><path d="M3 8h14"/><path d="m17 20 4-4-4-4"/><path d="M21 16H7"/>',
    info: '<circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/>',
    compress: '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5"/><path d="m10.5 11 1.5 2-1.5 2"/><path d="m13.5 11-1.5 2 1.5 2"/>',
  };
  const closeCtx = () => $ctx.prop("hidden", true);

  // Which entries make sense here, for this user. `row` is the row
  // right-clicked (already selected), or null for the empty area.
  const ctxItems = (row) => {
    const picked = [...state.selected].map(rowOf).filter(Boolean);
    const here = !!(state.listing && state.listing.writable) && !state.search;
    const changeable = picked.length > 0 && picked.every((r) => r.writable);
    const single = picked.length === 1 ? picked[0] : null;
    const file = single && !isDir(single);
    const pasteTarget = row ? (single && isDir(single) && single.writable ? single.path : null) : here ? state.path : null;
    const canPaste = clip && pasteTarget !== null && can(clip.mode === "cut" ? "move" : "upload");
    const groups = row
      ? [
          [["open", !!single], ["openTab", !!single], ["download", file && can("download")], ["preview", file && !!previewKind(single.name)],
            // Administrators only (cfg.access is set for them alone).
            ["compress", file && !!cfg.access && extOf(single.name) === "pdf" && single.writable]],
          [["copy", picked.length && can("upload")], ["cut", changeable && can("move")], ["paste", canPaste], ["duplicate", picked.length && here && can("upload")]],
          [["rename", single && single.writable && can("rename")], ["delete", changeable && can("delete")], ["empty", single && isDir(single) && single.writable && can("delete")]],
          [["all", true], ["invert", true], ["info", !!single]],
        ]
      : [
          [["upload", here && can("upload")], ["mkfile", here && can("upload")], ["mkdir", here && can("mkdir")], ["paste", canPaste]],
          [["all", state.rows.length > 0], ["info", true]],
        ];
    return { groups: groups.map((g) => g.filter(([, ok]) => ok).map(([op]) => op)).filter((g) => g.length), target: pasteTarget };
  };

  const openCtx = (e, row) => {
    e.preventDefault();
    if (row && !state.selected.has(row.path)) {
      state.selected = new Set([row.path]);
      state.anchor = row.path;
      showSelection();
    }
    if (!row && !e.metaKey && !e.ctrlKey) { state.selected.clear(); showSelection(); }
    const { groups, target } = ctxItems(row);
    if (!groups.length) return;
    $ctx.empty();
    groups.forEach((g, i) => {
      if (i) $ctx.append('<li class="isxs-fm-ctx-sep" role="separator"></li>');
      g.forEach((op) => {
        $ctx.append($('<li role="none">').append(
          $('<button type="button" role="menuitem">').attr("data-op", op).toggleClass("is-danger", op === "delete" || op === "empty")
            .html('<svg viewBox="0 0 24 24" aria-hidden="true">' + CTX_ICONS[op] + "</svg>").append($("<span>").text(t.menu[op]))
        ));
      });
    });
    $ctx.data("target", target).prop("hidden", false);
    // Keep the menu on screen.
    const w = $ctx.outerWidth(), h = $ctx.outerHeight();
    $ctx.css({ left: Math.min(e.clientX, window.innerWidth - w - 8), top: Math.min(e.clientY, window.innerHeight - h - 8) });
    $ctx.find("button").first().trigger("focus");
  };

  $table.on("contextmenu", "tbody tr[data-path]", function (e) { openCtx(e, rowOf($(this).attr("data-path"))); });
  $("#isxs-fm-main").on("contextmenu", function (e) {
    if ($(e.target).closest("tbody tr[data-path], thead").length) return;
    openCtx(e, null);
  });
  // Right-click a folder in the tree: open its parent with it picked, then
  // show the same menu as for that row. The top folder gets the menu of the
  // folder itself (upload, new file, paste …).
  $("#isxs-fm-tree").on("contextmenu", ".isxs-fm-node-name", async function (e) {
    e.preventDefault();
    const at = { clientX: e.clientX, clientY: e.clientY, metaKey: false, ctrlKey: false, preventDefault() {} };
    const path = $(this).closest("li").attr("data-path");
    if (path === cfg.jail) {
      await load(path);
      return openCtx(at, null);
    }
    await load(path.includes("/") ? path.slice(0, path.lastIndexOf("/")) : "", path);
    const row = rowOf(path);
    if (row) openCtx(at, row);
  });

  $ctx.on("click", "button[data-op]", function () {
    const op = $(this).attr("data-op");
    const target = $ctx.data("target");
    closeCtx();
    act(op, [...state.selected], target);
  });
  // Pointer and keyboard share one highlight: hovering moves the focus.
  $ctx.on("mouseenter", "button", function () { this.focus({ preventScroll: true }); });
  $ctx.on("keydown", (e) => {
    const $items = $ctx.find("button");
    const i = $items.index(document.activeElement);
    if (e.key === "ArrowDown" || e.key === "ArrowUp") {
      e.preventDefault();
      $items.eq((i + (e.key === "ArrowDown" ? 1 : -1) + $items.length) % $items.length).trigger("focus");
    }
  });
  $(document).on("mousedown", (e) => { if (!$(e.target).closest(".isxs-fm-ctx").length) closeCtx(); });
  $(document).on("keydown", (e) => { if (e.key === "Escape") closeCtx(); });
  $(window).on("blur resize scroll", closeCtx);
  $("#isxs-fm-main .isxs-fm-table-wrap").on("scroll", closeCtx);

  // ⌘/Ctrl + C, X, V like a desktop file manager.
  $(document).on("keydown", (e) => {
    if (!(e.metaKey || e.ctrlKey) || !$modal.prop("hidden") || $(e.target).is("input, textarea, select") || !$('[data-panel="files"]').is(":visible")) return;
    const key = e.key.toLowerCase();
    const picked = [...state.selected].map(rowOf).filter(Boolean);
    if (key === "c" && picked.length && can("upload")) { e.preventDefault(); act("copy", picked.map((r) => r.path)); }
    if (key === "x" && picked.length && picked.every((r) => r.writable) && can("move")) { e.preventDefault(); act("cut", picked.map((r) => r.path)); }
    if (key === "v" && clip && state.listing && state.listing.writable && !state.search) { e.preventDefault(); paste(state.path); }
  });

  /* ---------- Access tab (administrators only) ---------- */

  const initAccess = () => {
    const ACTIONS = Object.keys(t.actions);
    let config = cfg.access.config;

    const head = () =>
      "<thead><tr><th>" + esc(t.role) + "</th>" + ACTIONS.map((a) => '<th class="is-center">' + esc(t.actions[a]) + "</th>").join("") +
      "<th>" + esc(t.folder) + "</th></tr></thead>";

    const row = (key, label, rule) => {
      rule = rule || { actions: [], root: "" };
      return '<tr data-key="' + esc(key) + '"><td class="isxs-fm-who"><strong>' + esc(label) + "</strong></td>" +
        ACTIONS.map((a) => '<td class="is-center"><input type="checkbox" data-action="' + a + '"' + (rule.actions.includes(a) ? " checked" : "") +
          ' aria-label="' + esc(label + ": " + t.actions[a]) + '"></td>').join("") +
        '<td class="isxs-fm-root"><code>' + esc(rule.root ? "/" + rule.root : t.whole) + "</code>" +
        '<button type="button" class="isxs-btn isxs-btn-ghost isxs-fm-pickroot" data-root="' + esc(rule.root) + '">' + esc(t.choose) + "</button></td></tr>";
    };

    const render = () => {
      $("#isxs-fm-access-roles").html(head() + "<tbody>" +
        cfg.access.roles.map((r) => row(r.slug, r.name, config.roles[r.slug])).join("") + "</tbody>");
    };

    const collect = () => {
      const out = { roles: {} };
      $("#isxs-fm-access-roles tbody tr").each(function () {
        const $r = $(this);
        out.roles[$r.attr("data-key")] = {
          actions: $r.find("[data-action]:checked").map(function () { return $(this).attr("data-action"); }).get(),
          root: $r.find(".isxs-fm-pickroot").attr("data-root") || "",
        };
      });
      return out;
    };

    const save = () =>
      api("save_access", { config: JSON.stringify(collect()) })
        .then((d) => {
          config = d.config;
          render();
          toast(t.saved, false);
        })
        .catch((e) => toast(e.message, true));

    const $access = $("#isxs-fm-access-roles");
    $access.on("change", "[data-action]", function () {
      // Nothing counts without "view": any other box ticks it too, and
      // unticking it clears the row.
      const $row = $(this).closest("tr");
      if ($(this).attr("data-action") === "view") {
        if (!this.checked) $row.find("[data-action]").prop("checked", false);
      } else if (this.checked) {
        $row.find('[data-action="view"]').prop("checked", true);
      }
      save();
    });
    $access.on("click", ".isxs-fm-pickroot", async function () {
      const $btn = $(this);
      const to = await pickFolder(t.chooseFolder, t.useFolder, "", $btn.attr("data-root").replace(/\/$/, ""));
      if (to === null || to === true) return;
      $btn.attr("data-root", to ? to + "/" : "");
      save();
    });

    render();
  };

  $(".isxs-fm-tabs").on("click", "[data-tab]", function () {
    const tab = $(this).attr("data-tab");
    $(".isxs-fm-tabs [data-tab]").each(function () {
      const on = $(this).attr("data-tab") === tab;
      $(this).toggleClass("is-active", on).attr("aria-selected", on ? "true" : "false");
    });
    $("[data-panel]").each(function () { $(this).prop("hidden", $(this).attr("data-panel") !== tab); });
  });

  /* ---------- start ---------- */

  buildTree($("#isxs-fm-tree"), cfg.jail);
  load(state.path);
  if (cfg.access) initAccess();
})(jQuery);
