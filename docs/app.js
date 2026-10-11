"use strict";
/*
 * Progressive enhancement only. Every page is complete without this file:
 * it adds theme switching, site search, card filtering, copy buttons and a
 * highlighted table of contents.
 */
(function () {
  var root = document.documentElement;
  var base = root.getAttribute("data-root") || "./";

  function safeGet(key) { try { return window.localStorage.getItem(key); } catch (e) { return null; } }
  function safeSet(key, value) { try { window.localStorage.setItem(key, value); } catch (e) { /* storage may be blocked */ } }

  /* ---------- Theme ---------- */
  var themeButton = document.querySelector("[data-theme-toggle]");
  function effectiveTheme() {
    var set = root.getAttribute("data-theme");
    if (set === "light" || set === "dark") return set;
    return window.matchMedia && window.matchMedia("(prefers-color-scheme: dark)").matches ? "dark" : "light";
  }
  function paintThemeButton() {
    if (!themeButton) return;
    var next = effectiveTheme() === "dark" ? "light" : "dark";
    themeButton.textContent = next === "dark" ? "Dark mode" : "Light mode";
    themeButton.setAttribute("aria-label", "Switch to " + next + " theme");
  }
  if (themeButton) {
    themeButton.hidden = false;
    paintThemeButton();
    themeButton.addEventListener("click", function () {
      var next = effectiveTheme() === "dark" ? "light" : "dark";
      root.setAttribute("data-theme", next);
      safeSet("rt-docs-theme", next);
      paintThemeButton();
    });
    if (window.matchMedia) {
      var mq = window.matchMedia("(prefers-color-scheme: dark)");
      var onChange = function () { paintThemeButton(); };
      if (mq.addEventListener) mq.addEventListener("change", onChange);
    }
  }

  /* ---------- Small screens: show the current page in the scrolling top navigation ---------- */
  var currentNav = document.querySelector('.top-nav a[aria-current="page"]');
  if (currentNav && currentNav.parentNode.scrollWidth > currentNav.parentNode.clientWidth) {
    var navBox = currentNav.parentNode;
    navBox.scrollLeft = Math.max(0, currentNav.getBoundingClientRect().left - navBox.getBoundingClientRect().left + navBox.scrollLeft - 24);
  }

  /* ---------- Sidebar: collapse on small screens ---------- */
  var sideDetails = document.querySelector(".sidebar details");
  if (sideDetails && window.matchMedia && window.matchMedia("(max-width: 900px)").matches) {
    sideDetails.removeAttribute("open");
  }

  /* ---------- Site search ---------- */
  var searchHost = document.querySelector("[data-search-host]");
  if (searchHost) {
    var script = document.createElement("script");
    script.src = base + "search-index.js";
    script.defer = true;
    document.head.appendChild(script);

    var box = document.createElement("div");
    box.className = "search-box";
    var input = document.createElement("input");
    input.type = "search";
    input.placeholder = "Search the docs";
    input.setAttribute("aria-label", "Search the docs");
    input.setAttribute("autocomplete", "off");
    input.setAttribute("role", "combobox");
    input.setAttribute("aria-autocomplete", "list");
    input.setAttribute("aria-expanded", "false");
    input.setAttribute("aria-controls", "search-results");
    var results = document.createElement("div");
    results.className = "search-results";
    results.id = "search-results";
    results.hidden = true;
    results.setAttribute("role", "listbox");
    results.setAttribute("aria-label", "Search results");
    var live = document.createElement("div");
    live.className = "visually-hidden";
    live.setAttribute("role", "status");
    live.setAttribute("aria-live", "polite");
    box.appendChild(input);
    box.appendChild(results);
    box.appendChild(live);
    searchHost.appendChild(box);

    var active = -1;
    var links = [];
    var escapeHtml = function (s) { return String(s).replace(/[&<>"]/g, function (c) { return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;" }[c]; }); };
    var lower = function (s) { return String(s).toLowerCase(); };
    var headingText = function (h) { return h[1]; };
    var score = function (entry, terms) {
      var total = 0;
      var title = lower(entry.t);
      var headings = lower(entry.h.map(headingText).join(" "));
      var description = lower(entry.d);
      var text = lower(entry.x);
      for (var i = 0; i < terms.length; i++) {
        var term = terms[i];
        var s = 0;
        if (title.indexOf(term) !== -1) s += 10;
        if (headings.indexOf(term) !== -1) s += 5;
        if (description.indexOf(term) !== -1) s += 3;
        if (text.indexOf(term) !== -1) s += 1;
        if (!s) return 0;
        total += s;
      }
      return total;
    };
    var bestHeading = function (entry, terms) {
      for (var i = 0; i < entry.h.length; i++) {
        var text = lower(entry.h[i][1]);
        for (var j = 0; j < terms.length; j++) {
          if (text.indexOf(terms[j]) !== -1) return entry.h[i];
        }
      }
      return null;
    };
    var close = function () {
      results.hidden = true;
      input.setAttribute("aria-expanded", "false");
      input.removeAttribute("aria-activedescendant");
    };
    var render = function () {
      var query = input.value.trim().toLowerCase();
      links = [];
      active = -1;
      input.removeAttribute("aria-activedescendant");
      if (!query) { close(); results.textContent = ""; live.textContent = ""; return; }
      var data = window.RT_DOCS || [];
      var terms = query.split(/\s+/);
      var found = data.map(function (entry) { return { entry: entry, s: score(entry, terms) }; })
        .filter(function (item) { return item.s > 0; })
        .sort(function (a, b) { return b.s - a.s; })
        .slice(0, 8);
      results.hidden = false;
      input.setAttribute("aria-expanded", "true");
      if (!data.length) { results.innerHTML = '<p class="none">The search index is still loading. Try again in a moment.</p>'; live.textContent = "Search index is loading"; return; }
      if (!found.length) { results.innerHTML = '<p class="none">No page matches. Try a shorter word, such as "retry" or "circuit".</p>'; live.textContent = "No results"; return; }
      results.innerHTML = found.map(function (item, index) {
        var heading = bestHeading(item.entry, terms);
        var href = base + escapeHtml(item.entry.u) + (heading ? "#" + escapeHtml(heading[0]) : "");
        return '<a role="option" id="search-option-' + index + '" aria-selected="false" href="' + href + '"><strong>' + escapeHtml(item.entry.t) + "</strong>" +
          (heading ? '<span class="where">' + escapeHtml(heading[1]) + "</span>" : "") +
          "<span>" + escapeHtml(item.entry.d) + "</span></a>";
      }).join("");
      links = Array.prototype.slice.call(results.querySelectorAll("a"));
      live.textContent = found.length + (found.length === 1 ? " result" : " results");
    };
    var move = function (delta) {
      if (!links.length) return;
      if (links[active]) { links[active].classList.remove("is-active"); links[active].setAttribute("aria-selected", "false"); }
      active = (active + delta + links.length) % links.length;
      links[active].classList.add("is-active");
      links[active].setAttribute("aria-selected", "true");
      input.setAttribute("aria-activedescendant", links[active].id);
      links[active].scrollIntoView({ block: "nearest" });
    };
    input.addEventListener("input", render);
    input.addEventListener("focus", render);
    input.addEventListener("keydown", function (event) {
      if (event.key === "ArrowDown") { event.preventDefault(); move(1); }
      else if (event.key === "ArrowUp") { event.preventDefault(); move(-1); }
      else if (event.key === "Enter" && links[active]) { event.preventDefault(); window.location.href = links[active].href; }
      else if (event.key === "Escape") { close(); input.blur(); }
    });
    document.addEventListener("click", function (event) { if (!box.contains(event.target)) close(); });
    box.addEventListener("focusout", function (event) { if (event.relatedTarget && !box.contains(event.relatedTarget)) close(); });
    document.addEventListener("keydown", function (event) {
      var tag = (event.target && event.target.tagName) || "";
      if (event.key === "/" && !event.ctrlKey && !event.metaKey && !event.altKey && tag !== "INPUT" && tag !== "TEXTAREA" && tag !== "SELECT") {
        event.preventDefault();
        input.focus();
      }
    });
  }

  /* ---------- Card filters ---------- */
  document.querySelectorAll("[data-filter]").forEach(function (field) {
    var group = document.getElementById(field.getAttribute("data-filter"));
    if (!group) return;
    var items = Array.prototype.slice.call(group.querySelectorAll("[data-filter-item]"));
    var status = document.getElementById(field.getAttribute("data-status"));
    var empty = document.getElementById(field.getAttribute("data-empty"));
    var run = function () {
      var query = field.value.trim().toLowerCase();
      var visible = 0;
      items.forEach(function (item) {
        var match = !query || item.textContent.toLowerCase().indexOf(query) !== -1;
        item.hidden = !match;
        if (match) visible += 1;
      });
      if (status) status.textContent = visible + " of " + items.length + " guides shown";
      if (empty) empty.hidden = visible !== 0;
    };
    field.closest(".filter").hidden = false;
    field.addEventListener("input", run);
    run();
  });

  /* ---------- Copy buttons ---------- */
  document.querySelectorAll("pre").forEach(function (block) {
    /* A code block only needs to be a keyboard stop when it scrolls sideways. Blocks in a closed <details> are measured as zero, so leave those alone. */
    if (block.offsetParent !== null && block.scrollWidth <= block.clientWidth) block.removeAttribute("tabindex");
    /* The API listing has dozens of signature blocks, and copying them is not the point of that page. */
    if (block.hasAttribute("data-api")) return;
    var tools = document.createElement("div");
    tools.className = "code-tools";
    var button = document.createElement("button");
    button.type = "button";
    button.className = "copy-button";
    button.textContent = "Copy";
    button.setAttribute("aria-label", "Copy the code block above");
    var status = document.createElement("span");
    status.className = "visually-hidden";
    status.setAttribute("role", "status");
    button.addEventListener("click", function () {
      var done = function (label) {
        button.textContent = label;
        status.textContent = label;
        window.setTimeout(function () { button.textContent = "Copy"; status.textContent = ""; }, 2200);
      };
      var select = function () {
        var selection = window.getSelection();
        var range = document.createRange();
        range.selectNodeContents(block);
        selection.removeAllRanges();
        selection.addRange(range);
        done("Selected. Press copy.");
      };
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(block.textContent).then(function () { done("Copied"); }, select);
      } else { select(); }
    });
    tools.appendChild(button);
    tools.appendChild(status);
    block.parentNode.insertBefore(tools, block.nextSibling);
  });

  /* ---------- Table of contents highlight ---------- */
  var tocLinks = Array.prototype.slice.call(document.querySelectorAll(".toc a"));
  if (tocLinks.length && "IntersectionObserver" in window) {
    var byId = {};
    tocLinks.forEach(function (link) { byId[link.getAttribute("href").slice(1)] = link; });
    var observer = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (entry.isIntersecting && byId[entry.target.id]) {
          tocLinks.forEach(function (link) { link.classList.remove("is-current"); link.removeAttribute("aria-current"); });
          byId[entry.target.id].classList.add("is-current");
          byId[entry.target.id].setAttribute("aria-current", "location");
        }
      });
    }, { rootMargin: "-80px 0px -70% 0px" });
    document.querySelectorAll(".prose h2[id]").forEach(function (heading) { observer.observe(heading); });
  }
})();
