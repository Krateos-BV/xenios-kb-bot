<?php
/**
 * Knowledge base storage and retrieval.
 *
 * The knowledge base is a list of question/answer pairs stored in a single
 * wp_options row. It never leaves the WordPress database.
 *
 * @package Xenios_KB_Bot
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Xenios_KB_Bot_KB {

	/** wp_options key holding the Q&A pairs. */
	const OPTION = 'xenios_kb_bot_kb';

	public function __construct() {
		// Stub.
	}

	public static function init() {
		// Stub — storage is accessed statically; no hooks required.
	}

	/**
	 * Return the stored knowledge-base pairs.
	 *
	 * @return array<int,array{question:string,answer:string}>
	 */
	public static function get_pairs(): array {
		$raw = get_option( self::OPTION, array() );
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$pairs = array();
		foreach ( $raw as $entry ) {
			if ( ! is_array( $entry ) || ! isset( $entry['question'], $entry['answer'] ) ) {
				continue;
			}
			$pairs[] = array(
				'question' => (string) $entry['question'],
				'answer'   => (string) $entry['answer'],
			);
		}
		return $pairs;
	}

	/**
	 * Persist the knowledge-base pairs. No entry limit.
	 *
	 * @param array<int,array{question:string,answer:string}> $pairs
	 */
	public static function save( array $pairs ): void {
		$clean = array();
		foreach ( $pairs as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$question = isset( $entry['question'] ) ? sanitize_textarea_field( $entry['question'] ) : '';
			$answer   = isset( $entry['answer'] ) ? sanitize_textarea_field( $entry['answer'] ) : '';
			if ( $question === '' && $answer === '' ) {
				continue;
			}
			$clean[] = array(
				'question' => $question,
				'answer'   => $answer,
			);
		}

		update_option( self::OPTION, $clean );
	}
}
