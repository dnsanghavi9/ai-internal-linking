<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * V2 schema. Retrieval-first: posts carry a document embedding, passages carry
 * sentence/paragraph embeddings, opportunities record the best source passage for the link.
 * No topics, no importance — those belonged to the old lexical architecture.
 */
class AIIL_DB {

	public static function posts_table() {
		global $wpdb;
		return $wpdb->prefix . 'aiil_posts';
	}

	public static function passages_table() {
		global $wpdb;
		return $wpdb->prefix . 'aiil_passages';
	}

	public static function opportunities_table() {
		global $wpdb;
		return $wpdb->prefix . 'aiil_link_opportunities';
	}

	public static function links_table() {
		global $wpdb;
		return $wpdb->prefix . 'aiil_links';
	}

	/** Real internal links discovered by scanning existing post HTML. */
	public static function site_links_table() {
		global $wpdb;
		return $wpdb->prefix . 'aiil_site_links';
	}

	public static function queue_table() {
		global $wpdb;
		return $wpdb->prefix . 'aiil_queue';
	}

	public static function logs_table() {
		global $wpdb;
		return $wpdb->prefix . 'aiil_logs';
	}

	/** One row per Gemini API call, for the Cost tab. */
	public static function usage_table() {
		global $wpdb;
		return $wpdb->prefix . 'aiil_usage';
	}

	public static function schema() {
		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();

		$posts         = self::posts_table();
		$passages      = self::passages_table();
		$opportunities = self::opportunities_table();
		$links         = self::links_table();
		$site_links    = self::site_links_table();
		$queue         = self::queue_table();
		$logs          = self::logs_table();
		$usage         = self::usage_table();

		$sql = array();

		$sql[] = "CREATE TABLE {$posts} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			post_id BIGINT(20) UNSIGNED NOT NULL,
			blog_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 1,
			doc_vector LONGTEXT,
			content_hash VARCHAR(64) DEFAULT NULL,
			passage_count INT NOT NULL DEFAULT 0,
			incoming_links INT NOT NULL DEFAULT 0,
			outgoing_links INT NOT NULL DEFAULT 0,
			max_outgoing_links INT NOT NULL DEFAULT 3,
			indexed_at DATETIME DEFAULT NULL,
			updated_at DATETIME DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY post_blog (post_id, blog_id),
			KEY incoming_links (incoming_links)
		) {$charset_collate};";

		$sql[] = "CREATE TABLE {$passages} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			post_id BIGINT(20) UNSIGNED NOT NULL,
			blog_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 1,
			idx INT NOT NULL DEFAULT 0,
			text TEXT,
			vector LONGTEXT,
			PRIMARY KEY  (id),
			KEY post_blog (post_id, blog_id)
		) {$charset_collate};";

		$sql[] = "CREATE TABLE {$opportunities} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			source_post_id BIGINT(20) UNSIGNED NOT NULL,
			target_post_id BIGINT(20) UNSIGNED NOT NULL,
			doc_similarity FLOAT NOT NULL DEFAULT 0,
			passage_similarity FLOAT DEFAULT NULL,
			best_passage_id BIGINT(20) UNSIGNED DEFAULT NULL,
			status VARCHAR(50) NOT NULL DEFAULT 'pending',
			anchor_text VARCHAR(255) DEFAULT NULL,
			confidence FLOAT DEFAULT NULL,
			signals LONGTEXT,
			created_at DATETIME DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY source_target (source_post_id, target_post_id),
			KEY status (status),
			KEY doc_similarity (doc_similarity)
		) {$charset_collate};";

		$sql[] = "CREATE TABLE {$links} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			source_post_id BIGINT(20) UNSIGNED NOT NULL,
			target_post_id BIGINT(20) UNSIGNED NOT NULL,
			anchor_text VARCHAR(255) DEFAULT NULL,
			inserted_at DATETIME DEFAULT NULL,
			status VARCHAR(50) NOT NULL DEFAULT 'active',
			PRIMARY KEY  (id),
			KEY source_post (source_post_id),
			KEY target_post (target_post_id),
			KEY status (status)
		) {$charset_collate};";

		$sql[] = "CREATE TABLE {$site_links} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			source_post_id BIGINT(20) UNSIGNED NOT NULL,
			target_post_id BIGINT(20) UNSIGNED NOT NULL,
			blog_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 1,
			anchor_text VARCHAR(255) DEFAULT NULL,
			scanned_at DATETIME DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY source_target_blog (source_post_id, target_post_id, blog_id),
			KEY target_post (target_post_id),
			KEY blog_id (blog_id)
		) {$charset_collate};";

		$sql[] = "CREATE TABLE {$queue} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			post_id BIGINT(20) UNSIGNED DEFAULT NULL,
			job_type VARCHAR(100) NOT NULL,
			status VARCHAR(50) NOT NULL DEFAULT 'pending',
			attempts INT NOT NULL DEFAULT 0,
			payload LONGTEXT,
			last_error TEXT,
			created_at DATETIME DEFAULT NULL,
			updated_at DATETIME DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY status_type (status, job_type),
			KEY post_id (post_id)
		) {$charset_collate};";

		$sql[] = "CREATE TABLE {$logs} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			level VARCHAR(20) NOT NULL DEFAULT 'info',
			message TEXT,
			context LONGTEXT,
			created_at DATETIME DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY level (level),
			KEY created_at (created_at)
		) {$charset_collate};";

		$sql[] = "CREATE TABLE {$usage} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			blog_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 1,
			call_type VARCHAR(20) NOT NULL,
			model VARCHAR(100) NOT NULL DEFAULT '',
			service_tier VARCHAR(20) NOT NULL DEFAULT '',
			requests INT NOT NULL DEFAULT 1,
			input_tokens BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			output_tokens BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			measured TINYINT(1) NOT NULL DEFAULT 0,
			created_at DATETIME DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY blog_type (blog_id, call_type),
			KEY created_at (created_at)
		) {$charset_collate};";

		return $sql;
	}

	/**
	 * Empty all plugin data tables (keeps settings). For the testing/reset tool.
	 */
	public static function reset_data() {
		global $wpdb;
		foreach ( array( self::posts_table(), self::passages_table(), self::opportunities_table(), self::links_table(), self::site_links_table(), self::queue_table(), self::logs_table(), self::usage_table() ) as $table ) {
			$wpdb->query( "TRUNCATE TABLE {$table}" );
		}
		$like    = $wpdb->esc_like( '_transient_aiil_placement_' ) . '%';
		$like_to = $wpdb->esc_like( '_transient_timeout_aiil_placement_' ) . '%';
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$like,
				$like_to
			)
		);
	}
}
