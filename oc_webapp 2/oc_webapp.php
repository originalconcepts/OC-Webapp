<?php
/*
  Plugin Name: OC Webapp
  Plugin URI:  http://originalconcepts.co.il
  Description: Show web pages inside the Original Concepts mobile application (WebView), hide chosen elements in-app, and restrict coupons to app users.
  Author:      Original Concepts
  Author URI:  http://originalconcepts.co.il
  License:     GPLv2+
  Text Domain: oc_webapp
  Version:     1.4
*/

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

define( 'OC_WEBAPP_VERSION', '1.4' );
define( 'OC_WEBAPP_USER_AGENT_SUBSTR', 'originalconcepts/1.0' );

// GitHub repo that publishes releases for auto-updates. EDIT to match yours.
if ( ! defined( 'OC_WEBAPP_GH_USER' ) ) {
	define( 'OC_WEBAPP_GH_USER', 'originalconcepts' );
}
if ( ! defined( 'OC_WEBAPP_GH_REPO' ) ) {
	define( 'OC_WEBAPP_GH_REPO', 'OC-Webapp' );
}

require_once dirname( __FILE__ ) . '/includes/class-oc-coupon-restrictions-admin.php';
require_once dirname( __FILE__ ) . '/includes/class-oc-webapp-updater.php';

/**
 * Plugin settings, with safe defaults. Always returns the three expected keys.
 *
 * @return array{selectors:string,ua:string,header:string}
 */
function oc_webapp_get_settings() {
	$saved = get_option( 'oc_webapp_settings', array() );
	$saved = is_array( $saved ) ? $saved : array();

	return array(
		'selectors' => isset( $saved['oc_webapp_classes'] ) ? (string) $saved['oc_webapp_classes'] : '',
		'ua'        => ( isset( $saved['oc_webapp_ua'] ) && '' !== $saved['oc_webapp_ua'] ) ? (string) $saved['oc_webapp_ua'] : OC_WEBAPP_USER_AGENT_SUBSTR,
		'header'    => isset( $saved['oc_webapp_custom_header_ua'] ) ? (string) $saved['oc_webapp_custom_header_ua'] : '',
	);
}

/**
 * The configured selectors as a clean array. Each selector is reduced to a safe
 * CSS-selector character set so it can never break out of a <style> block.
 *
 * @return string[]
 */
function oc_webapp_get_selectors() {
	$settings = oc_webapp_get_settings();
	if ( '' === trim( $settings['selectors'] ) ) {
		return array();
	}

	$out = array();
	foreach ( explode( ',', $settings['selectors'] ) as $selector ) {
		// Allow only characters that legitimately appear in CSS selectors.
		$selector = preg_replace( '/[^a-zA-Z0-9 ._#\-\[\]=:>~+*"\'()|]/', '', $selector );
		$selector = trim( $selector );
		if ( '' !== $selector ) {
			$out[] = $selector;
		}
	}

	return $out;
}

/**
 * Is the current request coming from inside the app's WebView?
 *
 * Resolved server-side from the (configurable) user-agent substring on the
 * (configurable) request header. This is the public API other plugins can rely
 * on; the result is filterable via `oc_webapp_is_in_app`.
 *
 * @return bool
 */
