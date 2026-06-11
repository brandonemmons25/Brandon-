<?php
defined( 'ABSPATH' ) || exit;

class MDG_Generator {

    const API_URL = 'https://api.anthropic.com/v1/messages';

    // -------------------------------------------------------------------------
    // Auto-fill: generate + apply all missing fields for one post
    // -------------------------------------------------------------------------

    /**
     * Generate and immediately apply only the missing SEO fields for a post.
     * Never overwrites fields that already have a value.
     *
     * @return array { success, skipped?, fields_filled[], post_id, title, error? }
     */
    public static function auto_fill_post( int $post_id ): array {
        $api_key = self::get_api_key();
        if ( empty( $api_key ) ) {
            return [ 'success' => false, 'error' => 'No Claude API key configured.' ];
        }

        $missing = MDG_Scanner::get_missing_fields( $post_id );
        if ( empty( $missing ) ) {
            return [ 'success' => true, 'skipped' => true, 'post_id' => $post_id ];
        }

        $post_data = MDG_Scanner::get_post_data( $post_id );
        if ( ! $post_data ) {
            return [ 'success' => false, 'error' => "Post #{$post_id} not found." ];
        }

        $prompt = self::build_seo_fields_prompt( $post_data, $missing );
        $result = self::call_api( $api_key, $prompt, 400 );

        if ( ! $result['success'] ) {
            return $result;
        }

        $fields = self::parse_json_response( $result['text'] );
        if ( empty( $fields ) ) {
            return [ 'success' => false, 'error' => 'Could not parse API response as JSON.', 'raw' => $result['text'] ];
        }

        $filled = self::apply_fields_to_yoast( $post_id, $fields, $missing );

        return [
            'success'      => true,
            'post_id'      => $post_id,
            'title'        => $post_data['title'],
            'fields_filled'=> $filled,
            'fields'       => $fields,
        ];
    }

    // -------------------------------------------------------------------------
    // Manual generation (single meta description — kept for Generate & Apply UI)
    // -------------------------------------------------------------------------

    public static function generate_for_post( int $post_id ): array {
        $api_key = self::get_api_key();
        if ( empty( $api_key ) ) {
            return [ 'success' => false, 'error' => 'No Claude API key configured. Add it under Meta Descriptions → Settings.' ];
        }

        $post_data = MDG_Scanner::get_post_data( $post_id );
        if ( ! $post_data ) {
            return [ 'success' => false, 'error' => "Post #{$post_id} not found." ];
        }

        $prompt = self::build_description_prompt( $post_data );
        $result = self::call_api( $api_key, $prompt, 300 );

        if ( ! $result['success'] ) {
            return $result;
        }

        $description = self::clean_text( $result['text'] );

        return [
            'success'     => true,
            'description' => $description,
            'chars'       => mb_strlen( $description ),
            'post_id'     => $post_id,
            'title'       => $post_data['title'],
        ];
    }

    public static function apply_to_yoast( int $post_id, string $description ): array {
        $description = sanitize_text_field( wp_unslash( $description ) );
        if ( empty( $description ) ) {
            return [ 'success' => false, 'error' => 'Description cannot be empty.' ];
        }
        if ( ! get_post( $post_id ) ) {
            return [ 'success' => false, 'error' => "Post #{$post_id} not found." ];
        }

        update_post_meta( $post_id, '_yoast_wpseo_metadesc', $description );
        self::update_indexable( $post_id, [ 'description' => $description ] );

        return [
            'success'     => true,
            'post_id'     => $post_id,
            'description' => $description,
            'chars'       => mb_strlen( $description ),
        ];
    }

    // -------------------------------------------------------------------------
    // Prompt builders
    // -------------------------------------------------------------------------

