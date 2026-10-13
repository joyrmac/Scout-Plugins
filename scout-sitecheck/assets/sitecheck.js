/* Scout Site Check: start a check, run its four steps, show the report. */
(function () {
	var cfg = window.scoutSitecheck;
	var root = document.querySelector('[data-ssc]');
	if (!cfg || !root) { return; }
	var form = root.querySelector('[data-ssc-form]');
	var run = root.querySelector('[data-ssc-run]');
	var err = root.querySelector('[data-ssc-error]');
	var sample = root.querySelector('[data-ssc-sample]');
	var btn = form.querySelector('button[type=submit]');

	function post(path, body) {
		return fetch(cfg.rest + path, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify(body)
		}).then(function (r) {
			return r.json().then(function (data) {
				if (!r.ok) { throw new Error(data && data.message ? data.message : 'Something went wrong. Please try again.'); }
				return data;
			});
		});
	}

	function showError(msg) {
		err.textContent = msg;
		err.hidden = false;
		btn.disabled = false;
		form.hidden = false;
		run.hidden = true;
		if (sample) { sample.hidden = false; }
	}

	function done(step) {
		var li = run.querySelector('[data-step="' + step + '"]');
		if (li) { li.classList.add('is-done'); }
	}

	function esc(s) {
		var d = document.createElement('div');
		d.textContent = s;
		return d.innerHTML;
	}

	function show(report, email) {
		var head = run.querySelector('[data-ssc-head]');
		var box = run.querySelector('[data-ssc-result]');
		head.hidden = true;
		run.querySelector('.ssc-steps').hidden = true;
		var note = report.held
			? 'We\'ll review your report and email it to ' + esc(email) + ' shortly.'
			: 'We\'ve emailed the full report to ' + esc(email) + '.';
		box.innerHTML =
			'<div class="ssc-score"><div class="ssc-ring">' + report.passed + '/' + report.total + '</div>' +
			'<h2 class="ssc-h">' + esc(report.headline) + '</h2></div>' +
			'<p class="ssc-fine">' + note + '</p>' + report.html +
			'<div class="ssc-follow"><p>' + esc(report.follow) + '</p><a class="ssc-btn" href="' + esc(cfg.book) + '">Book a call</a></div>';
		box.hidden = false;
		box.setAttribute('tabindex', '-1');
		box.focus({ preventScroll: true });
		run.scrollIntoView({ behavior: 'smooth', block: 'start' });
	}

	form.addEventListener('submit', function (e) {
		e.preventDefault();
		err.hidden = true;
		btn.disabled = true;
		var data = new FormData(form);
		var email = String(data.get('email') || '');
		post('/start', {
			url: data.get('url'),
			name: data.get('name'),
			email: email,
			website: data.get('website')
		}).then(function (job) {
			form.hidden = true;
			if (sample) { sample.hidden = true; }
			run.hidden = false;
			run.querySelector('[data-ssc-site]').textContent = job.site;
			var ask = function (step) {
				return post('/step', { id: job.id, token: job.token, step: step }).then(function (res) {
					if (!res.ok) { throw new Error(res.message); }
					done(step);
					return res.report || null;
				});
			};
			return ask('basics').then(function (first) {
				if (first) { return first; }
				return Promise.allSettled(cfg.steps.filter(function (s) { return s !== 'basics'; }).map(ask)).then(function (results) {
					for (var i = 0; i < results.length; i++) {
						if (results[i].status === 'fulfilled' && results[i].value) { return results[i].value; }
					}
					return null;
				});
			}).then(function (report) {
				if (report) { show(report, email); return; }
				var head = run.querySelector('[data-ssc-head]');
				head.textContent = 'We\'re finishing your report and will email it to you within a few minutes.';
			});
		}).catch(function (e2) { showError(e2.message); });
	});
})();
