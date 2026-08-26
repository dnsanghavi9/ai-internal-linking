<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIIL_Queue {

	const JOB_INDEX_POST         = 'index_post';
	const JOB_GENERATE_MATCHES   = 'generate_matches';
	const JOB_PLACE_LINK         = 'place_link';
	const JOB_FINALIZE_POST      = 'finalize_post';

	const STATUS_PENDING    = 'pending';
	const STATUS_PROCESSING = 'processing';
	const STATUS_DONE       = 'done';
	const STATUS_FAILED     = 'failed';

	const MAX_ATTEMPTS = 3;

	/** Transient set while the API is rate limiting us; the worker pauses until it expires. */
	const BACKOFF_KEY     = 'aiil_rate_limited_until';
	const BACKOFF_SECONDS = 120;

	/**
	 * Is this failure the API telling us to slow down, rather than a problem with the job?
	 * Quota/rate/overload errors are transient and must not burn the job's retry budget —
	 * otherwise a large site indexing hundreds of posts permanently loses every post that
	 * happened to run while the key was throttled.
	 */
	public static function is_rate_limit_error( $message ) {
		return (bool) preg_match(
			'/\b(?:429|503)\b|RESOURCE_EXHAUSTED|UNAVAILABLE|quota|rate limit|too many requests|overloaded/i',
			(string) $message
		);
	}

	/** True while we are backing off from a rate limit. */
	public static function is_backing_off() {
		return (bool) get_transient( self::BACKOFF_KEY );
	}

	/**
	 * Add a job to the queue.
	 *
	 * @param bool $dedupe When true (default) a post-based job is skipped if an
	 *                     unfinished (pending/processing) job of the same type already
	 *                     exists for that post — prevents the queue filling with
	 *                     duplicates when "Analyze Existing Posts" is clicked repeatedly
	 *                     or save_post fires several times for one edit.
	 * @return int The queue row id (existing one when de-duplicated).
	 */
	public static function enqueue( $job_type, $post_id = null, $payload = array(), $dedupe = true ) {
		global $wpdb;

		if ( $dedupe && $post_id ) {
			$existing = self::find_unfinished( $job_type, (int) $post_id );
			if ( $existing ) {
				return (int) $existing;
			}
		}

		$now = current_time( 'mysql' );

		$wpdb->insert(
			AIIL_DB::queue_table(),
			array(
				'post_id'    => $post_id ? (int) $post_id : null,
				'job_type'   => $job_type,
				'status'     => self::STATUS_PENDING,
				'attempts'   => 0,
				'payload'    => $payload ? wp_json_encode( $payload ) : null,
				'created_at' => $now,
				'updated_at' => $now,
			)
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Id of an existing pending/processing job for this type+post, or 0.
	 */
	public static function find_unfinished( $job_type, $post_id ) {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM " . AIIL_DB::queue_table() . "
				 WHERE job_type = %s AND post_id = %d AND status IN (%s, %s)
				 ORDER BY id ASC LIMIT 1",
				$job_type,
				(int) $post_id,
				self::STATUS_PENDING,
				self::STATUS_PROCESSING
			)
		);
	}

	/**
	 * Whether an unfinished place_link job already targets this opportunity.
	 * place_link jobs carry the opportunity id in their JSON payload (post_id is null),
	 * so they can't be de-duplicated by the post_id path above.
	 */
	public static function has_unfinished_place_link( $opportunity_id ) {
		global $wpdb;
		$needle = '%' . $wpdb->esc_like( '"opportunity_id":' . (int) $opportunity_id ) . '%';
		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM " . AIIL_DB::queue_table() . "
				 WHERE job_type = %s AND status IN (%s, %s) AND payload LIKE %s
				 LIMIT 1",
				self::JOB_PLACE_LINK,
				self::STATUS_PENDING,
				self::STATUS_PROCESSING,
				$needle
			)
		);
	}

	public static function counts() {
		global $wpdb;
		$table = AIIL_DB::queue_table();
		$rows  = $wpdb->get_results( "SELECT status, COUNT(*) AS c FROM {$table} GROUP BY status", ARRAY_A );
		$out   = array(
			self::STATUS_PENDING    => 0,
			self::STATUS_PROCESSING => 0,
			self::STATUS_DONE       => 0,
			self::STATUS_FAILED     => 0,
		);
		foreach ( $rows as $row ) {
			$out[ $row['status'] ] = (int) $row['c'];
		}
		return $out;
	}

	public static function pending_count_for( $job_type ) {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM " . AIIL_DB::queue_table() . " WHERE status = %s AND job_type = %s",
				self::STATUS_PENDING,
				$job_type
			)
		);
	}

	public static function process_tick( $limit = null ) {
		global $wpdb;

		// Stop while the API is throttling us. Continuing would just convert every queued post
		// into another rate-limit error, and on a large site that is how a whole batch gets lost.
		if ( self::is_backing_off() ) {
			return 0;
		}

		// Single-flight: only one worker drains the queue at a time. Prevents duplicate
		// work when cron and the browser runner overlap, and keeps request concurrency
		// (and thus the AI provider's request rate) in check. If another worker holds the
		// lock we return 0 immediately; the caller can retry shortly. The MySQL lock is
		// released automatically if the request dies before RELEASE_LOCK runs.
		$lock_name = substr( $wpdb->prefix . 'aiil_queue', 0, 64 );
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $lock_name, 0 ) ) ) {
			return 0;
		}

		try {
			// Recover jobs left in 'processing' by a previous tick that timed out or died.
			self::requeue_stale_processing();

			$batch_size = null === $limit ? (int) AIIL_Settings::get( 'batch_size', 50 ) : (int) $limit;
			$jobs       = self::claim_batch( $batch_size );

			// Process within a time budget so a slow batch (each analyze job is an API call)
			// can't run past PHP's max_execution_time. Anything left claimed is requeued.
			$start     = time();
			$budget    = 20; // seconds
			$processed = 0;
			$leftover  = array();

			foreach ( $jobs as $i => $job ) {
				if ( ( time() - $start ) >= $budget ) {
					$leftover = array_slice( $jobs, $i );
					break;
				}
				self::dispatch( $job );
				$processed++;
			}

			if ( $leftover ) {
				self::release( $leftover );
			}

			return $processed;
		} finally {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
		}
	}

	/**
	 * Return claimed-but-unprocessed jobs to the pending pool without burning an attempt.
	 */
	protected static function release( array $jobs ) {
		global $wpdb;
		$table = AIIL_DB::queue_table();
		foreach ( $jobs as $job ) {
			$wpdb->update(
				$table,
				array(
					'status'     => self::STATUS_PENDING,
					'attempts'   => max( 0, (int) $job->attempts - 1 ),
					'updated_at' => current_time( 'mysql' ),
				),
				array( 'id' => (int) $job->id )
			);
		}
	}

	/**
	 * Reset jobs stuck in 'processing' (e.g. a tick that fatal-errored before marking them
	 * done). Exhausted jobs are failed; the rest go back to pending for another attempt.
	 */
	public static function requeue_stale_processing( $older_than_minutes = 5 ) {
		global $wpdb;
		$table  = AIIL_DB::queue_table();
		$mins   = max( 1, (int) $older_than_minutes );
		$cutoff = date( 'Y-m-d H:i:s', current_time( 'timestamp' ) - ( $mins * 60 ) );
		$now    = current_time( 'mysql' );

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = %s, updated_at = %s
				 WHERE status = %s AND attempts >= %d AND updated_at < %s",
				self::STATUS_FAILED,
				$now,
				self::STATUS_PROCESSING,
				self::MAX_ATTEMPTS,
				$cutoff
			)
		);

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = %s, updated_at = %s
				 WHERE status = %s AND attempts < %d AND updated_at < %s",
				self::STATUS_PENDING,
				$now,
				self::STATUS_PROCESSING,
				self::MAX_ATTEMPTS,
				$cutoff
			)
		);
	}

	protected static function claim_batch( $limit ) {
		global $wpdb;
		$table = AIIL_DB::queue_table();
		$limit = max( 1, (int) $limit );

		$pending = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE status = %s ORDER BY id ASC LIMIT %d",
				self::STATUS_PENDING,
				$limit
			)
		);

		$claimed = array();
		foreach ( $pending as $job ) {
			$updated = $wpdb->update(
				$table,
				array(
					'status'     => self::STATUS_PROCESSING,
					'attempts'   => (int) $job->attempts + 1,
					'updated_at' => current_time( 'mysql' ),
				),
				array(
					'id'     => $job->id,
					'status' => self::STATUS_PENDING,
				)
			);
			if ( $updated ) {
				$job->attempts = (int) $job->attempts + 1;
				$claimed[]     = $job;
			}
		}

		return $claimed;
	}

	protected static function dispatch( $job ) {
		try {
			$payload = $job->payload ? json_decode( $job->payload, true ) : array();
			if ( ! is_array( $payload ) ) {
				$payload = array();
			}

			switch ( $job->job_type ) {
				case self::JOB_INDEX_POST:
					$indexed = AIIL_Indexer::index_post( (int) $job->post_id );
					// Only (re)match if the post was actually indexed this run (not an unchanged skip).
					if ( $indexed && empty( $indexed['skipped'] ) ) {
						AIIL_Matcher::flush_cache();
						AIIL_Idf::flush(); // corpus changed; rebuild specificity map lazily
						AIIL_Queue::enqueue( self::JOB_GENERATE_MATCHES, (int) $job->post_id, array( 'auto' => ! empty( $payload['auto'] ) ? 1 : 0 ) );
					}
					break;

				case self::JOB_GENERATE_MATCHES:
					AIIL_Matcher::generate_for_post( (int) $job->post_id );
					// Auto-link only real publish/edit events (carried by the 'auto' flag), so the
					// bulk initial scan stays under the user's control on the Dashboard.
					if ( ! empty( $payload['auto'] ) && (int) AIIL_Settings::get( 'auto_link_new', 1 ) === 1 ) {
						AIIL_Queue::enqueue( self::JOB_FINALIZE_POST, (int) $job->post_id );
					}
					break;

				case self::JOB_FINALIZE_POST:
					AIIL_Placement::finalize_post( (int) $job->post_id );
					break;

				case self::JOB_PLACE_LINK:
					$opportunity_id = isset( $payload['opportunity_id'] ) ? (int) $payload['opportunity_id'] : 0;
					if ( $opportunity_id ) {
						AIIL_Placement::process_opportunity( $opportunity_id );
					}
					break;

				default:
					throw new Exception( 'Unknown job type: ' . $job->job_type );
			}

			self::mark_done( $job->id );
		} catch ( Exception $e ) {
			self::mark_failed_or_retry( $job, $e->getMessage() );
		}
	}

	protected static function mark_done( $job_id ) {
		global $wpdb;
		$wpdb->update(
			AIIL_DB::queue_table(),
			array(
				'status'     => self::STATUS_DONE,
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'id' => (int) $job_id )
		);
	}

	protected static function mark_failed_or_retry( $job, $error ) {
		global $wpdb;

		// Rate limiting is not the job's fault. Refund the attempt, put the job straight back in
		// the queue, and pause the whole worker briefly so we stop hammering a throttled key.
		if ( self::is_rate_limit_error( $error ) ) {
			set_transient( self::BACKOFF_KEY, time(), self::BACKOFF_SECONDS );
			$wpdb->update(
				AIIL_DB::queue_table(),
				array(
					'status'     => self::STATUS_PENDING,
					'attempts'   => max( 0, (int) $job->attempts - 1 ),
					'last_error' => $error,
					'updated_at' => current_time( 'mysql' ),
				),
				array( 'id' => (int) $job->id )
			);
			AIIL_Logger::warning(
				'API rate limit — pausing the queue and retrying this job later',
				array( 'job_id' => (int) $job->id, 'post_id' => (int) $job->post_id, 'pause_seconds' => self::BACKOFF_SECONDS )
			);
			return;
		}

		$status = ( (int) $job->attempts >= self::MAX_ATTEMPTS ) ? self::STATUS_FAILED : self::STATUS_PENDING;

		$wpdb->update(
			AIIL_DB::queue_table(),
			array(
				'status'     => $status,
				'last_error' => $error,
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'id' => (int) $job->id )
		);

		AIIL_Logger::error(
			'Job ' . $job->job_type . ' failed',
			array(
				'job_id'   => (int) $job->id,
				'post_id'  => (int) $job->post_id,
				'attempts' => (int) $job->attempts,
				'error'    => $error,
				'status'   => $status,
			)
		);
	}

	/**
	 * Put failed jobs back in the queue with a fresh attempt budget. Without this a post that
	 * failed three times (typically while the API key was throttled) is abandoned permanently
	 * with no way back short of clearing all plugin data.
	 *
	 * @return int Number of jobs requeued.
	 */
	public static function retry_failed() {
		global $wpdb;
		$count = (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE " . AIIL_DB::queue_table() . "
				 SET status = %s, attempts = 0, last_error = NULL, updated_at = %s
				 WHERE status = %s",
				self::STATUS_PENDING,
				current_time( 'mysql' ),
				self::STATUS_FAILED
			)
		);
		delete_transient( self::BACKOFF_KEY ); // let the worker start again immediately
		AIIL_Logger::info( 'Requeued failed jobs', array( 'jobs' => $count ) );
		return $count;
	}

	public static function purge_completed( $older_than_days = 7 ) {
		global $wpdb;
		$days = max( 1, (int) $older_than_days );
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM " . AIIL_DB::queue_table() . " WHERE status = %s AND updated_at < (NOW() - INTERVAL %d DAY)",
				self::STATUS_DONE,
				$days
			)
		);
	}
}