    private static function build_seo_fields_prompt( array $post_data, array $missing ): string {
        $type    = $post_data['post_type'];
        $title   = $post_data['title'];
        $content = $post_data['content'];

        $field_specs = [];
        if ( in_array( 'focus_keyphrase', $missing, true ) ) {
            $field_specs[] = '"focus_keyphrase": 2-4 words — the primary search term this page targets';
        }
        if ( in_array( 'seo_title', $missing, true ) ) {
            $field_specs[] = '"seo_title": ' . MDG_TITLE_MIN . '–' . MDG_TITLE_MAX . ' characters — keyword-rich, compelling, slightly different from the page title';
        }
        if ( in_array( 'meta_description', $missing, true ) ) {
            $field_specs[] = '"meta_description": ' . MDG_META_MIN . '–' . MDG_META_MAX . ' characters — place keywords + CTA within first 120 chars (mobile truncates there), conversational, unique';
        }

        $prompt  = "You are an SEO expert. Generate the missing SEO fields for this WordPress {$type}.\n\n";
        $prompt .= "Page title: {$title}\n";
        if ( ! empty( $content ) ) {
            $prompt .= "Content excerpt:\n{$content}\n\n";
        }
        $prompt .= "Generate ONLY these fields:\n";
        foreach ( $field_specs as $spec ) {
            $prompt .= "- {$spec}\n";
        }
        $prompt .= "\nRules:\n";
        $prompt .= "- Respond with ONLY valid JSON — no markdown, no explanation, no code fences\n";
        $prompt .= "- Do not include fields not listed above\n";
        $prompt .= "- Count characters carefully — SEO title and meta description limits are critical\n";
        $prompt .= "- No quotation marks inside field values\n\n";

        $example_fields = [];
        if ( in_array( 'focus_keyphrase', $missing, true ) ) {
            $example_fields[] = '"focus_keyphrase": "example keyphrase"';
        }
        if ( in_array( 'seo_title', $missing, true ) ) {
            $example_fields[] = '"seo_title": "Example SEO Title for This Page – Site"';
        }
        if ( in_array( 'meta_description', $missing, true ) ) {
            $example_fields[] = '"meta_description": "Compelling description that makes people click. Keywords near the start, CTA before 120 chars."';
        }
        $prompt .= 'Example format: {' . implode( ', ', $example_fields ) . '}';

        return $prompt;
    }

    private static function build_description_prompt( array $post_data ): string {
        $min     = MDG_META_MIN;
        $max     = MDG_META_MAX;
        $type    = $post_data['post_type'];
        $title   = $post_data['title'];
        $content = $post_data['content'];

        $prompt  = "You are an SEO copywriter. Write a compelling meta description for the following WordPress {$type}.\n\n";
        $prompt .= "Page title: {$title}\n";
        if ( ! empty( $content ) ) {
            $prompt .= "Page content (excerpt):\n{$content}\n\n";
        }
        $prompt .= "Requirements:\n";
        $prompt .= "- Total length: {$min}–{$max} characters (CRITICAL — count carefully)\n";
        $prompt .= "- Place the most important keyword and call-to-action within the FIRST 120 characters\n";
        $prompt .= "- Conversational and compelling — gives a reader a clear reason to click\n";
        $prompt .= "- Unique to this page — do not start by repeating the page title verbatim\n";
        $prompt .= "- No quotation marks, no markdown, no labels\n";
        $prompt .= "- Plain text only — your entire response IS the meta description\n\n";
        $prompt .= "Respond with ONLY the meta description text. Nothing else.";

        return $prompt;
    }

    // -------------------------------------------------------------------------
    // Apply fields to Yoast (only the ones that were missing)
    // -------------------------------------------------------------------------

