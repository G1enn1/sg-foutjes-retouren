<?php
/**
 * WhatsApp via HubSpot Conversations (inbox) API.
 *
 * Requires WhatsApp to be connected to the HubSpot inbox and the Private App
 * to carry the `conversations.read` scope. We walk inbox threads, keep those
 * on a WhatsApp channel, and store each message as an interaction row with
 * channel 'whatsapp' and the thread id — sessions are then simply
 * COUNT(DISTINCT thread_id). One WhatsApp SESSION counts as one interaction
 * in the volume totals (a session ≈ one email's worth of work); individual
 * messages are the detail metric.
 *
 * Attribution: outgoing messages by their sending agent, incoming messages by
 * the thread's assigned agent — both resolved via HubSpot userId → employee
 * email. Unassigned incoming messages cannot be attributed and are skipped.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class STM_WhatsApp {

	/** @var STM_HubSpot */
	private $hs;

	public function __construct() {
		$this->hs = new STM_HubSpot();
	}

	/**
	 * Sync WhatsApp messages whose thread saw activity in [start, end].
	 *
	 * @return array { inserted, threads, messages, error?, hint? }
	 */
	public function sync( $start, $end ) {
		$actor_map = $this->actor_map();
		if ( empty( $actor_map ) ) {
			return array( 'error' => __( 'Geen medewerkers te koppelen (owner-ids eerst koppelen).', 'stralend-team-monitor' ) );
		}

		$wa_channels = $this->whatsapp_channel_ids();
		if ( is_wp_error( $wa_channels ) ) {
			$out = array( 'error' => $wa_channels->get_error_message() );
			if ( false !== strpos( $wa_channels->get_error_code(), '403' ) ) {
				$out['hint'] = __( 'Voeg de scope conversations.read toe aan de privé-app in HubSpot.', 'stralend-team-monitor' );
			}
			return $out;
		}
		if ( empty( $wa_channels ) ) {
			return array( 'error' => __( 'Geen WhatsApp-kanaal gevonden in de HubSpot-inbox.', 'stralend-team-monitor' ) );
		}

		$after_iso = gmdate( 'Y-m-d\TH:i:s\Z', strtotime( get_gmt_from_date( $start . ' 00:00:00' ) . ' UTC' ) );

		$inserted = 0;
		$threads  = 0;
		$messages = 0;
		$after    = null;
		$guard    = 0;

		do {
			$path = '/conversations/v3/conversations/threads?limit=100&sort=latestMessageTimestamp'
				. '&latestMessageTimestampAfter=' . rawurlencode( $after_iso )
				. ( $after ? '&after=' . rawurlencode( $after ) : '' );
			$data = $this->hs->get_json_api( $path );
			if ( is_wp_error( $data ) ) {
				return array( 'error' => $data->get_error_message(), 'inserted' => $inserted, 'threads' => $threads, 'messages' => $messages );
			}

			foreach ( (array) ( $data['results'] ?? array() ) as $thread ) {
				$channel = (string) ( $thread['originalChannelId'] ?? '' );
				if ( ! in_array( $channel, $wa_channels, true ) ) {
					continue;
				}
				$threads++;
				$tid      = (string) $thread['id'];
				$assigned = (string) ( $thread['assignedTo'] ?? '' );
				$r        = $this->sync_thread( $tid, $assigned, $wa_channels, $actor_map, $start, $end );
				$inserted += $r['inserted'];
				$messages += $r['messages'];
				usleep( 150000 ); // stay well under the API rate limits
			}
			$after = $data['paging']['next']['after'] ?? null;
			$guard++;
		} while ( $after && $guard < 100 );

		return array( 'inserted' => $inserted, 'threads' => $threads, 'messages' => $messages );
	}

	/** Store the messages of one thread. */
	private function sync_thread( $tid, $assigned_actor, array $wa_channels, array $actor_map, $start, $end ) {
		$inserted = 0;
		$messages = 0;
		$after    = null;
		$guard    = 0;
		do {
			$path = '/conversations/v3/conversations/threads/' . rawurlencode( $tid ) . '/messages?limit=100'
				. ( $after ? '&after=' . rawurlencode( $after ) : '' );
			$data = $this->hs->get_json_api( $path );
			if ( is_wp_error( $data ) ) {
				break; // partial thread is fine; dedup makes the retry safe
			}
			foreach ( (array) ( $data['results'] ?? array() ) as $m ) {
				if ( 'MESSAGE' !== (string) ( $m['type'] ?? '' ) ) {
					continue;
				}
				$m_channel = (string) ( $m['channelId'] ?? '' );
				if ( '' !== $m_channel && ! in_array( $m_channel, $wa_channels, true ) ) {
					continue;
				}
				$ts_utc = strtotime( (string) ( $m['createdAt'] ?? '' ) );
				if ( ! $ts_utc ) {
					continue;
				}
				$ts_local = get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $ts_utc ) );
				$day      = substr( $ts_local, 0, 10 );
				if ( $day < $start || $day > $end ) {
					continue;
				}
				$messages++;

				$direction = ( 'INCOMING' === strtoupper( (string) ( $m['direction'] ?? '' ) ) ) ? 'inbound' : 'outbound';
				$actor     = '';
				if ( 'outbound' === $direction && ! empty( $m['senders'][0]['actorId'] ) ) {
					$actor = (string) $m['senders'][0]['actorId'];
				}
				if ( '' === $actor || ! isset( $actor_map[ $actor ] ) ) {
					$actor = $assigned_actor;
				}
				if ( '' === $actor || ! isset( $actor_map[ $actor ] ) ) {
					continue; // unassigned and unattributable
				}

				$inserted += STM_DB::insert( array(
					'event_ts'    => $ts_local,
					'employee_id' => $actor_map[ $actor ],
					'channel'     => 'whatsapp',
					'direction'   => $direction,
					'source'      => 'hubspot_wa',
					'thread_id'   => 'wa_' . $tid,
					'dedup_seed'  => 'wa_msg_' . ( $m['id'] ?? ( $tid . '_' . $ts_utc ) ),
				) );
			}
			$after = $data['paging']['next']['after'] ?? null;
			$guard++;
		} while ( $after && $guard < 20 );

		return array( 'inserted' => $inserted, 'messages' => $messages );
	}

	/**
	 * Inbox actor id ("A-<userId>") => employee id, matched on owner email.
	 */
	private function actor_map() {
		$by_email = array();
		foreach ( STM_Settings::employees() as $e ) {
			if ( ! empty( $e['email'] ) && '' !== $e['id'] ) {
				$by_email[ strtolower( $e['email'] ) ] = $e['id'];
			}
		}
		$map = array();
		foreach ( $this->hs->owners() as $owner ) {
			$mail = strtolower( (string) ( $owner['email'] ?? '' ) );
			$uid  = (string) ( $owner['userId'] ?? '' );
			if ( '' !== $mail && '' !== $uid && isset( $by_email[ $mail ] ) ) {
				$map[ 'A-' . $uid ] = $by_email[ $mail ];
			}
		}
		return $map;
	}

	/**
	 * Generic channel ids of WhatsApp in the Conversations inbox.
	 *
	 * @return string[]|WP_Error
	 */
	private function whatsapp_channel_ids() {
		$data = $this->hs->get_json_api( '/conversations/v3/conversations/channels' );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$ids = array();
		foreach ( (array) ( $data['results'] ?? array() ) as $c ) {
			if ( false !== stripos( (string) ( $c['name'] ?? '' ), 'whats' ) ) {
				$ids[] = (string) $c['id'];
			}
		}
		return $ids;
	}
}
