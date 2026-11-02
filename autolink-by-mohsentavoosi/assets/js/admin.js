/* Autolink by MohsenTavoosi - Admin JS */
(function () {
	'use strict';

	// Confirm before deleting a record.
	document.querySelectorAll('.ail-delete-form').forEach(function (form) {
		form.addEventListener('submit', function (e) {
			if (!window.confirm(form.getAttribute('data-confirm') || 'Delete this record?')) {
				e.preventDefault();
			}
		});
	});

	// Confirm before a Replace import.
	var importForm = document.getElementById('ail-import-form');
	if (importForm) {
		importForm.addEventListener('submit', function (e) {
			var mode = importForm.querySelector('input[name=ail_import_mode]:checked');
			var btn = importForm.querySelector('button[data-confirm]');
			if (mode && mode.value === 'replace' && btn && !window.confirm(btn.getAttribute('data-confirm'))) {
				e.preventDefault();
			}
		});
	}
})();

/* Search-and-pick boxes (excluded pages, record pages). */
(function () {
	'use strict';
	if (typeof window.ailAdmin === 'undefined') {
		return;
	}
	var cfg = window.ailAdmin;

	function syncCsv(box) {
		var csv = box.querySelector('.ail-picker-csv');
		if (!csv) {
			return;
		}
		var ids = [];
		box.querySelectorAll('.ail-chip').forEach(function (c) {
			ids.push(c.getAttribute('data-value').split(':')[1]);
		});
		csv.value = ids.join(',');
	}

	function addChip(box, value, label) {
		if (box.querySelector('.ail-chip[data-value="' + value + '"]')) {
			return;
		}
		var li = document.createElement('li');
		li.className = 'ail-chip';
		li.setAttribute('data-value', value);
		var span = document.createElement('span');
		span.textContent = label;
		var x = document.createElement('button');
		x.type = 'button';
		x.className = 'ail-chip-x';
		x.setAttribute('aria-label', cfg.remove);
		x.textContent = '×';
		li.appendChild(span);
		li.appendChild(x);
		if (box.getAttribute('data-mode') === 'list') {
			var h = document.createElement('input');
			h.type = 'hidden';
			h.name = box.getAttribute('data-name') + '[]';
			h.value = value;
			li.appendChild(h);
		}
		box.querySelector('.ail-picker-chips').appendChild(li);
		syncCsv(box);
	}

	document.querySelectorAll('.ail-picker').forEach(function (box) {
		var input = box.querySelector('.ail-picker-search');
		var list = box.querySelector('.ail-picker-results');
		var timer = null;

		box.addEventListener('click', function (e) {
			if (e.target.classList.contains('ail-chip-x')) {
				e.target.closest('.ail-chip').remove();
				syncCsv(box);
			}
		});

		input.addEventListener('keydown', function (e) {
			if (e.key === 'Enter') {
				e.preventDefault(); // Never submit the settings form from the search box.
			}
		});

		input.addEventListener('input', function () {
			clearTimeout(timer);
			var q = input.value.trim();
			if (q.length < 2) {
				list.hidden = true;
				list.innerHTML = '';
				return;
			}
			timer = setTimeout(function () {
				var url = cfg.ajax + '?action=ail_search&nonce=' + encodeURIComponent(cfg.nonce) +
					'&kind=' + encodeURIComponent(box.getAttribute('data-kind')) + '&q=' + encodeURIComponent(q);
				fetch(url, { credentials: 'same-origin' })
					.then(function (r) { return r.json(); })
					.then(function (res) {
						list.innerHTML = '';
						var items = (res && res.success && res.data) ? res.data : [];
						if (!items.length) {
							var none = document.createElement('li');
							none.className = 'ail-none';
							none.textContent = cfg.noResults;
							list.appendChild(none);
						}
						items.forEach(function (it) {
							var li = document.createElement('li');
							li.textContent = it.label;
							li.tabIndex = 0;
							li.addEventListener('click', function () {
								addChip(box, it.value, it.label);
								list.hidden = true;
								input.value = '';
							});
							li.addEventListener('keydown', function (e) {
								if (e.key === 'Enter') {
									e.preventDefault();
									li.click();
								}
							});
							list.appendChild(li);
						});
						list.hidden = false;
					})
					.catch(function () { list.hidden = true; });
			}, 300);
		});

		document.addEventListener('click', function (e) {
			if (!box.contains(e.target)) {
				list.hidden = true;
			}
		});
	});
})();
