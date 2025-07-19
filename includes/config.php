<?php
// config.php
if (!defined('BYTENFT_ONRAMP_PROTOCOL')) {
    define('BYTENFT_ONRAMP_PROTOCOL', is_ssl() ? 'https://' : 'http://');
}

if (!defined('BYTENFT_ONRAMP_HOST')) {
    define('BYTENFT_ONRAMP_HOST', 'www.bytenft.xyz');
}

if (!defined('BYTENFT_ONRAMP_BASE_URL')) {
	define('BYTENFT_ONRAMP_BASE_URL', BYTENFT_ONRAMP_PROTOCOL . BYTENFT_ONRAMP_HOST);
}
