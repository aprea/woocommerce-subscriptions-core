<?php

use Bramus\Monolog\Formatter\ColoredLineFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Monolog\Processor\PsrLogMessageProcessor;
use WR\Tools\Version_Bump;

$version          = $argv[1];
$plugin_slug      = 'woocommerce-subscriptions-core';
$plugin_folder    = dirname( __DIR__ );
$main_plugin_file = dirname( __DIR__ ) . '/' . $plugin_slug . '.php';

// Load the Woorelease autoloader.
require_once dirname( __DIR__, 2 ) . '/woorelease/vendor/autoload.php';

// Set up the logger.
$time_format   = 'H:i:s';
$output_format = "WR [%datetime%] [%level_name%] %message%\n";

$logger  = new Logger( 'Woorelease' );
$handler = new StreamHandler( 'php://stdout', Logger::INFO );
$handler->pushProcessor( new PsrLogMessageProcessor() );
$handler->setFormatter( new ColoredLineFormatter( null, $output_format, $time_format ) );
$logger->pushHandler( $handler );

Monolog\Registry::addLogger( $logger, 'default' );

// Bump versions across the codebase.
Version_Bump::maybe_bump(
	$plugin_slug,
	$plugin_folder,
	$main_plugin_file,
	$version,
	true
);
