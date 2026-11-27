<?php
/**
 * Tests for the ILSEJ_Editor class strip_highlight_marks methods.
 *
 * @package AISemanticInternalLinks
 */

namespace ILSEJ\Tests\Unit;

/**
 * Tests for highlight mark stripping in editor content.
 *
 * @covers ILSEJ_Editor
 */
class EditorTest extends TestCase {

	/**
	 * Editor instance under test.
	 *
	 * @var \ILSEJ_Editor
	 */
	private $editor;

	/**
	 * Set up each test.
	 *
	 * @return void
	 */
	protected function set_up(): void {
		parent::set_up();

		// ILSEJ_Editor is a singleton with side effects in the constructor
		// (add_action, add_filter). Use Reflection to create a clean instance.
		$reflection = new \ReflectionClass( \ILSEJ_Editor::class );

		// Reset the singleton so instance() works fresh.
		$instance_prop = $reflection->getProperty( 'instance' );
		$instance_prop->setValue( null, null );

		// Mock all WordPress hooks the constructor registers.
		\Brain\Monkey\Functions\when( 'add_action' )->justReturn( true );
		\Brain\Monkey\Functions\when( 'add_filter' )->justReturn( true );
		\Brain\Monkey\Functions\when( 'get_user_option' )->justReturn( 'false' );

		$this->editor = \ILSEJ_Editor::instance();
	}

	/**
	 * Tear down each test — reset singleton.
	 *
	 * @return void
	 */
	protected function tear_down(): void {
		$reflection    = new \ReflectionClass( \ILSEJ_Editor::class );
		$instance_prop = $reflection->getProperty( 'instance' );
		$instance_prop->setValue( null, null );

		parent::tear_down();
	}

	/**
	 * Test stripping ilsej-highlight marks from content.
	 *
	 * @return void
	 */
	public function test_strip_highlight_marks_removes_marks(): void {
		$input    = 'Hello <mark class="ilsej-highlight">world</mark> today.';
		$expected = 'Hello world today.';

		$this->assertSame( $expected, $this->editor->strip_highlight_marks( $input ) );
	}

	/**
	 * Test that non-ilsej mark tags are preserved.
	 *
	 * @return void
	 */
	public function test_strip_highlight_marks_preserves_other_marks(): void {
		$input = 'Hello <mark class="other-class">world</mark> today.';

		$this->assertSame( $input, $this->editor->strip_highlight_marks( $input ) );
	}

	/**
	 * Test that content without marks is returned unchanged.
	 *
	 * @return void
	 */
	public function test_strip_highlight_marks_no_marks_returns_unchanged(): void {
		$input = '<p>Regular paragraph with <strong>bold</strong> text.</p>';

		$this->assertSame( $input, $this->editor->strip_highlight_marks( $input ) );
	}

	/**
	 * Test stripping marks with additional attributes.
	 *
	 * @return void
	 */
	public function test_strip_highlight_marks_with_extra_attributes(): void {
		$input    = 'Text <mark data-id="1" class="ilsej-highlight" style="color:yellow">keyword</mark> more.';
		$expected = 'Text keyword more.';

		$this->assertSame( $expected, $this->editor->strip_highlight_marks( $input ) );
	}

	/**
	 * Test strip_highlight_marks_from_post_data strips from post_content key.
	 *
	 * @return void
	 */
	public function test_strip_from_post_data_strips_content(): void {
		$data = [
			'post_content' => 'Hello <mark class="ilsej-highlight">world</mark>.',
			'post_title'   => 'My Title',
		];

		$result = $this->editor->strip_highlight_marks_from_post_data( $data );

		$this->assertSame( 'Hello world.', $result['post_content'] );
		$this->assertSame( 'My Title', $result['post_title'] );
	}

	/**
	 * Test strip_highlight_marks_from_post_data with no marks is unchanged.
	 *
	 * @return void
	 */
	public function test_strip_from_post_data_no_marks_unchanged(): void {
		$data = [
			'post_content' => '<p>Clean content.</p>',
		];

		$result = $this->editor->strip_highlight_marks_from_post_data( $data );

		$this->assertSame( '<p>Clean content.</p>', $result['post_content'] );
	}
}
