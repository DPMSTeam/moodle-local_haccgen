<?php
defined('MOODLE_INTERNAL') || die();

class local_aicourse_api
{
    // Subscription check URL
    const SUBSCRIPTION_URL = 'https://dev.dynamicpixel.co.in/test3/local/subscription_manager/api.php';

    /**
     * Get the API endpoint dynamically from subscription check.
     *
     * @return string
     * @throws moodle_exception if inactive or missing endpoint.
     */
    protected static function get_api_url()
    {
        // Load API key & secret from plugin settings
        $apikey = get_config('local_aicourse', 'apikey');
        $apisecret = get_config('local_aicourse', 'apisecret');

        if (empty($apikey) || empty($apisecret)) {
            throw new moodle_exception('error', 'local_aicourse', '', 'API credentials are missing.');
        }

        // Build payload
        $payload = [
            'api_key'      => $apikey,
            'api_secret'   => $apisecret,
            'action'       => 'plugin_status',
            'plugin_name'  => 'AI Course'
        ];
        error_log("Payload: " . json_encode($payload, JSON_PRETTY_PRINT));
        // Make request
        $response = self::post_json_via_curl(self::SUBSCRIPTION_URL, $payload);
        error_log("Response: " . json_encode($response, JSON_PRETTY_PRINT));
        if (empty($response['status']) || $response['status'] !== 'active') {
            throw new moodle_exception('error', 'local_aicourse', '', $response['message'] ?? 'Subscription inactive.');
        }

        if (empty($response['content_endpoint'])) {
            throw new moodle_exception('error', 'local_aicourse', '', 'No content endpoint returned.');
        }

        return $response['content_endpoint'];
    }
    /**
     * Step 3: Generate subtopics only (no content).
     */
    public static function generate_subtopics_only($data)
    {
        global $USER;

        // Get endpoint dynamically
        $apiurl = self::get_api_url();
        if (!empty($data->pdf_reference_url)) {
            $payload = [
                'action' => 'generate_subtopics',
                'user_id' =>  $USER->id,
                'provider' => 'gemini',
                'course_data' => [
                    'title'           => $data->coursename,
                    'audience'        => $data->targetaudience,
                    'level'           => $data->levelofunderstanding,
                    'tone'            => $data->toneofnarrative,
                    'duration'        => $data->courseduration,
                    'description'     => $data->description ?? '',
                    'pdf_reference_url' => $data->pdf_reference_url,
                ]
            ];
          
        } else {
            $payload = [
                'action' => 'generate_subtopics',
                'user_id' => 'user_' . $USER->id,
                'course_data' => [
                    'title'           => $data->coursename,
                    'audience'        => $data->targetaudience,
                    'level'           => $data->levelofunderstanding,
                    'tone'            => $data->toneofnarrative,
                    'duration'        => $data->courseduration,
                    'description'     => $data->description ?? '',
                ]
            ];
        }
        $response = self::post_json_via_curl($apiurl, $payload);
        error_log("data->pdf_reference_url  ".$data->pdf_reference_url);
        error_log("Payload sent to content endpoint: " . json_encode($payload, JSON_PRETTY_PRINT));
        error_log("Raw API response: " . json_encode($response, JSON_PRETTY_PRINT));

        if (!isset($response['subtopics'])) {
            throw new moodle_exception('error', 'local_aicourse', '', 'No subtopics returned.');
        }

        return $response;
    }

    /**
     * Step 4: Generate lesson content for each finalized topic/subtopic.
     */
    public static function generate_content_for_topics(array $topics, $data)
    {
        global $USER;
        $apiurl = self::get_api_url();
        $enriched_topics = [];

        foreach ($topics as $topic) {
            if (empty($topic['title']) || !isset($topic['learning_objectives'])) {
                throw new moodle_exception('missing_topic_fields', 'local_aicourse', '', 'Title or learning objectives missing from topic.');
            }

            if (empty($data->case_study_data)) {
                throw new moodle_exception('missing_case_study', 'local_aicourse', '', 'Case study data is required.');
            }

            $payload = [
                'action' => 'generate_content',
                'user_id' => 'user_' . $USER->id,
                'content_request' => [
                    'course_title'        => $data->coursename,
                    'topic_title'         => $topic['title'],
                    'topic_description'   => $topic['description'] ?? '',
                    'audience'            => $data->targetaudience,
                    'level'               => $data->levelofunderstanding,
                    'tone'                => $data->toneofnarrative,
                    'content_type'        => 'lesson',
                    'duration'            => $topic['estimated_duration'] ?? 'Less than 15 minutes',
                    'learning_objectives' => $topic['learning_objectives'] ?? [],
                    'case_study_data'     => $data->case_study_data ?? null,
                    'include_quiz'        => !empty($topic['has_quiz']),
                    'quiz_count'          => !empty($topic['has_quiz']) ? ($topic['quiz_question_count'] ?? 3) : 0,
                    'question_types'      => ['multiple_choice', 'short_answer'],
                ]
            ];

            // error_log(json_encode("Payload   " . json_encode($payload)));
            $response = self::post_json_via_curl($apiurl, $payload);
            // error_log("Response " . json_encode($response, JSON_PRETTY_PRINT));

            $result = $response['result'] ?? [];
            $generated = $result['generated_content'] ?? [];


            $quiz_data = $result['quiz_data'] ?? null;
            $quiz_count = is_array($quiz_data['questions'] ?? null) ? count($quiz_data['questions']) : 0;

            $subtopics = [];
            $html = '';
            if (!empty($generated['topic_learning_objectives'])) {
                // Initialize subtopics array only if content_sections exist
                $all_objectives = [];
                foreach ($generated['topic_learning_objectives'] as $obj) {
                    $obj = trim($obj); // remove whitespace
                    if ($obj !== '') {
                        $all_objectives[] = $obj;
                    }
                }
            }
            // error_log("All Objectives: " . json_encode($all_objectives, JSON_PRETTY_PRINT));
            if (!empty($generated['content_sections'])) {
                $subtopics = [];

                foreach ($generated['content_sections'] as $section) {
                    $section_title = $section['section_title'] ?? 'Untitled Section';
                    $section_content = $section['content'] ?? '';
                    $examples = $section['examples'] ?? [];

                    // Add a hidden comment or anchor for logical separation (non-visible)
                    $raw_html = '<!-- SUBTOPIC: ' . s($section_title) . ' -->';

                    $raw_html .= $section_content;

                    if (!empty($examples)) {
                        $raw_html .= '<ul>';
                        foreach ($examples as $ex) {
                            $raw_html .= '<li>' . s($ex) . '</li>';
                        }
                        $raw_html .= '</ul>';
                    }

                    $enhanced_html = trim(self::enhance_llm_html($raw_html));

                    // ✅ Store per subtopic
                    $subtopics[] = [
                        'title'        => $section_title,
                        'content'      => $section_content,
                        'examples'     => $examples,
                        'content_html' => $enhanced_html,
                        'learning_objectives' => $all_objectives,
                    ];
                }
            }

            // No $html accumulation here
            $enriched_topics[] = [
                'id'                 => $topic['id'] ?? uniqid('topic_'),
                'title'              => $topic['title'],
                'description'        => $topic['description'] ?? '',
                'estimated_duration' => $topic['estimated_duration'] ?? '',
                'subtopics'          => $subtopics,
                'quiz_included'      => $quiz_count > 0,
                'quiz_data'          => $quiz_data
            ];
        }

        return $enriched_topics;
    }

