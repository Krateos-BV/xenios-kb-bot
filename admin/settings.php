<?php
/**
 * Settings view — knowledge-base editor. Unlimited Q&A pairs.
 *
 * Renders every stored Q&A pair plus one empty pair at the end, with an
 * "Add another Q&A pair" button and per-row "Remove" links (admin.js handles
 * the dynamic behaviour). Rendered inside the settings form provided by
 * Xenios_KB_Bot_Settings::render_page().
 *
 * @package Xenios_KB_Bot
 */

if ( ! defined( 'ABSPATH' ) ) exit;

$xenios_kb_bot_kb_pairs = Xenios_KB_Bot_KB::get_pairs();
// Always render one empty pair at the end so there is a blank row to fill.
$xenios_kb_bot_kb_pairs[] = array( 'question' => '', 'answer' => '' );

/**
 * Render a single editable pair row.
 *
 * @param int    $index    Field index used in the input name.
 * @param string $question Question text.
 * @param string $answer   Answer text.
 */
if ( ! function_exists( 'xenios_kb_bot_render_pair_row' ) ) :
function xenios_kb_bot_render_pair_row( $index, $question = '', $answer = '' ) {
	?>
	<div class="xkb-pair" data-xkb-pair>
		<a href="#" class="xkb-remove-pair" aria-label="<?php esc_attr_e( 'Remove this pair', 'xenios-kb-bot' ); ?>"><?php esc_html_e( 'Remove', 'xenios-kb-bot' ); ?></a>
		<div class="xkb-pair-col">
			<label class="xkb-pair-label"><?php esc_html_e( 'Question', 'xenios-kb-bot' ); ?></label>
			<textarea name="xenios_kb_bot_qa[<?php echo esc_attr( $index ); ?>][question]" rows="2" class="large-text"><?php echo esc_textarea( $question ); ?></textarea>
		</div>
		<div class="xkb-pair-col">
			<label class="xkb-pair-label"><?php esc_html_e( 'Answer', 'xenios-kb-bot' ); ?></label>
			<textarea name="xenios_kb_bot_qa[<?php echo esc_attr( $index ); ?>][answer]" rows="3" class="large-text"><?php echo esc_textarea( $answer ); ?></textarea>
		</div>
	</div>
	<?php
}
endif;
?>
<p class="description">
	<?php esc_html_e( 'Add as many question and answer pairs as you need. The bot answers visitors using only this knowledge base.', 'xenios-kb-bot' ); ?>
</p>

<div class="xkb-pairs" id="xkb-pairs">
	<?php
	$xenios_kb_bot_i = 0;
	foreach ( $xenios_kb_bot_kb_pairs as $xenios_kb_bot_pair ) {
		xenios_kb_bot_render_pair_row( $xenios_kb_bot_i, $xenios_kb_bot_pair['question'], $xenios_kb_bot_pair['answer'] );
		$xenios_kb_bot_i++;
	}
	?>
</div>

<p>
	<button type="button" class="button button-secondary" id="xkb-add-pair">
		<?php esc_html_e( 'Add another Q&A pair', 'xenios-kb-bot' ); ?>
	</button>
</p>

<?php // Row template consumed by admin.js. __INDEX__ is replaced with a unique index. ?>
<script type="text/template" id="xkb-pair-template">
	<div class="xkb-pair" data-xkb-pair>
		<a href="#" class="xkb-remove-pair" aria-label="<?php esc_attr_e( 'Remove this pair', 'xenios-kb-bot' ); ?>"><?php esc_html_e( 'Remove', 'xenios-kb-bot' ); ?></a>
		<div class="xkb-pair-col">
			<label class="xkb-pair-label"><?php esc_html_e( 'Question', 'xenios-kb-bot' ); ?></label>
			<textarea name="xenios_kb_bot_qa[__INDEX__][question]" rows="2" class="large-text"></textarea>
		</div>
		<div class="xkb-pair-col">
			<label class="xkb-pair-label"><?php esc_html_e( 'Answer', 'xenios-kb-bot' ); ?></label>
			<textarea name="xenios_kb_bot_qa[__INDEX__][answer]" rows="3" class="large-text"></textarea>
		</div>
	</div>
</script>

<div class="xkb-upgrade-callout">
	<?php
	printf(
		/* translators: %s: link to xeniacloud.eu. */
		esc_html__( 'Want more from your AI support bot? %s', 'xenios-kb-bot' ),
		'<a href="https://xeniacloud.eu" target="_blank" rel="noopener noreferrer">' . esc_html__( 'See what Xenios KnowBot Premium offers', 'xenios-kb-bot' ) . ' &rarr;</a>'
	);
	?>
</div>
