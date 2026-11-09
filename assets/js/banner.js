/* OC Webapp – download-app banner. Printed inline right after the banner markup,
 * so it runs before the rest of the page paints (no jump). Decides in the
 * browser (cache-safe) whether to show the banner and where. */
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
	b.className += ' oc-ab--' + (os || 'desktop') + ' oc-ab--' + pos;
	if (pos === 'top' && body.firstChild !== b) body.insertBefore(b, body.firstChild);
	b.hidden = false;

	// A bottom banner floats over the page: reserve room so it hides nothing.
	var padBefore = body.style.paddingBottom;
	if (pos === 'bottom') {
		var pad = parseFloat(window.getComputedStyle(body).paddingBottom) || 0;
		body.style.paddingBottom = (pad + b.offsetHeight) + 'px';
	}

	b.querySelector('.oc-ab__close').addEventListener('click', function () {
		b.hidden = true;
		if (pos === 'bottom') body.style.paddingBottom = padBefore;
		try { localStorage.setItem(KEY, String(Date.now())); } catch (e) {}
	});
})();
