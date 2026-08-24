<?php

return [
    // Permissions, referenced by lang_key from the migration.
    'permissions_group' => 'AIMage',
    'permission_access' => 'AIMage を使用する',

    // Page furniture
    'title' => 'AIMage',
    'tagline' => '画像作業のバッチを記述すると、バックグラウンドで実行されます。',
    'denied' => 'AIMage を使用する権限がありません。',

    'new_job' => '新しいバッチ',
    'jobs' => 'バッチ',
    'no_jobs' => 'まだ何もありません。やってほしいことを説明してください。',
    'instruction_placeholder' => '例: products/ の画像をすべて拡大する、または夜明けの山上湖の画像を10枚生成する',
    'send' => '送信',
    'record' => '音声入力',
    'recording' => '録音中 — クリックで停止',
    'transcribing' => '文字起こし中…',
    'speak_answer' => '回答を読み上げる',

    // Models
    'text_model' => '計画モデル',
    'image_model' => '画像モデル',
    'voice_model' => '音声認識モデル',
    'output_folder' => '結果フォルダ',
    'model_provider' => ':provider 経由',

    // The two numbers the picker exists to show.
    'per_image' => '画像1枚あたり',
    'est_cost' => '概算コスト',
    'est_time' => '概算待ち時間',
    'eta_range' => '通常 約:p50 秒、最大 :p90 秒',
    'price_exact' => '固定料金',
    'price_approx' => 'おおよそ',
    'price_unknown' => '公開された価格なし',
    'basis_tariff' => '画像1枚あたりの固定料金',
    'basis_tariff_max' => '最も価格の高い解像度帯、つまり上限値',
    'basis_observed' => '同等の過去の実行の中央値',
    'basis_rates' => 'トークン単価 — 最終金額は長さによって変わります',
    'basis_estimated' => '想定値。これに対する課金履歴はありません',
    'basis_unpriced' => '現在価格が設定されていません',
    'latency_measured' => ':n 件の実測値に基づく',
    'latency_coarse' => 'このモデルの各バリアントをまとめた値',
    'latency_seeded' => '実測ではなく推定値',
    'latency_none' => '不明',
    'catalog_stale' => 'キャッシュされたモデル一覧を表示しています — ゲートウェイに接続できませんでした。',

    // Job states
    'status_planning' => '計画中',
    'status_awaiting_input' => '回答待ち',
    'status_awaiting_approval' => '承認待ち',
    'status_running' => '実行中',
    'status_succeeded' => '完了',
    'status_failed' => '失敗',
    'status_cancelled' => 'キャンセル済み',

    'approve' => '承認して実行',
    'cancel_job' => 'キャンセル',
    'plan_summary' => 'ステップ数 :steps、画像はおよそ :images 枚',
    'progress' => ':total 件中 :done 件完了',
    'failed_count' => '失敗 :n 件',
    'reply_placeholder' => '回答…',

    // Steps
    'step_generate' => '生成',
    'step_edit' => '編集',
    'step_variate' => 'バリエーション',
    'step_upscale' => '拡大',
    'step_describe' => '説明',
    'before' => '変更前',
    'after' => '変更後',

    // Keys
    'key_needed_title' => 'API キーが必要です',
    'key_needed_body' => 'AIMage は ai.artur.work ゲートウェイ経由で動作します。ご自分のキーを入力するか、'
        . 'サイト全体のキーを設定するよう管理者に依頼してください。',
    'key_your_own' => 'あなたのキー',
    'key_site' => 'サイト全体のキー',
    'key_using_own' => 'あなた自身のキーを使用しています。',
    'key_using_site' => 'サイト全体のキーを使用しています。',
    'key_placeholder' => 'キーを貼り付けてください',
    'key_save' => 'キーを保存',
    'key_clear' => '削除',
    'key_saved' => 'キーを保存し、検証しました。',
    'key_cleared' => 'キーを削除しました。',

    // Batching health
    'batching_unavailable' => 'バックグラウンド処理が利用できないため、作業は自動では実行されません。',
    'batching_not_registered' => 'この Evolution CMS のインストールに AIMage のタスク種別が登録されていません。',
    'batching_scheduler_down' => 'スケジューラーが動作していません。「php core/artisan schedule:work」で起動するか、'
        . 'cron で毎分「schedule:run」を呼び出すよう設定してください。',

    // Errors returned by the endpoints
    'error_forbidden' => 'その操作を行う権限がありません。',
    'error_no_key' => 'あなたにもこのサイトにも API キーが設定されていません。',
    'error_empty_instruction' => 'やってほしいことを入力してください。',
    'error_unknown_model' => 'ゲートウェイに「:model」という :kind モデルはありません。',
    'error_folder_denied' => '「:folder」に書き込むことはできません。',
    'error_job_not_found' => 'そのバッチは存在しないか、あなたのものではありません。',
    'error_job_finished' => 'そのバッチはすでに完了しています。',
    'error_not_awaiting_approval' => 'そのバッチは承認待ちではありません。',
    'error_key_from_config' => 'サイトのキーは設定ファイルで指定されており、ここでは変更できません。',
    'error_key_rejected' => 'ゲートウェイがそのキーを拒否しました。',
    'error_no_audio' => '音声を受信できませんでした。',
    'error_audio_too_large' => 'その録音はサイズが大きすぎます。',
    'error_audio_unsupported' => 'その音声形式には対応していません。',
    'error_empty_transcript' => 'その録音からは何も文字起こしできませんでした。',
    'error_empty_text' => '読み上げる内容がありません。',
    'error_speech_disabled' => '回答の読み上げは設定されていません。',
];
