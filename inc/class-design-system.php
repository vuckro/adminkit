<?php
/**
 * Compile the saved design foundations into shared semantic tokens.
 * No user CSS, selectors or property names are accepted. All contexts, including
 * the editor iframe, consume this same compiler; frontend scope is toolbar-only.
 *
 * @package AdminKit
 */
defined( 'ABSPATH' ) || exit;

class AdminKit_Design_System {
	/**
	 * Supported UI font presets.
	 * `google` holds the Google Fonts CSS2 family parameter (null for self-hosted/system fonts).
	 *
	 * @return array<string, array{label: string, stack: string, google: string|null}>
	 */
	public static function fonts() {
		return array(
			'geist'             => array(
				'label'  => 'Geist (AdminKit)',
				'stack'  => '"Geist",ui-sans-serif,system-ui,-apple-system,"Segoe UI",Roboto,sans-serif',
				'google' => null,
			),
			'system'            => array(
				'label'  => 'System UI',
				'stack'  => 'ui-sans-serif,system-ui,-apple-system,"Segoe UI",Roboto,sans-serif',
				'google' => null,
			),
			'inter'             => array(
				'label'  => 'Inter (Google Fonts)',
				'stack'  => '"Inter",ui-sans-serif,system-ui,-apple-system,sans-serif',
				'google' => 'Inter:wght@400;500;600;700',
			),
			'plus-jakarta-sans' => array(
				'label'  => 'Plus Jakarta Sans (Google Fonts)',
				'stack'  => '"Plus Jakarta Sans",ui-sans-serif,system-ui,-apple-system,sans-serif',
				'google' => 'Plus+Jakarta+Sans:wght@400;500;600;700',
			),
			'dm-sans'           => array(
				'label'  => 'DM Sans (Google Fonts)',
				'stack'  => '"DM Sans",ui-sans-serif,system-ui,-apple-system,sans-serif',
				'google' => 'DM+Sans:wght@400;500;600;700',
			),
			'manrope'           => array(
				'label'  => 'Manrope (Google Fonts)',
				'stack'  => '"Manrope",ui-sans-serif,system-ui,-apple-system,sans-serif',
				'google' => 'Manrope:wght@400;500;600;700',
			),
			'outfit'            => array(
				'label'  => 'Outfit (Google Fonts)',
				'stack'  => '"Outfit",ui-sans-serif,system-ui,-apple-system,sans-serif',
				'google' => 'Outfit:wght@400;500;600;700',
			),
			'poppins'           => array(
				'label'  => 'Poppins (Google Fonts)',
				'stack'  => '"Poppins",ui-sans-serif,system-ui,-apple-system,sans-serif',
				'google' => 'Poppins:wght@400;500;600;700',
			),
			'space-grotesk'     => array(
				'label'  => 'Space Grotesk (Google Fonts)',
				'stack'  => '"Space Grotesk",ui-sans-serif,system-ui,-apple-system,sans-serif',
				'google' => 'Space+Grotesk:wght@400;500;600;700',
			),
			'ibm-plex-sans'     => array(
				'label'  => 'IBM Plex Sans (Google Fonts)',
				'stack'  => '"IBM Plex Sans",ui-sans-serif,system-ui,-apple-system,sans-serif',
				'google' => 'IBM+Plex+Sans:wght@400;500;600;700',
			),
		);
	}

	/** @return string CSS with validated values, without a style wrapper. */
	public static function css( $toolbar_only = false ) {
		$settings = AdminKit_Settings::sanitize_design_system( AdminKit_Settings::get( 'design_system' ) );
		$selector = $toolbar_only ? ':root #wpadminbar' : ':root,:root #wpadminbar';
		$vars = '--ak-type-scale:' . $settings['type_scale'] . ';--ak-radius-scale:' . $settings['radius_scale'] . ';';
		$vars .= '--wk-radius-scale:var(--ak-radius-scale);--ak-border-width:' . $settings['border_width'] . 'px;';
		foreach ( array( 's' => 8, 'm' => 12, 'l' => 16, 'xl' => 24 ) as $size => $pixels ) {
			$vars .= '--ak-radius-' . $size . ':calc(' . $pixels . 'px * var(--ak-radius-scale));';
		}
		$highlight = $settings['components']['buttons']['highlight'];
		$empty_shadow = 'inset 0 0 0 transparent';
		$vars .= '--ak-btn-primary-elevation:var(' . ( 'always' === $highlight ? '--wk-elevation-1' : '--wk-elevation-1-shadow' ) . ');';
		$vars .= '--ak-btn-primary-elevation-hover:var(' . ( 'off' === $highlight ? '--wk-elevation-1-shadow' : '--wk-elevation-1' ) . ');';
		$vars .= '--ak-btn-primary-highlight:' . ( 'always' === $highlight ? 'var(--ak-btn-highlight-effect)' : $empty_shadow ) . ';';
		$vars .= '--ak-btn-primary-highlight-hover:' . ( 'off' === $highlight ? $empty_shadow : 'var(--ak-btn-highlight-effect)' ) . ';';
		// Font is also scoped explicitly on the toolbar (its upstream sheet resets it).
		$fonts  = self::fonts();
		$font   = (string) AdminKit_Settings::get( 'ui_font' );
		$family = isset( $fonts[ $font ]['stack'] ) ? $fonts[ $font ]['stack'] : $fonts['geist']['stack'];
		$vars  .= '--ak-font-body:' . $family . ';--wk-font-sans:' . $family . ';';
		$shadows = array( 'flat' => 'none', 'subtle' => 'var(--wk-elevation-1)', 'raised' => 'var(--wk-elevation-2)' );
		// Default preserves the theme-specific contact shadow owned by card surfaces.
		if ( isset( $shadows[ $settings['elevation'] ] ) ) {
			$vars .= '--ak-design-elevation:' . $shadows[ $settings['elevation'] ] . ';';
		}
		$css = $selector . '{' . $vars . '}';
		foreach ( array( 'light', 'dark' ) as $mode ) {
			$palette = $settings['colors'][ $mode ];
			if ( 'custom' === AdminKit_Settings::accent_source() ) {
				$accent = sanitize_hex_color( (string) AdminKit_Settings::get( 'brand_accent' ) );
				if ( $accent ) { $palette = array_merge( array( 'accent' => $accent ), $palette ); }
			}
			if ( ! $palette ) { continue; }
			$root = ':root[data-adminkit-theme="' . $mode . '"]';
			// Match the specificity of explicit custom/provider dark presets.
			$targets = array( $root . ' #wpadminbar', $root . '[data-adminkit-accent] #wpadminbar' );
			if ( ! $toolbar_only ) { $targets[] = $root; $targets[] = $root . '[data-adminkit-accent]'; }
			$target = implode( ',', $targets );
			$css .= $target . '{' . self::palette( $palette, 'dark' === $mode ) . '}';
		}
		return $css;
	}