    /**
     * Enhance raw LLM HTML content:
     * - Convert **bold** to <strong>
     * - Label <image_prompt> blocks
     */
    private static function enhance_llm_html($html)
    {
        // Step 1: Convert **bold** to <strong>
        $html = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $html);
        $html = preg_replace('/\*\s*(.+?):/m', '<br><strong>$1:</strong>', $html);

        // Step 2: Remove standalone lines that begin with "Image:" if an <image_prompt> exists later
        // (optional but safer)
        if (strpos($html, '<image_prompt') !== false) {
            $html = preg_replace('/<p>\s*Image:\s*Image \d+:[^<]+<\/p>/i', '', $html);
            $html = preg_replace('/Image:\s*Image \d+:[^\n]+[\r\n]?/i', '', $html);
        }

        // Step 3: Enhance <image_prompt>
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?><div>' . $html . '</div>');
        libxml_clear_errors();

        $xpath = new DOMXPath($dom);
        $prompts = $xpath->query('//image_prompt');

        // foreach ($prompts as $prompt) {
        //     $existingClass = $prompt->getAttribute('class');
        //     if (strpos($existingClass, 'ai-image-generator') === false) {
        //         $prompt->setAttribute('class', trim($existingClass . ' ai-image-generator'));
        //     }

        //     if (strpos($prompt->textContent, 'Image Prompt (internal use):') === false) {
        //         $label = $dom->createElement('div');
        //         $label->appendChild($dom->createElement('strong', 'Image Prompt (internal use):'));
        //         $prompt->insertBefore($label, $prompt->firstChild);
        //     }
        // }

        foreach ($prompts as $prompt) {
            // Create a replacement <div> with the same content
            $replacement = $dom->createElement('div');

            // Apply styling (italic, grey background, etc.)
            $replacement->setAttribute('style', 'background-color:#f0f0f0; padding:10px; font-style:italic; border-left:4px solid #ccc; margin:10px 0;');
            $replacement->setAttribute('class', 'ai-image-generator');

            // Create label: <div><strong>Image Prompt (internal use):</strong></div>
            $label = $dom->createElement('div');
            $strong = $dom->createElement('strong', 'Image Prompt (internal use):');
            $label->appendChild($strong);
            $replacement->appendChild($label);

            // Add the actual prompt content as a text node
            $contentText = trim($prompt->textContent);
            $textNode = $dom->createTextNode($contentText);
            $replacement->appendChild($textNode);

            // Replace <image_prompt> with styled <div>
            $prompt->parentNode->replaceChild($replacement, $prompt);
        }



        // Extract processed HTML
        $container = $dom->getElementsByTagName('div')->item(0);
        $newHtml = '';
        if ($container) {
            foreach ($container->childNodes as $child) {
                $newHtml .= $dom->saveHTML($child);
            }
        }

        return $newHtml;
    }



    /**
     * cURL helper for all API requests.
     */
    private static function post_json_via_curl($url, $data)
    {
        $json_payload = json_encode($data);

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $json_payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // ⚠️ Dev only
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);

        $response = curl_exec($ch);
        if (curl_errno($ch)) {
            $error_msg = curl_error($ch);
            error_log("cURL connection error to $url: $error_msg");
            curl_close($ch);
            throw new moodle_exception('apierror', 'local_aicourse', '', 'cURL Error: ' . $error_msg);
        }

        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($status !== 200) {
            throw new moodle_exception('apierror', 'local_aicourse', '', 'API returned non-200 status: ' . $status);
        }

        $decoded = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            throw new moodle_exception('apierror', 'local_aicourse', '', 'Invalid JSON response from API.');
        }

        return $decoded;
    }
}
