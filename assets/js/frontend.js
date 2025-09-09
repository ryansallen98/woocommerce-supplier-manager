// assets/js/wcsm-frontend.js
(function () {
  "use strict";

  function qsAll(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }

  function hide(el) { el.style.display = "none"; el.setAttribute("aria-hidden", "true"); }
  function show(el) { el.style.display = "";     el.setAttribute("aria-hidden", "false"); }

  function toggle(el) { 
    var hidden = el.getAttribute("aria-hidden") === "true" || getComputedStyle(el).display === "none";
    hidden ? show(el) : hide(el);
  }

  function setToggleIcon(btn, open) {
    var icon = btn.querySelector(".wcsm-toggle-icon");
    if (icon) icon.textContent = open ? "–" : "+";
    btn.setAttribute("aria-expanded", open ? "true" : "false");
  }

  function initVariationRows() {
    // Start hidden
    qsAll("tr.wcsm-variation").forEach(hide);

    // Delegate clicks for parent toggles
    document.addEventListener("click", function (e) {
      var btn = e.target.closest(".wcsm-parent .wcsm-toggle[data-product]");
      if (!btn) return;

      var pid = btn.getAttribute("data-product");
      if (!pid) return;

      var rows = qsAll('tr.wcsm-variation[data-parent="' + pid + '"]');
      if (!rows.length) return;

      // Are they currently hidden? (check the first row)
      var opening = rows[0].getAttribute("aria-hidden") === "true" || getComputedStyle(rows[0]).display === "none";

      rows.forEach(function (r) { opening ? show(r) : hide(r); });
      setToggleIcon(btn, opening);
    });
  }

  function initOrderFormToggles() {
    // Delegate clicks for update buttons that point to a target row
    document.addEventListener("click", function (e) {
      var btn = e.target.closest('.wcsm-toggle[data-target]');
      if (!btn) return;

      var targetSel = btn.getAttribute("data-target");
      if (!targetSel) return;

      var target = document.querySelector(targetSel);
      if (!target) return;

      toggle(target);

      var nowOpen = target.getAttribute("aria-hidden") !== "true" && getComputedStyle(target).display !== "none";
      setToggleIcon(btn, nowOpen);
    });

    // Ensure hidden by default if template used inline style only
    qsAll(".wcsm-order-edit").forEach(function (row) {
      if (getComputedStyle(row).display === "none") {
        row.setAttribute("aria-hidden", "true");
      }
    });
  }

  function ready(fn) {
    if (document.readyState === "loading") {
      document.addEventListener("DOMContentLoaded", fn, { once: true });
    } else { fn(); }
  }

  ready(function () {
    initVariationRows();
    initOrderFormToggles();
  });
})();