<?php

declare( strict_types=1 );

namespace Gifty\WooCommerce;

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

class SessionGiftCard {
    private string $id;
    private string $masked_code;
    private string $code;
    private float $balance;
    private float $amount_used;
    private float $amount_refunded;
    private ?string $transaction_id_redeem;
    private ?string $transaction_id_capture;
    private ?string $transaction_id_release;

    public function __construct(
        string $id,
        string $code,
        float $balance,
        float $amount_used,
        string $masked_code = null,
        string $transaction_id_redeem = null,
        string $transaction_id_capture = null,
        string $transaction_id_release = null,
        float $amount_refunded = 0,
    ) {
        $this->id = $id;
        $this->code = $code;
        $this->balance = $balance;
        $this->amount_used = $amount_used;
        $this->amount_refunded = $amount_refunded;
        $this->masked_code = $masked_code ?? 'XXXX - XXXX - XXXX - ' . substr( $code, - 4 );
        $this->transaction_id_redeem = $transaction_id_redeem;
        $this->transaction_id_capture = $transaction_id_capture;
        $this->transaction_id_release = $transaction_id_release;
    }

    public function get_id(): string {
        return $this->id;
    }

    public function get_masked_code(): string {
        return $this->masked_code;
    }

    public function get_code(): string {
        return $this->code;
    }

    public function get_balance(): float {
        return $this->balance;
    }

    public function get_amount_used(): float {
        return $this->amount_used;
    }

    public function get_amount_refunded(): float {
        return $this->amount_refunded;
    }

    public function get_transaction_id_redeem(): ?string {
        return $this->transaction_id_redeem;
    }

    public function get_transaction_id_capture(): ?string {
        return $this->transaction_id_capture;
    }

    public function get_transaction_id_release(): ?string {
        return $this->transaction_id_release;
    }

    public function set_balance( float $balance ): void {
        $this->balance = $balance;
    }

    public function set_amount_used( float $amount_used ): void {
        $this->amount_used = $amount_used;
    }

    public function set_amount_refunded( float $amount_refunded ): void {
        $this->amount_refunded = $amount_refunded;
    }

    public function set_transaction_id_redeem( string $transaction_id_redeem ): void {
        $this->transaction_id_redeem = $transaction_id_redeem;
    }

    public function set_transaction_id_capture( string $transaction_id_capture ): void {
        $this->transaction_id_capture = $transaction_id_capture;
    }

    public function set_transaction_id_release( string $transaction_id_release ): void {
        $this->transaction_id_release = $transaction_id_release;
    }

    public function toArray(): array {
        return [
            'id' => $this->get_id(),
            'code' => $this->get_code(),
            'masked_code' => $this->get_masked_code(),
            'balance' => $this->get_balance(),
            'amount_used' => $this->get_amount_used(),
            'amount_refunded' => $this->get_amount_refunded(),
            'transaction_id_redeem' => $this->transaction_id_redeem,
            'transaction_id_capture' => $this->transaction_id_capture,
            'transaction_id_release' => $this->transaction_id_release
        ];
    }
}
