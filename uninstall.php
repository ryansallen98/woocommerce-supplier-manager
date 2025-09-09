<?php
// Runs on plugin DELETE (not just deactivate)
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) { exit; }
if ( ! defined( 'ABSPATH' ) ) { exit; }

require_once __DIR__ . '/woocommerce-supplier-manager.php'; // for constants
require_once __DIR__ . '/includes/Setup/Uninstaller.php';

\WCSM\Setup\Uninstaller::uninstall();