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

        // Claude can't reliably count characters — enforce the caps ourselves.
        if ( ! empty( $fields['seo_title'] ) ) {
            $fields['seo_title'] = self::enforce_max_length( $fields['seo_title'], MDG_TITLE_MAX );
        }
        if ( ! empty( $fields['meta_description'] ) ) {
            $fields['meta_description'] = self::enforce_max_length( $fields['meta_description'], MDG_META_MAX, MDG_META_MIN );
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
        $description = self::enforce_max_length( $description, MDG_META_MAX, MDG_META_MIN );

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

    /**
     * Wipe the Yoast meta description for a post so it can be regenerated
     * from scratch (and shows up under the "Missing description" filter again).
     */
    public static function clear_description( int $post_id ): array {
        if ( ! get_post( $post_id ) ) {
            return [ 'success' => false, 'error' => "Post #{$post_id} not found." ];
        }

        delete_post_meta( $post_id, '_yoast_wpseo_metadesc' );
        self::update_indexable( $post_id, [ 'description' => '' ] );

        return [ 'success' => true, 'post_id' => $post_id ];
    }

    // -------------------------------------------------------------------------
    // Prompt builders
    // -------------------------------------------------------------------------

    private static function build_seo_fields_prompt( array $post_data, array $missing ): string {
        $type       = $post_data['post_type'];
        $title      = $post_data['title'];
        $content    = $post_data['content'];
        $site_name  = $post_data['site_name'] ?? '';
        $site_desc  = $post_data['site_desc'] ?? '';
        $keyphrase  = trim( $post_data['focus_keyphrase'] ?? '' );
        $keyphrase_is_missing = in_array( 'focus_keyphrase', $missing, true );

        $field_specs = [];
        if ( in_array( 'focus_keyphrase', $missing, true ) ) {
            $field_specs[] = '"focus_keyphrase": 2-4 words — the primary search term this page targets';
        }
        if ( in_array( 'seo_title', $missing, true ) ) {
            $field_specs[] = '"seo_title": ' . MDG_TITLE_MIN . '–' . MDG_TITLE_MAX . ' characters — keyword-rich, compelling, slightly different from the page title';
        }
        if ( in_array( 'meta_description', $missing, true ) ) {
            $field_specs[] = '"meta_description": ' . MDG_META_MIN . '–' . MDG_META_MAX . ' characters — written as a selling proposition, not a summary. Open with a verb-led hook (Looking for…, Searching for…), lead with the benefit, keyword near the start, end with a direct catchy CTA before 120 chars (mobile truncates there)';
        }

        $prompt  = "You are an SEO expert and direct-response copywriter. Generate the missing SEO fields for this WordPress {$type}.\n\n";
        if ( ! empty( $site_name ) ) {
            $prompt .= "Business/site name: {$site_name}\n";
        }
        if ( ! empty( $site_desc ) ) {
            $prompt .= "Site tagline: {$site_desc}\n";
        }
        $prompt .= "Page title: {$title}\n";
        if ( ! empty( $keyphrase ) && ! $keyphrase_is_missing ) {
            $prompt .= "Existing focus keyphrase (already set, do not change it): {$keyphrase}\n";
        }
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
        $prompt .= "- No quotation marks inside field values\n";
        $prompt .= "- No semicolons, and no dashes (—, –) used to connect clauses — use a period or comma instead. Hyphens inside a compound word like \"fly-fishing\" are fine\n";
        $prompt .= "- The meta description is an ad, not a summary: sell the reason to click, don't just describe the page\n";
        $prompt .= "- Be specific and detailed — real numbers, specifics, or outcomes beat vague claims\n";
        $prompt .= "- Open the meta_description with an inviting verb-led hook or question — \"Looking for...\", \"Searching for...\", \"Want...\", \"Need...\", \"Ready to...\" — that pulls the reader in before you deliver the specific benefit and keyword\n";
        $prompt .= "- End with a short, direct call to action using an imperative verb: Call, Book, Schedule, Get, Request, Shop. NEVER end with a weak, generic CTA like \"Learn more\", \"Explore listings now\", \"Find out more\", \"See more\", or \"Discover more\" — those don't sell anything\n";
        if ( ! empty( $site_name ) ) {
            $prompt .= "- Work the business name \"{$site_name}\" naturally into the seo_title, and into the meta_description when it fits without pushing out the benefit or CTA — it builds trust and brand recognition in the search result\n";
        }
        $prompt .= "- Follow SEO best practice: match what someone searching for this page actually wants, use the focus keyphrase naturally (never stuffed or repeated), and avoid boilerplate that could read the same on another page of this site\n";
        if ( ! empty( $keyphrase ) && ! $keyphrase_is_missing ) {
            $prompt .= "- The meta_description MUST contain the exact phrase \"{$keyphrase}\" verbatim (case doesn't matter), ideally within the first 100 characters — Yoast SEO requires this to mark the page green\n";
        } elseif ( $keyphrase_is_missing ) {
            $prompt .= "- Whatever focus_keyphrase you choose, that exact phrase MUST also appear verbatim in the meta_description, ideally within the first 100 characters — Yoast SEO requires this to mark the page green\n";
        }
        $prompt .= "- Weak example to avoid: \"Dana real estate with panoramic Lassen Peak views, fly fishing access & ranch properties. Find your mountain home near Mt. Shasta. Explore listings now.\" (that's a summary with a generic CTA and no hook)\n";
        $prompt .= "- Strong example to write like: \"Looking for a Dana mountain retreat with Lassen Peak views? Intermountain Realty has private fly-fishing access and ranch acreage. Call today to tour.\" (verb-led hook, specific, benefit-led, direct CTA, no dashes or semicolons)\n\n";

        $example_fields = [];
        if ( in_array( 'focus_keyphrase', $missing, true ) ) {
            $example_fields[] = '"focus_keyphrase": "example keyphrase"';
        }
        if ( in_array( 'seo_title', $missing, true ) ) {
            $example_fields[] = '"seo_title": "Example SEO Title for This Page – Site"';
        }
        if ( in_array( 'meta_description', $missing, true ) ) {
            $example_fields[] = '"meta_description": "Looking for…-style hook, then a specific benefit-led pitch with the keyword up front, a concrete detail that builds trust, and a direct CTA such as Call now, all before 120 chars, no dashes or semicolons."';
        }
        $prompt .= 'Example format: {' . implode( ', ', $example_fields ) . '}';

        return $prompt;
    }

    private static function build_description_prompt( array $post_data ): string {
        $min       = MDG_META_MIN;
        $max       = MDG_META_MAX;
        $type      = $post_data['post_type'];
        $title     = $post_data['title'];
        $content   = $post_data['content'];
        $site_name = $post_data['site_name'] ?? '';
        $site_desc = $post_data['site_desc'] ?? '';
        $keyphrase = trim( $post_data['focus_keyphrase'] ?? '' );

        $prompt  = "You are a direct-response copywriter writing ad copy for a WordPress {$type}, not a summary of it.\n\n";
        if ( ! empty( $site_name ) ) {
            $prompt .= "Business/site name: {$site_name}\n";
        }
        if ( ! empty( $site_desc ) ) {
            $prompt .= "Site tagline: {$site_desc}\n";
        }
        $prompt .= "Page title: {$title}\n";
        if ( ! empty( $keyphrase ) ) {
            $prompt .= "Focus keyphrase (already set for this page, do not change it): {$keyphrase}\n";
        }
        if ( ! empty( $content ) ) {
            $prompt .= "Page content (excerpt):\n{$content}\n\n";
        }
        $prompt .= "Write the meta description as a selling proposition: the reader is scanning search results deciding what to click, and this is your one shot to win that click.\n\n";
        $prompt .= "Requirements:\n";
        $prompt .= "- Total length: {$min}–{$max} characters (CRITICAL — count carefully)\n";
        $prompt .= "- Open with an inviting verb-led hook or question — \"Looking for...\", \"Searching for...\", \"Want...\", \"Need...\", \"Ready to...\" — that pulls the reader in and entices them to act, before you deliver the specific benefit and keyword\n";
        $prompt .= "- Be specific and detailed: use real numbers, features, or outcomes from the content instead of vague claims like \"great\" or \"quality\"\n";
        $prompt .= "- Place the most important keyword within the FIRST 120 characters (mobile truncates there)\n";
        $prompt .= "- End with a short, direct, catchy call to action using an imperative verb: Call, Book, Schedule, Get, Request, Shop. NEVER end with a weak, generic CTA like \"Learn more\", \"Explore listings now\", \"Find out more\", \"See more\", or \"Discover more\" — those don't sell anything\n";
        $prompt .= "- Conversational and enticing, never robotic or generic\n";
        $prompt .= "- Unique to this page — do not start by repeating the page title verbatim\n";
        if ( ! empty( $site_name ) ) {
            $prompt .= "- Work the business name \"{$site_name}\" in naturally where it fits (e.g. \"...at {$site_name}\") without crowding out the benefit or the CTA — it builds trust and brand recall in the search result\n";
        }
        $prompt .= "- Follow SEO best practice: match what someone searching for this page actually wants (search intent), use the primary keyword naturally without stuffing, and don't write boilerplate that could pass for another page on this site\n";
        if ( ! empty( $keyphrase ) ) {
            $prompt .= "- The description MUST contain the exact phrase \"{$keyphrase}\" verbatim (case doesn't matter), ideally within the first 100 characters — Yoast SEO requires this to mark the page green\n";
        }
        $prompt .= "- No semicolons, and no dashes (—, –) used to connect clauses — use a period or comma instead. Hyphens inside a compound word like \"fly-fishing\" are fine\n";
        $prompt .= "- No quotation marks, no markdown, no labels\n";
        $prompt .= "- Plain text only — your entire response IS the meta description\n\n";
        $prompt .= "Example — weak (a summary with a generic CTA and no hook, avoid this style):\n";
        $prompt .= "\"Dana real estate with panoramic Lassen Peak views, fly fishing access & ranch properties. Find your mountain home near Mt. Shasta. Explore listings now.\"\n\n";
        $prompt .= "Example — strong (a verb-led hook, a specific pitch, and a direct CTA, write like this):\n";
        $prompt .= "\"Looking for a Dana mountain retreat with Lassen Peak views? Intermountain Realty has private fly-fishing access and ranch acreage. Call today to tour.\"\n\n";
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

        // Anthropic org rate limits can be as low as 5 req/min — retry once on
        // a 429 instead of failing the row outright.
        $max_attempts = 2;
        $code         = 0;
        $data         = [];

        for ( $attempt = 1; $attempt <= $max_attempts; $attempt++ ) {
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

            if ( $code === 429 && $attempt < $max_attempts ) {
                $retry_after = (int) wp_remote_retrieve_header( $response, 'retry-after' );
                sleep( max( $retry_after, 13 ) );
                continue;
            }

            break;
        }

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

    /**
     * Hard-cap generated text at $max characters, and if $min is given,
     * never land short of it either. Claude is asked to count characters
     * in the prompt, but LLMs count unreliably — this is the actual
     * guarantee that both limits are respected.
     *
     * Always finishes on a period, never an ellipsis. Prefers complete
     * sentences that fit within $max; if the sentences that fit fall short
     * of $min, pulls in as much of the next sentence as fits (cut at a
     * word boundary, dropping a trailing dangling word like "...and")
     * rather than settling for a short-but-complete first sentence.
     */
    private static function enforce_max_length( string $text, int $max, int $min = 0 ): string {
        $text = trim( $text );
        if ( mb_strlen( $text ) <= $max ) {
            return $text;
        }

        $sentences  = self::split_sentences( $text );
        $built      = '';
        $next_index = 0;
        foreach ( $sentences as $i => $sentence ) {
            $candidate = $built === '' ? $sentence : $built . ' ' . $sentence;
            if ( mb_strlen( $candidate ) > $max ) {
                $next_index = $i;
                break;
            }
            $built      = $candidate;
            $next_index = $i + 1;
        }

        if ( $built !== '' && mb_strlen( $built ) >= $min ) {
            return $built;
        }

        // What fits sentence-wise falls short of $min — pull in as much of
        // the next sentence as fits in the remaining room.
        $remainder = $sentences[ $next_index ] ?? '';
        $base      = $built;

        if ( $remainder !== '' ) {
            $room       = $max - ( $base === '' ? 0 : mb_strlen( $base ) + 1 );
            $piece      = mb_substr( $remainder, 0, max( 0, $room ) );
            $last_space = mb_strrpos( $piece, ' ' );
            if ( $last_space !== false ) {
                $piece = mb_substr( $piece, 0, $last_space );
            }
            $piece = self::strip_dangling_words( $piece );
            if ( $piece !== '' ) {
                $base = $base === '' ? $piece : $base . ' ' . $piece;
            }
        }

        if ( $base === '' ) {
            // No sentence boundary fits at all (one long run-on) — hard-cut the raw text.
            $truncated  = mb_substr( $text, 0, $max );
            $last_space = mb_strrpos( $truncated, ' ' );
            if ( $last_space !== false ) {
                $truncated = mb_substr( $truncated, 0, $last_space );
            }
            $base = self::strip_dangling_words( $truncated );
        }

        return rtrim( $base, " \t\n\r\0\x0B,;:–—-" ) . '.';
    }

    /**
     * Split into sentences on ., !, or ? followed by whitespace, without
     * treating common abbreviation periods (Mt., St., Ave., ...) as a
     * sentence boundary.
     */
    private static function split_sentences( string $text ): array {
        $pattern = '/(?<!\bMt)(?<!\bSt)(?<!\bDr)(?<!\bAve)(?<!\bBlvd)(?<!\bRd)(?<!\bLn)'
                 . '(?<!\bJr)(?<!\bSr)(?<!\bNo)(?<!\bvs)(?<!\bInc)(?<!\bCorp)(?<!\bCo)'
                 . '(?<!\bFt)(?<!\bMr)(?<!\bMrs)(?<!\bMs)(?<=[.!?])\s+/u';
        return preg_split( $pattern, $text, -1, PREG_SPLIT_NO_EMPTY );
    }

    /**
     * Drop a trailing word that would leave a truncated phrase dangling
     * mid-clause (e.g. "...mountain views and" -> "...mountain views").
     */
    private static function strip_dangling_words( string $text ): string {
        $text     = rtrim( $text, " \t\n\r\0\x0B,.;:–—-" );
        $dangling = [ 'and', 'or', 'but', 'with', 'for', 'to', 'in', 'on', 'at', 'of', 'the',
                      'a', 'an', 'your', 'its', 'that', 'this', 'is', 'are', 'was', 'were', 'near', 'from' ];
        $words = preg_split( '/\s+/', $text );
        while ( count( $words ) > 1 && in_array( mb_strtolower( rtrim( end( $words ), '.,!?' ) ), $dangling, true ) ) {
            array_pop( $words );
        }
        return implode( ' ', $words );
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
