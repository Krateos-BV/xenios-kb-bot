<?php
/**
 * Settings view — knowledge-base editor. Free version: 5 Q&A pairs.
 *
 * Renders exactly five static Q&A field pairs, pre-filled with any stored
 * pairs. Rendered inside the settings form provided by
 * Xenios_KB_Bot_Settings::render_page().
 *
 * @package Xenios_KB_Bot
 */

if ( ! defined( 'ABSPATH' ) ) exit;

$xenios_kb_bot_kb_pairs = Xenios_KB_Bot_KB::get_pairs();
?>
<p class="description">
	<?php
	printf(
		/* translators: %s: link to the paid Xenios KnowBot product. */
		esc_html__( 'Free version supports up to 5 Q&A entries. %s', 'xenios-kb-bot' ),
		wp_kses(
			sprintf(
				'<a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s &rarr;</a>',
				esc_url( 'https://xeniacloud.eu' ),
				esc_html__( 'Upgrade to Xenios KnowBot for unlimited entries', 'xenios-kb-bot' )
			),
			array( 'a' => array( 'href' => true, 'target' => true, 'rel' => true ) )
		)
	);
	?>
</p>

<div class="xkb-pairs" id="xkb-pairs">
	<?php
	for ( $xenios_kb_bot_i = 0; $xenios_kb_bot_i < 5; $xenios_kb_bot_i++ ) :
		$xenios_kb_bot_pair = isset( $xenios_kb_bot_kb_pairs[ $xenios_kb_bot_i ] )
			? $xenios_kb_bot_kb_pairs[ $xenios_kb_bot_i ]
			: array(
				'question' => '',
				'answer'   => '',
			);
		?>
		<div class="xkb-pair">
			<div class="xkb-pair-col">
				<label class="xkb-pair-label">
					<?php
					/* translators: %d: entry number (1-5). */
					printf( esc_html__( 'Question %d', 'xenios-kb-bot' ), absint( $xenios_kb_bot_i + 1 ) );
					?>
				</label>
				<textarea name="xenios_kb_bot_qa[<?php echo esc_attr( $xenios_kb_bot_i ); ?>][question]" rows="2" class="large-text"><?php echo esc_textarea( $xenios_kb_bot_pair['question'] ); ?></textarea>
			</div>
			<div class="xkb-pair-col">
				<label class="xkb-pair-label">
					<?php
					/* translators: %d: entry number (1-5). */
					printf( esc_html__( 'Answer %d', 'xenios-kb-bot' ), absint( $xenios_kb_bot_i + 1 ) );
					?>
				</label>
				<textarea name="xenios_kb_bot_qa[<?php echo esc_attr( $xenios_kb_bot_i ); ?>][answer]" rows="3" class="large-text"><?php echo esc_textarea( $xenios_kb_bot_pair['answer'] ); ?></textarea>
			</div>
		</div>
	<?php endfor; ?>
</div>

<div class="xkb-upgrade-callout">
	<?php
	printf(
		/* translators: %s: link to xeniacloud.eu. */
		esc_html__( 'Want more from your AI support bot? %s', 'xenios-kb-bot' ),
		wp_kses(
			sprintf(
				'<a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s &rarr;</a>',
				esc_url( 'https://xeniacloud.eu' ),
				esc_html__( 'See what Xenios KnowBot Premium offers', 'xenios-kb-bot' )
			),
			array( 'a' => array( 'href' => true, 'target' => true, 'rel' => true ) )
		)
	);
	?>
</div>
