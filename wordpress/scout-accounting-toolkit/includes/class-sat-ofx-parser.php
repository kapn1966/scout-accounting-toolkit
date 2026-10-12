<?php
/**
 * Reads OFX 1.x (SGML) and 2.x (XML) bank and credit-card statements.
 * Source keys and payload fields stay compatible with records imported by earlier versions.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SAT_OFX_Parser
{
    public static function parse(string $raw): array
    {
        if (trim($raw) === '') {
            throw new RuntimeException('The file is empty.');
        }
        $ofx = self::decode(str_replace(["\r\n", "\r"], "\n", $raw));
        $type = self::statement_type($ofx);
        $account_id = self::account_tag($ofx, $type, 'ACCTID');
        if ($account_id === '') {
            throw new RuntimeException('No account number (ACCTID) found in the file.');
        }
        $blocks = preg_match_all('/<STMTTRN\b[^>]*>\s*(.*?)\s*<\/STMTTRN>/is', $ofx, $m) ? $m[1] : [];
        if (!$blocks) {
            throw new RuntimeException('No transactions (STMTTRN) found in the file.');
        }
        $transactions = [];
        foreach ($blocks as $block) {
            $transactions[] = self::transaction($block);
        }
        $start = self::tag($ofx, 'DTSTART');
        $end = self::tag($ofx, 'DTEND');
        $ledger_date = self::tag($ofx, 'DTASOF');
        return [
            'format' => preg_match('/^\s*<\?xml\b/i', $ofx) ? 'OFXXML' : 'OFXSGML',
            'version' => self::version($ofx),
            'statement_type' => $type,
            'account_id' => $account_id,
            'account_type' => $type === 'credit_card' ? 'CREDITCARD' : self::account_tag($ofx, 'bank', 'ACCTTYPE'),
            'bank_id' => $type === 'bank' ? self::account_tag($ofx, 'bank', 'BANKID') : '',
            'branch_id' => $type === 'bank' ? self::account_tag($ofx, 'bank', 'BRANCHID') : '',
            'currency' => self::tag($ofx, 'CURDEF'),
            'statement_start' => $start === '' ? null : self::date($start),
            'statement_end' => $end === '' ? null : self::date($end),
            'ledger_balance' => self::tag($ofx, 'BALAMT'),
            'ledger_date' => $ledger_date === '' ? null : self::date($ledger_date),
            'transactions' => $transactions,
        ];
    }

    public static function description(string $name, string $memo): string
    {
        $name = trim($name);
        $memo = trim($memo);
        if ($name !== '' && $memo !== '') {
            return $name . ' | ' . $memo;
        }
        return $memo !== '' ? $memo : ($name !== '' ? $name : '[No OFX description]');
    }

    private static function transaction(string $block): array
    {
        $t = [
            'trntype' => self::tag($block, 'TRNTYPE'),
            'dtposted' => self::tag($block, 'DTPOSTED'),
            'trnamt' => self::tag($block, 'TRNAMT'),
            'fitid' => self::tag($block, 'FITID'),
            'name' => self::tag($block, 'NAME'),
            'memo' => self::tag($block, 'MEMO'),
            'checknum' => self::tag($block, 'CHECKNUM'),
            'refnum' => self::tag($block, 'REFNUM'),
            'raw' => trim($block),
            'error' => '',
        ];
        try {
            $missing = [];
            foreach (['dtposted', 'trnamt', 'fitid'] as $field) {
                if ($t[$field] === '') {
                    $missing[] = strtoupper($field);
                }
            }
            if ($missing) {
                throw new RuntimeException('Missing required OFX fields: ' . implode(', ', $missing));
            }
            $t['date'] = self::date($t['dtposted']);
            $amount = str_replace([',', ' '], '', trim($t['trnamt']));
            if ($amount === '' || !is_numeric($amount)) {
                throw new RuntimeException('Invalid OFX amount: ' . $t['trnamt']);
            }
            $t['amount'] = (float)$amount;
        } catch (Throwable $e) {
            $t['error'] = $e->getMessage();
        }
        return $t;
    }

    private static function decode(string $raw): string
    {
        $encoding = self::encoding($raw);
        if (strcasecmp($encoding, 'UTF-8') === 0) {
            return $raw;
        }
        if (function_exists('mb_convert_encoding')) {
            return mb_convert_encoding($raw, 'UTF-8', $encoding);
        }
        if (function_exists('iconv')) {
            $converted = iconv($encoding, 'UTF-8//TRANSLIT', $raw);
            if ($converted !== false) {
                return $converted;
            }
        }
        return $raw;
    }

    private static function encoding(string $ofx): string
    {
        $header = self::header($ofx, 'ENCODING');
        $charset = self::header($ofx, 'CHARSET');
        if (strcasecmp($header, 'UTF-8') === 0 || $charset === '65001') {
            return 'UTF-8';
        }
        if (preg_match('/<\?xml[^>]*encoding=["\']([^"\']+)["\']/i', $ofx, $m)) {
            $xml = strtoupper(trim($m[1]));
            if ($xml === 'UTF-8') {
                return 'UTF-8';
            }
            return $xml === 'US-ASCII' ? 'Windows-1252' : $xml;
        }
        return 'Windows-1252';
    }

    private static function statement_type(string $ofx): string
    {
        if (preg_match('/<CCACCTFROM\b/i', $ofx)) {
            return 'credit_card';
        }
        if (preg_match('/<BANKACCTFROM\b/i', $ofx)) {
            return 'bank';
        }
        throw new RuntimeException('The file contains neither CCACCTFROM nor BANKACCTFROM.');
    }

    private static function account_tag(string $ofx, string $type, string $tag): string
    {
        $container = $type === 'credit_card' ? 'CCACCTFROM' : 'BANKACCTFROM';
        if (!preg_match('/<' . $container . '\b[^>]*>(.*?)<\/' . $container . '>/is', $ofx, $m)) {
            return '';
        }
        return self::tag($m[1], $tag);
    }

    private static function version(string $ofx): string
    {
        $version = self::header($ofx, 'VERSION');
        if ($version !== '') {
            return $version;
        }
        return preg_match('/<\?OFX\b[^>]*\bVERSION=["\']?([^\s"\'?>]+)/i', $ofx, $m) ? trim($m[1]) : '';
    }

    private static function header(string $ofx, string $name): string
    {
        return preg_match('/(?:^|\n)' . preg_quote($name, '/') . ':([^\n]*)/i', $ofx, $m) ? trim($m[1]) : '';
    }

    private static function tag(string $text, string $tag): string
    {
        if (!preg_match('/<' . preg_quote($tag, '/') . '\b[^>]*>\s*([^<]*)/i', $text, $m)) {
            return '';
        }
        return trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    private static function date(string $value): string
    {
        if (!preg_match('/^(\d{4})(\d{2})(\d{2})/', $value, $m)) {
            throw new RuntimeException('Invalid OFX date: ' . $value);
        }
        if (!checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
            throw new RuntimeException('Invalid OFX calendar date: ' . $value);
        }
        return sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]);
    }
}
