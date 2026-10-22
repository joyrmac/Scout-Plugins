/* Scout Build -> Hours: fold closed days and unused second ranges away,
   copy Monday to the other weekdays, and show the special-hours times only
   when they apply. Without this script every field simply stays visible. */
(function () {
	var root = document.querySelector('.shh-layout');
	if (!root) {
		return;
	}
	root.classList.add('shh-js');

	function sync(day) {
		var open = day.querySelector('.shh-open'), split = day.querySelector('.shh-split-box');
		day.classList.toggle('is-open', open.checked);
		day.classList.toggle('is-closed', !open.checked);
		day.classList.toggle('is-split', split.checked);
	}

	var days = [].slice.call(root.querySelectorAll('.shh-day'));
	days.forEach(function (day) {
		sync(day);
		day.addEventListener('change', function () {
			sync(day);
		});
	});

	var copy = root.querySelector('.shh-copy');
	if (copy) {
		copy.hidden = false;
		copy.addEventListener('click', function () {
			var from = root.querySelector('.shh-day[data-day="monday"]');
			if (!from) {
				return;
			}
			var fields = from.querySelectorAll('input');
			['tuesday', 'wednesday', 'thursday', 'friday'].forEach(function (key) {
				var to = root.querySelector('.shh-day[data-day="' + key + '"]');
				if (!to) {
					return;
				}
				var targets = to.querySelectorAll('input');
				for (var i = 0; i < fields.length && i < targets.length; i++) {
					if (fields[i].type === 'checkbox') {
						targets[i].checked = fields[i].checked;
					} else {
						targets[i].value = fields[i].value;
					}
				}
				sync(to);
			});
			copy.textContent = copy.getAttribute('data-done') || copy.textContent;
		});
	}

	var add = root.querySelector('.shh-add');
	if (add) {
		var toggle = function () {
			var special = add.querySelector('input[name="closure[type]"][value="special"]');
			add.classList.toggle('is-special', !!(special && special.checked));
		};
		add.addEventListener('change', toggle);
		toggle();
	}
})();
