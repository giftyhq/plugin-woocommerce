<?php

declare( strict_types=1 );

namespace Gifty\WooCommerce\Compatibility;

use Gifty\Client\GiftyClient;

class CompatibilityRegister {

    private GiftyClient $client;

    public function __construct( GiftyClient $client ) {
        $this->client = $client;

        foreach ( $this->available_plugins() as $plugin ) {
            if ( $plugin->fix_should_be_activated() === false ) {
                continue;
            }

            $plugin->register_fix();
        }
    }

    private function available_plugins(): array {
        return [
            new WC_Gift_Cards( $this->client ),
        ];
    }

}
