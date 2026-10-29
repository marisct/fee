// Boxing Fee Manager - small UI helpers (no framework needed)
(function () {
  'use strict';

  // Select-all checkbox for bulk actions
  document.querySelectorAll('[data-check-all]').forEach(function (master) {
    var target = master.getAttribute('data-check-all');
    master.addEventListener('change', function () {
      document.querySelectorAll(target).forEach(function (cb) { cb.checked = master.checked; });
      updateBulkCount();
    });
  });
  document.querySelectorAll('.row-check').forEach(function (cb) {
    cb.addEventListener('change', updateBulkCount);
  });
  function updateBulkCount() {
    var n = document.querySelectorAll('.row-check:checked').length;
    document.querySelectorAll('[data-bulk-count]').forEach(function (el) { el.textContent = n; });
    document.querySelectorAll('[data-bulk-btn]').forEach(function (btn) { btn.disabled = n === 0; });
  }
  updateBulkCount();

  // Confirmation prompts: <form data-confirm="Are you sure?"> or <button data-confirm="...">
  document.querySelectorAll('[data-confirm]').forEach(function (el) {
    var evt = el.tagName === 'FORM' ? 'submit' : 'click';
    el.addEventListener(evt, function (e) {
      if (!window.confirm(el.getAttribute('data-confirm'))) { e.preventDefault(); }
    });
  });

  // Copy-to-clipboard buttons: <button data-copy="#elementId">
  document.querySelectorAll('[data-copy]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var src = document.querySelector(btn.getAttribute('data-copy'));
      if (!src) { return; }
      var text = src.value !== undefined && src.tagName !== 'DIV' ? src.value : src.textContent;
      navigator.clipboard.writeText(text.trim()).then(function () {
        var old = btn.innerHTML;
        btn.innerHTML = '<i class="bi bi-check2"></i> Copied';
        setTimeout(function () { btn.innerHTML = old; }, 1500);
      });
    });
  });

  // Disable the submit button after click to avoid double sends
  document.querySelectorAll('form[data-once]').forEach(function (f) {
    f.addEventListener('submit', function () {
      var b = f.querySelector('button[type=submit]:not([name])');
      if (b) { b.disabled = true; b.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>' + b.textContent; }
    });
  });

  // Frappe Charts: <div data-chart='{"labels":[],"values":[]}' data-fallback="idOfFallback">
  document.querySelectorAll('[data-chart]').forEach(function (el) {
    if (!window.frappe || !window.frappe.Chart) { el.remove(); return; }
    var cfg;
    try { cfg = JSON.parse(el.getAttribute('data-chart')); } catch (e) { return; }
    new window.frappe.Chart(el, {
      data: { labels: cfg.labels, datasets: [{ name: 'Collected', values: cfg.values }] },
      type: 'bar',
      height: 250,
      colors: ['#2490ef'],
      axisOptions: { xAxisMode: 'tick', yAxisMode: 'span', xIsSeries: false },
      barOptions: { spaceRatio: 0.55 },
      tooltipOptions: { formatTooltipY: function (d) { return '₹' + Number(d).toLocaleString('en-IN'); } }
    });
    var fb = document.getElementById(el.getAttribute('data-fallback'));
    if (fb) { fb.remove(); }
  });
})();
