<?php
declare(strict_types=1);

use DiviProEngagement\Modules\Comments\CommentsEngine;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside render_comments_section().
echo CommentsEngine::get_instance()->render_comments_section();
