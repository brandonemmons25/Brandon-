<?php
defined( 'ABSPATH' ) || exit;

/**
 * MDG_Generator
 *
 * Calls the Claude API (Anthropic Messages API) to write a meta description
 * for a single post, then optionally saves it to Yoast's meta field.
 */
class MDG_Generator {

    const API_URL = 'https://api.anthropic.com/v1/messages';

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Generate a meta description for one post via the Claude API.
     *
     * @param  int  $post_id
     * @return array { success: bool, description?: string, chars?: int, error?: string }
     */
    public static function generate_for_post( int $post_id ): array {
        $api_key = self::get_api_key();
        if ( empty( $api_key ) ) {
            return [ 'success' => false, 'error' => 'No Claude API key configured. Add it under Meta Descriptions → Settings.' ];
        }

        $post_data = MDG_Scanner::get_post_data( $post_id );
        if ( ! $post_data ) {
            return [ 'success' => false, 'error' => "Post #{$post_id} not found." ];
        }

        $prompt = self::build_prompt( $post_data );
        $result = self::call_api( $api_key, $prompt );

        if ( ! $result['success'] ) {
            return $result;
        }

        $description = self::clean_response( $result['text'] );

        return [
            'success'     => true,
            'description' => $description,
            'chars'       => mb_strlen( $description ),
            'post_id'     => $post_id,
            'title'       => $post_data['title'],
        ];
    }

    /**
     * Save a (reviewed) description directly to Yoast's meta field.
     *
     * @param int    $post_id
     * @param string $description  Already-validated text from the user.
     * @return array { success: bool, error?: string }
     */
    public static function apply_to_yoast( int $post_id, string $description ): array {
        $description = sanitize_text_field( wp_unslash( $description ) );

        if ( empty( $description ) ) {
            return [ 'success' => false, 'error' => 'Description cannot be empty.' ];
        }

        $post = get_post( $post_id );
        if ( ! $post ) {
            return [ 'success' => false, 'error' => "Post #{$post_id} not found." ];
        }

        update_post_meta( $post_id, '_yoast_wpseo_metadesc', $description );

        // Also update Yoast's indexable layer if Yoast is active (v14+).
        if ( class_exists( 'Yoast\WP\SEO\Repositories\Indexable_Repository' ) ) {
            try {
                $repository = \YoastSEO()->classes->get( \Yoast\WP\SEO\Repositories\Indexable_Repository::class );
                $indexable  = $repository->find_by_id_and_type( $post_id, 'post' );
                if ( $indexable ) {
                    $indexable->description = $description;
                    $indexable->save();
                }
            } catch ( \Exception $e ) {
                // Indexable update failed — post meta is already saved, which is
                // the canonical source. Yoast will rebuild indexables on next crawl.
            }
        }

        return [
            'success'     => true,
            'post_id'     => $post_id,
            'description' => $description,
            'chars'       => mb_strlen( $description ),
        ];
    }

    // -------------------------------------------------------------------------
    // Prompt building
    // -------------------------------------------------------------------------

    private static function build_prompt( array $post_data ): string {
        $min  = MDG_META_MIN;
        $max  = MDG_META_MAX;
        $type = esc_html( $post_data['post_type'] );
        $title   = esc_html( $post_data['title'] );
        $content = $post_data['content'];

        $prompt  = "You are an SEO copywriter. Write a compelling meta description for the following WordPress {$type}.\n\n";
        $prompt .= "Page title: {$title}\n";

        if ( ! empty( $content ) ) {
            $prompt .= "Page content (excerpt):\n{$content}\n\n";
        }

        $prompt .= "Requirements:\n";
        $prompt .= "- Total length: {$min}–{$max} characters (CRITICAL — count carefully)\n";
        $prompt .= "- Place the most important keyword and call-to-action within the FIRST 120 characters — mobile devices truncate after ~120 chars\n";
        $prompt .= "- Accurately describes the page in plain English\n";
        $prompt .= "- Conversational and compelling — gives a reader a clear reason to click\n";
        $prompt .= "- Unique to this page — do not start by repeating the page title verbatim\n";
        $prompt .= "- No quotation marks, no markdown, no labels\n";
        $prompt .= "- Plain text only — your entire response IS the meta description\n\n";
        $prompt .= "Respond with ONLY the meta description text. Nothing else.";

        return $prompt;
    }

    // -------------------------------------------------------------------------
    // Claude API call
    // -------------------------------------------------------------------------

    private static function call_api( string $api_key, string $prompt ): array {
        $body = wp_json_encode( [
            'model'      => MDG_CLAUDE_MODEL,
            'max_tokens' => 300,
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

    private static function clean_response( string $text ): string {
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
