/* OC Webapp – download-app banner. Printed inline right after the banner markup.
 * Decides in the browser (cache-safe) whether to show the banner and where,
 * then opens it once the page is up: at the top it slides down from above and
 * pushes the page down; at the bottom it slides up from below the screen.
 * Closing reverses it. No animation for visitors who prefer reduced motion. */
(function () {
	var b = document.getElementById('oc-app-banner');
	if (!b) return;

	var KEY = 'oc_app_banner_closed';
	var cfg = {};
	try { cfg = JSON.parse(b.getAttribute('data-oc-ab')) || {}; } catch (e) {}

	// Closed recently? (days = 0 → it comes back on the next page.)
	try {
		var closed = +localStorage.getItem(KEY);
		if (closed && Date.now() - closed < (+cfg.days || 0) * 864e5) return;
	} catch (e) {}

	var html = document.documentElement;
	var ua = navigator.userAgent || '';
	var os = /android/i.test(ua) ? 'android'
		: (/iPhone|iPad|iPod/.test(ua) || (/Macintosh/.test(ua) && navigator.maxTouchPoints > 1)) ? 'ios'
		: '';
	// Inside the app: the WebView user-agent substring, or the "oc-webapp" class
	// the plugin adds to <html> in <head> (either is enough).
	var inApp = (cfg.ua && ua.indexOf(cfg.ua) !== -1) || /(^|\s)oc-webapp(\s|$)/.test(html.className);
	var ctx = inApp ? 'app' : (os ? 'mobile' : 'desktop');
	var pos = cfg[ctx];
	if (pos !== 'top' && pos !== 'bottom') return;

	// On a phone offer only the matching store; no link for it → no banner.
	if (os && !b.querySelector('.oc-ab__btn--' + os)) return;

	var body = document.body;
	var top = pos === 'top';
	var prop = top ? 'margin-top' : 'transform';
	var SPEED = 500; // ms
	var reduce = !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
	var padBefore = body.style.paddingBottom;

	b.className += ' oc-ab--' + (os || 'desktop') + ' oc-ab--' + pos;
	if (top && body.firstChild !== b) body.insertBefore(b, body.firstChild);

	// Closed position: top = tucked above the page (takes no room);
	// bottom = just below the screen.
	function tuck() {
		if (top) b.style.marginTop = -b.offsetHeight + 'px';
		else b.style.transform = 'translateY(100%)';
	}
	function animate(on) {
		b.style.transition = on ? prop + ' ' + SPEED + 'ms cubic-bezier(.22,.61,.36,1)' : 'none';
	}

	function open() {
		if (!reduce) {
			animate(false);
			tuck(); // measure again: web fonts may have changed the height
			void b.offsetHeight; // apply it, so the next change animates
		}
		b.style.visibility = '';
		if (!reduce) {
			animate(true);
			if (top) b.style.marginTop = '0px';
			else b.style.transform = 'none';
		}
		if (!top) {
			// A bottom banner floats over the page: reserve room so it hides nothing.
			var pad = parseFloat(window.getComputedStyle(body).paddingBottom) || 0;
			body.style.paddingBottom = (pad + b.offsetHeight) + 'px';
		}
	}

	b.hidden = false;
	if (reduce) {
		open();
	} else {
		b.style.visibility = 'hidden';
		tuck();
		// Open shortly after the page is up, so the visitor sees it slide in.
		var later = function () { setTimeout(open, 500); };
		if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', later);
		else later();
	}

	b.querySelector('.oc-ab__close').addEventListener('click', function () {
		try { localStorage.setItem(KEY, String(Date.now())); } catch (e) {}
		function done() {
			b.hidden = true;
			b.style.marginTop = b.style.transform = b.style.transition = '';
			if (!top) body.style.paddingBottom = padBefore;
		}
		if (reduce) return done();
		animate(true);
		tuck();
		setTimeout(done, SPEED + 30);
	});
})();
