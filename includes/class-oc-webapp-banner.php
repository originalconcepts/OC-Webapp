<?php
/**
 * "Download the app" banner: a small bar that invites visitors to install the
 * app, with the app icon, a title, a short line of text, a small download
 * button and a close (X) button.
 *
 * The button links to the store that matches the visitor's phone (App Store on
 * iPhone/iPad, Google Play on Android); on a desktop both stores are offered.
 *
 * Cache-safe: the same hidden markup is printed on every page and a tiny inline
 * script decides in the browser whether to show it and where, so one cached
 * page works for phones, desktops and the app's WebView alike. Per context
 * (mobile browser / desktop / inside the app) the admin picks Hidden, Top of the
 * page or Bottom of the screen. Default: mobile browser only, at the top.
 *
 * @package OC_Webapp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

class OC_Webapp_Banner {

	const OPTION = 'oc_webapp_banner';
	const PAGE   = 'ocWebappPage';

	/** @var bool The banner prints once per page (wp_body_open, else wp_footer). */
	private $printed = false;

	/**
	 * Register hooks.
	 */
	public function init() {
		add_action( 'admin_init', array( $this, 'register_settings' ), 20 );
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_assets' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
		// Right after <body> so a top banner is in place before the page paints;
		// wp_footer is the fallback for themes that don't call wp_body_open().
		add_action( 'wp_body_open', array( $this, 'print_banner' ), 5 );
		add_action( 'wp_footer', array( $this, 'print_banner' ), 5 );
	}

	/* ---------------------------------------------------------------------
	 * Settings.
	 * ------------------------------------------------------------------ */

	/**
	 * @return array
	 */
	public static function defaults() {
		return array(
			'enabled'     => '0',
			'pos_mobile'  => 'top',
			'pos_desktop' => 'off',
			'pos_app'     => 'off',
			'ios_url'     => '',
			'android_url' => '',
			'icon'        => '',
			'title'       => '',
			'text'        => '',
			'button'      => '',
			'color'       => '#007aff',
			'days'        => 7,
			'rating'      => '1',
		);
	}

	/**
	 * Saved settings merged over the defaults.
	 *
	 * @return array
	 */
	public static function get_settings() {
		$saved = get_option( self::OPTION, array() );
		return array_merge( self::defaults(), is_array( $saved ) ? $saved : array() );
	}

	/**
	 * Where the banner may appear, keyed by setting name.
	 *
	 * @return array
	 */
	private static function contexts() {
		return array(
			'pos_mobile'  => __( 'Mobile browser', 'oc_webapp' ),
			'pos_desktop' => __( 'Desktop', 'oc_webapp' ),
			'pos_app'     => __( 'Inside the app', 'oc_webapp' ),
		);
	}

	/**
	 * @return array
	 */
	private static function positions() {
		return array(
			'off'    => __( 'Hidden', 'oc_webapp' ),
			'top'    => __( 'Top of the page', 'oc_webapp' ),
			'bottom' => __( 'Bottom of the screen', 'oc_webapp' ),
		);
	}

	/**
	 * Settings section + fields on Settings → OC Webapp.
	 */
	public function register_settings() {
		register_setting(
			self::PAGE,
			self::OPTION,
			array( 'sanitize_callback' => array( $this, 'sanitize' ) )
		);

		add_settings_section(
			'oc_webapp_banner_section',
			__( 'Download-app banner', 'oc_webapp' ),
			array( $this, 'section_intro' ),
			self::PAGE
		);

		$fields = array(
			'enabled'     => __( 'Enable', 'oc_webapp' ),
			'positions'   => __( 'Where to show', 'oc_webapp' ),
			'ios_url'     => __( 'App Store link (iPhone)', 'oc_webapp' ),
			'android_url' => __( 'Google Play link (Android)', 'oc_webapp' ),
			'icon'        => __( 'App icon', 'oc_webapp' ),
			'title'       => __( 'Title', 'oc_webapp' ),
			'text'        => __( 'Text', 'oc_webapp' ),
			'button'      => __( 'Button text', 'oc_webapp' ),
			'rating'      => __( 'Rating stars', 'oc_webapp' ),
			'color'       => __( 'Button color', 'oc_webapp' ),
			'days'        => __( 'Show again after closing', 'oc_webapp' ),
		);
		foreach ( $fields as $key => $label ) {
			add_settings_field(
				'oc_webapp_banner_' . $key,
				$label,
				array( $this, 'field_' . $key ),
				self::PAGE,
				'oc_webapp_banner_section'
			);
		}
	}

	/**
	 * Sanitize on save.
	 *
	 * @param mixed $input
	 * @return array
	 */
	public function sanitize( $input ) {
		$input = is_array( $input ) ? $input : array();
		$d     = self::defaults();
		$out   = array(
			'enabled' => empty( $input['enabled'] ) ? '0' : '1',
			'rating'  => empty( $input['rating'] ) ? '0' : '1',
		);

		foreach ( array_keys( self::contexts() ) as $key ) {
			$value       = isset( $input[ $key ] ) ? sanitize_key( $input[ $key ] ) : '';
			$out[ $key ] = array_key_exists( $value, self::positions() ) ? $value : $d[ $key ];
		}
		foreach ( array( 'ios_url', 'android_url', 'icon' ) as $key ) {
			$out[ $key ] = isset( $input[ $key ] ) ? esc_url_raw( trim( (string) $input[ $key ] ) ) : '';
		}
		foreach ( array( 'title', 'text', 'button' ) as $key ) {
			$out[ $key ] = isset( $input[ $key ] ) ? sanitize_text_field( $input[ $key ] ) : '';
		}

		$color        = isset( $input['color'] ) ? sanitize_hex_color( $input['color'] ) : '';
		$out['color'] = $color ? $color : $d['color'];
		$out['days']  = isset( $input['days'] ) ? min( 365, absint( $input['days'] ) ) : $d['days'];

		return $out;
	}

	public function section_intro() {
		echo '<p>' . esc_html__( 'A small bar that invites visitors to download the app. It detects whether the visitor is on iPhone or Android and links to the matching store.', 'oc_webapp' ) . '</p>';
	}

	/**
	 * @param string $key
	 * @return string
	 */
	private function name( $key ) {
		return self::OPTION . '[' . $key . ']';
	}

	public function field_enabled() {
		$s = self::get_settings();
		printf(
			'<label><input type="checkbox" name="%s" value="1" %s> %s</label>',
			esc_attr( $this->name( 'enabled' ) ),
			checked( '1', $s['enabled'], false ),
			esc_html__( 'Show the download-app banner', 'oc_webapp' )
		);
	}

	public function field_positions() {
		$s = self::get_settings();
		echo '<fieldset>';
		foreach ( self::contexts() as $key => $label ) {
			printf( '<p><label style="display:inline-block;min-width:130px">%s</label> <select name="%s">', esc_html( $label ), esc_attr( $this->name( $key ) ) );
			foreach ( self::positions() as $value => $text ) {
				printf( '<option value="%s" %s>%s</option>', esc_attr( $value ), selected( $s[ $key ], $value, false ), esc_html( $text ) );
			}
			echo '</select></p>';
		}
		echo '</fieldset>';
	}

	/**
	 * @param string $key
	 * @param string $placeholder
	 */
	private function url_input( $key, $placeholder ) {
		$s = self::get_settings();
		printf(
			'<input type="url" class="regular-text code" dir="ltr" name="%s" value="%s" placeholder="%s">',
			esc_attr( $this->name( $key ) ),
			esc_attr( $s[ $key ] ),
			esc_attr( $placeholder )
		);
	}

	public function field_ios_url() {
		$this->url_input( 'ios_url', 'https://apps.apple.com/app/id0000000000' );
	}

	public function field_android_url() {
		$this->url_input( 'android_url', 'https://play.google.com/store/apps/details?id=com.example.app' );
		echo '<p class="description">' . esc_html__( 'On a phone only the button of the matching store is shown. If that store\'s link is empty, the banner is not shown on that phone.', 'oc_webapp' ) . '</p>';
	}

	public function field_icon() {
		$s       = self::get_settings();
		$preview = $s['icon'] ? $s['icon'] : get_site_icon_url( 128 );
		printf(
			'<img id="oc-ab-icon-preview" src="%s" alt="" width="48" height="48" style="border-radius:11px;vertical-align:middle;object-fit:cover;margin-inline-end:8px;%s">',
			esc_url( $preview ),
			$preview ? '' : 'display:none'
		);
		printf(
			'<input type="url" id="oc-ab-icon" class="regular-text code" dir="ltr" name="%s" value="%s"> ',
			esc_attr( $this->name( 'icon' ) ),
			esc_attr( $s['icon'] )
		);
		printf(
			'<button type="button" class="button" id="oc-ab-icon-pick" data-title="%s" data-button="%s">%s</button>',
			esc_attr__( 'App icon', 'oc_webapp' ),
			esc_attr__( 'Use this image', 'oc_webapp' ),
			esc_html__( 'Choose image', 'oc_webapp' )
		);
		echo '<p class="description">' . esc_html__( 'Square image. If empty, the site icon is used.', 'oc_webapp' ) . '</p>';
	}

	/**
	 * @param string $key
	 * @param string $default_text
	 */
	private function text_input( $key, $default_text ) {
		$s = self::get_settings();
		printf(
			'<input type="text" class="regular-text" name="%s" value="%s" placeholder="%s">',
			esc_attr( $this->name( $key ) ),
			esc_attr( $s[ $key ] ),
			esc_attr( $default_text )
		);
	}

	public function field_title() {
		$this->text_input( 'title', self::default_text( 'title' ) );
	}

	public function field_text() {
		$this->text_input( 'text', self::default_text( 'text' ) );
	}

	public function field_button() {
		$this->text_input( 'button', self::default_text( 'button' ) );
		echo '<p class="description">' . esc_html__( 'Leave a text field empty to use the default shown in it.', 'oc_webapp' ) . '</p>';
	}

	public function field_rating() {
		$s = self::get_settings();
		printf(
			'<label><input type="checkbox" name="%s" value="1" %s> %s</label>',
			esc_attr( $this->name( 'rating' ) ),
			checked( '1', $s['rating'], false ),
			esc_html__( 'Show the app\'s store rating under the text', 'oc_webapp' )
		);
		if ( '1' !== $s['enabled'] || '1' !== $s['rating'] ) {
			return;
		}

		// Status per store. Fetch now if the saved data is for other links.
		$stored = OC_Webapp_Ratings::stored();
		foreach ( array( 'ios' => $s['ios_url'], 'android' => $s['android_url'] ) as $os => $url ) {
			if ( '' !== $url && ( ! isset( $stored[ $os ]['url'] ) || $stored[ $os ]['url'] !== $url ) ) {
				$stored = OC_Webapp_Ratings::refresh();
				break;
			}
		}

		$lines = array();
		foreach ( array( 'ios' => 'App Store', 'android' => 'Google Play' ) as $os => $store ) {
			if ( empty( $stored[ $os ] ) ) {
				continue;
			}
			$r = $stored[ $os ];
			switch ( $r['status'] ) {
				case 'ok':
					/* translators: 1: average rating, 2: number of ratings */
					$text = sprintf( __( '%1$s ★ (%2$s ratings)', 'oc_webapp' ), number_format_i18n( $r['rating'], 1 ), number_format_i18n( $r['count'] ) );
					break;
				case 'unsupported':
					$text = __( 'not a store link (e.g. TestFlight), so there is no rating', 'oc_webapp' );
					break;
				case 'error':
					$text = __( 'could not reach the store, will try again', 'oc_webapp' );
					break;
				default:
					$text = __( 'no ratings yet', 'oc_webapp' );
			}
			$lines[] = '<strong>' . esc_html( $store ) . ':</strong> ' . esc_html( $text );
		}
		if ( $lines ) {
			echo '<p class="description">' . implode( '<br>', $lines ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above.
		}
		echo '<p class="description">' . esc_html__( 'Read from the stores automatically once a day. Stars appear only once the app has ratings.', 'oc_webapp' ) . '</p>';
	}

	public function field_color() {
		$s = self::get_settings();
		printf( '<input type="color" name="%s" value="%s">', esc_attr( $this->name( 'color' ) ), esc_attr( $s['color'] ) );
	}

	public function field_days() {
		$s = self::get_settings();
		printf(
			'<input type="number" class="small-text" min="0" max="365" name="%s" value="%d"> %s',
			esc_attr( $this->name( 'days' ) ),
			(int) $s['days'],
			esc_html__( 'days (0 = on the next page)', 'oc_webapp' )
		);
	}

	/**
	 * Media picker for the app icon, on our settings page only.
	 *
	 * @param string $hook
	 */
	public function admin_assets( $hook ) {
		if ( 'settings_page_oc-webapp-options' !== $hook ) {
			return;
		}
		wp_enqueue_media();
		wp_enqueue_script( 'oc-webapp-banner-admin', plugins_url( 'assets/js/banner-admin.js', OC_WEBAPP_FILE ), array( 'jquery' ), OC_WEBAPP_VERSION, true );
	}

	/* ---------------------------------------------------------------------
	 * Front-end.
	 * ------------------------------------------------------------------ */

	/**
	 * Built-in texts used when the admin leaves a field empty.
	 *
	 * @param string $key title|text|button
	 * @return string
	 */
	private static function default_text( $key ) {
		switch ( $key ) {
			case 'title':
				return __( 'Download the app', 'oc_webapp' );
			case 'text':
				return __( 'Faster, easier shopping from your phone', 'oc_webapp' );
			default:
				return __( 'Download', 'oc_webapp' );
		}
	}

	/**
	 * Should the (hidden) banner markup be printed on this request at all? Whether
	 * it is actually shown is decided client-side.
	 *
	 * @return bool
	 */
	private function should_render() {
		if ( is_admin() || wp_doing_ajax() || is_feed() || is_embed() ) {
			return false;
		}
		$s = self::get_settings();
		if ( '1' !== $s['enabled'] || ( '' === $s['ios_url'] && '' === $s['android_url'] ) ) {
			return false;
		}
		if ( 'off' === $s['pos_mobile'] && 'off' === $s['pos_desktop'] && 'off' === $s['pos_app'] ) {
			return false;
		}

		/**
		 * Filter: return false to suppress the banner on a request (e.g. checkout).
		 *
		 * @param bool  $show
		 * @param array $settings
		 */
		return (bool) apply_filters( 'oc_webapp_show_banner', true, $s );
	}

	public function enqueue() {
		if ( $this->should_render() ) {
			wp_enqueue_style( 'oc-webapp-banner', plugins_url( 'assets/css/banner.css', OC_WEBAPP_FILE ), array(), OC_WEBAPP_VERSION );
		}
	}

	public function print_banner() {
		if ( $this->printed || ! $this->should_render() ) {
			return;
		}
		$this->printed = true;

		$s      = self::get_settings();
		$icon   = $s['icon'] ? $s['icon'] : get_site_icon_url( 128 );
		$title  = '' !== $s['title'] ? $s['title'] : self::default_text( 'title' );
		$text   = '' !== $s['text'] ? $s['text'] : self::default_text( 'text' );
		$button = '' !== $s['button'] ? $s['button'] : self::default_text( 'button' );
		$main   = oc_webapp_get_settings();
		$config = array(
			'mobile'  => $s['pos_mobile'],
			'desktop' => $s['pos_desktop'],
			'app'     => $s['pos_app'],
			'days'    => (int) $s['days'],
			'ua'      => $main['ua'], // App WebView user-agent substring.
		);

		$stores = array(
			'ios'     => array( $s['ios_url'], 'App Store' ),
			'android' => array( $s['android_url'], 'Google Play' ),
		);

		// Store rating: each phone sees its own store's; desktop sees the one
		// with more ratings.
		$ratings = '1' === $s['rating'] ? OC_Webapp_Ratings::for_display( $s ) : array();
		$desktop = '';
		foreach ( $ratings as $os => $r ) {
			if ( '' === $desktop || $r['count'] > $ratings[ $desktop ]['count'] ) {
				$desktop = $os;
			}
		}
		?>
<div id="oc-app-banner" class="oc-ab" role="region" aria-label="<?php echo esc_attr( $title ); ?>" style="--oc-ab-color:<?php echo esc_attr( $s['color'] ); ?>" data-oc-ab="<?php echo esc_attr( wp_json_encode( $config ) ); ?>" hidden>
	<?php if ( $icon ) : ?>
	<img class="oc-ab__icon" src="<?php echo esc_url( $icon ); ?>" alt="" width="44" height="44">
	<?php endif; ?>
	<div class="oc-ab__body">
		<strong class="oc-ab__title"><?php echo esc_html( $title ); ?></strong>
		<span class="oc-ab__text"><?php echo esc_html( $text ); ?></span>
		<?php
		foreach ( $ratings as $os => $r ) :
			/* translators: 1: average rating, 2: number of ratings */
			$label = sprintf( __( 'Rated %1$s out of 5, %2$s ratings', 'oc_webapp' ), number_format_i18n( $r['rating'], 1 ), number_format_i18n( $r['count'] ) );
			?>
		<span class="oc-ab__rating oc-ab__rating--<?php echo esc_attr( $os . ( $os === $desktop ? ' oc-ab__rating--desktop' : '' ) ); ?>" role="img" aria-label="<?php echo esc_attr( $label ); ?>"><span class="oc-ab__stars" aria-hidden="true"><span class="oc-ab__stars-fill" style="width:<?php echo esc_attr( round( $r['rating'] / 5 * 100, 1 ) ); ?>%"></span></span><span class="oc-ab__count" aria-hidden="true">(<?php echo esc_html( number_format_i18n( $r['count'] ) ); ?>)</span></span>
		<?php endforeach; ?>
	</div>
	<?php foreach ( $stores as $os => $store ) : ?>
		<?php if ( '' !== $store[0] ) : ?>
	<a class="oc-ab__btn oc-ab__btn--<?php echo esc_attr( $os ); ?>" href="<?php echo esc_url( $store[0] ); ?>" target="_blank" rel="noopener"><span class="oc-ab__label"><?php echo esc_html( $button ); ?></span><span class="oc-ab__store"><?php echo esc_html( $store[1] ); ?></span></a>
		<?php endif; ?>
	<?php endforeach; ?>
	<button type="button" class="oc-ab__close" aria-label="<?php echo esc_attr__( 'Close', 'oc_webapp' ); ?>"><svg viewBox="0 0 12 12" aria-hidden="true" focusable="false"><path d="M1 1l10 10M11 1L1 11" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg></button>
</div>
		<?php
		// Must run right away: ask Cloudflare Rocket Loader, LiteSpeed and WP Rocket
		// not to delay or defer it.
		$js    = (string) file_get_contents( dirname( OC_WEBAPP_FILE ) . '/assets/js/banner.js' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$attrs = array(
			'id'               => 'oc-app-banner-js',
			'data-cfasync'     => 'false',
			'data-no-optimize' => '1',
			'data-no-defer'    => '1',
			'nowprocket'       => true,
		);
		if ( function_exists( 'wp_print_inline_script_tag' ) ) {
			wp_print_inline_script_tag( $js, $attrs );
		} else {
			echo '<script id="oc-app-banner-js" data-cfasync="false" data-no-optimize="1" data-no-defer="1" nowprocket>' . $js . "</script>\n"; // phpcs:ignore WordPress.Security.EscapeOutput
		}
	}
}