function oc_webapp_is_in_app() {
	$settings = oc_webapp_get_settings();

	$header_name = $settings['header'] ? $settings['header'] : 'User-Agent';
	$server_key  = 'HTTP_' . strtoupper( str_replace( '-', '_', $header_name ) );

	$in_app = false;
	if ( isset( $_SERVER[ $server_key ] ) ) {
		$value  = wp_unslash( $_SERVER[ $server_key ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$in_app = ( '' !== $settings['ua'] && false !== strpos( (string) $value, $settings['ua'] ) );
	}

	return (bool) apply_filters( 'oc_webapp_is_in_app', $in_app );
}

/* -------------------------------------------------------------------------
 * Front-end: hide chosen elements when viewed inside the app.
 *
 * Detection runs client-side (navigator.userAgent) so it stays correct behind
 * full-page caches, and the `oc-webapp` class is added to <html> in the <head>
 * before the body paints, so there is no flash of the hidden elements.
 * ---------------------------------------------------------------------- */

add_action( 'wp_enqueue_scripts', 'oc_webapp_enqueue_styles' );
function oc_webapp_enqueue_styles() {
	wp_register_style( 'oc-webapp', plugins_url( 'assets/css/styles.css', __FILE__ ), array(), OC_WEBAPP_VERSION );
	wp_enqueue_style( 'oc-webapp' );
}

add_action( 'wp_head', 'oc_webapp_print_head', 1 );
function oc_webapp_print_head() {
	$settings  = oc_webapp_get_settings();
	$selectors = oc_webapp_get_selectors();

	// Tag <html> as in-app as early as possible (cache-safe, no flash).
	echo '<script>(function(){try{var u=navigator.userAgent||navigator.vendor||"";'
		. 'if(u.indexOf(' . wp_json_encode( $settings['ua'] ) . ')!==-1){'
		. 'document.documentElement.className+=" oc-webapp";}}catch(e){}})();</script>' . "\n";

	if ( empty( $selectors ) ) {
		return;
	}

	$rules = array();
	foreach ( $selectors as $selector ) {
		$rules[] = 'html.oc-webapp ' . $selector . '{display:none !important;}';
	}
	echo '<style id="oc-webapp-hide">' . implode( '', $rules ) . "</style>\n"; // phpcs:ignore WordPress.Security.EscapeOutput
}

/* -------------------------------------------------------------------------
 * Admin: settings page (Settings → OC Webapp).
 * ---------------------------------------------------------------------- */

add_action( 'admin_menu', 'oc_webapp_add_admin_menu' );
function oc_webapp_add_admin_menu() {
	add_options_page(
		__( 'Original Concepts App', 'oc_webapp' ),
		__( 'OC Webapp', 'oc_webapp' ),
		'manage_options',
		'oc-webapp-options',
		'oc_webapp_options_page'
	);
}

add_action( 'admin_init', 'oc_webapp_settings_init' );
function oc_webapp_settings_init() {
	register_setting(
		'ocWebappPage',
		'oc_webapp_settings',
		array( 'sanitize_callback' => 'oc_webapp_sanitize_settings' )
	);

	add_settings_section(
		'oc_webapp_section',
		__( 'Settings to fit your pages inside the Original Concepts app', 'oc_webapp' ),
		'__return_false',
		'ocWebappPage'
	);

	add_settings_field(
		'oc_webapp_classes',
		__( 'Comma-separated selectors to hide inside the app', 'oc_webapp' ),
		'oc_webapp_field_classes',
		'ocWebappPage',
		'oc_webapp_section'
	);

	add_settings_field(
		'oc_webapp_ua',
		__( 'App WebView user-agent substring', 'oc_webapp' ),
		'oc_webapp_field_ua',
		'ocWebappPage',
		'oc_webapp_section'
	);

	add_settings_field(
		'oc_webapp_custom_header_ua',
		__( 'Custom HTTP header for the user-agent (optional)', 'oc_webapp' ),
		'oc_webapp_field_header',
		'ocWebappPage',
		'oc_webapp_section'
	);
}

/**
 * Sanitize settings on save.
 *
 * @param mixed $input
 * @return array
 */
function oc_webapp_sanitize_settings( $input ) {
	$input = is_array( $input ) ? $input : array();

	return array(
		'oc_webapp_classes'          => isset( $input['oc_webapp_classes'] ) ? sanitize_text_field( $input['oc_webapp_classes'] ) : '',
		'oc_webapp_ua'               => isset( $input['oc_webapp_ua'] ) ? sanitize_text_field( $input['oc_webapp_ua'] ) : '',
		'oc_webapp_custom_header_ua' => isset( $input['oc_webapp_custom_header_ua'] ) ? sanitize_text_field( $input['oc_webapp_custom_header_ua'] ) : '',
	);
}

function oc_webapp_field_classes() {
	$settings = oc_webapp_get_settings();
	printf(
		'<input type="text" class="regular-text" name="oc_webapp_settings[oc_webapp_classes]" value="%s">',
		esc_attr( $settings['selectors'] )
	);
	echo '<p class="description">' . esc_html__( 'Example: header, .site-footer, #download-app-popup', 'oc_webapp' ) . '</p>';
}

function oc_webapp_field_ua() {
	$settings = oc_webapp_get_settings();
	printf(
		'<input type="text" class="regular-text" name="oc_webapp_settings[oc_webapp_ua]" value="%s" placeholder="%s">',
		esc_attr( $settings['ua'] ),
		esc_attr( OC_WEBAPP_USER_AGENT_SUBSTR )
	);
}

function oc_webapp_field_header() {
	$settings = oc_webapp_get_settings();
	printf(
		'<input type="text" class="regular-text" name="oc_webapp_settings[oc_webapp_custom_header_ua]" value="%s" placeholder="User-Agent">',
		esc_attr( $settings['header'] )
	);
}

function oc_webapp_options_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	?>
	<div class="wrap">
		<h1><?php echo esc_html__( 'Original Concepts App', 'oc_webapp' ); ?></h1>
		<form action="options.php" method="post">
			<?php
			settings_fields( 'ocWebappPage' );
			do_settings_sections( 'ocWebappPage' );
			submit_button();
			?>
		</form>
	</div>
	<?php
}

/* -------------------------------------------------------------------------
 * Boot the coupon "app only" restriction admin/validation.
 * ---------------------------------------------------------------------- */

add_action( 'plugins_loaded', 'oc_webapp_boot' );
function oc_webapp_boot() {
	load_plugin_textdomain( 'oc_webapp', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

	// Auto-update from GitHub releases (same mechanism as the Giorgio plugin).
	new OC_Webapp_Updater( __FILE__, OC_WEBAPP_VERSION, OC_WEBAPP_GH_USER, OC_WEBAPP_GH_REPO );

	if ( class_exists( 'WC_Coupon' ) ) {
		( new OC_Coupon_Restrictions_Admin() )->init();
	}
}
