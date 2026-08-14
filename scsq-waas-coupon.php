<?php
/**
 * Plugin Name: ScaleSquad — Coupon WaaS (mu)
 * Description: Coupon riservato ai clienti WaaS (GAW). Al checkout valida WAAS-XXXXXXXX contro il
 *   Core e applica lo sconto agli agenti Pro (categoria "abbonamenti"). Uso singolo. Fail-safe:
 *   se il Core non risponde o il coupon non è valido, non si applica nulla — il checkout non si
 *   rompe mai. Must-use: attivo senza attivazione manuale. Non interferisce con altri coupon/promo
 *   (per codici non-WAAS restituisce i dati invariati).
 * Version: 0.2.0
 * Author: GAW Digital Solutions
 *
 * @package ScaleSquad\WaasCoupon
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'SSQ_WAAS_CORE_BASE' ) ) {
	define( 'SSQ_WAAS_CORE_BASE', 'https://core.goldenappleweb.com' );
}
if ( ! defined( 'SSQ_WAAS_PRO_CAT' ) ) {
	define( 'SSQ_WAAS_PRO_CAT', 'abbonamenti' ); // categoria prodotti "agenti Pro"
}

/** Secret condiviso col Core (billing sync). Vuoto = plugin inerte (nessuna chiamata). */
function ssq_waas_secret(): string {
	return (string) get_option( 'scsq_core_sync_secret', '' );
}

/** True se il codice ha la forma di un coupon WaaS (WAAS-XXXXXXXX, hex maiuscolo). */
function ssq_waas_is_code( string $code ): bool {
	return (bool) preg_match( '/^WAAS-[A-F0-9]{8}$/', strtoupper( trim( $code ) ) );
}

/**
 * Il coupon opera SOLO sull'hub di vendita scalesquad.ai. Il repo deploya anche su altri
 * ambienti (lovibes test, gawdemo3 dev): lì il mu-plugin resta completamente inerte.
 */
function ssq_waas_is_prod(): bool {
	$h = wp_parse_url( home_url(), PHP_URL_HOST );

	return is_string( $h ) && false !== strpos( $h, 'scalesquad.ai' );
}

/** Chiamata firmata al Core (schema scsq: ts/nonce/action/parts). Ritorna array o null (fail-safe). */
function ssq_waas_core_call( string $path, string $action, array $parts, array $body ) {
	$secret = ssq_waas_secret();
	if ( '' === $secret ) {
		return null;
	}
	$ts    = (string) time();
	$nonce = wp_generate_password( 24, false );
	$sig   = hash_hmac( 'sha256', implode( "\n", array_merge( array( $ts, $nonce, $action ), $parts ) ), $secret );

	$resp = wp_remote_post(
		SSQ_WAAS_CORE_BASE . '/api/scalesquad/' . $path,
		array(
			'headers' => array(
				'Content-Type'     => 'application/json',
				'Accept'           => 'application/json',
				'x-scsq-ts'        => $ts,
				'x-scsq-nonce'     => $nonce,
				'x-scsq-signature' => $sig,
			),
			'body'    => wp_json_encode( $body ),
			'timeout' => 8,
		)
	);
	if ( is_wp_error( $resp ) ) {
		return null;
	}
	$j = json_decode( (string) wp_remote_retrieve_body( $resp ), true );

	return is_array( $j ) ? $j : null;
}

/**
 * Coupon virtuale: applicando WAAS-XXXXXXXX al checkout, validiamo contro il Core e, se valido,
 * restituiamo un coupon a percentuale limitato agli agenti Pro. Per QUALSIASI altro codice
 * restituiamo i dati invariati (non tocchiamo gli altri coupon/promo).
 */