	/** Derive interactive states from the editable roles, keeping all aliases in sync. */
	private static function palette( array $palette, $dark ) {
		$map = array( 'bg' => 'bg', 'surface' => 'surface', 'text' => 'text', 'text-muted' => 'text-muted', 'border' => 'border', 'accent' => 'primary', 'on-accent' => 'on-accent', 'success-text' => 'success', 'warning-text' => 'warning', 'danger-text' => 'error', 'info-text' => 'info' );
		$css = '';
		foreach ( $palette as $role => $value ) {
			$css .= '--ak-' . $map[ $role ] . ':' . $value . ';--wk-' . $role . ':' . $value . ';';
		}
		if ( isset( $palette['text'] ) ) { $css .= '--ak-heading:var(--ak-text);'; }
		if ( isset( $palette['text-muted'] ) ) { $css .= '--ak-text-subtle:var(--ak-text-muted);--ak-text-disabled:color-mix(in srgb,var(--ak-text-muted) 65%,var(--ak-surface));'; }
		if ( isset( $palette['surface'] ) || isset( $palette['bg'] ) ) {
			$css .= '--ak-elevated:var(--ak-surface);--ak-input-bg:var(--ak-surface);--ak-btn-bg:var(--ak-surface);--ak-surface-muted:color-mix(in srgb,var(--ak-text) 4%,var(--ak-surface));--ak-hover-bg:color-mix(in srgb,var(--ak-text) 7%,var(--ak-surface));--ak-btn-hover-bg:var(--ak-hover-bg);--wk-surface-raised:var(--ak-elevated);--wk-surface-sunken:var(--ak-surface-muted);--wk-surface-hover:var(--ak-hover-bg);';
		}
		if ( isset( $palette['border'] ) ) { $css .= '--ak-border-strong:color-mix(in srgb,var(--ak-border) 65%,var(--ak-text));--ak-btn-hover-border:var(--ak-border-strong);--wk-border-strong:var(--ak-border-strong);'; }
		if ( isset( $palette['accent'] ) ) {
			$mix = $dark ? 'white' : 'black';
			$hex = $palette['accent'];
			if ( 4 === strlen( $hex ) ) { $hex = '#' . $hex[1] . $hex[1] . $hex[2] . $hex[2] . $hex[3] . $hex[3]; }
			$rgb = array( hexdec( substr( $hex, 1, 2 ) ), hexdec( substr( $hex, 3, 2 ) ), hexdec( substr( $hex, 5, 2 ) ) );
			$foreground = isset( $palette['on-accent'] ) ? $palette['on-accent'] : AdminKit_Assets::contrast_text_for( $hex );
			$mix = '#000000' === $foreground ? 'white' : 'black';
			$lighten = 'white' === $mix;
			$hover = array_map( static function ( $c ) use ( $lighten ) { return (int) round( $c * .82 + ( $lighten ? 255 * .18 : 0 ) ); }, $rgb );
			$css .= '--ak-primary-rgb:' . implode( ',', $rgb ) . ';--ak-primary-hover-rgb:' . implode( ',', $hover ) . ';';
			$css .= '--ak-primary-hover:color-mix(in srgb,var(--ak-primary) 82%,' . $mix . ');--ak-primary-subtle:color-mix(in srgb,var(--ak-primary) 12%,var(--ak-surface));--ak-primary-text:color-mix(in srgb,var(--ak-primary) 30%,var(--ak-text));--ak-focus:var(--ak-primary-text);--wk-accent-hover:var(--ak-primary-hover);--wk-accent-soft:var(--ak-primary-subtle);--wk-accent-text:var(--ak-primary-text);--wk-focus-ring:var(--ak-focus);';
			$css .= '--ak-on-accent:' . $foreground . ';';
			$css .= '--ak-on-accent-hover:' . $foreground . ';';
		}
		foreach ( array( 'success-text' => 'success', 'warning-text' => 'warning', 'danger-text' => 'error', 'info-text' => 'info' ) as $role => $token ) {
			if ( isset( $palette[ $role ] ) || isset( $palette['surface'] ) ) { $css .= '--ak-' . $token . '-subtle:color-mix(in srgb,var(--ak-' . $token . ') 10%,var(--ak-surface));'; }
		}
		return $css;
	}
}
