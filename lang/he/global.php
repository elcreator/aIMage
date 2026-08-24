<?php

return [
    // Permissions, referenced by lang_key from the migration.
    'permissions_group' => 'AIMage',
    'permission_access' => 'שימוש ב-AIMage',

    // Page furniture
    'title' => 'AIMage',
    'tagline' => 'תארו אצווה של עבודה על תמונות; היא מתבצעת ברקע.',
    'denied' => 'אין לך הרשאה להשתמש ב-AIMage.',

    'new_job' => 'אצווה חדשה',
    'jobs' => 'אצוות',
    'no_jobs' => 'עדיין אין כלום. תארו מה ברצונכם שייעשה.',
    'instruction_placeholder' => 'למשל: להגדיל את כל התמונות ב-products/, או ליצור 10 תמונות של אגם הררי עם שחר',
    'send' => 'שליחה',
    'record' => 'הכתבה',
    'recording' => 'מקליט — לחצו כדי לעצור',
    'transcribing' => 'מתמלל…',
    'speak_answer' => 'הקראת תשובות בקול',

    // Models
    'text_model' => 'מודל תכנון',
    'image_model' => 'מודל תמונה',
    'voice_model' => 'מודל הכתבה',
    'output_folder' => 'תיקיית תוצאות',
    'model_provider' => 'דרך :provider',

    // The two numbers the picker exists to show.
    'per_image' => 'לכל תמונה',
    'est_cost' => 'עלות משוערת',
    'est_time' => 'המתנה משוערת',
    'eta_range' => 'בדרך כלל כ-:p50 שנ׳, עד :p90 שנ׳',
    'price_exact' => 'מחיר קבוע',
    'price_approx' => 'בקירוב',
    'price_unknown' => 'אין מחיר מפורסם',
    'basis_tariff' => 'תעריף קבוע לכל תמונה',
    'basis_tariff_max' => 'דרג הרזולוציה היקר ביותר, כלומר חסם עליון',
    'basis_observed' => 'החציון של הרצות קודמות דומות',
    'basis_rates' => 'תעריפי טוקנים — הסכום הסופי תלוי באורך',
    'basis_estimated' => 'הנחה; אין עבורה היסטוריית חיוב',
    'basis_unpriced' => 'כרגע ללא תמחור',
    'latency_measured' => 'נמדד מתוך :n הרצות אמיתיות',
    'latency_coarse' => 'מאוגד על פני הווריאנטים של מודל זה',
    'latency_seeded' => 'הערכה, לא מדידה',
    'latency_none' => 'לא ידוע',
    'catalog_stale' => 'מוצגת רשימת מודלים מהמטמון — לא ניתן היה להגיע לשער.',

    // Job states
    'status_planning' => 'מתכנן',
    'status_awaiting_input' => 'ממתין לתשובתך',
    'status_awaiting_approval' => 'ממתין לאישורך',
    'status_running' => 'פועל',
    'status_succeeded' => 'הושלם',
    'status_failed' => 'נכשל',
    'status_cancelled' => 'בוטל',

    'approve' => 'אישור והרצה',
    'cancel_job' => 'ביטול',
    'plan_summary' => ':steps שלבים, בערך :images תמונות',
    'progress' => 'הושלמו :done מתוך :total',
    'failed_count' => ':n נכשלו',
    'reply_placeholder' => 'תשובה…',

    // Steps
    'step_generate' => 'יצירה',
    'step_edit' => 'עריכה',
    'step_variate' => 'וריאציה',
    'step_upscale' => 'הגדלה',
    'step_describe' => 'תיאור',
    'before' => 'לפני',
    'after' => 'אחרי',

    // Keys
    'key_needed_title' => 'נדרש מפתח API',
    'key_needed_body' => 'AIMage עובד דרך השער ai.artur.work. הזינו מפתח משלכם, או בקשו ממנהל מערכת '
        . 'להגדיר מפתח לכל האתר.',
    'key_your_own' => 'המפתח שלך',
    'key_site' => 'מפתח לכל האתר',
    'key_using_own' => 'נעשה שימוש במפתח שלך.',
    'key_using_site' => 'נעשה שימוש במפתח של האתר.',
    'key_placeholder' => 'הדביקו את המפתח',
    'key_save' => 'שמירת המפתח',
    'key_clear' => 'הסרה',
    'key_saved' => 'המפתח נשמר ואומת.',
    'key_cleared' => 'המפתח הוסר.',

    // Batching health
    'batching_unavailable' => 'עיבוד ברקע אינו זמין, ולכן העבודה לא תרוץ מעצמה.',
    'batching_not_registered' => 'סוג המשימה של AIMage אינו רשום בהתקנה זו של Evolution CMS.',
    'batching_scheduler_down' => 'המתזמן אינו פועל. הפעילו אותו באמצעות ‏"php core/artisan schedule:work"‏, או '
        . 'הגדירו את cron לקרוא ל-"schedule:run" בכל דקה.',

    // Errors returned by the endpoints
    'error_forbidden' => 'אין לך הרשאה לכך.',
    'error_no_key' => 'לא הוגדר מפתח API עבורך ולא עבור אתר זה.',
    'error_empty_instruction' => 'כתבו מה ברצונכם שייעשה.',
    'error_unknown_model' => 'השער אינו מציע מודל :kind בשם ":model".',
    'error_folder_denied' => 'אין לך הרשאת כתיבה אל ":folder".',
    'error_job_not_found' => 'אצווה זו אינה קיימת, או שאינה שלך.',
    'error_job_finished' => 'אצווה זו כבר הסתיימה.',
    'error_not_awaiting_approval' => 'אצווה זו אינה ממתינה לאישור.',
    'error_key_from_config' => 'מפתח האתר מוגדר בקובץ התצורה ולא ניתן לשנותו כאן.',
    'error_key_rejected' => 'השער דחה את המפתח הזה.',
    'error_no_audio' => 'לא התקבל אודיו.',
    'error_audio_too_large' => 'ההקלטה גדולה מדי.',
    'error_audio_unsupported' => 'פורמט אודיו זה אינו נתמך.',
    'error_empty_transcript' => 'לא ניתן היה לתמלל דבר מהקלטה זו.',
    'error_empty_text' => 'אין מה להקריא.',
    'error_speech_disabled' => 'הקראת תשובות בקול אינה מוגדרת.',
];
