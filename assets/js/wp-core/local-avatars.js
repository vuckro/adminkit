/**
 * AdminKit — local avatars media picker.
 *
 * The avatar bubble itself is the upload target: clicking it (a focusable
 * <button> wrapping the image) opens the WordPress media frame; picking an image
 * stores its attachment id in the hidden field, swaps the preview in, and flips
 * the field to its "filled" state. "Remove" clears the id + preview and reverts
 * to the empty placeholder. Filled / empty is carried by an `is-filled` /
 * `is-empty` class on the root (CSS owns the visuals). i18n labels arrive via
 * `window.AdminKitLocalAvatars` (set by an inline bootstrap). No-op when the
 * field or `wp.media` isn't present. Footer script, loaded only on profile.php /
 * user-edit.php (and only when the user can upload files).
 */
(function () {
	var root = document.getElementById('adminkit-local-avatar');
	if (!root || root.dataset.akWired) { return; }
	if (!window.wp || !window.wp.media) { return; }
	root.dataset.akWired = '1';

	var L = window.AdminKitLocalAvatars || {};
	var input = document.getElementById('adminkit-local-avatar-input');
	var preview = document.getElementById('adminkit-local-avatar-preview');
	var placeholder = document.getElementById('adminkit-local-avatar-placeholder');
	var mediaBtn = document.getElementById('adminkit-local-avatar-btn');
	var removeBtn = document.getElementById('adminkit-local-avatar-remove');
	if (!input || !preview || !mediaBtn) { return; }

	var frame = null;

	// Reflect the current state into the UI: toggle the root's filled/empty class
	// (CSS shows the preview vs. the placeholder), show/hide Remove, and swap the
	// button's accessible name to match.
	function sync() {
		var hasImage = !!input.value && !!preview.getAttribute('src');
		root.classList.toggle('is-filled', hasImage);
		root.classList.toggle('is-empty', !hasImage);

		if (hasImage) {
			preview.removeAttribute('hidden');
			if (placeholder) { placeholder.setAttribute('hidden', ''); }
			if (removeBtn) { removeBtn.removeAttribute('hidden'); }
			if (L.ariaFill) { mediaBtn.setAttribute('aria-label', L.ariaFill); }
		} else {
			preview.setAttribute('hidden', '');
			preview.removeAttribute('src');
			if (placeholder) { placeholder.removeAttribute('hidden'); }
			if (removeBtn) { removeBtn.setAttribute('hidden', ''); }
			if (L.ariaEmpty) { mediaBtn.setAttribute('aria-label', L.ariaEmpty); }
		}
	}

	function pickSize(sizes) {
		if (!sizes) { return null; }
		return sizes.thumbnail || sizes.medium || sizes.full || null;
	}

	function openFrame(e) {
		if (e) { e.preventDefault(); }
		if (!frame) {
			frame = window.wp.media({
				title: L.title || '',
				button: { text: L.button || '' },
				multiple: false,
				library: { type: 'image' }
			});
			frame.on('select', function () {
				var attachment = frame.state().get('selection').first().toJSON();
				if (!attachment || !attachment.id) { return; }
				input.value = attachment.id;
				var size = pickSize(attachment.sizes);
				preview.setAttribute('src', size ? size.url : (attachment.url || ''));
				sync();
			});
		}
		frame.open();
	}

	// The preview/overlay button is the primary upload target.
	mediaBtn.addEventListener('click', openFrame);

	if (removeBtn) {
		removeBtn.addEventListener('click', function (e) {
			e.preventDefault();
			input.value = '';
			preview.removeAttribute('src');
			sync();
			// Move focus back to the now-empty target so keyboard users aren't
			// stranded on the hidden Remove control.
			mediaBtn.focus();
		});
	}

	sync();
})();
