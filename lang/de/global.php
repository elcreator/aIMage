<?php

return [
    // Permissions, referenced by lang_key from the migration.
    'permissions_group' => 'AIMage',
    'permission_access' => 'AIMage verwenden',

    // Page furniture
    'title' => 'AIMage',
    'tagline' => 'Beschreiben Sie einen Stapel Bildarbeit; er wird im Hintergrund ausgeführt.',
    'denied' => 'Sie haben keine Berechtigung, AIMage zu verwenden.',

    'new_job' => 'Neuer Stapel',
    'jobs' => 'Stapel',
    'no_jobs' => 'Noch nichts. Beschreiben Sie, was getan werden soll.',
    'instruction_placeholder' => 'z. B. alle Bilder in products/ hochskalieren oder 10 Titelbilder eines Bergsees im Morgengrauen erzeugen',
    'send' => 'Senden',
    'record' => 'Diktieren',
    'recording' => 'Aufnahme läuft — zum Beenden klicken',
    'transcribing' => 'Wird transkribiert…',
    'speak_answer' => 'Antworten vorlesen',

    // Models
    'text_model' => 'Planungsmodell',
    'image_model' => 'Bildmodell',
    'voice_model' => 'Diktiermodell',
    'output_folder' => 'Ergebnisordner',
    'model_provider' => 'über :provider',

    // The two numbers the picker exists to show.
    'per_image' => 'pro Bild',
    'est_cost' => 'Geschätzte Kosten',
    'est_time' => 'Geschätzte Wartezeit',
    'eta_range' => '~:p50 s üblich, bis zu :p90 s',
    'price_exact' => 'Festpreis',
    'price_approx' => 'Ungefähr',
    'price_unknown' => 'Kein veröffentlichter Preis',
    'basis_tariff' => 'ein fester Tarif pro Bild',
    'basis_tariff_max' => 'die teuerste Auflösungsstufe, also eine Obergrenze',
    'basis_observed' => 'der Median vergleichbarer früherer Durchläufe',
    'basis_rates' => 'Token-Tarife — der Endbetrag hängt von der Länge ab',
    'basis_estimated' => 'eine Annahme; dafür liegt keine Abrechnungshistorie vor',
    'basis_unpriced' => 'derzeit nicht bepreist',
    'latency_measured' => 'gemessen an :n echten Durchläufen',
    'latency_coarse' => 'über die Varianten dieses Modells zusammengefasst',
    'latency_seeded' => 'eine Schätzung, keine Messung',
    'latency_none' => 'unbekannt',
    'catalog_stale' => 'Zwischengespeicherte Modellliste — das Gateway war nicht erreichbar.',

    // Job states
    'status_planning' => 'Planung',
    'status_awaiting_input' => 'Benötigt Ihre Antwort',
    'status_awaiting_approval' => 'Benötigt Ihre Freigabe',
    'status_running' => 'Läuft',
    'status_succeeded' => 'Fertig',
    'status_failed' => 'Fehlgeschlagen',
    'status_cancelled' => 'Abgebrochen',

    'approve' => 'Freigeben und ausführen',
    'cancel_job' => 'Abbrechen',
    'plan_summary' => ':steps Schritt(e), etwa :images Bild(er)',
    'progress' => ':done von :total erledigt',
    'failed_count' => ':n fehlgeschlagen',
    'reply_placeholder' => 'Antwort…',

    // Steps
    'step_generate' => 'Erzeugen',
    'step_edit' => 'Bearbeiten',
    'step_variate' => 'Variation',
    'step_upscale' => 'Hochskalieren',
    'step_describe' => 'Beschreiben',
    'before' => 'Vorher',
    'after' => 'Nachher',

    // Keys
    'key_needed_title' => 'Ein API-Schlüssel wird benötigt',
    'key_needed_body' => 'AIMage arbeitet über das Gateway ai.artur.work. Geben Sie Ihren eigenen Schlüssel ein '
        . 'oder bitten Sie einen Administrator, einen websiteweiten Schlüssel zu hinterlegen.',
    'key_your_own' => 'Ihr Schlüssel',
    'key_site' => 'Websiteweiter Schlüssel',
    'key_using_own' => 'Ihr eigener Schlüssel wird verwendet.',
    'key_using_site' => 'Der websiteweite Schlüssel wird verwendet.',
    'key_placeholder' => 'Schlüssel einfügen',
    'key_save' => 'Schlüssel speichern',
    'key_clear' => 'Entfernen',
    'key_saved' => 'Schlüssel gespeichert und geprüft.',
    'key_cleared' => 'Schlüssel entfernt.',

    // Batching health
    'batching_unavailable' => 'Die Hintergrundverarbeitung ist nicht verfügbar, daher läuft die Arbeit nicht von selbst.',
    'batching_not_registered' => 'Der AIMage-Aufgabentyp ist in dieser Evolution-CMS-Installation nicht registriert.',
    'batching_scheduler_down' => 'Der Scheduler läuft nicht. Starten Sie ihn mit „php core/artisan schedule:work“ oder '
        . 'lassen Sie cron jede Minute „schedule:run“ aufrufen.',

    // Errors returned by the endpoints
    'error_forbidden' => 'Sie haben dafür keine Berechtigung.',
    'error_no_key' => 'Weder für Sie noch für diese Website ist ein API-Schlüssel konfiguriert.',
    'error_empty_instruction' => 'Sagen Sie, was getan werden soll.',
    'error_unknown_model' => 'Das Gateway bietet kein :kind-Modell namens „:model“ an.',
    'error_folder_denied' => 'Sie dürfen nicht in „:folder“ schreiben.',
    'error_job_not_found' => 'Dieser Stapel existiert nicht oder gehört Ihnen nicht.',
    'error_job_finished' => 'Dieser Stapel ist bereits abgeschlossen.',
    'error_not_awaiting_approval' => 'Dieser Stapel wartet nicht auf eine Freigabe.',
    'error_key_from_config' => 'Der Website-Schlüssel ist in der Konfiguration gesetzt und kann hier nicht geändert werden.',
    'error_key_rejected' => 'Das Gateway hat diesen Schlüssel abgelehnt.',
    'error_no_audio' => 'Es wurde kein Audio empfangen.',
    'error_audio_too_large' => 'Diese Aufnahme ist zu groß.',
    'error_audio_unsupported' => 'Dieses Audioformat wird nicht unterstützt.',
    'error_empty_transcript' => 'Aus dieser Aufnahme konnte nichts transkribiert werden.',
    'error_empty_text' => 'Es gibt nichts vorzulesen.',
    'error_speech_disabled' => 'Das Vorlesen von Antworten ist nicht konfiguriert.',
];
