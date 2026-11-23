<?php
/**
 * Switches for the cache acceptance run, for the DISPOSABLE site only.
 *
 * Usage: wp --path=/tmp/aafm_accept_siteops eval-file cache-lib.php ACTION [SLUG] [VALUE].
 * Every recipe is one the benches ran (bench3 cen.php, bench4 cen4.php and nhcfg.php, bench5
 * pre.php, sgen.php and w3mob.php). Nothing here calls this plugin's own code.
 *
 * Actions: "fixtures" prints JSON, decoded post name to id, url and path. "enable SLUG" and
 * "disable SLUG" switch page caching in the vendor plugin. "variants SLUG" switches on the
 * vendor's mobile, compression and logged-in options. "preheat on" and "preheat off" set
 * SiteGround's preheat option. "cookie" prints a logged_in cookie for user 1 as name=value.
 *
 * @package AgentAbilitiesForMCP
 */

// phpcs:disable -- A throwaway harness file for the acceptance run on a disposable site. Never loaded by the plugin and never shipped.

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( 1 );
}
if ( 'aafm_accept_siteops' !== DB_NAME || 0 !== strpos( ABSPATH, '/tmp/aafm_accept_siteops' ) ) {
	WP_CLI::error( 'Refusing: this is not the disposable acceptance site.' );
}

$aafm_action = isset( $args[0] ) ? (string) $args[0] : '';
$aafm_slug   = isset( $args[1] ) ? (string) $args[1] : '';
$aafm_value  = isset( $args[2] ) ? (string) $args[2] : '';
$aafm_out    = 'ok';

if ( 'fixtures' === $aafm_action ) {
	$aafm_out = array();
	foreach ( get_posts( array( 'post_type' => array( 'post', 'page' ), 'post_status' => 'any', 'numberposts' => -1 ) ) as $aafm_post ) {
		$aafm_url = (string) get_permalink( $aafm_post );
		$aafm_out[ rawurldecode( $aafm_post->post_name ) ] = array(
			'id'   => $aafm_post->ID,
			'url'  => $aafm_url,
			'path' => (string) wp_parse_url( $aafm_url, PHP_URL_PATH ),
		);
	}
	echo wp_json_encode( $aafm_out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ), "\n";
	return;
}

if ( 'cookie' === $aafm_action ) {
	echo LOGGED_IN_COOKIE . '=' . rawurlencode( wp_generate_auth_cookie( 1, time() + 7200, 'logged_in' ) ), "\n";
	return;
}

if ( 'preheat' === $aafm_action ) {
	if ( 'on' === $aafm_slug ) {
		\SiteGround_Optimizer\Options\Options::enable_option( 'siteground_optimizer_preheat_cache' );
	} else {
		\SiteGround_Optimizer\Options\Options::disable_option( 'siteground_optimizer_preheat_cache' );
	}
	\SiteGround_Optimizer\File_Cacher\File_Cacher::get_instance()->refresh_config();
	echo 'preheat=', wp_json_encode( get_option( 'siteground_optimizer_preheat_cache' ) ), "\n";
	return;
}

$aafm_on = 'disable' !== $aafm_action;

