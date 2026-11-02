<?php
/**
 * One processing run over all content fragments of one page. Internal helper of AIL_Processor.
 *
 * Steps:
 * 1. Existing links (R11): right link kept and counted, wrong link unwrapped.
 * 2. Claims: every keyword match on the page, longest first (R07). A claim locks its spot,
 *    whether it gets a link or not, so a shorter keyword never links inside it.
 * 3. Selection: which claims get a link, within the page and paragraph limits (R08, R09).
 *    With "smart link diversity" each record links its keywords in their saved order:
 *    first link to keyword 1, the next to the first keyword not linked yet, and so on.
 * 4. Optional: unlock long phrases that hit their limit, and/or let a shorter phrase that
 *    has no link yet on the page link once inside a long phrase that did not get a link.
 *
 * The DOM API uses camelCase property names (childNodes, parentNode, ...), which is why the naming sniff is off here.
 *
 * @package Auto_Internal_Links
 */

// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

if ( ! defined( 'ABSPATH' ) && ! defined( 'AIL_TESTING' ) ) {
	exit;
}

class AIL_Run {

	private $rules;
	private $cands;
	private $state;
	private $zwnj;
	private $home;
	private $opts;
	private $dom;

	/** Text nodes in document order: array( node, pbr paragraph id, heading section id, inside heading ). */
	private $nodes = array();

	/** All claims: array of arrays with n, s, e, id, kw, len, phrase, linked. */
	private $claims = array();

	private $pbr_id  = 0;
	private $head_id = 0;
	private $in_head = 0;
	private $next_id = 0;

	private static $skip = array( 'script', 'style', 'head', 'noscript', 'code', 'pre', 'textarea', 'select', 'option', 'button', 'svg', 'math', 'iframe', 'object', 'template', 'title' );
	private static $head = array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' );

	/**
	 * @param array $rules Active rules by id.
	 * @param array $cands Candidates sorted longest first: array( length, rule id, keyword index, regex, phrase ).
	 * @param array $state Shared page state: page, kw, phrase counters (passed by reference).
	 * @param bool  $zwnj  Half-space counts as a space.
	 * @param string $home Home URL.
	 * @param array $opts  diversity, unlock_capped, short_inside.
	 */
	public function __construct( array $rules, array $cands, array &$state, $zwnj, $home, array $opts ) {
		$this->rules = $rules;
		$this->cands = $cands;
		$this->state = &$state;
		$this->zwnj  = $zwnj;
		$this->home  = $home;
		$this->opts  = $opts;
		foreach ( array( 'page', 'kw', 'phrase', 'para' ) as $k ) {
			if ( ! isset( $this->state[ $k ] ) ) {
				$this->state[ $k ] = array();
			}
		}
	}

	/**
	 * Process several fragments as one page.
	 *
	 * @param string[] $fragments HTML fragments.
	 * @return string[]|null Processed fragments, or null when the fragments could not be split back safely.
	 */
	public function execute( array $fragments ) {
		$n    = count( $fragments );
		$html = '';
		foreach ( array_values( $fragments ) as $i => $f ) {
			$html .= '<div data-ail-seg="' . $i . '">' . $f . '</div>';
		}
		$prev      = libxml_use_internal_errors( true );
		$this->dom = new DOMDocument( '1.0', 'UTF-8' );
		$ok        = $this->dom->loadHTML(
			'<?xml encoding="UTF-8"><html><body>' . $html . '</body></html>',
			LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
		);
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );
		$body = $ok ? $this->dom->getElementsByTagName( 'body' )->item( 0 ) : null;
		if ( ! $body ) {
			return null;
		}
		$segs = array();
		foreach ( $body->childNodes as $child ) {
			if ( $child instanceof DOMElement && $child->hasAttribute( 'data-ail-seg' ) ) {
				$segs[] = $child;
			} elseif ( ! ( $child instanceof DOMText && '' === trim( $child->nodeValue ) ) ) {
				return null; // A fragment broke out of its wrapper. Let the caller fall back.
			}
		}
		if ( count( $segs ) !== $n ) {
			return null;
		}

