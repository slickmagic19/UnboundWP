<?php
/**
 * Print an admin login cookie for HTTP smoke tests.
 *
 * Usage: php tests/make-cookie.php  ->  "wordpress_logged_in_<hash>=<value>"
 *
 * @package UnboundWP
 */

$site = getenv( 'E2E_SITE' ) ? getenv( 'E2E_SITE' ) : 'C:/Users/Achi/Local Sites/thriftywedsites/app/public';
$site = rtrim( str_replace( '\\', '/', $site ), '/' );

$_SERVER['HTTP_HOST']       = 'thriftywedsites.local';
$_SERVER['SERVER_NAME']     = 'thriftywedsites.local';
$_SERVER['REQUEST_URI']     = '/';
$_SERVER['REQUEST_METHOD']  = 'GET';
$_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
$_SERVER['REMOTE_ADDR']     = '127.0.0.1';
$_SERVER['SCRIPT_NAME']     = '/index.php';

define( 'WP_USE_THEMES', false );
require $site . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';

$admins = get_users(
	array(
		'role'   => 'administrator',
		'number' => 1,
	)
);
if ( empty( $admins ) ) {
	fwrite( STDERR, "No administrator found\n" );
	exit( 1 );
}
$user = $admins[0];
$expiration = time() + 3600;
$hash       = md5( get_option( 'siteurl' ) );
// The "auth" cookie only validates when its session token exists in the
// user's session_tokens usermeta - a restore replaces that table, so the
// token has to be created here (not just signed).
$manager = WP_Session_Tokens::get_instance( $user->ID );
$token   = $manager->create( $expiration );
// wp-admin authenticates with the "auth" cookie; front-end checks use
// the "logged_in" one. Emit both so every screen works.
echo 'wordpress_' . $hash . '=' . wp_generate_auth_cookie( $user->ID, $expiration, 'auth', $token ) . "\n";
echo 'wordpress_logged_in_' . $hash . '=' . wp_generate_auth_cookie( $user->ID, $expiration, 'logged_in', $token ) . "\n";
