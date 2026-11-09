<?php
/**
 * Store ratings for the download-app banner: reads the app's average rating
 * and number of ratings from the App Store (Apple's public lookup API) and from
 * Google Play (the structured data on the app's public page).
 *
 * Fetched in the background (daily cron + whenever the store links change) and
 * kept in an option, so the front-end never waits on a store. A failed fetch
 * keeps the last good value for the same link.
 *
 * Stored shape (option oc_webapp_banner_ratings), per platform:
 *   'ios' => array( 'url' => ..., 'status' => ok|none|unsupported|error,
 *                   'rating' => 4.6, 'count' => 120, 'time' => 1700000000 )
 *
 * @package OC_Webapp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

class OC_Webapp_Ratings {

	const OPTION = 'oc_webapp_banner_ratings';
	const CRON   = 'oc_webapp_refresh_ratings';

	/**
	 * Register hooks.
	 */
	public function init() {
		add_action( self::CRON, array( __CLASS__, 'refresh' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ) );
		// Fetch right away when the banner settings change (e.g. new store links).
		add_action( 'update_option_' . OC_Webapp_Banner::OPTION, array( $this, 'settings_updated' ), 10, 2 );
		add_action( 'add_option_' . OC_Webapp_Banner::OPTION, array( $this, 'settings_added' ), 10, 2 );
	}

	public static function schedule() {
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON );
		}
	}

	/**
	 * Deactivation: stop the daily refresh.
	 */
	public static function unschedule() {
		wp_clear_scheduled_hook( self::CRON );
	}

	/**
	 * @param mixed $old
	 * @param mixed $new
	 */
	public function settings_updated( $old, $new ) {
		self::refresh( is_array( $new ) ? $new : null );
	}

	/**
	 * @param string $option
	 * @param mixed  $value
	 */
	public function settings_added( $option, $value ) {
		self::refresh( is_array( $value ) ? $value : null );
	}

	/**
	 * Fetch both stores' ratings for the current links and store them.
	 *
	 * @param array|null $settings Banner settings (defaults to the saved ones).
	 * @return array The stored ratings.
	 */
	public static function refresh( $settings = null ) {
		$s      = is_array( $settings ) ? array_merge( OC_Webapp_Banner::defaults(), $settings ) : OC_Webapp_Banner::get_settings();
		$stored = self::stored();
		$out    = array();

		if ( '1' === $s['enabled'] && '1' === $s['rating'] ) {
			foreach ( array( 'ios' => $s['ios_url'], 'android' => $s['android_url'] ) as $os => $url ) {
				if ( '' === $url ) {
					continue;
				}
				$result = 'ios' === $os ? self::fetch_app_store( $url ) : self::fetch_google_play( $url );

				if ( null === $result ) {
					// Store unreachable: keep the last good value for this same link.
					$keep       = isset( $stored[ $os ] ) && $stored[ $os ]['url'] === $url && 'ok' === $stored[ $os ]['status'];
					$out[ $os ] = $keep ? $stored[ $os ] : array( 'url' => $url, 'status' => 'error', 'rating' => 0, 'count' => 0, 'time' => time() );
					continue;
				}
				$out[ $os ] = array(
					'url'    => $url,
					'status' => false === $result ? 'unsupported' : ( $result['count'] > 0 ? 'ok' : 'none' ),
					'rating' => false === $result ? 0 : $result['rating'],
					'count'  => false === $result ? 0 : $result['count'],
					'time'   => time(),
				);
			}
		}

		update_option( self::OPTION, $out );
		return $out;
	}

	/**
	 * @return array
	 */
	public static function stored() {
		$stored = get_option( self::OPTION, array() );
		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * Ratings to show in the banner for the given settings: only for the current
	 * links, only when the store actually has ratings.
	 *
	 * @param array $s Banner settings.
	 * @return array e.g. array( 'ios' => array( 'rating' => 4.6, 'count' => 120 ) )
	 */
	public static function for_display( array $s ) {
		$min = (float) apply_filters( 'oc_webapp_banner_min_rating', 0 );
		$out = array();
		foreach ( self::stored() as $os => $r ) {
			$url = 'ios' === $os ? $s['ios_url'] : ( 'android' === $os ? $s['android_url'] : '' );
			if ( '' !== $url && isset( $r['url'], $r['status'] ) && $r['url'] === $url && 'ok' === $r['status']
				&& $r['count'] > 0 && $r['rating'] >= $min ) {
				$out[ $os ] = array(
					'rating' => min( 5, max( 0, (float) $r['rating'] ) ),
					'count'  => (int) $r['count'],
				);
			}
		}

		/**
		 * Filter the ratings shown in the banner (e.g. to set them by hand).
		 *
		 * @param array $out      array( 'ios'|'android' => array( 'rating' => float, 'count' => int ) )
		 * @param array $settings Banner settings.
		 */
		return (array) apply_filters( 'oc_webapp_banner_ratings', $out, $s );
	}

	/**
	 * Two-letter store country: from the link when it has one, else from the
	 * site language (he_IL → il), else "us".
	 *
	 * @return string
	 */
	private static function country() {
		$locale  = function_exists( 'get_locale' ) ? (string) get_locale() : '';
		$country = preg_match( '/_([A-Za-z]{2})$/', $locale, $m ) ? strtolower( $m[1] ) : 'us';
		return (string) apply_filters( 'oc_webapp_store_country', $country );
	}

	/**
	 * App Store rating via Apple's public lookup API.
	 *
	 * @param string $url App Store link, e.g. https://apps.apple.com/il/app/name/id123456789
	 * @return array|false|null array( rating, count ); false = not an App Store
	 *                          link (e.g. TestFlight); null = request failed.
	 */
	public static function fetch_app_store( $url ) {
		if ( ! preg_match( '#^https?://(?:apps|itunes)\.apple\.com/#i', $url ) || ! preg_match( '#/id(\d+)#', $url, $id ) ) {
			return false;
		}
		$country = preg_match( '#apple\.com/([a-z]{2})/#i', $url, $c ) ? strtolower( $c[1] ) : self::country();

		$response = wp_remote_get( 'https://itunes.apple.com/lookup?id=' . $id[1] . '&country=' . $country, array( 'timeout' => 10 ) );
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) || ! isset( $data['resultCount'] ) ) {
			return null;
		}
		if ( empty( $data['results'][0] ) ) {
			return array( 'rating' => 0, 'count' => 0 ); // Not (yet) in this storefront.
		}
		$app = $data['results'][0];

		return array(
			'rating' => isset( $app['averageUserRating'] ) ? round( (float) $app['averageUserRating'], 2 ) : 0,
			'count'  => isset( $app['userRatingCount'] ) ? (int) $app['userRatingCount'] : 0,
		);
	}

	/**
	 * Google Play rating, read from the structured data (aggregateRating) on the
	 * app's public store page.
	 *
	 * @param string $url Google Play link, e.g. https://play.google.com/store/apps/details?id=com.example.app
	 * @return array|false|null array( rating, count ); false = not a Google Play
	 *                          link; null = request failed.
	 */
	public static function fetch_google_play( $url ) {
		$query = (string) wp_parse_url( $url, PHP_URL_QUERY );
		parse_str( $query, $args );
		if ( ! preg_match( '#^https?://play\.google\.com/#i', $url ) || empty( $args['id'] ) || ! is_string( $args['id'] ) ) {
			return false;
		}
		$package = preg_replace( '/[^A-Za-z0-9._]/', '', $args['id'] );

		$response = wp_remote_get(
			'https://play.google.com/store/apps/details?id=' . rawurlencode( $package ) . '&hl=en&gl=' . strtoupper( self::country() ),
			array(
				'timeout'    => 15,
				'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36',
			)
		);
		if ( is_wp_error( $response ) ) {
			return null;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 404 === $code ) {
			return array( 'rating' => 0, 'count' => 0 ); // Not published (yet).
		}
		if ( 200 !== $code ) {
			return null;
		}

		$body = (string) wp_remote_retrieve_body( $response );
		if ( preg_match( '/"aggregateRating"\s*:\s*\{([^}]*)\}/', $body, $agg )
			&& preg_match( '/"ratingValue"\s*:\s*"?([\d.]+)/', $agg[1], $value )
			&& preg_match( '/"ratingCount"\s*:\s*"?(\d+)/', $agg[1], $count ) ) {
			return array(
				'rating' => round( (float) $value[1], 2 ),
				'count'  => (int) $count[1],
			);
		}

		return array( 'rating' => 0, 'count' => 0 ); // Page loaded, no ratings shown yet.
	}
}
