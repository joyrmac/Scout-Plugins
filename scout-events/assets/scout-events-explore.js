/* Scout Events: series chips and the List / Month switch on [scout_events views="list,month" filter="series"].
   Every event is already in the page; this only shows and hides. */
(function () {
	document.querySelectorAll('[data-se-explore]').forEach(function (root) {
		var empty = root.querySelector('.se-filter-empty');
		function apply(series) {
			var shown = 0;
			root.querySelectorAll('[data-series]').forEach(function (el) {
				var on = !series || el.getAttribute('data-series') === series;
				el.hidden = !on;
				if (on && el.closest('[data-se-view]') && !el.closest('[data-se-view]').hidden) shown++;
			});
			// month headings and calendars with nothing left in them step aside
			root.querySelectorAll('.se-month, .se-cal').forEach(function (box) {
				box.hidden = !box.querySelector('[data-series]:not([hidden])');
			});
			root.querySelectorAll('.se-cal-day.has-events').forEach(function (d) {
				d.classList.toggle('is-quiet', !d.querySelector('[data-series]:not([hidden])'));
			});
			if (empty) empty.hidden = shown > 0;
		}
		var current = '';
		root.querySelectorAll('[data-se-series]').forEach(function (b) {
			b.addEventListener('click', function () {
				current = b.getAttribute('data-se-series');
				root.querySelectorAll('[data-se-series]').forEach(function (o) { o.setAttribute('aria-pressed', o === b ? 'true' : 'false'); });
				apply(current);
			});
		});
		root.querySelectorAll('[data-se-show]').forEach(function (b) {
			b.addEventListener('click', function () {
				var v = b.getAttribute('data-se-show');
				root.querySelectorAll('[data-se-show]').forEach(function (o) { o.setAttribute('aria-pressed', o === b ? 'true' : 'false'); });
				root.querySelectorAll('[data-se-view]').forEach(function (p) { p.hidden = p.getAttribute('data-se-view') !== v; });
				apply(current);
			});
		});
	});
})();
