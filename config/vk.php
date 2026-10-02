<?php

return [

    'api_version' => env('VK_API_VERSION', '5.199'),

    'api_url' => env('VK_API_URL', 'https://api.vk.com/method'),

    'admin_token' => env('ADMIN_TOKEN', ''),

    'owner_id' => (int) env('VK_OWNER_ID', 0),

    'kirkorov_rules' => env('VK_KIRKOROV_RULES', true),

    'confirmation_code' => env('VK_CONFIRMATION_CODE', ''),

    'auto_reply' => env('VK_AUTO_REPLY', false),

    'auto_reply_text' => env('VK_AUTO_REPLY_TEXT', 'Сообщение получено. Бот на связи.'),

    'reaction_id' => (int) env('VK_REACTION_ID', 1),

    'reaction_ids' => env('VK_REACTION_IDS', ''),

    'stale_seconds' => (int) env('VK_STALE_SECONDS', 300),

    'stale_reply_text' => env('VK_STALE_REPLY_TEXT', 'Дорогой, Филипп Киркоров просто спал и не мог уделить вам внимание.'),

    'llm_enabled' => env('LLM_ENABLED', false),

    'llm_url' => env('OLLAMA_URL', 'http://ollama:11434'),

    'llm_model' => env('OLLAMA_MODEL', 'qwen3:8b'),

    'llm_timeout' => (int) env('LLM_TIMEOUT', 110),

    'llm_system_prompt' => env('LLM_SYSTEM_PROMPT', 'Ты Филипп Киркоров, большая звезда эстрады. Отвечай на смысл последней реплики, а не одной и той же заготовкой. Виктор Мельников — твой президент: ему льсти и не перечь, но реагируй на то, что он сказал. Остальным отвечай пафосно и снисходительно. Одно или два коротких предложения, каждый раз другими словами.'),

    'llm_max_sentences' => (int) env('LLM_MAX_SENTENCES', 5),

    'llm_num_predict' => (int) env('LLM_NUM_PREDICT', 280),

    'name_patterns' => env('VK_NAME_PATTERNS', 'филипп,филя,киркоров,kirkorov,philipp,philip'),

    'speaker_name' => env('LLM_SPEAKER', 'Филипп'),

    'group_fields' => 'description,members_count,activity,status,site,city,contacts,links',

    'user_fields' => 'screen_name,photo_100,sex,city,can_write_private_message,domain',

];