switch ( $aafm_slug ) {
	case 'wp_super_cache':
		if ( $aafm_on ) {
			wp_cache_enable();
			wp_super_cache_enable();
			wp_cache_setting( 'wp_cache_mod_rewrite', 0 );
			if ( 'variants' === $aafm_action ) {
				wp_cache_setting( 'wp_cache_mobile_enabled', 1 );
				wp_cache_setting( 'cache_compression', 1 );
				wp_cache_setting( 'wp_cache_not_logged_in', 0 );
			}
		} else {
			wp_cache_disable();
		}
		break;
	case 'wp_fastest_cache':
		$aafm_settings = $aafm_on ? array( 'wpFastestCacheStatus' => 'on' ) : array();
		if ( 'variants' === $aafm_action ) {
			$aafm_settings['wpFastestCacheMobile'] = 'on';
			$aafm_settings['wpFastestCacheGzip']   = 'on';
		}
		$aafm_out = update_option( 'WpFastestCache', wp_json_encode( (object) $aafm_settings ) );
		break;
	case 'w3_total_cache':
		// Page caching itself is switched with the plugin's own WP-CLI commands by cache.sh.
		if ( 'variants' === $aafm_action ) {
			$aafm_config = \W3TC\Dispatcher::config();
			$aafm_groups = $aafm_config->get_array( 'mobile.rgroups' );
			foreach ( $aafm_groups as $aafm_key => $aafm_group ) {
				$aafm_groups[ $aafm_key ]['enabled'] = true;
			}
			$aafm_config->set( 'mobile.enabled', true );
			$aafm_config->set( 'mobile.rgroups', $aafm_groups );
			$aafm_config->save();
			\W3TC\Dispatcher::component( 'CacheFlush' )->flush_all();
			\W3TC\Dispatcher::component( 'CacheFlush' )->execute_delayed_operations();
		}
		break;
	case 'wp_optimize':
		if ( $aafm_on ) {
			$aafm_out = WP_Optimize()->get_page_cache()->enable( true );
			if ( 'variants' === $aafm_action ) {
				$aafm_cfg = WPO_Cache_Config::instance()->get();
				WPO_Cache_Config::instance()->update( array_merge( $aafm_cfg, array( 'enable_page_caching' => true, 'enable_mobile_caching' => true, 'enable_user_caching' => true ) ) );
			}
		} else {
			$aafm_out = WP_Optimize()->get_page_cache()->disable();
		}
		break;
	case 'cache_enabler':
		// The plugin has no on and off setting: it caches whenever its drop-in is in place.
		$aafm_drop_in = WP_CONTENT_DIR . '/advanced-cache.php';
		if ( $aafm_on && is_file( $aafm_drop_in . '.off' ) ) {
			rename( $aafm_drop_in . '.off', $aafm_drop_in );
		} elseif ( ! $aafm_on && is_file( $aafm_drop_in ) ) {
			rename( $aafm_drop_in, $aafm_drop_in . '.off' );
		}
		if ( 'variants' === $aafm_action ) {
			$aafm_settings = get_option( 'cache_enabler', array() );
			update_option( 'cache_enabler', array_merge( is_array( $aafm_settings ) ? $aafm_settings : array(), array( 'mobile_cache' => 1, 'compress_cache' => 1 ) ) );
			if ( method_exists( 'Cache_Enabler', 'update_backend' ) ) {
				Cache_Enabler::update_backend();
			}
		}
		break;
	case 'hummingbird':
		$aafm_module = \Hummingbird\Core\Utils::get_module( 'page_cache' );
		if ( $aafm_on ) {
			$aafm_module->enable( true );
			if ( 'variants' === $aafm_action ) {
				$aafm_settings                          = $aafm_module->get_settings();
				$aafm_settings['settings']['logged_in'] = 1;
				$aafm_settings['settings']['mobile']    = 1;
				$aafm_module->save_settings( $aafm_settings );
			}
		} else {
			$aafm_module->disable();
		}
		break;
	case 'jetpack_boost':
		$aafm_module = new \Automattic\Jetpack_Boost\Modules\Module( new \Automattic\Jetpack_Boost\Modules\Optimizations\Page_Cache\Page_Cache() );
		$aafm_out    = $aafm_module->update( $aafm_on );
		if ( $aafm_on ) {
			\Automattic\Jetpack_Boost\Modules\Optimizations\Page_Cache\Page_Cache_Setup::run_setup();
		}
		break;
	case 'speedycache':
		$aafm_settings           = get_option( 'speedycache_options', array() );
		$aafm_settings           = is_array( $aafm_settings ) ? $aafm_settings : array();
		$aafm_settings['status'] = $aafm_on;
		if ( 'variants' === $aafm_action ) {
			$aafm_settings['mobile']       = true;
			$aafm_settings['mobile_theme'] = true;
		}
		$aafm_out = update_option( 'speedycache_options', $aafm_settings );
		if ( method_exists( '\SpeedyCache\Util', 'set_config_file' ) ) {
			\SpeedyCache\Util::set_config_file();
		}
		break;
	case 'breeze':
		$aafm_settings                  = get_option( 'breeze_basic_settings', array() );
		$aafm_settings                  = is_array( $aafm_settings ) ? $aafm_settings : array();
		$aafm_settings['breeze-active'] = $aafm_on ? '1' : '0';
		if ( 'variants' === $aafm_action ) {
			$aafm_settings = array_merge( $aafm_settings, array( 'breeze-mobile-separate' => '1', 'breeze-gzip-compression' => '1', 'breeze-disable-admin' => array( 'administrator' => 1 ), 'breeze-desktop-cache' => '1', 'breeze-mobile-cache' => '1' ) );
		}
		breeze_update_option( 'basic_settings', $aafm_settings, true );
		Breeze_ConfigCache::factory()->write();
		Breeze_ConfigCache::factory()->write_config_cache();
		break;
	case 'siteground':
		$aafm_out = \SiteGround_Optimizer\File_Cacher\File_Cacher::toggle_file_cache( $aafm_on ? 1 : 0 );
		break;
	case 'nginx_helper':
		$aafm_settings = get_option( 'rt_wp_nginx_helper_options', array() );
		$aafm_settings = is_array( $aafm_settings ) ? $aafm_settings : array();
		$aafm_out      = update_option( 'rt_wp_nginx_helper_options', array_merge( $aafm_settings, array( 'enable_purge' => $aafm_on ? 1 : 0, 'cache_method' => 'enable_fastcgi', 'purge_method' => 'unlink_files' ) ) );
		break;
	case 'proxy_cache_purge':
		// The local listener of this run stands in for the proxy.
		$aafm_out = update_option( 'vhp_varnish_ip', '127.0.0.1:8099' );
		break;
	case 'litespeed':
		// Switched on with `wp litespeed-option set cache 1` by cache.sh; nothing to do here.
		break;
	default:
		WP_CLI::error( 'Unknown adapter: ' . $aafm_slug );
}

echo $aafm_action, ' ', $aafm_slug, ': ', substr( (string) wp_json_encode( is_wp_error( $aafm_out ) ? $aafm_out->get_error_messages() : $aafm_out ), 0, 200 ), "\n";
// phpcs:enable
