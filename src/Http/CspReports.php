<?php

declare(strict_types=1);

namespace Patrimeter\Http;

/**
 * Reading of the CSP violation reports: report-uri format (`{"csp-report": {...}}`) and Reporting API format
 * (`[{"type": "csp-violation", "body": {...}}]`). Only keeps what helps the diagnosis, cleaned for the log:
 * addresses without query nor fragment, link tokens (reset, confirmation) masked, strings truncated; the code
 * excerpt (`sample`) is never kept.
 */
final class CspReports
{
    private const int MAX_REPORTS = 10;
    private const int MAX_LENGTH = 200;

    /** @return list<array{directive: string, blocked: string, document: string, source: string}> */
    public static function parse(string $body): array
    {
        try {
            $data = json_decode($body, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }
        if (!is_array($data)) {
            return [];
        }
        $bodies = [];
        if (isset($data['csp-report']) && is_array($data['csp-report'])) {
            $report = $data['csp-report'];
            $bodies[] = [
                'directive' => $report['effective-directive'] ?? $report['violated-directive'] ?? null,
                'blocked' => $report['blocked-uri'] ?? null,
                'document' => $report['document-uri'] ?? null,
                'source' => $report['source-file'] ?? null,
            ];
        } elseif (array_is_list($data)) {
            foreach (array_keys($data) as $index) {
                $body = self::reportingBody($data[$index]);
                if ($body !== null) {
                    $bodies[] = $body;
                }
            }
        }

        $violations = [];
        foreach (array_slice($bodies, 0, self::MAX_REPORTS) as $fields) {
            $violations[] = [
                'directive' => self::text($fields['directive']),
                'blocked' => self::location($fields['blocked']),
                'document' => self::location($fields['document']),
                'source' => self::location($fields['source']),
            ];
        }

        return $violations;
    }

    /** @return array{directive: mixed, blocked: mixed, document: mixed, source: mixed}|null body of a Reporting API report */
    private static function reportingBody(mixed $report): ?array
    {
        if (!is_array($report) || ($report['type'] ?? null) !== 'csp-violation' || !isset($report['body']) || !is_array($report['body'])) {
            return null;
        }

        return [
            'directive' => $report['body']['effectiveDirective'] ?? null,
            'blocked' => $report['body']['blockedURL'] ?? null,
            'document' => $report['body']['documentURL'] ?? null,
            'source' => $report['body']['sourceFile'] ?? null,
        ];
    }

    /** Address without query nor fragment, tokens masked; keyword (`inline`, `eval`…) as is. */
    private static function location(mixed $value): string
    {
        $text = self::text($value);
        $text = (string) preg_replace('/[?#].*\z/s', '', $text);

        return (string) preg_replace('/[A-Za-z0-9_-]{20,}(\.[A-Za-z0-9_-]{20,})?/', '[jeton]', $text);
    }

    private static function text(mixed $value): string
    {
        if (!is_string($value) || $value === '') {
            return '-';
        }
        $clean = (string) preg_replace('/[\x00-\x1F\x7F]/u', '', $value);

        return mb_substr($clean, 0, self::MAX_LENGTH);
    }
}
