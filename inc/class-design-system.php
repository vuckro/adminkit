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
	/** @return string CSS with validated values, without a style wrapper. */
	public static function css( $toolbar_only = false ) {
		$settings = AdminKit_Settings::sanitize_design_system( AdminKit_Settings::get( 'design_system' ) );
		$selector = $toolbar_only ? ':root #wpadminbar' : ':root,:root #wpadminbar';
		$vars = '--ak-type-scale:' . $settings['type_scale'] . ';--ak-space-scale:' . $settings['space_scale'] . ';--ak-radius-scale:' . $settings['radius_scale'] . ';';
		$vars .= '--wk-radius-scale:var(--ak-radius-scale);';
		foreach ( array( 's' => 8, 'm' => 12, 'l' => 16, 'xl' => 24 ) as $size => $pixels ) {
			$vars .= '--ak-radius-' . $size . ':calc(' . $pixels . 'px * var(--ak-radius-scale));';
		}
		$vars .= '--ak-card-radius:var(--ak-radius-l);';
		// Font is also scoped explicitly on the toolbar (its upstream sheet resets it).
		$fonts = array( 'humanist' => '"Trebuchet MS",Verdana,sans-serif', 'classic' => 'Arial,Helvetica,sans-serif', 'system' => 'ui-sans-serif,system-ui,-apple-system,"Segoe UI",sans-serif' );
		$font = AdminKit_Settings::get( 'ui_font' );
		$family = isset( $fonts[ $font ] ) ? $fonts[ $font ] : $fonts['humanist'];
		$vars .= '--ak-font-body:' . $family . ';--wk-font-sans:' . $family . ';';
		$shadows = array( 'flat' => 'none', 'subtle' => 'var(--wk-elevation-1)', 'raised' => 'var(--wk-elevation-2)' );
		$vars .= '--ak-shadow-subtle:' . $shadows[ $settings['elevation'] ] . ';--ak-design-elevation:' . $shadows[ $settings['elevation'] ] . ';';
		$css = $selector . '{' . $vars . '}';
		foreach ( array( 'light', 'dark' ) as $mode ) {
			$palette = array();
			if ( 'custom' === AdminKit_Settings::accent_source() ) {
				$palette = self::custom_neutrals( (string) AdminKit_Settings::get( 'brand_accent' ), $mode );
			}
			// Explicit role edits always win; generated values are never persisted.
			$palette = array_merge( $palette, $settings['colors'][ $mode ] );
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

	/** One recipe shared with the settings editor via its boot data. Percentages
	 * stay low so the custom hue gently colors neutrals without flattening roles.
	 */
	public static function neutral_recipe() {
		return array(
			'light' => array( 'bg' => array( '#f9fafc', 3 ), 'surface' => array( '#ffffff', 2 ), 'text' => array( '#0c0d0f', 12 ), 'text-muted' => array( '#35383d', 10 ), 'border' => array( '#e4e6e9', 12 ) ),
			'dark' => array( 'bg' => array( '#161719', 4 ), 'surface' => array( '#202124', 5 ), 'text' => array( '#f7f8fa', 8 ), 'text-muted' => array( '#dbdee2', 8 ), 'border' => array( '#37393c', 14 ) ),
		);
	}

	/** Pure, deterministic sRGB interpolation. Invalid seeds leave the baseline. */
	public static function custom_neutrals( $hex, $mode ) {
		$hex = is_string( $hex ) ? sanitize_hex_color( $hex ) : null;
		if ( ! $hex ) { return array(); }
		if ( 4 === strlen( $hex ) ) { $hex = '#' . $hex[1] . $hex[1] . $hex[2] . $hex[2] . $hex[3] . $hex[3]; }
		$recipe = self::neutral_recipe();
		$palette = array();
		foreach ( $recipe[ 'dark' === $mode ? 'dark' : 'light' ] as $role => $entry ) {
			$rgb = array();
			for ( $i = 1; $i <= 5; $i += 2 ) {
				$base = hexdec( substr( $entry[0], $i, 2 ) );
				$seed = hexdec( substr( $hex, $i, 2 ) );
				$rgb[] = (int) round( $base + ( $seed - $base ) * $entry[1] / 100 );
			}
			$palette[ $role ] = sprintf( '#%02x%02x%02x', $rgb[0], $rgb[1], $rgb[2] );
		}
		return $palette;
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
			$hover = array_map( static function ( $c ) use ( $dark ) { return (int) round( $c * .82 + ( $dark ? 255 * .18 : 0 ) ); }, $rgb );
			$css .= '--ak-primary-rgb:' . implode( ',', $rgb ) . ';--ak-primary-hover-rgb:' . implode( ',', $hover ) . ';';
			$css .= '--ak-primary-hover:color-mix(in srgb,var(--ak-primary) 82%,' . $mix . ');--ak-primary-subtle:color-mix(in srgb,var(--ak-primary) 12%,var(--ak-surface));--ak-primary-text:color-mix(in srgb,var(--ak-primary) 30%,var(--ak-text));--ak-focus:var(--ak-primary-text);--wk-accent-hover:var(--ak-primary-hover);--wk-accent-soft:var(--ak-primary-subtle);--wk-accent-text:var(--ak-primary-text);--wk-focus-ring:var(--ak-focus);';
			$css .= '--ak-on-accent:' . ( isset( $palette['on-accent'] ) ? $palette['on-accent'] : AdminKit_Assets::contrast_text_for( $hex ) ) . ';';
			$css .= '--ak-on-accent-hover:' . AdminKit_Assets::contrast_text_for( sprintf( '#%02x%02x%02x', $hover[0], $hover[1], $hover[2] ) ) . ';';
		}
		foreach ( array( 'success-text' => 'success', 'warning-text' => 'warning', 'danger-text' => 'error', 'info-text' => 'info' ) as $role => $token ) {
			if ( isset( $palette[ $role ] ) || isset( $palette['surface'] ) ) { $css .= '--ak-' . $token . '-subtle:color-mix(in srgb,var(--ak-' . $token . ') 10%,var(--ak-surface));'; }
		}
		return $css;
	}
}
