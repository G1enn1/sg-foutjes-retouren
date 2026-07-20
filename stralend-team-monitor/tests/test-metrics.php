<?php
/**
 * Standalone tests for STM_Metrics (attribution + aggregation) — no WordPress.
 * Run: php tests/test-metrics.php
 *
 * We stub STM_Settings with a fixed team so the logic can be exercised against
 * the REAL Voys Freedom "2xx/Naam" destination format.
 */

define( 'ABSPATH', __DIR__ ); // satisfy the direct-access guard

// --- stub STM_Settings before loading metrics ---
class STM_Settings {
	public static function employees() {
		return array(
			array( 'id' => 'sandra', 'name' => 'Sandra Kamphuis', 'ext' => '210' ),
			array( 'id' => 'saskia', 'name' => 'Saskia Uittien',  'ext' => '211' ),
			array( 'id' => 'daniel', 'name' => 'Daniel Korringa', 'ext' => '203' ),
			array( 'id' => 'senna',  'name' => 'Senna van der Hooft', 'ext' => '212' ),
		);
	}
	public static function ext_index() {
		$i = array();
		foreach ( self::employees() as $e ) { $i[ $e['ext'] ] = $e; }
		return $i;
	}
	public static function name_index() {
		$i = array();
		foreach ( self::employees() as $e ) { $i[ strtolower( explode( ' ', $e['name'] )[0] ) ] = $e; }
		return $i;
	}
	public static function name_map() {
		$m = array();
		foreach ( self::employees() as $e ) { $m[ $e['id'] ] = $e['name']; }
		return $m;
	}
	public static function category_weights() {
		return array( 'garantie' => 3.0, 'adreswijziging' => 1.0 );
	}
}

require __DIR__ . '/../includes/class-stm-metrics.php';

$failures = 0;
function check( $label, $got, $expected ) {
	global $failures;
	$ok = ( $got === $expected );
	if ( ! $ok ) { $failures++; }
	printf( "[%s] %s  (got: %s, expected: %s)\n",
		$ok ? 'PASS' : 'FAIL', $label,
		var_export( $got, true ), var_export( $expected, true ) );
}

// --- attribution: leading extension parsing ---
check( 'leading_ext 210/Sandra', STM_Metrics::leading_ext( '210/Sandra sip' ), '210' );
check( 'leading_ext plain 213',  STM_Metrics::leading_ext( '213' ), '213' );
check( 'leading_ext external',   STM_Metrics::leading_ext( '+31502110420' ), '' );
check( 'name_token 210/Sandra',  STM_Metrics::name_token( '210/Sandra sip' ), 'sandra' );

// --- resolve_call against the real export format ---
$sandra = STM_Metrics::resolve_call( 'x', '210/Sandra sip', 'inbound' );
check( 'inbound -> Sandra', $sandra['id'] ?? null, 'sandra' );

$daniel = STM_Metrics::resolve_call( 'x', '203/Daniel sip', 'inbound' );
check( 'inbound -> Daniel', $daniel['id'] ?? null, 'daniel' );

check( 'main line +31.. -> null', STM_Metrics::resolve_call( 'x', '+31502110420', 'inbound' ), null );
check( 'unmapped ext 206 -> null', STM_Metrics::resolve_call( 'x', '206/Voip Fotoho sip', 'inbound' ), null );
check( 'masked outbound -> null', STM_Metrics::resolve_call( 'x', '+31594509547', 'outbound' ), null );

// --- aggregation ---
$rows = array(
	array( 'event_date' => '2026-07-13', 'employee_id' => 'sandra', 'channel' => 'call', 'direction' => 'inbound', 'duration_seconds' => 130, 'ticket_id' => 'T1', 'category' => 'garantie', 'is_escalation' => 0 ),
	array( 'event_date' => '2026-07-13', 'employee_id' => 'sandra', 'channel' => 'call', 'direction' => 'inbound', 'duration_seconds' => 178, 'ticket_id' => 'T1', 'category' => 'garantie', 'is_escalation' => 0 ),
	array( 'event_date' => '2026-07-13', 'employee_id' => 'sandra', 'channel' => 'email', 'direction' => 'outbound', 'ticket_id' => 'T2', 'category' => 'adreswijziging', 'is_fcr' => 1 ),
	array( 'event_date' => '2026-07-13', 'employee_id' => 'saskia', 'channel' => 'call', 'direction' => 'inbound', 'duration_seconds' => 301 ),
);
$daily = STM_Metrics::aggregate( $rows );
check( 'aggregate rows', count( $daily ), 2 );

$sandra_row = null;
foreach ( $daily as $s ) { if ( 'sandra' === $s['employee_id'] ) { $sandra_row = $s; } }
check( 'Sandra calls', $sandra_row['calls_handled'], 2 );
check( 'Sandra call minutes', STM_Metrics::call_minutes( $sandra_row ), round( ( 130 + 178 ) / 60, 1 ) );
check( 'Sandra emails sent', $sandra_row['emails_sent'], 1 );
check( 'Sandra tickets (T1 dedup + T2)', $sandra_row['ticket_count'], 2 );
// weighted difficulty: garantie x2 (3.0) + adreswijziging x1 (1.0) = 7/3
check( 'Sandra weighted difficulty', STM_Metrics::weighted_difficulty( $sandra_row ), round( 7 / 3, 2 ) );

echo $failures ? "\n{$failures} FAILURE(S)\n" : "\nAll checks passed.\n";
exit( $failures ? 1 : 0 );
