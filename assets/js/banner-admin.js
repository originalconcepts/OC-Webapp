/* OC Webapp – media picker for the banner's app icon (Settings → OC Webapp). */
jQuery(function ($) {
	var frame;
	var $input = $('#oc-ab-icon');
	var $preview = $('#oc-ab-icon-preview');

	$('#oc-ab-icon-pick').on('click', function (e) {
		e.preventDefault();
		if (!frame) {
			frame = wp.media({
				title: $(this).data('title'),
				button: { text: $(this).data('button') },
				library: { type: 'image' },
				multiple: false
			});
			frame.on('select', function () {
				var a = frame.state().get('selection').first().toJSON();
				// The square-cropped thumbnail is ideal for a small app icon.
				var url = a.sizes && a.sizes.thumbnail ? a.sizes.thumbnail.url : a.url;
				$input.val(url).trigger('change');
			});
		}
		frame.open();
	});

	$input.on('input change', function () {
		var url = $.trim($input.val());
		$preview.attr('src', url).toggle(!!url);
	});
});
