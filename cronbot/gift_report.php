<?php

function gift_unfinished_list(array $job): array
{
    $list = $job['unfinished'] ?? [];
    return is_array($list) ? array_values($list) : [];
}

function gift_append_unfinished(array &$job, string $username, string $panel, string $reason): void
{
    if (!isset($job['unfinished']) || !is_array($job['unfinished'])) {
        $job['unfinished'] = [];
    }
    $job['unfinished'][] = [
        'username' => $username,
        'panel' => $panel,
        'reason' => $reason,
    ];
}

function gift_merge_remaining_unfinished(array $job, array $remaining, string $reason = 'در صف / لغو'): array
{
    $list = gift_unfinished_list($job);
    $seen = [];
    foreach ($list as $row) {
        if (!is_array($row)) {
            continue;
        }
        $seen[(string) ($row['username'] ?? '')] = true;
    }
    foreach ($remaining as $item) {
        if (!is_array($item) || empty($item['username'])) {
            continue;
        }
        $username = (string) $item['username'];
        if (isset($seen[$username])) {
            continue;
        }
        $seen[$username] = true;
        $list[] = [
            'username' => $username,
            'panel' => (string) ($item['Service_location'] ?? $job['name_panel'] ?? ''),
            'reason' => $reason,
        ];
    }
    return $list;
}

function gift_send_text_chunks($chatId, string $text, $threadId = null): void
{
    if ($chatId === null || $chatId === '') {
        return;
    }
    if ($text === '') {
        return;
    }
    $max = 3500;
    if (mb_strlen($text, 'UTF-8') <= $max) {
        $payload = [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
        ];
        if ($threadId !== null && $threadId !== '') {
            $payload['message_thread_id'] = $threadId;
        }
        telegram('sendmessage', $payload);
        return;
    }
    $parts = preg_split("/\n/", $text);
    $buffer = '';
    foreach ($parts as $line) {
        $candidate = $buffer === '' ? $line : ($buffer . "\n" . $line);
        if (mb_strlen($candidate, 'UTF-8') > $max && $buffer !== '') {
            $payload = [
                'chat_id' => $chatId,
                'text' => $buffer,
                'parse_mode' => 'HTML',
            ];
            if ($threadId !== null && $threadId !== '') {
                $payload['message_thread_id'] = $threadId;
            }
            telegram('sendmessage', $payload);
            $buffer = $line;
            continue;
        }
        $buffer = $candidate;
    }
    if ($buffer !== '') {
        $payload = [
            'chat_id' => $chatId,
            'text' => $buffer,
            'parse_mode' => 'HTML',
        ];
        if ($threadId !== null && $threadId !== '') {
            $payload['message_thread_id'] = $threadId;
        }
        telegram('sendmessage', $payload);
    }
}

function gift_build_unfinished_text(array $job, array $unfinished, bool $cancelled): string
{
    $success = intval($job['success_count'] ?? 0);
    $failed = intval($job['failed_count'] ?? 0);
    $skipped = intval($job['skipped_count'] ?? 0);
    $timeoutCount = 0;
    $queuedCount = 0;
    foreach ($unfinished as $row) {
        $reason = (string) ($row['reason'] ?? '');
        if (strpos($reason, 'timeout') !== false || strpos($reason, 'Timeout') !== false) {
            $timeoutCount++;
        } else {
            $queuedCount++;
        }
    }
    $title = $cancelled ? '⏹ شارژ همگانی لغو شد.' : '📌 شارژ همگانی به پایان رسید.';
    $lines = [
        $title,
        '',
        '✅ موفق: ' . number_format($success),
        '❌ ناموفق: ' . number_format($failed),
        '⏭ ردشده: ' . number_format($skipped),
        '⏱ Timeout: ' . number_format($timeoutCount),
        '📋 انجام‌نشده: ' . number_format(count($unfinished)),
    ];
    if ($queuedCount > 0) {
        $lines[] = '⏹ باقی‌مانده در صف: ' . number_format($queuedCount);
    }
    $lines[] = '';
    $lines[] = 'سرویس‌های انجام‌نشده:';
    foreach ($unfinished as $row) {
        if (!is_array($row) || empty($row['username'])) {
            continue;
        }
        $username = htmlspecialchars((string) $row['username'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $panel = htmlspecialchars((string) ($row['panel'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $reason = htmlspecialchars((string) ($row['reason'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $meta = trim($panel . ($reason !== '' ? ' — ' . $reason : ''), ' —');
        $lines[] = $meta !== '' ? "• <code>{$username}</code> | {$meta}" : "• <code>{$username}</code>";
    }
    return implode("\n", $lines);
}

function gift_send_unfinished_report(array $job, array $remaining = [], bool $cancelled = false): void
{
    $unfinished = gift_merge_remaining_unfinished($job, $remaining, 'در صف / لغو');
    if ($unfinished === []) {
        if (!$cancelled) {
            return;
        }
        $unfinished = [];
    }
    $text = gift_build_unfinished_text($job, $unfinished, $cancelled);
    if ($unfinished === [] && $cancelled) {
        $text = "⏹ شارژ همگانی لغو شد.\n\n"
            . '✅ موفق: ' . number_format(intval($job['success_count'] ?? 0)) . "\n"
            . '❌ ناموفق: ' . number_format(intval($job['failed_count'] ?? 0)) . "\n"
            . '⏭ ردشده: ' . number_format(intval($job['skipped_count'] ?? 0)) . "\n"
            . 'سرویس انجام‌نشده‌ای باقی نماند.';
    }

    if (!empty($job['id_admin'])) {
        gift_send_text_chunks($job['id_admin'], $text);
    }

    $setting = select('setting', '*');
    if (!empty($setting['Channel_Report'])) {
        $errorTopic = select('topicid', 'idreport', 'report', 'errorreport', 'select');
        $errorreport = $errorTopic['idreport'] ?? null;
        gift_send_text_chunks($setting['Channel_Report'], $text, $errorreport);
    }
}