    private static function apply_fields_to_yoast( int $post_id, array $fields, array $missing ): array {
        $filled   = [];
        $indexable_updates = [];

        if ( in_array( 'focus_keyphrase', $missing, true ) && ! empty( $fields['focus_keyphrase'] ) ) {
            $val = sanitize_text_field( $fields['focus_keyphrase'] );
            update_post_meta( $post_id, '_yoast_wpseo_focuskw', $val );
            $filled[] = 'focus_keyphrase';
        }

        if ( in_array( 'seo_title', $missing, true ) && ! empty( $fields['seo_title'] ) ) {
            $val = sanitize_text_field( $fields['seo_title'] );
            update_post_meta( $post_id, '_yoast_wpseo_title', $val );
            $indexable_updates['title'] = $val;
            $filled[] = 'seo_title';
        }

        if ( in_array( 'meta_description', $missing, true ) && ! empty( $fields['meta_description'] ) ) {
            $val = sanitize_text_field( $fields['meta_description'] );
            update_post_meta( $post_id, '_yoast_wpseo_metadesc', $val );
            $indexable_updates['description'] = $val;
            $filled[] = 'meta_description';
        }

        if ( ! empty( $indexable_updates ) ) {
            self::update_indexable( $post_id, $indexable_updates );
        }

        return $filled;
    }

    private static function update_indexable( int $post_id, array $updates ): void {
        if ( ! class_exists( 'Yoast\WP\SEO\Repositories\Indexable_Repository' ) ) {
            return;
        }
        try {
            $repository = \YoastSEO()->classes->get( \Yoast\WP\SEO\Repositories\Indexable_Repository::class );
            $indexable  = $repository->find_by_id_and_type( $post_id, 'post' );
            if ( $indexable ) {
                foreach ( $updates as $key => $value ) {
                    $indexable->$key = $value;
                }
                $indexable->save();
            }
        } catch ( \Exception $e ) {
            // Post meta already saved — Yoast rebuilds indexables on next crawl.
        }
    }

    // -------------------------------------------------------------------------
    // Claude API
    // -------------------------------------------------------------------------

    private static function call_api( string $api_key, string $prompt, int $max_tokens = 300 ): array {
        $body = wp_json_encode( [
            'model'      => MDG_CLAUDE_MODEL,
            'max_tokens' => $max_tokens,
            'messages'   => [
                [ 'role' => 'user', 'content' => $prompt ],
            ],
        ] );

        $response = wp_remote_post( self::API_URL, [
            'timeout' => 30,
            'headers' => [
                'Content-Type'      => 'application/json',
                'x-api-key'         => $api_key,
                'anthropic-version' => '2023-06-01',
            ],
            'body' => $body,
        ] );

        if ( is_wp_error( $response ) ) {
            return [ 'success' => false, 'error' => 'API request failed: ' . $response->get_error_message() ];
        }

        $code = (int) wp_remote_retrieve_response_code( $response );
        $raw  = wp_remote_retrieve_body( $response );
        $data = json_decode( $raw, true );

        if ( $code !== 200 ) {
            $msg = $data['error']['message'] ?? "HTTP {$code}";
            return [ 'success' => false, 'error' => "Claude API error: {$msg}" ];
        }

        $text = $data['content'][0]['text'] ?? '';
        if ( empty( $text ) ) {
            return [ 'success' => false, 'error' => 'Claude returned an empty response.' ];
        }

        return [ 'success' => true, 'text' => $text ];
    }

    private static function parse_json_response( string $text ): array {
        $text = trim( $text );
        // Strip markdown code fences if Claude added them.
        $text = preg_replace( '/^```(?:json)?\s*/i', '', $text );
        $text = preg_replace( '/\s*```$/', '', $text );
        $data = json_decode( trim( $text ), true );
        return is_array( $data ) ? $data : [];
    }

    private static function clean_text( string $text ): string {
        $text = trim( $text );
        $text = trim( $text, '"' );
        $text = preg_replace( '/^(Meta description:|Description:)\s*/i', '', $text );
        return trim( $text );
    }

    // -------------------------------------------------------------------------
    // Settings
    // -------------------------------------------------------------------------

    public static function get_api_key(): string {
        return trim( (string) get_option( 'mdg_claude_api_key', '' ) );
    }

    public static function save_api_key( string $key ): void {
        update_option( 'mdg_claude_api_key', sanitize_text_field( trim( $key ) ) );
    }
}
