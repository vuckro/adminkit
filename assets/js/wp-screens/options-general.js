/**
 * AdminKit — options-general.php: split the single big .form-table into
 * three themed blocks for readability.
 *
 *   • Site identity        — blogname, blogdescription, siteurl, home
 *   • Account & registration — admin_email, new_admin_email,
 *                              users_can_register, default_role
 *   • Language, date & time  — WPLANG, timezone_string, date_format,
 *                              time_format, start_of_week
 *
 * Routing is by INPUT NAME (locale-proof). Each block gets its own
 * `<h2>` + nested `.form-table` — every `<input>` keeps its `name=` so
 * submission posts the same shape WP expects. Anything WP rendered that
 * we don't route (e.g. Site Icon, or a third-party plugin's row) stays
 * in place above the WP submit button.
 *
 * Defensive: no anti-FOUC hide (the page renders WP-default first, then
 * we re-arrange). If the script bails for any reason (no form, etc.) the
 * page stays usable. Strings ride via `window.AdminKitOptionsGeneral`.
 */
(function () {
	'use strict';

	var form = document.querySelector( '.wrap > form[action="options.php"]' );
	if ( ! form || form.dataset.akGrouped ) { return; }
	form.dataset.akGrouped = '1';

	var S = window.AdminKitOptionsGeneral || {};

	// Field NAMES per block, in display order. Match by `input[name="…"]` so
	// translation-of-labels doesn't break detection. Anything not on the page
	// (e.g. WPLANG on a site without translations, users_can_register on
	// multisite) is silently skipped. `desc` is the short caption rendered
	// between the heading and the card — gives the title breathing room
	// against the card and clarifies the bucket's scope in one line.
	//
	// `site-icon` is its own block between Site identity and Account because
	// the row is a complex media-picker widget (not a flat field), and
	// placing it inside Site identity made that card very tall. Standalone
	// reads as a focused single-control card.
	var BLOCKS = [
		{ id: 'site-identity', title: S.identity || 'Site identity',          desc: S.identityDesc || '', rows: [ 'blogname', 'blogdescription', 'siteurl', 'home' ] },
		{ id: 'site-icon',     title: S.siteIcon || 'Site Icon',              desc: S.siteIconDesc || '', rows: [ 'site_icon' ] },
		{ id: 'account',       title: S.account  || 'Account & registration', desc: S.accountDesc  || '', rows: [ 'admin_email', 'new_admin_email', 'users_can_register', 'default_role' ] },
		{ id: 'locale',        title: S.locale   || 'Language, date & time',  desc: S.localeDesc   || '', rows: [ 'WPLANG', 'timezone_string', 'date_format', 'time_format', 'start_of_week' ] }
	];

	// Find the first .form-table (WP renders all rows in one) as the anchor.
	// Some sites (Site Icon section) have a second form-table — those stay
	// where they are.
	var sourceTable = form.querySelector( ':scope > .form-table' );
	if ( ! sourceTable ) { return; }

	BLOCKS.forEach( function ( b ) {
		var section = document.createElement( 'section' );
		section.className = 'ak-options-block';
		section.id = b.id;

		var heading = document.createElement( 'h2' );
		heading.className = 'ak-options-block__title';
		heading.textContent = b.title;
		section.appendChild( heading );

		// Optional one-line caption under the heading — gives the title
		// visual breathing room against the card and clarifies the block's
		// scope. Skipped when no description string was passed.
		if ( b.desc ) {
			var desc = document.createElement( 'p' );
			desc.className = 'ak-options-block__desc';
			desc.textContent = b.desc;
			section.appendChild( desc );
		}

		var table = document.createElement( 'table' );
		table.className = 'form-table';
		var tbody = document.createElement( 'tbody' );
		table.appendChild( tbody );
		section.appendChild( table );

		// Scoop matching rows from the source table by input name.
		b.rows.forEach( function ( name ) {
			// admin_email_lite_settings etc. — exact match only.
			var input = form.querySelector( '[name="' + name + '"]' );
			if ( ! input ) { return; }
			var tr = input.closest( 'tr' );
			if ( ! tr || tr.parentNode === tbody ) { return; }
			tbody.appendChild( tr );
		} );

		// Only mount the section if at least one row landed in it.
		if ( tbody.children.length > 0 ) {
			sourceTable.parentNode.insertBefore( section, sourceTable );
		}
	} );

	// Remove the source table if every row was scooped. Otherwise leave it
	// in place — there may be third-party rows or future WP fields we don't
	// know about, and they shouldn't disappear.
	var sourceBody = sourceTable.querySelector( 'tbody' );
	if ( sourceBody && ! sourceBody.querySelector( 'tr' ) ) {
		sourceTable.parentNode.removeChild( sourceTable );
	}
})();