		$this->phase1( $body );
		foreach ( $segs as $seg ) {
			$this->walk( $seg );
		}
		$this->select( $this->claim_all( null ) );
		if ( ! empty( $this->opts['unlock_capped'] ) ) {
			$this->unlock_capped();
		}
		if ( ! empty( $this->opts['short_inside'] ) ) {
			$this->short_inside();
		}
		$this->apply();

		$out = array();
		foreach ( $segs as $seg ) {
			$s = '';
			foreach ( $seg->childNodes as $child ) {
				$s .= $this->dom->saveHTML( $child );
			}
			$out[] = $this->restore_nbsp( $s );
		}
		return $out;
	}

	/**
	 * DOM writes a no-break space as a raw character. Put the entity back everywhere
	 * except inside script and style blocks, where an entity would be literal text.
	 */
	private function restore_nbsp( $html ) {
		if ( false === strpos( $html, "\xC2\xA0" ) ) {
			return $html;
		}
		$parts = preg_split( '#(<(script|style)\b.*?</\2\s*>)#is', $html, -1, PREG_SPLIT_DELIM_CAPTURE );
		if ( false === $parts ) {
			return $html;
		}
		$out = '';
		$n   = count( $parts );
		for ( $i = 0; $i < $n; $i++ ) {
			if ( 0 === $i % 3 ) {
				$out .= str_replace( "\xC2\xA0", '&nbsp;', $parts[ $i ] );
			} elseif ( 1 === $i % 3 ) {
				$out .= $parts[ $i ];
			}
		}
		return $out;
	}

	// ── 1. Existing links ───────────────────────────────────────────────────

	/** R11: right link is kept and counted, wrong link is unwrapped, everything else is untouched. */
	private function phase1( DOMNode $body ) {
		$anchors = array();
		$this->collect_anchors( $body, $anchors );
		foreach ( $anchors as $a ) {
			if ( ! $a->parentNode || ! $a->hasAttribute( 'href' ) ) {
				continue;
			}
			$href = trim( $a->getAttribute( 'href' ) );
			if ( '' === $href || '#' === $href[0] || preg_match( '#^(?!https?:)[a-z][a-z0-9+.\-]*:#i', $href ) ) {
				continue; // Anchors, mailto, tel and similar are never ours.
			}
			$text = AIL_Processor::norm_text( $a->textContent, $this->zwnj, false );
			foreach ( $this->rules as $id => $r ) {
				if ( ! isset( $r['norm'][ $text ] ) ) {
					continue;
				}
				if ( AIL_Processor::norm_url( $href, $this->home ) === AIL_Processor::norm_url( $r['target_url'], $this->home ) ) {
					$this->bump( 'page', $id );
					$this->state['kw'][ $id ][ $r['norm'][ $text ] ] = $this->get_kw( $id, $r['norm'][ $text ] ) + 1;
					$this->bump( 'phrase', $text );
				} else {
					$this->unwrap( $a );
				}
				break;
			}
		}
	}

	private function collect_anchors( DOMNode $node, array &$out ) {
		foreach ( $this->children( $node ) as $child ) {
			if ( $child instanceof DOMElement ) {
				if ( 'a' === strtolower( $child->nodeName ) ) {
					$out[] = $child;
				} else {
					$this->collect_anchors( $child, $out );
				}
			}
		}
	}

	// ── Text nodes and paragraphs ───────────────────────────────────────────

	/** Collect text nodes in order, with their paragraph ids for both paragraph modes (R09, R10). */
	private function walk( DOMNode $node ) {
		foreach ( $this->children( $node ) as $child ) {
			if ( $child instanceof DOMText ) {
				if ( '' !== trim( $child->nodeValue ) ) {
					$this->nodes[] = array( $child, $this->pbr_id, $this->head_id, $this->in_head > 0 );
				}
				continue;
			}
			if ( ! ( $child instanceof DOMElement ) ) {
				continue;
			}
			$tag = strtolower( $child->nodeName );
			if ( 'a' === $tag || in_array( $tag, self::$skip, true ) ) {
				continue; // R14.
			}
			if ( 'br' === $tag ) {
				$this->pbr_id = ++$this->next_id;
				continue;
			}
			if ( 'p' === $tag ) {
				$saved        = $this->pbr_id;
				$this->pbr_id = ++$this->next_id;
				$this->walk( $child );
				$this->pbr_id = $saved;
				continue;
			}
			if ( in_array( $tag, self::$head, true ) ) {
				$this->head_id = ++$this->next_id; // R10: a heading starts a new section.
				++$this->in_head;
				$this->walk( $child );
				--$this->in_head;
				continue;
			}
			$this->walk( $child );
		}
	}

	// ── 2. Claims ───────────────────────────────────────────────────────────

	/**
	 * Find matches, longest first, that do not overlap an existing claim.
	 *
	 * @param array|null $inside Limit to these spans: array of array( node index, start, end, max length ). Null means the whole page.
	 * @return int[] Indexes of the new claims.
	 */
	private function claim_all( $inside ) {
		$new = array();
		foreach ( $this->nodes as $ni => $nd ) {
			if ( null !== $inside && ! isset( $inside[ $ni ] ) ) {
				continue;
			}
			$text = $nd[0]->nodeValue;
			foreach ( $this->cands as $c ) {
				$id = $c[1];
				$r  = $this->rules[ $id ];
				if ( 'heading' === $r['para_mode'] && $nd[3] ) {
					continue; // Heading mode: text inside a heading never gets a link.
				}
				if ( ! preg_match_all( $c[3], $text, $m, PREG_OFFSET_CAPTURE ) ) {
					continue;
				}
				foreach ( $m[0] as $hit ) {
					$s = $hit[1];
					$e = $s + strlen( $hit[0] );
					if ( null !== $inside && ! $this->in_spans( $inside[ $ni ], $s, $e, $c[0] ) ) {
						continue;
					}
					if ( $this->overlaps( $ni, $s, $e ) ) {
						continue; // A longer keyword already holds this spot.
					}
					$this->claims[] = array(
						'n'      => $ni,
						's'      => $s,
						'e'      => $e,
						'id'     => $id,
						'kw'     => $c[2],
						'len'    => $c[0],
						'phrase' => $c[4],
						'linked' => false,
						'free'   => false,
					);
					$new[]          = count( $this->claims ) - 1;
				}
			}
		}
		return $new;
	}

	private function in_spans( array $spans, $s, $e, $len ) {
		foreach ( $spans as $sp ) {
			if ( $s >= $sp[0] && $e <= $sp[1] && $len < $sp[2] ) {
				return true;
			}
		}
		return false;
	}

	/** True when [s, e) overlaps a claim that still holds its spot. */
	private function overlaps( $ni, $s, $e ) {
		foreach ( $this->claims as $c ) {
			if ( $c['n'] !== $ni || $c['free'] ) {
				continue;
			}
			if ( ! ( $e <= $c['s'] || $s >= $c['e'] ) ) {
				return true;
			}
		}
		return false;
	}

	// ── 3. Selection ────────────────────────────────────────────────────────

	/**
	 * Decide which claims get a link.
	 *
	 * @param int[] $keys Claim indexes to choose from.
	 */
	private function select( array $keys ) {
		if ( ! $keys ) {
			return;
		}
		usort(
			$keys,
			function ( $a, $b ) {
				$ca = $this->claims[ $a ];
				$cb = $this->claims[ $b ];
				return ( $ca['n'] <=> $cb['n'] ) ?: ( $ca['s'] <=> $cb['s'] );
			}
		);
		if ( empty( $this->opts['diversity'] ) ) {
			foreach ( $keys as $k ) {
				if ( $this->eligible( $k ) ) {
					$this->link( $k );
				}
			}
			return;
		}
		// Smart link diversity: per record, keywords in their saved order, round by round.
		$by_rule = array();
		foreach ( $keys as $k ) {
			$c                                     = $this->claims[ $k ];
			$by_rule[ $c['id'] ][ $c['kw'] ][]     = $k;
		}
		foreach ( $by_rule as $id => $by_kw ) {
			ksort( $by_kw );
			$rounds = 0;
			foreach ( $by_kw as $kw => $list ) {
				$rounds = max( $rounds, $this->get_kw( $id, $kw ) + count( $list ) );
			}
			for ( $round = 1; $round <= $rounds; $round++ ) {
				foreach ( $by_kw as $kw => $list ) {
					if ( $this->get( 'page', $id ) >= $this->rules[ $id ]['max_links'] ) {
						break 2;
					}
					if ( $this->get_kw( $id, $kw ) >= $round ) {
						continue; // This keyword already has its link for this round.
					}
					foreach ( $list as $k ) {
						if ( ! $this->claims[ $k ]['linked'] && $this->eligible( $k ) ) {
							$this->link( $k );
							break;
						}
					}
				}
			}
		}
	}

	private function eligible( $k ) {
		$c  = $this->claims[ $k ];
		$id = $c['id'];
		$r  = $this->rules[ $id ];
		if ( $this->get( 'page', $id ) >= $r['max_links'] ) {
			return false; // R08.
		}
		if ( $r['para_max'] > 0 && $this->get_para( $k ) >= $r['para_max'] ) {
			return false; // R09.
		}
		return true;
	}

	private function para_key( $k ) {
		$c  = $this->claims[ $k ];
		$nd = $this->nodes[ $c['n'] ];
		return ( 'heading' === $this->rules[ $c['id'] ]['para_mode'] ? 'h' . $nd[2] : 'p' . $nd[1] ) . ':' . $c['id'];
	}

	private function get_para( $k ) {
		$pk = $this->para_key( $k );
		return isset( $this->state['para'][ $pk ] ) ? $this->state['para'][ $pk ] : 0;
	}

	private function link( $k ) {
		$c                                 = &$this->claims[ $k ];
		$c['linked']                       = true;
		$id                                = $c['id'];
		$this->bump( 'page', $id );
		$this->state['kw'][ $id ][ $c['kw'] ] = $this->get_kw( $id, $c['kw'] ) + 1;
		$this->bump( 'phrase', $c['phrase'] );
		$pk                                = $this->para_key( $k );
		$this->state['para'][ $pk ]        = ( isset( $this->state['para'][ $pk ] ) ? $this->state['para'][ $pk ] : 0 ) + 1;
	}

	// ── 4. Optional ticks ───────────────────────────────────────────────────

	/** Long phrases that did not get a link because of a limit free their spot for shorter keywords. */
	private function unlock_capped() {
		for ( $pass = 0; $pass < 5; $pass++ ) {
			$spans = array();
			foreach ( $this->claims as $k => $c ) {
				if ( $c['linked'] || $c['free'] || $this->eligible( $k ) ) {
					continue;
				}
				$this->claims[ $k ]['free'] = true;
				$spans[ $c['n'] ][]         = array( $c['s'], $c['e'], $c['len'] );
			}
			if ( ! $spans ) {
				return;
			}
			$new = $this->claim_all( $spans );
			if ( ! $new ) {
				return;
			}
			$this->select( $new );
		}
	}

	/** A shorter phrase with no link yet on this page links once inside a long phrase that has no link. */
	private function short_inside() {
		foreach ( $this->claims as $k => $c ) {
			if ( $c['linked'] || $c['free'] ) {
				continue;
			}
			$text = $this->nodes[ $c['n'] ][0]->nodeValue;
			foreach ( $this->cands as $cand ) {
				if ( $cand[0] >= $c['len'] || $this->get( 'phrase', $cand[4] ) > 0 ) {
					continue;
				}
				$id = $cand[1];
				$r  = $this->rules[ $id ];
				if ( 'heading' === $r['para_mode'] && $this->nodes[ $c['n'] ][3] ) {
					continue;
				}
				if ( ! preg_match_all( $cand[3], $text, $m, PREG_OFFSET_CAPTURE ) ) {
					continue;
				}
				foreach ( $m[0] as $hit ) {
					$s = $hit[1];
					$e = $s + strlen( $hit[0] );
					if ( $s < $c['s'] || $e > $c['e'] ) {
						continue;
					}
					$this->claims[ $k ]['free'] = true; // The long phrase gives up this spot for the inner link.
					$this->claims[]             = array(
						'n'      => $c['n'],
						's'      => $s,
						'e'      => $e,
						'id'     => $id,
						'kw'     => $cand[2],
						'len'    => $cand[0],
						'phrase' => $cand[4],
						'linked' => false,
						'free'   => false,
					);
					$nk                         = count( $this->claims ) - 1;
					if ( $this->eligible( $nk ) ) {
						$this->link( $nk );
						continue 3; // One inner link per long phrase.
					}
					array_pop( $this->claims );
					$this->claims[ $k ]['free'] = false;
					break;
				}
			}
		}
	}

	// ── Output ──────────────────────────────────────────────────────────────

	private function apply() {
		$by_node = array();
		foreach ( $this->claims as $c ) {
			if ( $c['linked'] ) {
				$by_node[ $c['n'] ][] = $c;
			}
		}
		foreach ( $by_node as $ni => $list ) {
			usort(
				$list,
				function ( $a, $b ) {
					return $a['s'] <=> $b['s'];
				}
			);
			$node = $this->nodes[ $ni ][0];
			$text = $node->nodeValue;
			$frag = $this->dom->createDocumentFragment();
			$pos  = 0;
			foreach ( $list as $t ) {
				if ( $t['s'] < $pos ) {
					continue; // Never happens by construction. Safety net against nested links.
				}
				if ( $t['s'] > $pos ) {
					$frag->appendChild( $this->dom->createTextNode( substr( $text, $pos, $t['s'] - $pos ) ) );
				}
				$a = $this->dom->createElement( 'a' );
				$a->setAttribute( 'href', esc_url_raw( $this->rules[ $t['id'] ]['target_url'], array( 'http', 'https' ) ) ); // R21.
				$a->appendChild( $this->dom->createTextNode( substr( $text, $t['s'], $t['e'] - $t['s'] ) ) );
				$frag->appendChild( $a );
				$pos = $t['e'];
			}
			if ( $pos < strlen( $text ) ) {
				$frag->appendChild( $this->dom->createTextNode( substr( $text, $pos ) ) );
			}
			$node->parentNode->replaceChild( $frag, $node );
		}
	}

	// ── Small helpers ───────────────────────────────────────────────────────

	private function get( $bucket, $key ) {
		return isset( $this->state[ $bucket ][ $key ] ) ? $this->state[ $bucket ][ $key ] : 0;
	}

	private function bump( $bucket, $key ) {
		$this->state[ $bucket ][ $key ] = $this->get( $bucket, $key ) + 1;
	}

	private function get_kw( $id, $kw ) {
		return isset( $this->state['kw'][ $id ][ $kw ] ) ? $this->state['kw'][ $id ][ $kw ] : 0;
	}

	private function children( DOMNode $node ) {
		$out = array();
		foreach ( $node->childNodes as $c ) {
			$out[] = $c;
		}
		return $out;
	}

	private function unwrap( DOMElement $node ) {
		$parent = $node->parentNode;
		if ( ! $parent ) {
			return;
		}
		while ( $node->firstChild ) {
			$parent->insertBefore( $node->firstChild, $node );
		}
		$parent->removeChild( $node );
	}
}
