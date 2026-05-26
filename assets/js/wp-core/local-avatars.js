/**
 * AdminKit — local avatars media picker.
 *
 * The avatar bubble itself is the upload target: clicking it (a focusable
 * <button> wrapping the image) opens the WordPress media frame; picking an image
 * stores its attachment id in the hidden field, swaps the preview in, and flips
 * the field to its "filled" state. "Reset to default" clears the upload and
 * reverts the preview to the default (Gravatar / generated face). It shows only
 * when there's an upload to clear. Filled / empty is carried by an `is-filled` /
 * `is-empty` class on the root (CSS owns the visuals).
 *
 * The page-title avatar (the "hero", built by profile-account.js) is wired here
 * too as a SECOND trigger that shares the one hidden input + state — no media
 * frame logic is duplicated. Picking / resetting syncs BOTH previews.
 *
 * i18n labels arrive via `window.AdminKitLocalAvatars` (set by an inline
 * bootstrap). No-op when the field or `wp.media` isn't present. Footer script,
 * loaded only on profile.php / user-edit.php (and only when the user can upload).
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
	var resetBtn = document.getElementById('adminkit-local-avatar-reset');
	var resetInput = document.getElementById('adminkit-local-avatar-reset-input');
	if (!input || !preview || !mediaBtn) { return; }

	var frame = null;
	// The hero's preview <img>, populated by setupHero() so previews stay in sync.
	var heroPreview = null;

	// Set the avatar image everywhere it's shown (field + hero), from one URL.
	function setPreviewSrc(url) {
		if (url) { preview.setAttribute('src', url); }
		else { preview.removeAttribute('src'); }
		if (heroPreview) {
			if (url) { heroPreview.setAttribute('src', url); }
			else { heroPreview.removeAttribute('src'); }
		}
	}

	// Reflect state into the UI. is-filled / is-empty track whether there's an
	// UPLOAD (drives Reset visibility + aria label). The preview always shows the
	// EFFECTIVE avatar (upload OR the Gravatar/generated fallback), so it's only
	// swapped for the glyph when there's genuinely no image URL at all.
	function sync() {
		var hasUpload  = !!input.value;
		var hasPreview = !!preview.getAttribute('src');
		root.classList.toggle('is-filled', hasUpload);
		root.classList.toggle('is-empty', !hasUpload);

		if (hasPreview) {
			preview.removeAttribute('hidden');
			if (placeholder) { placeholder.setAttribute('hidden', ''); }
		} else {
			preview.setAttribute('hidden', '');
			if (placeholder) { placeholder.removeAttribute('hidden'); }
		}
		if (resetBtn) {
			if (hasUpload) { resetBtn.removeAttribute('hidden'); }
			else { resetBtn.setAttribute('hidden', ''); }
		}
		mediaBtn.setAttribute('aria-label', hasUpload ? (L.ariaFill || '') : (L.ariaEmpty || ''));
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
				// An explicit upload supersedes any pending reset intent.
				if (resetInput) { resetInput.value = ''; }
				var size = pickSize(attachment.sizes);
				setPreviewSrc(size ? size.url : (attachment.url || ''));
				sync();
			});
		}
		frame.open();
	}

	// The preview/overlay button is the primary upload target.
	mediaBtn.addEventListener('click', openFrame);

	if (resetBtn) {
		resetBtn.addEventListener('click', function (e) {
			e.preventDefault();
			// Reset to default: clear the upload AND flag the reset so save() clears
			// the user meta server-side — reverting to the real Gravatar /
			// deterministic generated face.
			input.value = '';
			if (resetInput) { resetInput.value = '1'; }
			// Preview the default so the bubble is never left blank.
			setPreviewSrc(L.defaultUrl || '');
			sync();
			// Move focus back to the picker so keyboard users aren't stranded on the
			// now-hidden Reset control.
			mediaBtn.focus();
		});
	}

	// --- page-title "hero" as a second picker trigger -------------------------

	// profile-account.js lifts the native profile-picture avatar into the page
	// header and tags its <img> #ak-hero-avatar. Turn that image into a real
	// <button> (camera overlay + aria-label, like the field) that reuses this
	// brick's frame/state, so clicking the page avatar opens the same picker and
	// keeps the field's hidden input + preview in sync.
	function setupHero() {
		var img = document.getElementById('ak-hero-avatar');
		if (!img || img.dataset.akHero) { return; }
		var host = img.parentNode;
		if (!host) { return; }
		img.dataset.akHero = '1';

		var btn = document.createElement('button');
		btn.type = 'button';
		btn.className = 'ak-profile-picture-hero__btn';
		btn.setAttribute('aria-label', L.heroAria || L.ariaFill || '');

		host.insertBefore(btn, img);
		btn.appendChild(img);

		// Hover/focus camera overlay, mirroring the field's affordance. At the hero's
		// small (48px) size the camera glyph alone reads clearly; the accessible name
		// (the button's aria-label) carries the "Change" intent for AT.
		var overlay = document.createElement('span');
		overlay.className = 'ak-profile-picture-hero__overlay';
		overlay.setAttribute('aria-hidden', 'true');
		overlay.innerHTML = '<svg viewBox="0 0 24 24" focusable="false" aria-hidden="true">'
			+ '<path d="M9 3a1 1 0 0 0-.8.4L7 5H4a2 2 0 0 0-2 2v11a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-3l-1.2-1.6A1 1 0 0 0 15 3H9Zm3 5.5A4.5 4.5 0 1 1 7.5 13 4.5 4.5 0 0 1 12 8.5Zm0 2A2.5 2.5 0 1 0 14.5 13 2.5 2.5 0 0 0 12 10.5Z"/>'
			+ '</svg>';
		btn.appendChild(overlay);

		heroPreview = img;
		btn.addEventListener('click', openFrame);
		// Seed the hero with the field's current effective preview so both match.
		var current = preview.getAttribute('src');
		if (current) { img.setAttribute('src', current); }
	}

	setupHero();

	sync();
})();
