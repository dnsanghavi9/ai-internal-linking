<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Knowledge-graph data builder.
 *
 * Turns the semantic database into a post graph: nodes are indexed posts, edges are the
 * semantic connections the plugin already discovered (opportunities), with actually-inserted
 * links highlighted. Topic communities are detected with label propagation so related posts
 * share a colour — all corpus-derived, no niche assumptions.
 */
class AIIL_Graph {

	/**
	 * @param array $args {
	 *     @type float  $min_sim    Minimum undirected edge similarity (0-100). Default: setting.
	 *     @type string $edge_type  'semantic' | 'links' | 'both'. Default 'semantic'.
	 *     @type bool   $orphans    Include posts with no edges. Default true.
	 * }
	 * @return array{nodes:array,edges:array,meta:array}
	 */
	public static function data( array $args = array() ) {
		global $wpdb;

		$min_sim   = isset( $args['min_sim'] ) && null !== $args['min_sim'] ? (float) $args['min_sim'] : (float) AIIL_Settings::get( 'min_doc_similarity', 55 );
		$edge_type = isset( $args['edge_type'] ) && in_array( $args['edge_type'], array( 'semantic', 'links', 'both' ), true ) ? $args['edge_type'] : 'semantic';
		$orphans   = ! isset( $args['orphans'] ) || (bool) $args['orphans'];
		$blog_id   = get_current_blog_id();

		// --- Nodes: indexed posts ------------------------------------------------------
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT m.post_id, m.incoming_links, m.outgoing_links, m.passage_count,
				        p.post_title
				 FROM " . AIIL_DB::posts_table() . " m
				 INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id
				 WHERE m.blog_id = %d AND m.indexed_at IS NOT NULL",
				$blog_id
			)
		);

		$nodes = array();
		foreach ( $rows as $r ) {
			$id = (int) $r->post_id;
			$nodes[ $id ] = array(
				'id'        => $id,
				'title'     => (string) $r->post_title,
				'incoming'  => (int) $r->incoming_links,
				'outgoing'  => (int) $r->outgoing_links,
				'passages'  => (int) $r->passage_count,
				'edit'      => get_edit_post_link( $id, 'raw' ),
				'community' => $id,   // seeded; overwritten by label propagation
				'degree'    => 0,
			);
		}
		if ( empty( $nodes ) ) {
			return array( 'nodes' => array(), 'edges' => array(), 'meta' => self::meta( 0, 0, $min_sim, $edge_type ) );
		}

		// --- Inserted links (active) as an undirected set ------------------------------
		$inserted = array();
		if ( 'semantic' !== $edge_type ) {
			$links = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT source_post_id AS s, target_post_id AS t FROM " . AIIL_DB::links_table() . " WHERE status = %s",
					'active'
				)
			);
			foreach ( $links as $l ) {
				$inserted[ self::pair_key( (int) $l->s, (int) $l->t ) ] = true;
			}
		}

		// --- Edges ---------------------------------------------------------------------
		$edges = array();

		if ( 'links' === $edge_type ) {
			foreach ( array_keys( $inserted ) as $key ) {
				list( $a, $b ) = explode( '-', $key );
				if ( isset( $nodes[ (int) $a ], $nodes[ (int) $b ] ) ) {
					$edges[ $key ] = array( 'source' => (int) $a, 'target' => (int) $b, 'weight' => 100.0, 'inserted' => true );
				}
			}
		} else {
			// Semantic edges from opportunities, deduped to undirected pairs keeping the max
			// similarity. Skip decided-away states so the graph shows genuine relationships.
			$opps = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT source_post_id AS s, target_post_id AS t, doc_similarity AS sim, status
					 FROM " . AIIL_DB::opportunities_table() . "
					 WHERE doc_similarity >= %f
					   AND status NOT IN ('invalid')",
					$min_sim
				)
			);
			foreach ( $opps as $o ) {
				$a = (int) $o->s;
				$b = (int) $o->t;
				if ( ! isset( $nodes[ $a ], $nodes[ $b ] ) ) {
					continue;
				}
				$key = self::pair_key( $a, $b );
				$sim = (float) $o->sim;
				if ( ! isset( $edges[ $key ] ) || $sim > $edges[ $key ]['weight'] ) {
					list( $la, $lb ) = array_map( 'intval', explode( '-', $key ) );
					$edges[ $key ] = array(
						'source'   => $la,
						'target'   => $lb,
						'weight'   => round( $sim, 1 ),
						'inserted' => isset( $inserted[ $key ] ),
					);
				}
				if ( 'inserted' === $o->status ) {
					$edges[ $key ]['inserted'] = true;
				}
			}
		}

		// Degree + adjacency for clustering.
		$adj = array();
		foreach ( $edges as $e ) {
			$nodes[ $e['source'] ]['degree']++;
			$nodes[ $e['target'] ]['degree']++;
			$adj[ $e['source'] ][] = array( $e['target'], $e['weight'] );
			$adj[ $e['target'] ][] = array( $e['source'], $e['weight'] );
		}

		self::label_propagation( $nodes, $adj );

		if ( ! $orphans ) {
			foreach ( $nodes as $id => $n ) {
				if ( 0 === $n['degree'] ) {
					unset( $nodes[ $id ] );
				}
			}
		}

		// Renumber communities to small contiguous ids for stable colouring.
		self::compact_communities( $nodes );

		return array(
			'nodes' => array_values( $nodes ),
			'edges' => array_values( $edges ),
			'meta'  => self::meta( count( $nodes ), count( $edges ), $min_sim, $edge_type ),
		);
	}

	protected static function meta( $node_count, $edge_count, $min_sim, $edge_type ) {
		return array(
			'nodes'     => (int) $node_count,
			'edges'     => (int) $edge_count,
			'min_sim'   => (float) $min_sim,
			'edge_type' => (string) $edge_type,
		);
	}

	protected static function pair_key( $a, $b ) {
		return $a < $b ? $a . '-' . $b : $b . '-' . $a;
	}

	/**
	 * Weighted label propagation: each node repeatedly adopts the strongest label among its
	 * neighbours. A few passes over a shuffled node order converge to topic communities.
	 */
	protected static function label_propagation( array &$nodes, array $adj, $iterations = 12 ) {
		$labels = array();
		foreach ( $nodes as $id => $n ) {
			$labels[ $id ] = $id;
		}
		$ids = array_keys( $nodes );

		for ( $it = 0; $it < $iterations; $it++ ) {
			shuffle( $ids );
			$changed = false;
			foreach ( $ids as $id ) {
				if ( empty( $adj[ $id ] ) ) {
					continue;
				}
				$scores = array();
				foreach ( $adj[ $id ] as $pair ) {
					$nb  = $pair[0];
					$w   = (float) $pair[1];
					$lbl = $labels[ $nb ];
					$scores[ $lbl ] = ( $scores[ $lbl ] ?? 0 ) + $w;
				}
				arsort( $scores );
				$best = array_key_first( $scores );
				if ( null !== $best && $labels[ $id ] !== (int) $best ) {
					$labels[ $id ] = (int) $best;
					$changed       = true;
				}
			}
			if ( ! $changed ) {
				break;
			}
		}

		foreach ( $labels as $id => $lbl ) {
			$nodes[ $id ]['community'] = (int) $lbl;
		}
	}

	protected static function compact_communities( array &$nodes ) {
		$map  = array();
		$next = 0;
		foreach ( $nodes as $id => $n ) {
			$c = $n['community'];
			if ( ! isset( $map[ $c ] ) ) {
				$map[ $c ] = $next++;
			}
			$nodes[ $id ]['community'] = $map[ $c ];
		}
	}
}
