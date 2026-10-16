<?php
/**
 * Deterministic OFX statements for one side of an accepted SAT transfer.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SAT_OFX_Writer
{
    private const FITID_NAMESPACE = 'sat-transfer-ofx-fitid-v1';
    private const FITID_LENGTH = 24;

    public static function amount_cents(string $amount): int
    {
        if (!preg_match('/^([+-]?)(\d+)(?:\.(\d{1,2}))?$/D', trim($amount), $matches)) {
            throw new InvalidArgumentException('Transaction amount must be a decimal with at most two places.');
        }
        $whole = (int)$matches[2];
        $fraction = (int)str_pad($matches[3] ?? '', 2, '0');
        $cents = ($whole * 100) + $fraction;
        return ($matches[1] ?? '') === '-' ? -$cents : $cents;
    }

    public static function serialize(array $account, array $transaction): string
    {
        return self::serialize_internal($account, [$transaction], true);
    }

    public static function serialize_statement(array $account, array $transactions): string
    {
        return self::serialize_internal($account, $transactions, false);
    }

    private static function serialize_internal(array $account, array $transactions, bool $legacy_statement_range): string
    {
        if ($transactions === []) {
            throw new InvalidArgumentException('At least one transfer transaction is required.');
        }

        $type = (string)($account['type'] ?? '');
        if (!in_array($type, ['bank', 'credit_card'], true)) {
            throw new InvalidArgumentException('Account type must be bank or credit_card.');
        }
        $label = self::required_text($account, 'label', 'Configured account name is required.');
        $account_id = self::required_text($account, 'acctid', 'Configured account ID is required.');
        $currency = strtoupper(self::required_text($account, 'currency', 'Account currency is required.'));
        if (!preg_match('/^[A-Z]{3}$/D', $currency)) {
            throw new InvalidArgumentException('Account currency must be a three-letter code.');
        }

        $account_fields = [];
        if ($type === 'bank') {
            $account_fields = [
                'bank_id' => self::required_text($account, 'bank_id', 'Bank ID is required for a bank account.'),
                'branch_id' => self::required_text($account, 'branch_id', 'Branch ID is required for a bank account.'),
                'account_type' => self::required_text($account, 'account_type', 'Bank account type is required.'),
            ];
        }

        $normalized = [];
        foreach ($transactions as $index => $transaction) {
            if (!is_array($transaction)) {
                throw new InvalidArgumentException('Transaction #' . ($index + 1) . ' must be an array.');
            }
            $normalized[] = self::normalize_transaction($account_id, $transaction);
        }

        usort($normalized, static function (array $left, array $right): int {
            $cmp = strcmp((string)$left['posted_date'], (string)$right['posted_date']);
            if ($cmp !== 0) {
                return $cmp;
            }
            return strcmp((string)$left['fitid'], (string)$right['fitid']);
        });

        $seen_fitids = [];
        foreach ($normalized as $transaction) {
            $fitid = (string)$transaction['fitid'];
            if (isset($seen_fitids[$fitid])) {
                throw new InvalidArgumentException('Duplicate FITID generated: ' . $fitid . '.');
            }
            $seen_fitids[$fitid] = true;
        }

        $start_date = (string)$normalized[0]['posted_date'];
        $end_date = (string)$normalized[count($normalized) - 1]['posted_date'];
        if ($legacy_statement_range && count($normalized) === 1) {
            $start_date = (string)$normalized[0]['statement_start'];
            $end_date = (string)$normalized[0]['statement_end'];
        }

        if ($type === 'credit_card') {
            $output = self::multi_credit_card_statement(
                $label,
                $account_id,
                $currency,
                $normalized,
                $start_date,
                $end_date
            );
        } else {
            $output = self::multi_bank_statement(
                $account_id,
                $currency,
                $account_fields,
                $normalized,
                $start_date,
                $end_date
            );
        }

        return $output;
    }

    private static function normalize_transaction(string $account_id, array $transaction): array
    {
        $source_system = self::required_text($transaction, 'source_system', 'Transaction source system is required.');
        $source_key = self::required_text($transaction, 'source_key', 'Transaction source key is required.');
        $pair = $transaction['pair_identity'] ?? null;
        if (!is_array($pair) || count($pair) !== 2) {
            throw new InvalidArgumentException('A transfer pair must have exactly two source identities.');
        }
        $pair_keys = [];
        foreach ($pair as $identity) {
            if (!is_array($identity)) {
                throw new InvalidArgumentException('Transfer source identities must be structured values.');
            }
            $pair_keys[] = self::required_text($identity, 'source_system', 'Transfer source system is required.')
                . ':' . self::required_text($identity, 'source_key', 'Transfer source key is required.');
        }
        sort($pair_keys, SORT_STRING);
        $current_pair_key = $source_system . ':' . $source_key;
        if (!in_array($current_pair_key, $pair_keys, true) || $pair_keys[0] === $pair_keys[1]) {
            throw new InvalidArgumentException('The current transaction is not a distinct leg of the supplied transfer pair.');
        }

        $leg_identity = $current_pair_key . ':' . $account_id;
        $fitid = self::fitid($pair_keys, $leg_identity);
        if (isset($transaction['fitid']) && !hash_equals($fitid, strtoupper(trim((string)$transaction['fitid'])))) {
            throw new InvalidArgumentException('Frozen FITID does not match the transfer leg identity.');
        }
        $amount_cents = self::amount_cents((string)($transaction['amount'] ?? ''));
        if ($amount_cents === 0) {
            throw new InvalidArgumentException('Transfer amount cannot be zero.');
        }
        $amount = self::format_cents($amount_cents);
        $posted_date = self::ofx_date(self::required_text($transaction, 'date', 'Transaction posting date is required.'));
        $start_date = self::ofx_date((string)($transaction['statement_start'] ?? '') ?: (string)$transaction['date']);
        $end_date = self::ofx_date((string)($transaction['statement_end'] ?? '') ?: (string)$transaction['date']);

        $name = trim((string)($transaction['name'] ?? ''));
        if ($name === '') {
            $name = self::limit_text('Transfer ' . ($amount_cents < 0 ? 'to ' : 'from ') . self::required_text($transaction, 'other_account', 'The other account name is required.'), 32);
        }
        $memo = trim((string)($transaction['memo'] ?? ''));
        if ($memo === '') {
            $memo = trim((string)($transaction['description'] ?? ''));
        }
        if ($memo === '') {
            $memo = $name;
        }

        return [
            'amount' => $amount,
            'amount_cents' => $amount_cents,
            'fitid' => $fitid,
            'posted_date' => $posted_date,
            'statement_start' => $start_date,
            'statement_end' => $end_date,
            'name' => $name,
            'memo' => $memo,
        ];
    }

    public static function fitid_for_leg(array $pair, string $source_system, string $source_key, string $account_id): string
    {
        if (count($pair) !== 2) {
            throw new InvalidArgumentException('A transfer pair must have exactly two source identities.');
        }
        $pair_keys = [];
        foreach ($pair as $identity) {
            if (!is_array($identity)) {
                throw new InvalidArgumentException('Transfer source identities must be structured values.');
            }
            $pair_keys[] = self::required_text($identity, 'source_system', 'Transfer source system is required.')
                . ':' . self::required_text($identity, 'source_key', 'Transfer source key is required.');
        }
        sort($pair_keys, SORT_STRING);
        if ($pair_keys[0] === $pair_keys[1]) {
            throw new InvalidArgumentException('Transfer source identities must be distinct.');
        }
        $current_pair_key = self::required_text(['value' => $source_system], 'value', 'Transaction source system is required.')
            . ':' . self::required_text(['value' => $source_key], 'value', 'Transaction source key is required.');
        if (!in_array($current_pair_key, $pair_keys, true)) {
            throw new InvalidArgumentException('The current transaction is not a distinct leg of the supplied transfer pair.');
        }
        $leg_identity = $current_pair_key . ':' . self::required_text(['value' => $account_id], 'value', 'Configured account ID is required.');
        return self::fitid($pair_keys, $leg_identity);
    }

    private static function fitid(array $pair_keys, string $leg_identity): string
    {
        $fitid_input = self::FITID_NAMESPACE . "\n" . implode("\n", $pair_keys) . "\n" . $leg_identity;
        return strtoupper(substr(hash('sha256', $fitid_input), 0, self::FITID_LENGTH));
    }

    private static function multi_credit_card_statement(
        string $label,
        string $account_id,
        string $currency,
        array $transactions,
        string $start_date,
        string $end_date
    ): string {
        $date_server = substr($transactions[0]['posted_date'], 0, 8) . '000000';
        $body = [];
        foreach ($transactions as $transaction) {
            $body[] = '<STMTTRN><TRNTYPE>' . ($transaction['amount_cents'] < 0 ? 'DEBIT' : 'CREDIT')
                . '</TRNTYPE><DTPOSTED>' . $transaction['posted_date'] . '</DTPOSTED><TRNAMT>' . $transaction['amount']
                . '</TRNAMT><FITID>' . $transaction['fitid'] . '</FITID><NAME>' . self::escape_ascii($transaction['name'], true)
                . '</NAME><MEMO>' . self::escape_ascii($transaction['memo'], true) . '</MEMO></STMTTRN>';
        }

        $xml = [
            '<?xml version="1.0" encoding="US-ASCII" standalone="no"?>',
            '<?OFX OFXHEADER="200" VERSION="200" SECURITY="NONE" OLDFILEUID="NONE" NEWFILEUID="NONE"?>',
            '<OFX>',
            '<SIGNONMSGSRSV1><SONRS><STATUS><CODE>0</CODE><SEVERITY>INFO</SEVERITY></STATUS>'
                . '<DTSERVER>' . $date_server . '</DTSERVER><LANGUAGE>ENG</LANGUAGE><FI><ORG>'
                . self::escape_ascii($label, true) . '</ORG></FI></SONRS></SIGNONMSGSRSV1>',
            '<CREDITCARDMSGSRSV1><CCSTMTTRNRS><TRNUID>' . $transactions[0]['fitid']
                . '</TRNUID><STATUS><CODE>0</CODE><SEVERITY>INFO</SEVERITY></STATUS><CCSTMTRS>',
            '<CURDEF>' . $currency . '</CURDEF><CCACCTFROM><ACCTID>'
                . self::escape_ascii($account_id, true) . '</ACCTID></CCACCTFROM>',
            '<BANKTRANLIST><DTSTART>' . $start_date . '</DTSTART><DTEND>' . $end_date . '</DTEND>' . implode('', $body) . '</BANKTRANLIST>',
            '</CCSTMTRS></CCSTMTTRNRS></CREDITCARDMSGSRSV1></OFX>',
        ];
        return implode("\n", array_filter($xml, static fn (string $line): bool => $line !== '')) . "\n";
    }

    private static function multi_bank_statement(
        string $account_id,
        string $currency,
        array $account_fields,
        array $transactions,
        string $start_date,
        string $end_date
    ): string {
        $date_server = substr($transactions[0]['posted_date'], 0, 8) . '000000';
        $body = [];
        foreach ($transactions as $transaction) {
            $body[] = '<STMTTRN><TRNTYPE>' . ($transaction['amount_cents'] < 0 ? 'DEBIT' : 'CREDIT')
                . '<DTPOSTED>' . $transaction['posted_date'] . '<TRNAMT>' . $transaction['amount']
                . '<FITID>' . $transaction['fitid'] . '<NAME>' . self::escape_ascii($transaction['name'], false)
                . '<MEMO>' . self::escape_ascii($transaction['memo'], false) . '</STMTTRN>';
        }

        $lines = [
            'OFXHEADER:100',
            'DATA:OFXSGML',
            'VERSION:100',
            'SECURITY:TYPE1',
            'ENCODING:USASCII',
            'CHARSET:1252',
            'COMPRESSION:NONE',
            'OLDFILEUID:NONE',
            'NEWFILEUID:NONE',
            '<OFX>',
            '<SIGNONMSGSRSV1><SONRS><STATUS><CODE>0</CODE><SEVERITY>INFO</SEVERITY><MESSAGE>OK</MESSAGE></STATUS>'
                . '<DTSERVER>' . $date_server . '<LANGUAGE>ENG</SONRS></SIGNONMSGSRSV1>',
            '<BANKMSGSRSV1><STMTTRNRS><TRNUID>' . $transactions[0]['fitid']
                . '<STATUS><CODE>0</CODE><SEVERITY>INFO</SEVERITY><MESSAGE>OK</MESSAGE></STATUS><STMTRS>',
            '<CURDEF>' . $currency,
            '<BANKACCTFROM><BANKID>' . self::escape_ascii($account_fields['bank_id'], false)
                . '<BRANCHID>' . self::escape_ascii($account_fields['branch_id'], false)
                . '<ACCTID>' . self::escape_ascii($account_id, false)
                . '<ACCTTYPE>' . self::escape_ascii($account_fields['account_type'], false) . '</BANKACCTFROM>',
            '<BANKTRANLIST><DTSTART>' . $start_date . '<DTEND>' . $end_date . implode('', $body) . '</BANKTRANLIST>',
        ];
        $lines[] = '</STMTRS></STMTTRNRS></BANKMSGSRSV1></OFX>';
        return implode("\n", $lines) . "\n";
    }

    private static function required_text(array $values, string $key, string $error): string
    {
        $value = trim((string)($values[$key] ?? ''));
        if ($value === '') {
            throw new InvalidArgumentException($error);
        }
        return $value;
    }

    private static function ofx_date(string $value): string
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', trim($value));
        if (!$date || $date->format('Y-m-d') !== trim($value)) {
            throw new InvalidArgumentException('OFX dates must be valid YYYY-MM-DD values.');
        }
        return $date->format('Ymd') . '000000';
    }

    private static function format_cents(int $cents): string
    {
        $absolute = abs($cents);
        return ($cents < 0 ? '-' : '')
            . intdiv($absolute, 100)
            . '.'
            . str_pad((string)($absolute % 100), 2, '0', STR_PAD_LEFT);
    }

    private static function limit_text(string $value, int $length): string
    {
        if (preg_match_all('/./us', $value, $matches) === false) {
            throw new InvalidArgumentException('Text must be valid UTF-8.');
        }
        return implode('', array_slice($matches[0], 0, $length));
    }

    private static function escape_ascii(string $value, bool $xml): string
    {
        if (preg_match('//u', $value) !== 1) {
            throw new InvalidArgumentException('OFX text must be valid UTF-8.');
        }
        $value = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $value);
        $value = trim((string)preg_replace('/\s+/u', ' ', (string)$value));
        $flags = ENT_XML1 | ENT_SUBSTITUTE | ($xml ? ENT_QUOTES : ENT_NOQUOTES);
        $escaped = htmlspecialchars($value, $flags, 'UTF-8');
        $ascii = preg_replace_callback('/[^\x00-\x7F]/u', static function (array $match): string {
            $codepoint = iconv('UTF-8', 'UCS-4BE', $match[0]);
            if ($codepoint === false) {
                throw new InvalidArgumentException('Unable to encode OFX text.');
            }
            $number = unpack('N', $codepoint);
            return '&#x' . strtoupper(dechex((int)$number[1])) . ';';
        }, $escaped);
        if ($ascii === null || preg_match('/[^\x00-\x7F]/', $ascii)) {
            throw new InvalidArgumentException('Unable to encode OFX text as ASCII.');
        }
        return $ascii;
    }
}
