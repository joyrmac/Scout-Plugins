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

	var bar = run.querySelector('[data-ssc-bar]');
	function done(step) {
		var li = run.querySelector('[data-step="' + step + '"]');
		if (li) { li.classList.add('is-done'); }
		if (bar) { bar.style.width = Math.max(6, Math.round(100 * run.querySelectorAll('.ssc-steps .is-done').length / cfg.steps.length)) + '%'; }
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
		if (bar) { bar.parentNode.hidden = true; }
		var note = report.held
			? 'We\'ll review your report and email it to ' + esc(email) + ' shortly.'
			: 'We\'ve emailed the full report to ' + esc(email) + '.';
		box.innerHTML =
			'<div class="ssc-score"><span class="ssc-n">' + report.passed + '<small>/' + report.total + '</small></span>' +
			'<h2 class="ssc-score-h">' + esc(report.headline) + '</h2></div>' +
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

	/* The sample report: rows resolve one at a time, once, when it scrolls into view. Static under reduced motion. */
	(function () {
		if (!sample || (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches)) { return; }
		var rows = sample.querySelectorAll('.ssc-r');
		var segs = sample.querySelectorAll('.ssc-seg i');
		var passEl = sample.querySelector('[data-ssc-pass]');
		var head = sample.querySelector('[data-ssc-sample-head]');
		if (!rows.length || !passEl || !head) { return; }
		var finalHead = head.textContent;
		var states = [];
		rows.forEach(function (r, i) {
			states.push(r.getAttribute('data-status'));
			r.classList.add('is-wait');
			if (segs[i]) { segs[i].className = ''; }
		});
		passEl.textContent = '0';
		head.textContent = 'Checking yourbusiness.com';
		sample.setAttribute('aria-busy', 'true');
		var started = false;
		function play() {
			if (started) { return; }
			started = true;
			var i = 0, passed = 0;
			var timer = setInterval(function () {
				rows[i].classList.remove('is-wait');
				if (segs[i]) { segs[i].className = 'is-' + states[i]; }
				if (states[i] === 'pass') { passed++; passEl.textContent = String(passed); }
				i++;
				if (i >= rows.length) {
					clearInterval(timer);
					head.textContent = finalHead;
					sample.removeAttribute('aria-busy');
				}
			}, 320);
		}
		if ('IntersectionObserver' in window) {
			var io = new IntersectionObserver(function (entries) {
				if (entries[0].isIntersecting) { io.disconnect(); setTimeout(play, 300); }
			}, { threshold: 0.35 });
			io.observe(sample);
		} else {
			setTimeout(play, 300);
		}
	})();
})();
