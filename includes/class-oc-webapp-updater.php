<?php
/**
 * Self-contained "update from GitHub releases" updater — the same mechanism used
 * by the Giorgio plugin. Each time a new release is published on GitHub, WordPress
 * shows an update for this plugin and updates it in place.
 *
 * Works with a PRIVATE repo too, when a read-only token is provided via the
 * constant OC_WEBAPP_GH_TOKEN (in wp-config.php) or the `github_token` setting.
 *
 * @package OC_Webapp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OC_Webapp_Updater {

	/** @var string Full path to the main plugin file. */
	private $file;
	/** @var string Plugin basename, e.g. oc_webapp/oc_webapp.php */
	private $basename;
	/** @var string Plugin folder slug, e.g. oc_webapp */
	private $slug;
	/** @var string Current installed version. */
	private $version;
	/** @var string GitHub owner. */
	private $user;
	/** @var string GitHub repo. */
	private $repo;
	/** @var array|null Cached latest release. */
	private $release = null;

	public function __construct( $file, $version, $user, $repo ) {
		$this->file     = $file;
		$this->basename = plugin_basename( $file );
		$this->slug     = dirname( $this->basename );
		$this->version  = $version;
		$this->user     = $user;
		$this->repo     = $repo;

		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_for_update' ) );
		add_filter( 'plugins_api', array( $this, 'plugins_api' ), 10, 3 );
		add_filter( 'upgrader_source_selection', array( $this, 'fix_source_dir' ), 10, 4 );
		add_action( 'upgrader_process_complete', array( $this, 'flush_cache' ), 10, 2 );
		add_filter( 'http_request_args', array( $this, 'authorize_github_request' ), 10, 2 );
		add_filter( 'auto_update_plugin', array( $this, 'enable_auto_update' ), 10, 2 );

		// "Check now" button + handler.
		add_action( 'admin_post_oc_webapp_force_check', array( $this, 'handle_force_check' ) );
		add_action( 'admin_notices', array( $this, 'render_check_button' ) );
	}

	/**
	 * Read-only GitHub token for a private repo (empty = public repo, no auth).
	 *
	 * @return string
	 */
	private function get_token() {
		if ( defined( 'OC_WEBAPP_GH_TOKEN' ) && OC_WEBAPP_GH_TOKEN ) {
			return (string) OC_WEBAPP_GH_TOKEN;
		}
		$opts = get_option( 'oc_webapp_settings', array() );
		return ( is_array( $opts ) && ! empty( $opts['github_token'] ) ) ? (string) $opts['github_token'] : '';
	}

	/**
	 * Fetch (and cache) the latest GitHub release for this repo.
	 *
	 * @param bool $force Bypass the cache.
	 * @return array|null
	 */
	private function get_release( $force = false ) {
		if ( null !== $this->release && ! $force ) {
			return $this->release;
		}

		$cache_key = 'oc_webapp_gh_release';
		if ( ! $force ) {
			$cached = get_transient( $cache_key );
			if ( false !== $cached ) {
				$this->release = $cached ? $cached : null;
				return $this->release;
			}
		}

		$url      = 'https://api.github.com/repos/' . $this->user . '/' . $this->repo . '/releases/latest';
		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 15,
				'headers' => array(
					'Accept'     => 'application/vnd.github+json',
					'User-Agent' => 'OC-Webapp-Updater',
				),
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			set_transient( $cache_key, array(), 30 * MINUTE_IN_SECONDS ); // brief negative cache.
			$this->release = null;
			return null;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) || empty( $data['tag_name'] ) ) {
			set_transient( $cache_key, array(), 30 * MINUTE_IN_SECONDS );
			$this->release = null;
			return null;
		}

		$this->release = $data;
		set_transient( $cache_key, $data, 6 * HOUR_IN_SECONDS );
		return $data;
	}

	/**
	 * Normalize a tag like "v1.4" or "1.4" to "1.4".
	 */
	private function tag_to_version( $tag ) {
		return ltrim( (string) $tag, 'vV' );
	}

	/**
	 * Best download URL for a release: a real .zip asset if present, else the
	 * source zipball (which we then rename to the plugin folder).
	 */
	private function package_url( array $release ) {
		if ( ! empty( $release['assets'] ) && is_array( $release['assets'] ) ) {
			foreach ( $release['assets'] as $asset ) {
				if ( isset( $asset['name'] ) && '.zip' === strtolower( substr( $asset['name'], -4 ) ) ) {
					// For private repos the API asset URL needs auth + octet-stream.
					return isset( $asset['url'] ) ? $asset['url'] : $asset['browser_download_url'];
				}
			}
		}
		return isset( $release['zipball_url'] ) ? $release['zipball_url'] : '';
	}

	/**
	 * Inject an available update into the plugins update transient.
	 */
	public function check_for_update( $transient ) {
		if ( empty( $transient->checked ) ) {
			return $transient;
		}

		$release = $this->get_release();
		if ( ! $release ) {
			return $transient;
		}

		$remote_version = $this->tag_to_version( $release['tag_name'] );
		if ( version_compare( $remote_version, $this->version, '<=' ) ) {
			return $transient; // up to date.
		}

		$package = $this->package_url( $release );
		if ( '' === $package ) {
			return $transient;
		}

		$transient->response[ $this->basename ] = (object) array(
			'slug'        => $this->slug,
			'plugin'      => $this->basename,
			'new_version' => $remote_version,
			'url'         => 'https://github.com/' . $this->user . '/' . $this->repo,
			'package'     => $package,
		);

		return $transient;
	}

	/**
	 * Populate the "View details" modal.
	 */
	public function plugins_api( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || $args->slug !== $this->slug ) {
			return $result;
		}
		$release = $this->get_release();
		if ( ! $release ) {
			return $result;
		}

		return (object) array(
			'name'          => 'OC Webapp',
			'slug'          => $this->slug,
			'version'       => $this->tag_to_version( $release['tag_name'] ),
			'author'        => 'Original Concepts',
			'homepage'      => 'https://github.com/' . $this->user . '/' . $this->repo,
			'download_link' => $this->package_url( $release ),
			'sections'      => array(
				'changelog' => isset( $release['body'] ) ? wpautop( wp_kses_post( $release['body'] ) ) : '',
			),
		);
	}

	/**
	 * GitHub zipballs (and asset zips) may extract to a differently-named folder;
	 * rename the extracted directory to the plugin slug so WordPress updates in place.
	 */
	public function fix_source_dir( $source, $remote_source, $upgrader, $args = array() ) {
		if ( empty( $args['plugin'] ) || $args['plugin'] !== $this->basename ) {
			return $source;
		}
		global $wp_filesystem;
		$desired = trailingslashit( $remote_source ) . $this->slug;
		if ( $source === trailingslashit( $remote_source ) . $this->slug . '/' ) {
			return $source;
		}
		if ( $wp_filesystem && $wp_filesystem->move( $source, $desired ) ) {
			return trailingslashit( $desired );
		}
		return $source;
	}

	/**
	 * Clear the cached release after a successful update.
	 */
	public function flush_cache( $upgrader, $data ) {
		if ( isset( $data['type'] ) && 'plugin' === $data['type'] ) {
			delete_transient( 'oc_webapp_gh_release' );
		}
	}

	/**
	 * Authenticate GitHub API + asset requests for a PRIVATE repo.
	 */
	public function authorize_github_request( $args, $url ) {
		$token = $this->get_token();
		if ( '' === $token ) {
			return $args;
		}
		if ( false === strpos( (string) $url, 'github.com/' . $this->user . '/' . $this->repo )
			&& false === strpos( (string) $url, 'api.github.com/repos/' . $this->user . '/' . $this->repo ) ) {
			return $args;
		}

		$args['headers'] = isset( $args['headers'] ) ? (array) $args['headers'] : array();
		$args['headers']['Authorization'] = 'Bearer ' . $token;

		// Binary endpoints (asset download / zipball) must return raw bytes.
		if ( false !== strpos( (string) $url, '/releases/assets/' ) ) {
			$args['headers']['Accept'] = 'application/octet-stream';
		} elseif ( false !== strpos( (string) $url, '/zipball/' ) || false !== strpos( (string) $url, '/tarball/' ) ) {
			// no Accept override needed.
			$args['headers']['User-Agent'] = 'OC-Webapp-Updater';
		} else {
			$args['headers']['Accept'] = 'application/vnd.github+json';
		}
		$args['headers']['User-Agent'] = 'OC-Webapp-Updater';

		return $args;
	}

	/**
	 * Let WordPress auto-update this plugin in the background.
	 */
	public function enable_auto_update( $update, $item ) {
		if ( isset( $item->plugin ) && $item->plugin === $this->basename ) {
			return true;
		}
		return $update;
	}

	/**
	 * Render a "Check for updates now" button on the Plugins screen.
	 */
	public function render_check_button() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'plugins' !== $screen->id || ! current_user_can( 'update_plugins' ) ) {
			return;
		}
		if ( isset( $_GET['oc_webapp_checked'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			echo '<div class="notice notice-success is-dismissible"><p>'
				. esc_html__( 'OC Webapp: checked GitHub for updates.', 'oc_webapp' ) . '</p></div>';
		}
		$url = wp_nonce_url( admin_url( 'admin-post.php?action=oc_webapp_force_check' ), 'oc_webapp_force_check' );
		echo '<div class="notice notice-info"><p>'
			. esc_html__( 'OC Webapp updates from GitHub releases.', 'oc_webapp' ) . ' '
			. '<a class="button" href="' . esc_url( $url ) . '">' . esc_html__( 'Check for updates now', 'oc_webapp' ) . '</a>'
			. '</p></div>';
	}

	/**
	 * Force a fresh GitHub check.
	 */
	public function handle_force_check() {
		if ( ! current_user_can( 'update_plugins' )
			|| ! isset( $_GET['_wpnonce'] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'oc_webapp_force_check' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'oc_webapp' ) );
		}
		delete_transient( 'oc_webapp_gh_release' );
		delete_site_transient( 'update_plugins' );
		$this->get_release( true );
		wp_safe_redirect( admin_url( 'plugins.php?oc_webapp_checked=1' ) );
		exit;
	}
}