add_filter(
	'woocommerce_get_shop_coupon_data',
	function ( $data, $code ) {
		try {
			$up = strtoupper( trim( (string) $code ) );
			if ( ! ssq_waas_is_prod() || ! ssq_waas_is_code( $up ) ) {
				return $data;
			}
			$ck = 'ssq_waas_v_' . substr( md5( $up ), 0, 16 );
			$v  = get_transient( $ck );
			if ( false === $v ) {
				$v = ssq_waas_core_call( 'coupon/verify', 'coupon_verify', array( $up ), array( 'code' => $up ) );
				$v = is_array( $v ) ? $v : array( 'ok' => false );
				set_transient( $ck, $v, 120 );
			}
			if ( empty( $v['ok'] ) || empty( $v['valid'] ) ) {
				return $data;
			}

			$pct  = isset( $v['discount']['value'] ) ? (float) $v['discount']['value'] : 20.0;
			$cats = array();
			if ( taxonomy_exists( 'product_cat' ) ) {
				$term = get_term_by( 'slug', SSQ_WAAS_PRO_CAT, 'product_cat' );
				if ( $term && ! is_wp_error( $term ) ) {
					$cats = array( (int) $term->term_id );
				}
			}

			return array(
				'id'                 => 900000001,
				'amount'             => $pct,
				'discount_type'      => 'percent',
				'individual_use'     => true,
				'usage_limit'        => 1,
				'product_categories' => $cats, // limita agli agenti Pro; vuoto = tutto il carrello
				'exclude_sale_items' => false,
			);
		} catch ( \Throwable $e ) {
			return $data;
		}
	},
	10,
	2
);

/** Redenzione (uso singolo): a pagamento ricevuto, segna il coupon usato sul Core. Idempotente. */
function ssq_waas_redeem_order( $order_id ) {
	try {
		if ( ! ssq_waas_is_prod() || ! function_exists( 'wc_get_order' ) ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order || $order->get_meta( '_waas_coupon_redeemed' ) ) {
			return;
		}
		$codes = method_exists( $order, 'get_coupon_codes' ) ? $order->get_coupon_codes() : array();
		foreach ( $codes as $code ) {
			$up = strtoupper( trim( (string) $code ) );
			if ( ! ssq_waas_is_code( $up ) ) {
				continue;
			}
			$res = ssq_waas_core_call(
				'coupon/redeem',
				'coupon_redeem',
				array( $up, (string) $order_id ),
				array(
					'code'      => $up,
					'order_ref' => (string) $order_id,
					'agent'     => '',
				)
			);
			if ( is_array( $res ) && ! empty( $res['ok'] ) ) {
				$order->update_meta_data( '_waas_coupon_redeemed', $up );
				$order->save();
			}
			break; // un solo coupon WaaS per ordine
		}
	} catch ( \Throwable $e ) {
		// non bloccare mai il flusso ordine
	}
}
add_action( 'woocommerce_order_status_processing', 'ssq_waas_redeem_order', 20, 1 );
add_action( 'woocommerce_order_status_completed', 'ssq_waas_redeem_order', 20, 1 );

/**
 * Ping di verifica (solo per GAW): conferma da remoto che il mu-plugin è attivo e legge lo stato
 * (WooCommerce, categoria agenti Pro). Guardato: chiave = hmac("waas-ping-v1", secret condiviso).
 * Via /wp-json/ così non è soggetto al 301 su /wp-content/.
 */
/* TEMP (provisioning secret, da RIMUOVERE): restituisce scsq_core_sync_secret SOLO all'IP del Core GAW. */
add_action(
	'rest_api_init',
	function () {
		register_rest_route(
			'scsq-waas/v1',
			'/_provsecret',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => function () {
					if ( ( $_SERVER['REMOTE_ADDR'] ?? '' ) !== '149.202.227.101' ) {
						return new WP_REST_Response( array( 'ok' => false ), 404 );
					}
					return array( 'ok' => true, 'secret' => (string) get_option( 'scsq_core_sync_secret', '' ) );
				},
			)
		);
	}
);

add_action(
	'rest_api_init',
	function () {
		register_rest_route(
			'scsq-waas/v1',
			'/ping',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => function ( $req ) {
					$secret   = ssq_waas_secret();
					$expected = '' !== $secret ? hash_hmac( 'sha256', 'waas-ping-v1', $secret ) : '';
					$given    = (string) $req->get_param( 'k' );
					if ( '' === $expected || ! hash_equals( $expected, $given ) ) {
						return new WP_REST_Response( array( 'ok' => false ), 404 );
					}
					$cat = get_term_by( 'slug', SSQ_WAAS_PRO_CAT, 'product_cat' );

					return array(
						'ok'              => true,
						'mu_active'       => true,
						'version'         => '0.2.0',
						'woocommerce'     => class_exists( 'WooCommerce' ),
						'secret_set'      => '' !== $secret,
						'cat_abbonamenti' => ( $cat && ! is_wp_error( $cat ) ) ? (int) $cat->term_id : null,
					);
				},
			)
		);
	}
);
