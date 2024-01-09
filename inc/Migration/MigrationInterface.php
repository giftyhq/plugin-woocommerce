<?php

namespace Gifty\WooCommerce\Migration;

interface MigrationInterface
{
    public static function schedule(): void;
    public function migrate( array $options = [] ): void;
}
