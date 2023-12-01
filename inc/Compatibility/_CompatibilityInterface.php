<?php

declare( strict_types=1 );

namespace Gifty\WooCommerce\Compatibility;

interface _CompatibilityInterface {

    public function fix_should_be_activated(): bool;

    public function register_fix(): void;
}
