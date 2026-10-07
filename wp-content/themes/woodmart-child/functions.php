<?php
/**
 * Woodmart child theme.
 */

/**
 * Parent + child style.css (kept from the original child theme).
 */
function woodmart_child_enqueue_styles() {
	wp_register_style( 'child-style', get_stylesheet_directory_uri() . '/style.css', array( 'woodmart-style' ), woodmart_get_theme_info( 'Version' ) );
	wp_style_add_data( 'child-style', 'path', get_stylesheet_directory() . '/style.css' );

	wp_enqueue_style( 'child-style' );
}
add_action( 'wp_enqueue_scripts', 'woodmart_child_enqueue_styles', 10010 );

/**
 * Compiled assets (assets/dest, built by Gulp).
 */
function papylon_scripts_styles() {
	$dir = get_stylesheet_directory();

	if ( file_exists( $dir . '/assets/dest/css/main.css' ) ) {
		wp_enqueue_style( 'main', papylon_asset( 'assets/dest/css/main.css' ), array( 'child-style' ), filemtime( $dir . '/assets/dest/css/main.css' ) );
	}

	foreach ( array( 'main', 'shop' ) as $handle ) {
		$file = "assets/dest/js/{$handle}.js";
		if ( file_exists( "$dir/$file" ) ) {
			wp_enqueue_script( $handle, papylon_asset( $file ), array(), filemtime( "$dir/$file" ), true );
		}
	}
}
add_action( 'wp_enqueue_scripts', 'papylon_scripts_styles', 10020 );

/**
 * Cache-busting outside production.
 */
function papylon_asset( $path ) {
	if ( wp_get_environment_type() === 'production' ) {
		return get_stylesheet_directory_uri() . '/' . $path;
	}

	return add_query_arg( 'time', time(), get_stylesheet_directory_uri() . '/' . $path );
}

/* Load custom includes (shortcodes, helpers): every inc/*.php is auto-loaded */
foreach ( glob( get_stylesheet_directory() . '/inc/*.php' ) as $papylon_inc ) {
	require_once $papylon_inc;
}
