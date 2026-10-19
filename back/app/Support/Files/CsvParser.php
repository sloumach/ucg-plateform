<?php

namespace App\Support\Files;

use Illuminate\Validation\ValidationException;

final class CsvParser
{
    /** @return array{headers:list<string>,rows:list<array{line:int,cells:list<string>}>} */
    public function parse(string $contents, string $delimiter, int $maximumRows, int $maximumColumns = 32): array
    {
        if (! in_array($delimiter, [',', ';'], true) || $contents === '' || ! mb_check_encoding($contents, 'UTF-8')
            || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $contents)) {
            $this->invalid();
        }
        $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents) ?? $contents;
        $records = [];
        $cells = [];
        $field = '';
        $state = 'start';
        $line = 1;
        $startLine = 1;
        $length = strlen($contents);
        for ($i = 0; $i < $length; $i++) {
            $char = $contents[$i];
            if ($char === "\r") {
                if (($contents[$i + 1] ?? '') !== "\n") {
                    $this->invalid();
                }
                $i++;
                $char = "\n";
            }
            if ($state === 'quoted') {
                if ($char === '"') {
                    if (($contents[$i + 1] ?? '') === '"') {
                        $field .= '"';
                        $i++;
                    } else {
                        $state = 'after';
                    }
                } else {
                    $field .= $char;
                }
                if ($char === "\n") {
                    $line++;
                }
            } elseif ($char === $delimiter || $char === "\n") {
                $cells[] = $field;
                $field = '';
                $state = 'start';
                if (count($cells) > $maximumColumns) {
                    $this->invalid();
                }
                if ($char === "\n") {
                    if ($cells !== ['']) {
                        $records[] = ['line' => $startLine, 'cells' => $cells];
                    }
                    $cells = [];
                    $line++;
                    $startLine = $line;
                    if (count($records) > $maximumRows + 1) {
                        $this->invalid();
                    }
                }
            } elseif ($state === 'after') {
                $this->invalid();
            } elseif ($char === '"') {
                if ($state !== 'start' || $field !== '') {
                    $this->invalid();
                }
                $state = 'quoted';
            } else {
                $state = 'plain';
                $field .= $char;
            }
            if (strlen($field) > 32768) {
                $this->invalid();
            }
        }
        if ($state === 'quoted') {
            $this->invalid();
        }
        if ($field !== '' || $cells !== [] || $state !== 'start') {
            $cells[] = $field;
            $records[] = ['line' => $startLine, 'cells' => $cells];
        }
        if (count($records) < 2 || count($records) > $maximumRows + 1) {
            $this->invalid();
        }
        $headers = array_map(trim(...), $records[0]['cells']);
        if (count($headers) > $maximumColumns
            || count(array_unique(array_map(mb_strtolower(...), $headers))) !== count($headers)) {
            $this->invalid();
        }
        foreach ($headers as $header) {
            if ($header === '' || mb_strlen($header) > 80 || str_contains($header, "\n")) {
                $this->invalid();
            }
        }

        return ['headers' => $headers, 'rows' => array_slice($records, 1)];
    }

    private function invalid(): never
    {
        throw ValidationException::withMessages(['file' => __('people_import.invalid_csv')]);
    }
}
